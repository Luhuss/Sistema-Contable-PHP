<?php

declare(strict_types=1);

require_once __DIR__ . '/../Auditoria/AuditoriaService.php';

/**
 * Servicio de Gestión del Plan de Cuentas Contables (Multi-tenancy).
 *
 * Administra la estructura jerárquica del plan de cuentas por empresa,
 * calculando automáticamente la naturaleza contable (DEUDORA / ACREEDORA)
 * y el nivel jerárquico (1 a 4), además de soportar homologación SII (F22).
 */
class PlanCuentasService
{
    private const TIPOS_CUENTA_VALIDOS = [
        'ACTIVO',
        'PASIVO',
        'PATRIMONIO',
        'INGRESO',
        'GASTO',
        'COSTO',
    ];

    private PDO $pdo;
    private AuditoriaService $auditoriaService;

    public function __construct(PDO $pdo, AuditoriaService $auditoriaService)
    {
        $this->pdo = $pdo;
        $this->auditoriaService = $auditoriaService;
    }

    /**
     * Crea una nueva cuenta contable dentro del plan de cuentas de una empresa,
     * deduciendo automáticamente su naturaleza contable y nivel jerárquico.
     *
     * @param int   $empresaId ID de la empresa (Multi-tenancy)
     * @param array $datos     ['codigo', 'nombre', 'tipo', 'es_imputable', 'cuenta_padre_id', 'codigo_sii']
     * @param int   $usuarioId ID del usuario que ejecuta la operación
     * @return int ID de la cuenta creada
     * @throws InvalidArgumentException|RuntimeException
     */
    public function crearCuenta(int $empresaId, array $datos, int $usuarioId): int
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('El ID de empresa es obligatorio.');
        }

        $codigo = $this->normalizarCodigo((string)($datos['codigo'] ?? ''));
        $nombre = trim((string)($datos['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 150) {
            throw new InvalidArgumentException('El nombre de la cuenta es obligatorio y no debe superar los 150 caracteres.');
        }

        $tipo = strtoupper(trim((string)($datos['tipo'] ?? '')));
        if (!in_array($tipo, self::TIPOS_CUENTA_VALIDOS, true)) {
            throw new InvalidArgumentException("Tipo de cuenta contable inválido: '{$tipo}'.");
        }

        $naturaleza = $this->deducirNaturaleza($tipo);
        $nivel      = $this->calcularNivelJerarquico($codigo);

        $esImputable   = !empty($datos['es_imputable']) ? 1 : 0;
        $cuentaPadreId = isset($datos['cuenta_padre_id']) && is_numeric($datos['cuenta_padre_id']) && (int)$datos['cuenta_padre_id'] > 0
            ? (int)$datos['cuenta_padre_id']
            : null;

        $codigoSii = isset($datos['codigo_sii']) && trim((string)$datos['codigo_sii']) !== ''
            ? mb_substr(trim((string)$datos['codigo_sii']), 0, 20)
            : null;

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Verificar unicidad del código dentro de la misma empresa
            $stmtExiste = $this->pdo->prepare("
                SELECT id FROM plan_cuentas
                WHERE empresa_id = :empresa_id AND codigo = :codigo
                LIMIT 1
            ");
            $stmtExiste->execute([
                ':empresa_id' => $empresaId,
                ':codigo'     => $codigo,
            ]);
            if ($stmtExiste->fetchColumn() !== false) {
                throw new RuntimeException("Ya existe una cuenta con el código '{$codigo}' en esta empresa.");
            }

            // 2. Validar cuenta padre si fue especificada
            if ($cuentaPadreId !== null) {
                $padre = $this->obtenerCuentaPorId($empresaId, $cuentaPadreId);
                if (!$padre) {
                    throw new InvalidArgumentException('La cuenta padre especificada no existe o no pertenece a la empresa.');
                }
                if ((int)$padre['activa'] !== 1) {
                    throw new InvalidArgumentException("La cuenta padre [{$padre['codigo']}] se encuentra inactiva.");
                }
                if ((int)$padre['es_imputable'] === 1) {
                    throw new InvalidArgumentException(
                        "La cuenta [{$padre['codigo']} - {$padre['nombre']}] es imputable y no puede tener subcuentas hijas."
                    );
                }
                if ($padre['naturaleza'] !== $naturaleza) {
                    throw new InvalidArgumentException(
                        "Inconsistencia contable: la naturaleza de la subcuenta ({$naturaleza}) no coincide con su cuenta padre ({$padre['naturaleza']})."
                    );
                }
            }

            // 3. Insertar cuenta contable con todas las columnas de `plan_cuentas`
            $sql = "INSERT INTO plan_cuentas (
                        empresa_id,
                        cuenta_padre_id,
                        codigo,
                        nombre,
                        tipo,
                        naturaleza,
                        nivel,
                        es_imputable,
                        codigo_sii,
                        activa
                    ) VALUES (
                        :empresa_id,
                        :cuenta_padre_id,
                        :codigo,
                        :nombre,
                        :tipo,
                        :naturaleza,
                        :nivel,
                        :es_imputable,
                        :codigo_sii,
                        1
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':empresa_id'      => $empresaId,
                ':cuenta_padre_id' => $cuentaPadreId,
                ':codigo'          => $codigo,
                ':nombre'          => $nombre,
                ':tipo'            => $tipo,
                ':naturaleza'      => $naturaleza,
                ':nivel'           => $nivel,
                ':es_imputable'    => $esImputable,
                ':codigo_sii'      => $codigoSii,
            ]);

            $cuentaId = (int)$this->pdo->lastInsertId();

            // 4. Registrar trazabilidad en AuditoriaService
            $this->auditoriaService->registrarEvento(
                $empresaId,
                'INSERT',
                'plan_cuentas',
                $cuentaId,
                [
                    'codigo'          => $codigo,
                    'nombre'          => $nombre,
                    'tipo'            => $tipo,
                    'naturaleza'      => $naturaleza,
                    'nivel'           => $nivel,
                    'es_imputable'    => (bool)$esImputable,
                    'codigo_sii'      => $codigoSii,
                    'cuenta_padre_id' => $cuentaPadreId,
                    'usuario_id'      => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }

            return $cuentaId;
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Error al crear la cuenta contable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Actualiza el nombre, homologación SII o condición de imputabilidad de una cuenta contable.
     *
     * @param int   $empresaId ID de la empresa
     * @param int   $cuentaId  ID de la cuenta contable
     * @param array $datos     ['nombre', 'es_imputable', 'codigo_sii']
     * @param int   $usuarioId ID del usuario que ejecuta la modificación
     * @return void
     * @throws InvalidArgumentException|RuntimeException
     */
    public function actualizarCuenta(int $empresaId, int $cuentaId, array $datos, int $usuarioId): void
    {
        $actual = $this->obtenerCuentaPorId($empresaId, $cuentaId);
        if (!$actual) {
            throw new RuntimeException('La cuenta contable no existe o no pertenece a la empresa.');
        }

        $nombre = trim((string)($datos['nombre'] ?? $actual['nombre']));
        if ($nombre === '' || mb_strlen($nombre) > 150) {
            throw new InvalidArgumentException('El nombre de la cuenta es obligatorio y no debe superar los 150 caracteres.');
        }

        $nuevoImputable = isset($datos['es_imputable'])
            ? (!empty($datos['es_imputable']) ? 1 : 0)
            : (int)$actual['es_imputable'];

        $codigoSii = array_key_exists('codigo_sii', $datos)
            ? (trim((string)$datos['codigo_sii']) !== '' ? mb_substr(trim((string)$datos['codigo_sii']), 0, 20) : null)
            : $actual['codigo_sii'];

        if ((int)$actual['es_imputable'] === 1 && $nuevoImputable === 0 && $this->tieneMovimientosContables($cuentaId)) {
            throw new RuntimeException(
                "No es posible convertir en agrupadora la cuenta [{$actual['codigo']}] porque ya registra movimientos contables históricos."
            );
        }

        if ($nuevoImputable === 1 && $this->tieneSubcuentasHijas($empresaId, $cuentaId)) {
            throw new RuntimeException(
                "No es posible marcar como imputable la cuenta [{$actual['codigo']}] porque posee subcuentas dependientes."
            );
        }

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE plan_cuentas
                SET nombre = :nombre,
                    es_imputable = :es_imputable,
                    codigo_sii = :codigo_sii,
                    actualizado_en = NOW()
                WHERE id = :id AND empresa_id = :empresa_id
            ");
            $stmt->execute([
                ':nombre'       => $nombre,
                ':es_imputable' => $nuevoImputable,
                ':codigo_sii'   => $codigoSii,
                ':id'           => $cuentaId,
                ':empresa_id'   => $empresaId,
            ]);

            $this->auditoriaService->registrarEvento(
                $empresaId,
                'CONFIGURACION',
                'plan_cuentas',
                $cuentaId,
                [
                    'codigo'                => $actual['codigo'],
                    'nombre_anterior'       => $actual['nombre'],
                    'nombre_nuevo'          => $nombre,
                    'es_imputable_anterior' => (bool)$actual['es_imputable'],
                    'es_imputable_nuevo'    => (bool)$nuevoImputable,
                    'codigo_sii'            => $codigoSii,
                    'usuario_id'            => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Error al actualizar la cuenta contable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Activa o desactiva una cuenta contable de la empresa.
     *
     * @param int  $empresaId ID de la empresa
     * @param int  $cuentaId  ID de la cuenta
     * @param bool $activa    true para activar, false para desactivar
     * @param int  $usuarioId ID del usuario
     * @return void
     * @throws RuntimeException
     */
    public function cambiarEstadoCuenta(int $empresaId, int $cuentaId, bool $activa, int $usuarioId): void
    {
        $actual = $this->obtenerCuentaPorId($empresaId, $cuentaId);
        if (!$actual) {
            throw new RuntimeException('La cuenta contable no existe o no pertenece a la empresa.');
        }

        $nuevoEstado = $activa ? 1 : 0;
        if ((int)$actual['activa'] === $nuevoEstado) {
            return;
        }

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE plan_cuentas
                SET activa = :activa,
                    actualizado_en = NOW()
                WHERE id = :id AND empresa_id = :empresa_id
            ");
            $stmt->execute([
                ':activa'     => $nuevoEstado,
                ':id'         => $cuentaId,
                ':empresa_id' => $empresaId,
            ]);

            $this->auditoriaService->registrarEvento(
                $empresaId,
                'CONFIGURACION',
                'plan_cuentas',
                $cuentaId,
                [
                    'codigo'     => $actual['codigo'],
                    'accion'     => $activa ? 'ACTIVAR_CUENTA' : 'DESACTIVAR_CUENTA',
                    'usuario_id' => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Error al cambiar el estado de la cuenta contable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Obtiene una cuenta contable por su ID validando pertenencia a la empresa.
     *
     * @param int $empresaId
     * @param int $cuentaId
     * @return array<string, mixed>|null
     */
    public function obtenerCuentaPorId(int $empresaId, int $cuentaId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, empresa_id, cuenta_padre_id, codigo, nombre, tipo, naturaleza,
                    nivel, es_imputable, codigo_sii, activa, creado_en, actualizado_en
            FROM plan_cuentas
            WHERE id = :id AND empresa_id = :empresa_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id'         => $cuentaId,
            ':empresa_id' => $empresaId,
        ]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /**
     * Busca una cuenta contable por su código único dentro de la empresa.
     *
     * @param int    $empresaId
     * @param string $codigo
     * @return array<string, mixed>|null
     */
    public function obtenerCuentaPorCodigo(int $empresaId, string $codigo): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, empresa_id, cuenta_padre_id, codigo, nombre, tipo, naturaleza,
                    nivel, es_imputable, codigo_sii, activa
            FROM plan_cuentas
            WHERE empresa_id = :empresa_id AND codigo = :codigo
            LIMIT 1
        ");
        $stmt->execute([
            ':empresa_id' => $empresaId,
            ':codigo'     => trim($codigo),
        ]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /**
     * Lista el plan de cuentas completo de una empresa ordenado jerárquicamente por código.
     *
     * @param int  $empresaId      ID de la empresa
     * @param bool $soloImputables Si es true, retorna únicamente cuentas habilitadas para asientos
     * @param bool $soloActivas    Si es true, omite cuentas desactivadas
     * @return array<int, array<string, mixed>>
     */
    public function listarPlanCuentas(
        int $empresaId,
        bool $soloImputables = false,
        bool $soloActivas = true
    ): array {
        $condiciones = ['pc.empresa_id = :empresa_id'];
        if ($soloImputables) {
            $condiciones[] = 'pc.es_imputable = 1';
        }
        if ($soloActivas) {
            $condiciones[] = 'pc.activa = 1';
        }

        $whereSql = implode(' AND ', $condiciones);

        $sql = "SELECT pc.id,
                        pc.empresa_id,
                        pc.cuenta_padre_id,
                        padre.codigo AS codigo_padre,
                        padre.nombre AS nombre_padre,
                        pc.codigo,
                        pc.nombre,
                        pc.tipo,
                        pc.naturaleza,
                        pc.nivel,
                        pc.es_imputable,
                        pc.codigo_sii,
                        pc.activa
                    FROM plan_cuentas pc
                    LEFT JOIN plan_cuentas padre
                        ON padre.id = pc.cuenta_padre_id
                        AND padre.empresa_id = pc.empresa_id
                WHERE {$whereSql}
                ORDER BY pc.codigo ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':empresa_id' => $empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Deduce la naturaleza contable estándar según el tipo de cuenta.
     *
     * @param string $tipo
     * @return string 'DEUDORA' | 'ACREEDORA'
     */
    private function deducirNaturaleza(string $tipo): string
    {
        return match ($tipo) {
            'ACTIVO', 'GASTO', 'COSTO'       => 'DEUDORA',
            'PASIVO', 'PATRIMONIO', 'INGRESO' => 'ACREEDORA',
            default                          => throw new InvalidArgumentException("Tipo de cuenta sin naturaleza definida: '{$tipo}'."),
        };
    }

    /**
     * Calcula el nivel jerárquico (1 a 4) a partir de los segmentos del código (ej. '1.1.01.001' => 4).
     *
     * @param string $codigo
     * @return int
     */
    private function calcularNivelJerarquico(string $codigo): int
    {
        $segmentos = explode('.', $codigo);
        return max(1, min(4, count($segmentos)));
    }

    private function tieneMovimientosContables(int $cuentaId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM detalle_asientos WHERE cuenta_id = :cuenta_id LIMIT 1");
        $stmt->execute([':cuenta_id' => $cuentaId]);
        return $stmt->fetchColumn() !== false;
    }

    private function tieneSubcuentasHijas(int $empresaId, int $cuentaId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM plan_cuentas
            WHERE empresa_id = :empresa_id AND cuenta_padre_id = :cuenta_id
            LIMIT 1
        ");
        $stmt->execute([
            ':empresa_id' => $empresaId,
            ':cuenta_id'  => $cuentaId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function normalizarCodigo(string $codigoRaw): string
    {
        $codigo = trim($codigoRaw);
        if ($codigo === '' || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $codigo) || strlen($codigo) > 30) {
            throw new InvalidArgumentException(
                "El código de cuenta '{$codigoRaw}' es inválido. Use formato numérico separado por puntos (ej. 1.1.01.001)."
            );
        }

        return $codigo;
    }
}