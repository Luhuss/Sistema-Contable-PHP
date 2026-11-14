<?php

declare(strict_types=1);

/**
 * Motor Contable Central e Inmutable (ADR-001, ADR-002, ADR-003).
 *
 * Gestiona el registro de asientos contables respetando la partida doble con
 * precisión BCMath (4 decimales), aislamiento multi-tenant estricto por empresa
 * y soporte para transacciones ACID anidadas mediante delegación al orquestador.
 */
class AsientoService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Registra un asiento contable inmutable respetando la partida doble.
     *
     * @param int    $empresaId ID de la empresa (Multi-tenancy)
     * @param int    $periodoId ID del período fiscal abierto
     * @param string $fecha     Fecha contable (Formato YYYY-MM-DD)
     * @param string $glosa     Glosa descriptiva del comprobante
     * @param array  $lineas    Matriz unidireccional de ['cuenta_id' => int, 'debe' => string, 'haber' => string, 'glosa_linea' => ?string]
     * @return int ID del asiento contable creado
     * @throws Exception Si falla la cuadratura, validación de cuentas, período cerrado o error de BD.
     */
    public function registrarAsiento(
        int $empresaId,
        int $periodoId,
        string $fecha,
        string $glosa,
        array $lineas
    ): int {
        $glosaLimpia = trim($glosa);
        if ($glosaLimpia === '') {
            throw new Exception("La glosa del asiento contable es obligatoria.");
        }

        if (!$this->esFechaValida($fecha)) {
            throw new Exception("La fecha contable '{$fecha}' no tiene un formato válido (YYYY-MM-DD).");
        }

        if (count($lineas) < 2) {
            throw new Exception("El asiento contable debe contener al menos dos apuntes para cumplir con la partida doble.");
        }

        // 1. Validar Partida Doble y Unidireccionalidad con BCMath (Escala 4 decimales)
        $totalDebe = '0.0000';
        $totalHaber = '0.0000';
        $cuentasIds = [];

        foreach ($lineas as $index => $linea) {
            $cuentaId = (int)($linea['cuenta_id'] ?? 0);
            if ($cuentaId <= 0) {
                throw new Exception("Cada línea del asiento debe estar asignada a una cuenta contable válida.");
            }

            $debeOriginal = trim((string)($linea['debe'] ?? '0'));
            $haberOriginal = trim((string)($linea['haber'] ?? '0'));

            if (!is_numeric($debeOriginal) || !is_numeric($haberOriginal)) {
                throw new Exception("Los montos de DEBE y HABER deben ser valores numéricos válidos.");
            }

            $debeClean = bcadd($debeOriginal, '0', 4);
            $haberClean = bcadd($haberOriginal, '0', 4);

            // Validar que no existan montos negativos
            if (bccomp($debeClean, '0.0000', 4) < 0 || bccomp($haberClean, '0.0000', 4) < 0) {
                throw new Exception("No se permiten montos negativos en los apuntes contables.");
            }

            $tieneDebe = bccomp($debeClean, '0.0000', 4) > 0;
            $tieneHaber = bccomp($haberClean, '0.0000', 4) > 0;

            // Validar regla estricta de unidireccionalidad (DEBE XOR HABER)
            if ($tieneDebe && $tieneHaber) {
                throw new Exception("Un apunte contable no puede registrar montos en DEBE y HABER simultáneamente.");
            }

            if (!$tieneDebe && !$tieneHaber) {
                throw new Exception("Cada línea del asiento debe registrar un monto mayor a cero en el DEBE o en el HABER.");
            }

            $lineas[$index]['cuenta_id_clean'] = $cuentaId;
            $lineas[$index]['debe_clean'] = $debeClean;
            $lineas[$index]['haber_clean'] = $haberClean;
            $lineas[$index]['glosa_linea_clean'] = isset($linea['glosa_linea']) && trim((string)$linea['glosa_linea']) !== ''
                ? mb_substr(trim((string)$linea['glosa_linea']), 0, 200)
                : null;

            $cuentasIds[$cuentaId] = true;
            $totalDebe = bcadd($totalDebe, $debeClean, 4);
            $totalHaber = bcadd($totalHaber, $haberClean, 4);
        }

        if (bccomp($totalDebe, '0.0000', 4) <= 0) {
            throw new Exception("El total del asiento contable debe ser mayor a cero.");
        }

        if (bccomp($totalDebe, $totalHaber, 4) !== 0) {
            throw new Exception("Error de Partida Doble: La suma del DEBE ({$totalDebe}) no cuadra con el HABER ({$totalHaber}).");
        }

        // 2. Transacción ACID con soporte de anidamiento (ADR-003)
        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            // A. Verificar período abierto y perteneciente a la empresa con bloqueo FOR UPDATE
            $stmtPeriodo = $this->pdo->prepare("
                SELECT anio, mes, estado
                FROM periodos_fiscales
                WHERE id = :id AND empresa_id = :empresa_id
                FOR UPDATE
            ");
            $stmtPeriodo->execute([
                ':id'         => $periodoId,
                ':empresa_id' => $empresaId
            ]);
            $periodo = $stmtPeriodo->fetch(PDO::FETCH_ASSOC);

            if (!$periodo) {
                throw new Exception("El período fiscal especificado no existe o no pertenece a la empresa.");
            }

            if ($periodo['estado'] !== 'ABIERTO') {
                throw new Exception("No se pueden registrar asientos en un período fiscal cerrado ({$periodo['mes']}/{$periodo['anio']}).");
            }

            // B. Verificar que todas las cuentas pertenezcan a la empresa, estén activas y sean imputables
            $this->validarCuentasEmpresa($empresaId, array_keys($cuentasIds));

            // C. Calcular correlativo del número de asiento dentro del período
            $stmtNum = $this->pdo->prepare("
                SELECT COALESCE(MAX(numero_asiento), 0) + 1
                FROM asientos
                WHERE empresa_id = :empresa_id AND periodo_id = :periodo_id
            ");
            $stmtNum->execute([
                ':empresa_id' => $empresaId,
                ':periodo_id' => $periodoId
            ]);
            $numeroAsiento = (int)$stmtNum->fetchColumn();

            // D. Insertar cabecera de asiento inmutable
            $stmtAsiento = $this->pdo->prepare("
                INSERT INTO asientos (
                    empresa_id, periodo_id, numero_asiento, fecha, glosa,
                    tipo_asiento, total_debe, total_haber
                ) VALUES (
                    :empresa_id, :periodo_id, :numero_asiento, :fecha, :glosa,
                    'NORMAL', :total_debe, :total_haber
                )
            ");
            $stmtAsiento->execute([
                ':empresa_id'     => $empresaId,
                ':periodo_id'     => $periodoId,
                ':numero_asiento' => $numeroAsiento,
                ':fecha'          => $fecha,
                ':glosa'          => mb_substr($glosaLimpia, 0, 255),
                ':total_debe'     => $totalDebe,
                ':total_haber'    => $totalHaber
            ]);

            $asientoId = (int)$this->pdo->lastInsertId();

            // E. Insertar líneas del asiento
            $stmtDetalle = $this->pdo->prepare("
                INSERT INTO detalle_asientos (asiento_id, cuenta_id, debe, haber, glosa_linea)
                VALUES (:asiento_id, :cuenta_id, :debe, :haber, :glosa_linea)
            ");

            foreach ($lineas as $linea) {
                $stmtDetalle->execute([
                    ':asiento_id'  => $asientoId,
                    ':cuenta_id'   => $linea['cuenta_id_clean'],
                    ':debe'        => $linea['debe_clean'],
                    ':haber'       => $linea['haber_clean'],
                    ':glosa_linea' => $linea['glosa_linea_clean']
                ]);
            }

            // F. Registrar log inalterable de auditoría
            $stmtAuditoria = $this->pdo->prepare("
                INSERT INTO bitacora_auditoria (
                    empresa_id, accion, tabla_afectada, registro_id, detalles, ip_origen
                ) VALUES (
                    :empresa_id, 'INSERT', 'asientos', :registro_id, :detalles, :ip
                )
            ");
            $stmtAuditoria->execute([
                ':empresa_id'  => $empresaId,
                ':registro_id' => $asientoId,
                ':detalles'    => json_encode([
                    'numero_asiento' => $numeroAsiento,
                    'periodo_id'     => $periodoId,
                    'fecha'          => $fecha,
                    'total_debe'     => $totalDebe,
                    'total_haber'    => $totalHaber,
                    'lineas_count'   => count($lineas)
                ], JSON_UNESCAPED_UNICODE),
                ':ip'          => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            if ($transaccionPropia) {
                $this->pdo->commit();
            }

            return $asientoId;
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof Exception) {
                throw $e;
            }
            throw new Exception("Error interno en el motor contable: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Valida que todas las cuentas involucradas existan en la empresa, estén activas y sean imputables.
     *
     * @param int   $empresaId  ID de la empresa
     * @param int[] $cuentasIds Lista única de IDs de cuentas
     * @return void
     * @throws Exception Si alguna cuenta no pertenece a la empresa, está inactiva o es agrupadora.
     */
    private function validarCuentasEmpresa(int $empresaId, array $cuentasIds): void
    {
        $placeholders = implode(',', array_fill(0, count($cuentasIds), '?'));
        $sql = "SELECT id, codigo, nombre, es_imputable, activa
                FROM plan_cuentas
                WHERE empresa_id = ? AND id IN ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$empresaId], $cuentasIds));
        $encontradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($encontradas) !== count($cuentasIds)) {
            throw new Exception("Una o más cuentas contables no existen o no pertenecen a la empresa seleccionada.");
        }

        foreach ($encontradas as $cuenta) {
            if ((int)$cuenta['activa'] !== 1) {
                throw new Exception("La cuenta contable [{$cuenta['codigo']} - {$cuenta['nombre']}] se encuentra inactiva.");
            }
            if ((int)$cuenta['es_imputable'] !== 1) {
                throw new Exception("La cuenta contable [{$cuenta['codigo']} - {$cuenta['nombre']}] es agrupadora y no admite imputaciones directas.");
            }
        }
    }

    /**
     * Valida que la fecha tenga formato estrictamente YYYY-MM-DD y exista en el calendario.
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