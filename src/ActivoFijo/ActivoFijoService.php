<?php

declare(strict_types=1);

class ActivoFijoService
{
    private const REGIMENES_VALIDOS = ['NORMAL', 'ACELERADA', 'INSTANTANEA_14D'];

    private PDO $pdo;
    private AsientoService $asientoService;
    private AuditoriaService $auditoriaService;

    public function __construct(
        PDO $pdo,
        AsientoService $asientoService,
        AuditoriaService $auditoriaService
    ) {
        $this->pdo              = $pdo;
        $this->asientoService   = $asientoService;
        $this->auditoriaService = $auditoriaService;
    }

    /**
     * Registra un nuevo bien en el inventario de Activo Fijo de la empresa.
     *
     * @param int                  $empresaId
     * @param array<string, mixed> $datos
     * @param int                  $usuarioId
     * @return int ID del activo fijo creado
     */
    public function registrarActivo(int $empresaId, array $datos, int $usuarioId): int
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('ID de empresa inválido.');
        }

        $codigo      = strtoupper(trim((string)($datos['codigo_interno'] ?? '')));
        $nombre      = trim((string)($datos['nombre'] ?? ''));
        $categoria   = trim((string)($datos['categoria'] ?? 'General'));
        $fechaAdq    = trim((string)($datos['fecha_adquisicion'] ?? ''));
        $fechaMarcha = trim((string)($datos['fecha_puesta_marcha'] ?? $fechaAdq));
        $regimen     = strtoupper(trim((string)($datos['regimen_depreciacion'] ?? 'NORMAL')));
        $vidaNormal  = (int)($datos['vida_util_normal_meses'] ?? 0);

        $valorRaw = preg_replace('/[^0-9]/', '', (string)($datos['valor_adquisicion'] ?? '0'));
        $valorAdq = ($valorRaw !== null && $valorRaw !== '') ? $valorRaw : '0';

        $ctaActivoId  = (int)($datos['cuenta_activo_id'] ?? 0);
        $ctaDepAcumId = (int)($datos['cuenta_dep_acumulada_id'] ?? 0);
        $ctaGastoId   = (int)($datos['cuenta_gasto_dep_id'] ?? 0);

        if ($codigo === '' || $nombre === '') {
            throw new InvalidArgumentException('El código interno y la descripción del bien son obligatorios.');
        }

        if (!$this->esFechaValida($fechaAdq) || !$this->esFechaValida($fechaMarcha)) {
            throw new InvalidArgumentException('Las fechas de adquisición y puesta en marcha deben tener formato YYYY-MM-DD.');
        }

        if (!in_array($regimen, self::REGIMENES_VALIDOS, true)) {
            throw new InvalidArgumentException("Régimen de depreciación no soportado: '{$regimen}'.");
        }

        if ($vidaNormal <= 0 || $vidaNormal > 1200) {
            throw new InvalidArgumentException('La vida útil normal SII (en meses) debe estar entre 1 y 1200.');
        }

        if (bccomp($valorAdq, '2', 0) < 0) {
            throw new InvalidArgumentException('El valor de adquisición debe ser mayor a $1 CLP.');
        }

        if ($ctaActivoId <= 0 || $ctaDepAcumId <= 0 || $ctaGastoId <= 0) {
            throw new InvalidArgumentException('Debe asignar las tres cuentas contables (Activo, Depreciación Acumulada y Gasto).');
        }

        // Calcular vida útil aplicada según régimen tributario SII
        $vidaAplicada = match ($regimen) {
            'ACELERADA'       => max(1, (int)floor($vidaNormal / 3)),
            'INSTANTANEA_14D' => 1,
            default           => $vidaNormal,
        };

        $stmtDup = $this->pdo->prepare("
            SELECT id FROM activos_fijos
            WHERE empresa_id = :empresa_id AND codigo_interno = :codigo
            LIMIT 1
        ");
        $stmtDup->execute([
            ':empresa_id' => $empresaId,
            ':codigo'     => $codigo,
        ]);
        if ($stmtDup->fetchColumn()) {
            throw new RuntimeException("Ya existe un activo fijo con el código interno '{$codigo}' en esta empresa.");
        }

        $sql = "INSERT INTO activos_fijos (
                    empresa_id, codigo_interno, nombre, categoria,
                    fecha_adquisicion, fecha_puesta_marcha, regimen_depreciacion,
                    vida_util_normal_meses, vida_util_aplicada_meses, meses_depreciados,
                    valor_adquisicion, correccion_monetaria_acum, depreciacion_acumulada, valor_residual,
                    cuenta_activo_id, cuenta_dep_acumulada_id, cuenta_gasto_dep_id,
                    estado, creado_por
                ) VALUES (
                    :empresa_id, :codigo, :nombre, :categoria,
                    :fecha_adq, :fecha_marcha, :regimen,
                    :vida_normal, :vida_aplicada, 0,
                    :valor_adq, '0.0000', '0.0000', '1.0000',
                    :cta_activo, :cta_dep_acum, :cta_gasto,
                    'ACTIVO', :creado_por
                )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':empresa_id'    => $empresaId,
            ':codigo'        => $codigo,
            ':nombre'        => $nombre,
            ':categoria'     => $categoria !== '' ? $categoria : 'General',
            ':fecha_adq'     => $fechaAdq,
            ':fecha_marcha'  => $fechaMarcha,
            ':regimen'       => $regimen,
            ':vida_normal'   => $vidaNormal,
            ':vida_aplicada' => $vidaAplicada,
            ':valor_adq'     => bcadd($valorAdq, '0', 4),
            ':cta_activo'    => $ctaActivoId,
            ':cta_dep_acum'  => $ctaDepAcumId,
            ':cta_gasto'     => $ctaGastoId,
            ':creado_por'    => $usuarioId,
        ]);

        $activoId = (int)$this->pdo->lastInsertId();

        $this->auditoriaService->registrarEvento(
            $empresaId,
            'INSERT',
            'activos_fijos',
            $activoId,
            [
                'usuario_id'        => $usuarioId,
                'codigo_interno'    => $codigo,
                'nombre'            => $nombre,
                'regimen'           => $regimen,
                'valor_adquisicion' => bcadd($valorAdq, '0', 0),
                'vida_aplicada'     => $vidaAplicada,
            ]
        );

        return $activoId;
    }

    /**
     * Calcula el reajuste por IPC (Art. 41 LIR) y la cuota de depreciación del período
     * respetando el valor residual simbólico de $1 CLP (normativa SII).
     *
     * @param array<string, mixed> $activo
     * @param string               $variacionIpcPct Porcentaje de variación IPC (ej. '0.40' o '0.00')
     * @return array<string, string|int|bool>
     */
    public function calcularCuotaPeriodo(array $activo, string $variacionIpcPct = '0.00'): array
    {
        $valorAdq     = bcadd((string)$activo['valor_adquisicion'], '0', 4);
        $cmAcum       = bcadd((string)$activo['correccion_monetaria_acum'], '0', 4);
        $depAcum      = bcadd((string)$activo['depreciacion_acumulada'], '0', 4);
        $residual     = bcadd((string)$activo['valor_residual'], '0', 4);
        $vidaAplicada = (int)$activo['vida_util_aplicada_meses'];
        $mesesDep     = (int)$activo['meses_depreciados'];
        $regimen      = (string)$activo['regimen_depreciacion'];

        $valorBrutoActual = bcadd($valorAdq, $cmAcum, 4);
        $valorLibroActual = bcsub($valorBrutoActual, $depAcum, 4);

        if ($mesesDep >= $vidaAplicada || bccomp($valorLibroActual, $residual, 4) <= 0) {
            return [
                'aplica'             => false,
                'reajuste_bruto'     => '0.0000',
                'reajuste_dep_acum'  => '0.0000',
                'reajuste_neto'      => '0.0000',
                'meses_consumidos'   => 0,
                'cuota_depreciacion' => '0.0000',
                'nueva_cm_acum'      => $cmAcum,
                'nueva_dep_acum'     => $depAcum,
                'valor_libro_final'  => $valorLibroActual,
                'depreciado_total'   => true,
            ];
        }

        // 1. Corrección Monetaria Art. 41 LIR (no aplica en depreciación instantánea 14D)
        $reajusteBruto   = '0.0000';
        $reajusteDepAcum = '0.0000';

        if ($regimen !== 'INSTANTANEA_14D' && bccomp($variacionIpcPct, '0', 4) !== 0) {
            $factor          = bcdiv($variacionIpcPct, '100', 6);
            $reajusteBruto   = bcadd((string)round((float)bcmul($valorBrutoActual, $factor, 4)), '0', 4);
            $reajusteDepAcum = bcadd((string)round((float)bcmul($depAcum, $factor, 4)), '0', 4);
        }

        $reajusteNeto         = bcsub($reajusteBruto, $reajusteDepAcum, 4);
        $valorBrutoReajustado = bcadd($valorBrutoActual, $reajusteBruto, 4);
        $depAcumReajustada    = bcadd($depAcum, $reajusteDepAcum, 4);
        $valorLibroReajustado = bcsub($valorBrutoReajustado, $depAcumReajustada, 4);

        $baseDepreciable = bcsub($valorLibroReajustado, $residual, 4);
        if (bccomp($baseDepreciable, '0', 4) <= 0) {
            $baseDepreciable = '0.0000';
        }

        // 2. Cuota de Depreciación del Período
        $mesesRestantes = max(1, $vidaAplicada - $mesesDep);

        if ($regimen === 'INSTANTANEA_14D') {
            $mesesConsumidos   = $mesesRestantes;
            $cuotaDepreciacion = $baseDepreciable;
        } elseif ($mesesRestantes === 1) {
            $mesesConsumidos   = 1;
            $cuotaDepreciacion = $baseDepreciable;
        } else {
            $mesesConsumidos = 1;
            $cuotaRaw        = bcdiv($baseDepreciable, (string)$mesesRestantes, 4);
            $cuotaRedondeada = bcadd((string)round((float)$cuotaRaw), '0', 4);
            $cuotaDepreciacion = bccomp($cuotaRedondeada, $baseDepreciable, 4) > 0
                ? $baseDepreciable
                : $cuotaRedondeada;
        }

        $nuevaCmAcum     = bcadd($cmAcum, $reajusteBruto, 4);
        $nuevaDepAcum    = bcadd($depAcumReajustada, $cuotaDepreciacion, 4);
        $valorLibroFinal = bcsub(bcadd($valorAdq, $nuevaCmAcum, 4), $nuevaDepAcum, 4);
        $depreciadoTotal = ($mesesDep + $mesesConsumidos >= $vidaAplicada)
            || (bccomp($valorLibroFinal, $residual, 4) <= 0);

        return [
            'aplica'             => true,
            'reajuste_bruto'     => $reajusteBruto,
            'reajuste_dep_acum'  => $reajusteDepAcum,
            'reajuste_neto'      => $reajusteNeto,
            'meses_consumidos'   => $mesesConsumidos,
            'cuota_depreciacion' => $cuotaDepreciacion,
            'nueva_cm_acum'      => $nuevaCmAcum,
            'nueva_dep_acum'     => $nuevaDepAcum,
            'valor_libro_final'  => $valorLibroFinal,
            'depreciado_total'   => $depreciadoTotal,
        ];
    }

    /**
     * Contabiliza la depreciación mensual de todos los activos vigentes del período
     * en una transacción atómica, generando el asiento consolidado en AsientoService.
     *
     * @return array{procesados: int, asiento_id: int|null, total_depreciacion: string}
     */
    public function contabilizarDepreciacionPeriodo(
        int $empresaId,
        int $periodoId,
        int $usuarioId,
        string $variacionIpcPct = '0.00'
    ): array {
        $stmtPer = $this->pdo->prepare("
            SELECT id, anio, mes, estado
            FROM periodos_fiscales
            WHERE id = :id AND empresa_id = :empresa_id
            LIMIT 1
        ");
        $stmtPer->execute([
            ':id'         => $periodoId,
            ':empresa_id' => $empresaId,
        ]);
        $periodo = $stmtPer->fetch(PDO::FETCH_ASSOC);

        if (!$periodo) {
            throw new RuntimeException('El período fiscal no pertenece a la empresa activa.');
        }
        if ($periodo['estado'] !== 'ABIERTO') {
            throw new RuntimeException('No se puede centralizar depreciación en un período fiscal CERRADO.');
        }

        $ultimoDiaMes    = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', (int)$periodo['anio'], (int)$periodo['mes'])));
        $fechaContable   = ((int)$periodo['anio'] === (int)date('Y') && (int)$periodo['mes'] === (int)date('n'))
            ? date('Y-m-d')
            : $ultimoDiaMes;

        $stmtActivos = $this->pdo->prepare("
            SELECT af.*
            FROM activos_fijos af
            WHERE af.empresa_id = :empresa_id
                AND af.estado = 'ACTIVO'
                AND af.fecha_puesta_marcha <= :fecha_corte
                AND NOT EXISTS (
                    SELECT 1 FROM activo_fijo_movimientos m
                    WHERE m.activo_id = af.id
                        AND m.periodo_id = :periodo_id
                        AND m.tipo_movimiento = 'DEPRECIACION'
                )
            ORDER BY af.codigo_interno ASC
        ");
        $stmtActivos->execute([
            ':empresa_id'  => $empresaId,
            ':fecha_corte' => $ultimoDiaMes,
            ':periodo_id'  => $periodoId,
        ]);
        $activosPendientes = $stmtActivos->fetchAll(PDO::FETCH_ASSOC);

        if (empty($activosPendientes)) {
            throw new RuntimeException('No existen activos fijos pendientes de depreciar en el período actual.');
        }

        $this->pdo->beginTransaction();

        try {
            $lineasPorCuenta = [];
            $movimientos     = [];
            $totalDepGlobal  = '0.0000';

            foreach ($activosPendientes as $af) {
                $calc = $this->calcularCuotaPeriodo($af, $variacionIpcPct);
                if (!$calc['aplica'] || bccomp((string)$calc['cuota_depreciacion'], '0', 4) <= 0) {
                    continue;
                }

                $cuota = (string)$calc['cuota_depreciacion'];
                $totalDepGlobal = bcadd($totalDepGlobal, $cuota, 4);

                $ctaGasto   = (int)$af['cuenta_gasto_dep_id'];
                $ctaDepAcum = (int)$af['cuenta_dep_acumulada_id'];

                $lineasPorCuenta['DEBE_' . $ctaGasto] = [
                    'cuenta_id' => $ctaGasto,
                    'monto'     => bcadd($lineasPorCuenta['DEBE_' . $ctaGasto]['monto'] ?? '0.0000', $cuota, 4),
                    'tipo'      => 'DEBE',
                ];
                $lineasPorCuenta['HABER_' . $ctaDepAcum] = [
                    'cuenta_id' => $ctaDepAcum,
                    'monto'     => bcadd($lineasPorCuenta['HABER_' . $ctaDepAcum]['monto'] ?? '0.0000', $cuota, 4),
                    'tipo'      => 'HABER',
                ];

                $movimientos[] = [
                    'activo' => $af,
                    'calc'   => $calc,
                ];
            }

            if (empty($movimientos) || bccomp($totalDepGlobal, '0', 4) <= 0) {
                throw new RuntimeException('Ningún activo generó cuota de depreciación positiva en este período.');
            }

            $glosaAsiento = sprintf(
                'Centralización depreciación activo fijo período %02d/%04d (%d bienes)',
                (int)$periodo['mes'],
                (int)$periodo['anio'],
                count($movimientos)
            );

            $lineasAsiento = [];
            foreach ($lineasPorCuenta as $item) {
                $lineasAsiento[] = [
                    'cuenta_id'   => $item['cuenta_id'],
                    'debe'        => $item['tipo'] === 'DEBE' ? $item['monto'] : '0.0000',
                    'haber'       => $item['tipo'] === 'HABER' ? $item['monto'] : '0.0000',
                    'glosa_linea' => $glosaAsiento,
                ];
            }

            $asientoId = $this->asientoService->registrarAsiento(
                $empresaId,
                $periodoId,
                $fechaContable,
                $glosaAsiento,
                $lineasAsiento
            );

            $stmtInsertMov = $this->pdo->prepare("
                INSERT INTO activo_fijo_movimientos (
                    empresa_id, activo_id, periodo_id, asiento_id, tipo_movimiento,
                    fecha_movimiento, variacion_ipc_pct, monto_reajuste_cm,
                    meses_consumidos, monto_depreciacion, valor_libro_resultante
                ) VALUES (
                    :empresa_id, :activo_id, :periodo_id, :asiento_id, 'DEPRECIACION',
                    :fecha_mov, :ipc_pct, :reajuste_cm,
                    :meses_cons, :monto_dep, :valor_libro
                )
            ");

            $stmtUpdAf = $this->pdo->prepare("
                UPDATE activos_fijos
                SET meses_depreciados         = :meses_dep,
                    correccion_monetaria_acum = :cm_acum,
                    depreciacion_acumulada    = :dep_acum,
                    estado                    = :estado
                WHERE id = :id AND empresa_id = :empresa_id
            ");

            foreach ($movimientos as $mov) {
                $af   = $mov['activo'];
                $calc = $mov['calc'];

                $stmtInsertMov->execute([
                    ':empresa_id'  => $empresaId,
                    ':activo_id'   => (int)$af['id'],
                    ':periodo_id'  => $periodoId,
                    ':asiento_id'  => $asientoId,
                    ':fecha_mov'   => $fechaContable,
                    ':ipc_pct'     => bcadd($variacionIpcPct, '0', 4),
                    ':reajuste_cm' => (string)$calc['reajuste_neto'],
                    ':meses_cons'  => (int)$calc['meses_consumidos'],
                    ':monto_dep'   => (string)$calc['cuota_depreciacion'],
                    ':valor_libro' => (string)$calc['valor_libro_final'],
                ]);

                $nuevoEstado = $calc['depreciado_total'] ? 'DEPRECIADO_TOTAL' : 'ACTIVO';
                $stmtUpdAf->execute([
                    ':meses_dep'  => (int)$af['meses_depreciados'] + (int)$calc['meses_consumidos'],
                    ':cm_acum'    => (string)$calc['nueva_cm_acum'],
                    ':dep_acum'   => (string)$calc['nueva_dep_acum'],
                    ':estado'     => $nuevoEstado,
                    ':id'         => (int)$af['id'],
                    ':empresa_id' => $empresaId,
                ]);
            }

            $this->auditoriaService->registrarEvento(
                $empresaId,
                'INSERT',
                'activo_fijo_movimientos',
                $asientoId,
                [
                    'usuario_id'         => $usuarioId,
                    'periodo_id'         => $periodoId,
                    'bienes_depreciados' => count($movimientos),
                    'total_depreciacion' => bcadd($totalDepGlobal, '0', 0),
                ]
            );

            $this->pdo->commit();

            return [
                'procesados'         => count($movimientos),
                'asiento_id'         => $asientoId,
                'total_depreciacion' => bcadd($totalDepGlobal, '0', 0),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Registra la baja o castigo auditado de un activo fijo.
     */
    public function darDeBajaActivo(
        int $empresaId,
        int $activoId,
        string $motivo,
        string $tipoBaja,
        int $usuarioId
    ): void {
        $motivoLimpio = trim($motivo);
        if (mb_strlen($motivoLimpio) < 5) {
            throw new InvalidArgumentException('Debe ingresar un motivo de baja válido (mínimo 5 caracteres).');
        }

        $estadoFinal = $tipoBaja === 'VENDIDO' ? 'VENDIDO' : 'DADO_DE_BAJA';

        $stmt = $this->pdo->prepare("
            SELECT id, codigo_interno, nombre, estado
            FROM activos_fijos
            WHERE id = :id AND empresa_id = :empresa_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id'         => $activoId,
            ':empresa_id' => $empresaId,
        ]);
        $activo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activo) {
            throw new RuntimeException('El activo fijo seleccionado no pertenece a la empresa activa.');
        }

        if (in_array($activo['estado'], ['DADO_DE_BAJA', 'VENDIDO'], true)) {
            throw new RuntimeException('El activo fijo ya se encuentra dado de baja.');
        }

        $stmtUpd = $this->pdo->prepare("
            UPDATE activos_fijos
            SET estado = :estado,
                fecha_baja = CURDATE(),
                motivo_baja = :motivo
            WHERE id = :id AND empresa_id = :empresa_id
        ");
        $stmtUpd->execute([
            ':estado'     => $estadoFinal,
            ':motivo'     => $motivoLimpio,
            ':id'         => $activoId,
            ':empresa_id' => $empresaId,
        ]);

        $this->auditoriaService->registrarEvento(
            $empresaId,
            'UPDATE_ANULACION',
            'activos_fijos',
            $activoId,
            [
                'usuario_id'      => $usuarioId,
                'codigo_interno'  => $activo['codigo_interno'],
                'estado_anterior' => $activo['estado'],
                'estado_nuevo'    => $estadoFinal,
                'motivo'          => $motivoLimpio,
            ]
        );
    }

    /**
     * Lista los activos fijos de la empresa con su valor libro actualizado calculado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarActivos(int $empresaId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT af.*,
                    pa.codigo AS cta_activo_cod, pa.nombre AS cta_activo_nom,
                    pd.codigo AS cta_dep_cod, pd.nombre AS cta_dep_nom,
                    pg.codigo AS cta_gasto_cod, pg.nombre AS cta_gasto_nom
            FROM activos_fijos af
            INNER JOIN plan_cuentas pa ON pa.id = af.cuenta_activo_id
            INNER JOIN plan_cuentas pd ON pd.id = af.cuenta_dep_acumulada_id
            INNER JOIN plan_cuentas pg ON pg.id = af.cuenta_gasto_dep_id
            WHERE af.empresa_id = :empresa_id
            ORDER BY af.estado ASC, af.codigo_interno ASC
        ");
        $stmt->execute([':empresa_id' => $empresaId]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filas as &$f) {
            $brutoAct = bcadd((string)$f['valor_adquisicion'], (string)$f['correccion_monetaria_acum'], 0);
            $depAcum  = bcadd((string)$f['depreciacion_acumulada'], '0', 0);
            $neto     = bcsub($brutoAct, $depAcum, 0);

            $f['valor_bruto_entero'] = $brutoAct;
            $f['dep_acum_entero']    = $depAcum;
            $f['valor_libro_entero'] = $neto;
        }
        unset($f);

        return $filas;
    }

    /**
     * Calcula los totales consolidados del inventario de Activo Fijo para tarjetas KPI.
     *
     * @param array<int, array<string, mixed>> $activos
     * @return array<string, string|int>
     */
    public function obtenerResumen(array $activos): array
    {
        $totalBruto   = '0';
        $totalDepAcum = '0';
        $totalLibro   = '0';
        $vigentes     = 0;

        foreach ($activos as $af) {
            if (in_array($af['estado'], ['DADO_DE_BAJA', 'VENDIDO'], true)) {
                continue;
            }
            $vigentes++;
            $totalBruto   = bcadd($totalBruto, (string)$af['valor_bruto_entero'], 0);
            $totalDepAcum = bcadd($totalDepAcum, (string)$af['dep_acum_entero'], 0);
            $totalLibro   = bcadd($totalLibro, (string)$af['valor_libro_entero'], 0);
        }

        return [
            'vigentes'       => $vigentes,
            'total_bruto'    => $totalBruto,
            'total_dep_acum' => $totalDepAcum,
            'total_libro'    => $totalLibro,
        ];
    }

    private function esFechaValida(string $fecha): bool
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
        return $dt !== false && $dt->format('Y-m-d') === $fecha;
    }
}