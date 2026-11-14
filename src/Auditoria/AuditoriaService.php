<?php

declare(strict_types=1);

/**
 * Servicio Central de Auditoría e Inmutabilidad (ADR-002).
 *
 * Gestiona el registro inalterable de eventos críticos y la consulta
 * auditada de trazabilidad sobre la tabla `bitacora_auditoria`,
 * manteniendo aislamiento multi-tenant estricto por `empresa_id`.
 */
class AuditoriaService
{
    private const ACCIONES_PERMITIDAS = [
        'INSERT',
        'UPDATE_ANULACION',
        'CIERRE_PERIODO',
        'APERTURA_PERIODO',
        'LOGIN',
        'CONFIGURACION',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Registra un evento inmutable en la bitácora de auditoría.
     *
     * Participa de forma transparente en cualquier transacción PDO activa
     * iniciada por servicios orquestadores superiores (ADR-003).
     *
     * @param int         $empresaId     ID de la empresa (Multi-tenancy)
     * @param string      $accion        Tipo de operación auditada
     * @param string      $tablaAfectada Nombre de la tabla afectada
     * @param int         $registroId    ID del registro afectado en la tabla destino
     * @param array       $detalles      Carga útil estructurada de auditoría (se serializa a JSON)
     * @param string|null $ipOrigen      IP del cliente (se autodetecta si es null)
     * @return int ID del registro creado en bitacora_auditoria
     * @throws InvalidArgumentException Si los parámetros de auditoría son inválidos.
     * @throws RuntimeException Si falla la inserción en base de datos.
     */
    public function registrarEvento(
        int $empresaId,
        string $accion,
        string $tablaAfectada,
        int $registroId,
        array $detalles = [],
        ?string $ipOrigen = null
    ): int {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('El ID de empresa es obligatorio para registrar auditoría.');
        }

        $accionNormalizada = strtoupper(trim($accion));
        if (!in_array($accionNormalizada, self::ACCIONES_PERMITIDAS, true)) {
            throw new InvalidArgumentException("Acción de auditoría no permitida: '{$accionNormalizada}'.");
        }

        $tablaLimpia = trim($tablaAfectada);
        if ($tablaLimpia === '' || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $tablaLimpia)) {
            throw new InvalidArgumentException("Nombre de tabla afectada inválido: '{$tablaAfectada}'.");
        }

        if ($registroId < 0) {
            throw new InvalidArgumentException('El ID del registro afectado no puede ser negativo.');
        }

        $ipFinal = $this->resolverIpOrigen($ipOrigen);

        try {
            $detallesJson = json_encode(
                $detalles,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('No se pudo serializar el detalle de auditoría a JSON: ' . $e->getMessage(), 0, $e);
        }

        try {
            $sql = "INSERT INTO bitacora_auditoria (
                        empresa_id,
                        accion,
                        tabla_afectada,
                        registro_id,
                        detalles,
                        ip_origen
                    ) VALUES (
                        :empresa_id,
                        :accion,
                        :tabla_afectada,
                        :registro_id,
                        :detalles,
                        :ip_origen
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':empresa_id'     => $empresaId,
                ':accion'         => $accionNormalizada,
                ':tabla_afectada' => $tablaLimpia,
                ':registro_id'    => $registroId,
                ':detalles'       => $detallesJson,
                ':ip_origen'      => $ipFinal,
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new RuntimeException('Error al persistir registro en bitácora de auditoría: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Consulta los registros de auditoría de una empresa con filtros opcionales.
     *
     * @param int   $empresaId ID de la empresa (Multi-tenancy estricto)
     * @param array $filtros   Filtros opcionales: ['accion' => string, 'tabla_afectada' => string, 'registro_id' => int, 'fecha_desde' => string, 'fecha_hasta' => string]
     * @param int   $limite    Límite máximo de registros retornados (1 a 500)
     * @return array<int, array<string, mixed>>
     */
    public function listarEventos(int $empresaId, array $filtros = [], int $limite = 100): array
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('El ID de empresa es obligatorio para consultar auditoría.');
        }

        $limiteSeguro = max(1, min(500, $limite));

        $condiciones = ['empresa_id = :empresa_id'];
        $parametros  = [':empresa_id' => $empresaId];

        if (!empty($filtros['accion'])) {
            $condiciones[] = 'accion = :accion';
            $parametros[':accion'] = strtoupper(trim((string)$filtros['accion']));
        }

        if (!empty($filtros['tabla_afectada'])) {
            $condiciones[] = 'tabla_afectada = :tabla_afectada';
            $parametros[':tabla_afectada'] = trim((string)$filtros['tabla_afectada']);
        }

        if (isset($filtros['registro_id']) && is_numeric($filtros['registro_id'])) {
            $condiciones[] = 'registro_id = :registro_id';
            $parametros[':registro_id'] = (int)$filtros['registro_id'];
        }

        if (!empty($filtros['fecha_desde']) && $this->esFechaValida((string)$filtros['fecha_desde'])) {
            $condiciones[] = 'creado_en >= :fecha_desde';
            $parametros[':fecha_desde'] = $filtros['fecha_desde'] . ' 00:00:00';
        }

        if (!empty($filtros['fecha_hasta']) && $this->esFechaValida((string)$filtros['fecha_hasta'])) {
            $condiciones[] = 'creado_en <= :fecha_hasta';
            $parametros[':fecha_hasta'] = $filtros['fecha_hasta'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $condiciones);

        $sql = "SELECT id,
                        empresa_id,
                        accion,
                        tabla_afectada,
                        registro_id,
                        detalles,
                        ip_origen,
                        creado_en
                FROM bitacora_auditoria
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limiteSeguro}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filas as &$fila) {
            $decodificado = json_decode((string)$fila['detalles'], true);
            $fila['detalles_array'] = is_array($decodificado) ? $decodificado : [];
        }
        unset($fila);

        return $filas;
    }

    /**
     * Obtiene el historial cronológico completo de un documento o asiento específico.
     *
     * @param int    $empresaId     ID de la empresa
     * @param string $tablaAfectada Nombre de la tabla
     * @param int    $registroId    ID del registro
     * @return array<int, array<string, mixed>>
     */
    public function obtenerHistorialRegistro(int $empresaId, string $tablaAfectada, int $registroId): array
    {
        return $this->listarEventos($empresaId, [
            'tabla_afectada' => $tablaAfectada,
            'registro_id'    => $registroId,
        ], 200);
    }

    /**
     * Resuelve y valida la dirección IP de origen del cliente.
     *
     * @param string|null $ipExplicita
     * @return string
     */
    private function resolverIpOrigen(?string $ipExplicita): string
    {
        $candidata = $ipExplicita ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $candidata = trim($candidata);

        if (filter_var($candidata, FILTER_VALIDATE_IP) !== false) {
            return $candidata;
        }

        return '0.0.0.0';
    }

    /**
     * Valida formato de fecha YYYY-MM-DD.
     *
     * @param string $fecha
     * @return bool
     */
    private function esFechaValida(string $fecha): bool
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
        return $dt !== false && $dt->format('Y-m-d') === $fecha;
    }
}