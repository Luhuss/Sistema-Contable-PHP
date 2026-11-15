<?php

declare(strict_types=1);

/**
 * Servicio de Reportes Contables y Estados Financieros (Chile - Normativa SII).
 *
 * Genera Libro Mayor con saldo móvil, Balance Tributario de 8 Columnas
 * (Sumas, Saldos, Inventario y Resultado), Estado de Resultados clasificado
 * y métricas ejecutivas por empresa/período mediante aritmética exacta BCMath.
 */
class ReportesService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Genera el Libro Mayor detallado de una cuenta contable de la empresa,
     * calculando saldo anterior (si se filtra por fechaDesde) y saldo móvil.
     *
     * @param int         $empresaId  ID de la empresa (Multi-tenancy)
     * @param int         $cuentaId   ID de la cuenta en `plan_cuentas`
     * @param int|null    $periodoId  Filtro opcional por período fiscal
     * @param string|null $fechaDesde Filtro opcional fecha inicio (YYYY-MM-DD)
     * @param string|null $fechaHasta Filtro opcional fecha término (YYYY-MM-DD)
     * @return array{
     *     cuenta: array<string, mixed>,
     *     saldo_anterior: string,
     *     movimientos: array<int, array<string, mixed>>,
     *     totales: array{debe: string, haber: string, saldo_final: string, tipo_saldo: string}
     * }
     * @throws InvalidArgumentException|RuntimeException
     */
    public function obtenerLibroMayor(
        int $empresaId,
        int $cuentaId,
        ?int $periodoId = null,
        ?string $fechaDesde = null,
        ?string $fechaHasta = null
    ): array {
        if ($empresaId <= 0 || $cuentaId <= 0) {
            throw new InvalidArgumentException('Los parámetros empresa_id y cuenta_id son obligatorios.');
        }

        $stmtCuenta = $this->pdo->prepare("
            SELECT id, codigo, nombre, tipo, naturaleza, nivel, es_imputable, codigo_sii
            FROM plan_cuentas
            WHERE id = :id AND empresa_id = :empresa_id
            LIMIT 1
        ");
        $stmtCuenta->execute([
            ':id'         => $cuentaId,
            ':empresa_id' => $empresaId,
        ]);
        $cuenta = $stmtCuenta->fetch(PDO::FETCH_ASSOC);

        if (!$cuenta) {
            throw new RuntimeException('La cuenta contable solicitada no existe o no pertenece a la empresa.');
        }

        $esDeudora     = ($cuenta['naturaleza'] === 'DEUDORA');
        $saldoAnterior = '0.0000';

        // Calcular saldo acumulado previo si se filtra por fechaDesde
        if ($fechaDesde !== null && $this->esFechaValida($fechaDesde)) {
            $prevCondiciones = [
                'a.empresa_id = :empresa_id',
                'da.cuenta_id = :cuenta_id',
                'a.fecha < :fecha_desde',
            ];
            $prevParams = [
                ':empresa_id'  => $empresaId,
                ':cuenta_id'   => $cuentaId,
                ':fecha_desde' => $fechaDesde,
            ];

            if ($periodoId !== null && $periodoId > 0) {
                $prevCondiciones[] = 'a.periodo_id = :periodo_id';
                $prevParams[':periodo_id'] = $periodoId;
            }

            $stmtPrev = $this->pdo->prepare("
                SELECT COALESCE(SUM(da.debe), 0) AS total_debe,
                        COALESCE(SUM(da.haber), 0) AS total_haber
                FROM detalle_asientos da
                INNER JOIN asientos a ON a.id = da.asiento_id
                WHERE " . implode(' AND ', $prevCondiciones)
            );
            $stmtPrev->execute($prevParams);
            $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

            $prevDebe  = bcadd((string)($prev['total_debe'] ?? '0'), '0', 4);
            $prevHaber = bcadd((string)($prev['total_haber'] ?? '0'), '0', 4);

            $saldoAnterior = $esDeudora
                ? bcsub($prevDebe, $prevHaber, 4)
                : bcsub($prevHaber, $prevDebe, 4);
        }

        // Consultar movimientos cronológicos del rango solicitado
        $condiciones = [
            'a.empresa_id = :empresa_id',
            'da.cuenta_id = :cuenta_id',
        ];
        $parametros = [
            ':empresa_id' => $empresaId,
            ':cuenta_id'  => $cuentaId,
        ];

        if ($periodoId !== null && $periodoId > 0) {
            $condiciones[] = 'a.periodo_id = :periodo_id';
            $parametros[':periodo_id'] = $periodoId;
        }

        if ($fechaDesde !== null && $this->esFechaValida($fechaDesde)) {
            $condiciones[] = 'a.fecha >= :fecha_desde';
            $parametros[':fecha_desde'] = $fechaDesde;
        }

        if ($fechaHasta !== null && $this->esFechaValida($fechaHasta)) {
            $condiciones[] = 'a.fecha <= :fecha_hasta';
            $parametros[':fecha_hasta'] = $fechaHasta;
        }

        $whereSql = implode(' AND ', $condiciones);

        $sql = "SELECT a.id AS asiento_id,
                        a.numero_asiento,
                        a.fecha,
                        a.tipo_asiento,
                        a.glosa AS glosa_asiento,
                        da.glosa_linea,
                        da.debe,
                        da.haber
                FROM detalle_asientos da
                INNER JOIN asientos a ON a.id = da.asiento_id
                WHERE {$whereSql}
                ORDER BY a.fecha ASC, a.numero_asiento ASC, da.id ASC";

        $stmtMov = $this->pdo->prepare($sql);
        $stmtMov->execute($parametros);

        $saldoCorriente = $saldoAnterior;
        $sumaDebe       = '0.0000';
        $sumaHaber      = '0.0000';
        $movimientos    = [];

        while ($fila = $stmtMov->fetch(PDO::FETCH_ASSOC)) {
            $debe  = bcadd((string)$fila['debe'], '0', 4);
            $haber = bcadd((string)$fila['haber'], '0', 4);

            $sumaDebe  = bcadd($sumaDebe, $debe, 4);
            $sumaHaber = bcadd($sumaHaber, $haber, 4);

            if ($esDeudora) {
                $saldoCorriente = bcsub(bcadd($saldoCorriente, $debe, 4), $haber, 4);
            } else {
                $saldoCorriente = bcsub(bcadd($saldoCorriente, $haber, 4), $debe, 4);
            }

            $movimientos[] = [
                'asiento_id'      => (int)$fila['asiento_id'],
                'numero_asiento'  => (int)$fila['numero_asiento'],
                'fecha'           => $fila['fecha'],
                'tipo_asiento'    => $fila['tipo_asiento'],
                'glosa'           => $fila['glosa_linea'] ?: $fila['glosa_asiento'],
                'debe'            => $this->bcround($debe, 0),
                'haber'           => $this->bcround($haber, 0),
                'saldo_acumulado' => $this->bcround($saldoCorriente, 0),
            ];
        }

        $tipoSaldo = 'SALDADA';
        if (bccomp($saldoCorriente, '0.0000', 4) > 0) {
            $tipoSaldo = $esDeudora ? 'DEUDOR' : 'ACREEDOR';
        } elseif (bccomp($saldoCorriente, '0.0000', 4) < 0) {
            $tipoSaldo = $esDeudora ? 'ACREEDOR' : 'DEUDOR';
        }

        return [
            'cuenta'         => $cuenta,
            'saldo_anterior' => $this->bcround($saldoAnterior, 0),
            'movimientos'    => $movimientos,
            'totales'        => [
                'debe'        => $this->bcround($sumaDebe, 0),
                'haber'       => $this->bcround($sumaHaber, 0),
                'saldo_final' => $this->bcround($saldoCorriente, 0),
                'tipo_saldo'  => $tipoSaldo,
            ],
        ];
    }

    /**
     * Genera el Balance Tributario de 8 Columnas (Sumas, Saldos, Inventario y Resultado)
     * conforme al estándar contable y tributario del SII en Chile.
     *
     * @param int         $empresaId  ID de la empresa
     * @param int|null    $periodoId  Filtro opcional por período específico
     * @param string|null $fechaHasta Filtro opcional acumulado hasta fecha (YYYY-MM-DD)
     * @return array{
     *     filas: array<int, array<string, mixed>>,
     *     subtotales: array<string, string>,
     *     resultado_ejercicio: array{monto: string, tipo: string},
     *     totales_iguales: array<string, string>,
     *     cuadrado: bool
     * }
     */
    public function obtenerBalanceTributario(
        int $empresaId,
        ?int $periodoId = null,
        ?string $fechaHasta = null
    ): array {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('El ID de empresa es obligatorio.');
        }

        $condiciones = [
            'pc.empresa_id = :empresa_id_plan',
            'pc.es_imputable = 1',
            'a.empresa_id = :empresa_id_asiento',
        ];
        $parametros = [
            ':empresa_id_plan'    => $empresaId,
            ':empresa_id_asiento' => $empresaId,
        ];

        if ($periodoId !== null && $periodoId > 0) {
            $condiciones[] = 'a.periodo_id = :periodo_id';
            $parametros[':periodo_id'] = $periodoId;
        }

        if ($fechaHasta !== null && $this->esFechaValida($fechaHasta)) {
            $condiciones[] = 'a.fecha <= :fecha_hasta';
            $parametros[':fecha_hasta'] = $fechaHasta;
        }

        $whereSql = implode(' AND ', $condiciones);

        $sql = "SELECT pc.id,
                        pc.codigo,
                        pc.nombre,
                        pc.tipo,
                        pc.naturaleza,
                        pc.codigo_sii,
                        COALESCE(SUM(da.debe), 0) AS sum_debe,
                        COALESCE(SUM(da.haber), 0) AS sum_haber
                FROM plan_cuentas pc
                INNER JOIN detalle_asientos da ON da.cuenta_id = pc.id
                INNER JOIN asientos a ON a.id = da.asiento_id
                WHERE {$whereSql}
                GROUP BY pc.id, pc.codigo, pc.nombre, pc.tipo, pc.naturaleza, pc.codigo_sii
                HAVING COALESCE(SUM(da.debe), 0) > 0 OR COALESCE(SUM(da.haber), 0) > 0
                ORDER BY pc.codigo ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);

        $subtotales = [
            'debitos'        => '0',
            'creditos'       => '0',
            'saldo_deudor'   => '0',
            'saldo_acreedor' => '0',
            'activo'         => '0',
            'pasivo'         => '0',
            'perdida'        => '0',
            'ganancia'       => '0',
        ];

        $filas = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $debito  = $this->bcround((string)$row['sum_debe'], 0);
            $credito = $this->bcround((string)$row['sum_haber'], 0);

            $saldoDeudor   = '0';
            $saldoAcreedor = '0';

            $comparacion = bccomp($debito, $credito, 0);
            if ($comparacion > 0) {
                $saldoDeudor = bcsub($debito, $credito, 0);
            } elseif ($comparacion < 0) {
                $saldoAcreedor = bcsub($credito, $debito, 0);
            }

            $activo   = '0';
            $pasivo   = '0';
            $perdida  = '0';
            $ganancia = '0';

            $esCuentaInventario = in_array($row['tipo'], ['ACTIVO', 'PASIVO', 'PATRIMONIO'], true);

            if ($esCuentaInventario) {
                $activo = $saldoDeudor;
                $pasivo = $saldoAcreedor;
            } else {
                // INGRESO, COSTO, GASTO -> Columnas de Resultado
                $perdida  = $saldoDeudor;
                $ganancia = $saldoAcreedor;
            }

            $subtotales['debitos']        = bcadd($subtotales['debitos'], $debito, 0);
            $subtotales['creditos']       = bcadd($subtotales['creditos'], $credito, 0);
            $subtotales['saldo_deudor']   = bcadd($subtotales['saldo_deudor'], $saldoDeudor, 0);
            $subtotales['saldo_acreedor'] = bcadd($subtotales['saldo_acreedor'], $saldoAcreedor, 0);
            $subtotales['activo']         = bcadd($subtotales['activo'], $activo, 0);
            $subtotales['pasivo']         = bcadd($subtotales['pasivo'], $pasivo, 0);
            $subtotales['perdida']        = bcadd($subtotales['perdida'], $perdida, 0);
            $subtotales['ganancia']       = bcadd($subtotales['ganancia'], $ganancia, 0);

            $filas[] = [
                'cuenta_id'      => (int)$row['id'],
                'codigo'         => $row['codigo'],
                'nombre'         => $row['nombre'],
                'tipo'           => $row['tipo'],
                'codigo_sii'     => $row['codigo_sii'],
                'debitos'        => $debito,
                'creditos'       => $credito,
                'saldo_deudor'   => $saldoDeudor,
                'saldo_acreedor' => $saldoAcreedor,
                'activo'         => $activo,
                'pasivo'         => $pasivo,
                'perdida'        => $perdida,
                'ganancia'       => $ganancia,
            ];
        }

        // Determinación del Resultado del Ejercicio (Ganancias - Pérdidas)
        $diferenciaResultado = bcsub($subtotales['ganancia'], $subtotales['perdida'], 0);
        $compResultado       = bccomp($diferenciaResultado, '0', 0);

        $totalesIguales = $subtotales;

        if ($compResultado > 0) {
            // Utilidad del Ejercicio: se suma en Pérdida y en Pasivo (Patrimonio) para cuadrar columnas
            $montoResultado            = $diferenciaResultado;
            $tipoResultado             = 'UTILIDAD';
            $totalesIguales['perdida'] = bcadd($totalesIguales['perdida'], $montoResultado, 0);
            $totalesIguales['pasivo']  = bcadd($totalesIguales['pasivo'], $montoResultado, 0);
        } elseif ($compResultado < 0) {
            // Pérdida del Ejercicio: se suma en Ganancia y en Activo para cuadrar columnas
            $montoResultado             = bcsub('0', $diferenciaResultado, 0);
            $tipoResultado              = 'PERDIDA';
            $totalesIguales['ganancia'] = bcadd($totalesIguales['ganancia'], $montoResultado, 0);
            $totalesIguales['activo']   = bcadd($totalesIguales['activo'], $montoResultado, 0);
        } else {
            $montoResultado = '0';
            $tipoResultado  = 'EQUILIBRIO';
        }

        $cuadrado = (
            bccomp($totalesIguales['debitos'], $totalesIguales['creditos'], 0) === 0 &&
            bccomp($totalesIguales['saldo_deudor'], $totalesIguales['saldo_acreedor'], 0) === 0 &&
            bccomp($totalesIguales['activo'], $totalesIguales['pasivo'], 0) === 0 &&
            bccomp($totalesIguales['perdida'], $totalesIguales['ganancia'], 0) === 0
        );

        return [
            'filas'               => $filas,
            'subtotales'          => $subtotales,
            'resultado_ejercicio' => [
                'monto' => $montoResultado,
                'tipo'  => $tipoResultado,
            ],
            'totales_iguales'     => $totalesIguales,
            'cuadrado'            => $cuadrado,
        ];
    }

    /**
     * Genera el Estado de Resultados clasificado (Ingresos, Costos, Margen Bruto,
     * Gastos Operacionales y Resultado Neto) para una empresa y rango de fechas/período.
     *
     * @param int         $empresaId
     * @param int|null    $periodoId
     * @param string|null $fechaDesde
     * @param string|null $fechaHasta
     * @return array{
     *     ingresos: array<int, array<string, mixed>>,
     *     costos: array<int, array<string, mixed>>,
     *     gastos: array<int, array<string, mixed>>,
     *     resumen: array{
     *         total_ingresos: string,
     *         total_costos: string,
     *         margen_bruto: string,
     *         total_gastos: string,
     *         resultado_neto: string,
     *         estado: string
     *     }
     * }
     */
    public function obtenerEstadoResultados(
        int $empresaId,
        ?int $periodoId = null,
        ?string $fechaDesde = null,
        ?string $fechaHasta = null
    ): array {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('El ID de empresa es obligatorio.');
        }

        $condiciones = [
            'pc.empresa_id = :empresa_id',
            "pc.tipo IN ('INGRESO', 'COSTO', 'GASTO')",
            'a.empresa_id = :empresa_id_asiento',
        ];
        $parametros = [
            ':empresa_id'         => $empresaId,
            ':empresa_id_asiento' => $empresaId,
        ];

        if ($periodoId !== null && $periodoId > 0) {
            $condiciones[] = 'a.periodo_id = :periodo_id';
            $parametros[':periodo_id'] = $periodoId;
        }

        if ($fechaDesde !== null && $this->esFechaValida($fechaDesde)) {
            $condiciones[] = 'a.fecha >= :fecha_desde';
            $parametros[':fecha_desde'] = $fechaDesde;
        }

        if ($fechaHasta !== null && $this->esFechaValida($fechaHasta)) {
            $condiciones[] = 'a.fecha <= :fecha_hasta';
            $parametros[':fecha_hasta'] = $fechaHasta;
        }

        $whereSql = implode(' AND ', $condiciones);

        $sql = "SELECT pc.id,
                        pc.codigo,
                        pc.nombre,
                        pc.tipo,
                        pc.codigo_sii,
                        COALESCE(SUM(da.debe), 0) AS total_debe,
                        COALESCE(SUM(da.haber), 0) AS total_haber
                FROM plan_cuentas pc
                INNER JOIN detalle_asientos da ON da.cuenta_id = pc.id
                INNER JOIN asientos a ON a.id = da.asiento_id
                WHERE {$whereSql}
                GROUP BY pc.id, pc.codigo, pc.nombre, pc.tipo, pc.codigo_sii
                ORDER BY pc.codigo ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);

        $ingresos = [];
        $costos   = [];
        $gastos   = [];

        $totalIngresos = '0';
        $totalCostos   = '0';
        $totalGastos   = '0';

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $debe  = $this->bcround((string)$row['total_debe'], 0);
            $haber = $this->bcround((string)$row['total_haber'], 0);

            if ($row['tipo'] === 'INGRESO') {
                // Naturaleza acreedora: Haber - Debe
                $saldo = bcsub($haber, $debe, 0);
                if (bccomp($saldo, '0', 0) === 0) {
                    continue;
                }
                $totalIngresos = bcadd($totalIngresos, $saldo, 0);
                $ingresos[] = [
                    'cuenta_id'  => (int)$row['id'],
                    'codigo'     => $row['codigo'],
                    'nombre'     => $row['nombre'],
                    'codigo_sii' => $row['codigo_sii'],
                    'monto'      => $saldo,
                ];
            } elseif ($row['tipo'] === 'COSTO') {
                // Naturaleza deudora: Debe - Haber
                $saldo = bcsub($debe, $haber, 0);
                if (bccomp($saldo, '0', 0) === 0) {
                    continue;
                }
                $totalCostos = bcadd($totalCostos, $saldo, 0);
                $costos[] = [
                    'cuenta_id'  => (int)$row['id'],
                    'codigo'     => $row['codigo'],
                    'nombre'     => $row['nombre'],
                    'codigo_sii' => $row['codigo_sii'],
                    'monto'      => $saldo,
                ];
            } else {
                // GASTO - Naturaleza deudora: Debe - Haber
                $saldo = bcsub($debe, $haber, 0);
                if (bccomp($saldo, '0', 0) === 0) {
                    continue;
                }
                $totalGastos = bcadd($totalGastos, $saldo, 0);
                $gastos[] = [
                    'cuenta_id'  => (int)$row['id'],
                    'codigo'     => $row['codigo'],
                    'nombre'     => $row['nombre'],
                    'codigo_sii' => $row['codigo_sii'],
                    'monto'      => $saldo,
                ];
            }
        }

        $margenBruto   = bcsub($totalIngresos, $totalCostos, 0);
        $resultadoNeto = bcsub($margenBruto, $totalGastos, 0);

        $compNeto = bccomp($resultadoNeto, '0', 0);
        $estado   = $compNeto > 0 ? 'UTILIDAD' : ($compNeto < 0 ? 'PERDIDA' : 'EQUILIBRIO');

        return [
            'ingresos' => $ingresos,
            'costos'   => $costos,
            'gastos'   => $gastos,
            'resumen'  => [
                'total_ingresos' => $totalIngresos,
                'total_costos'   => $totalCostos,
                'margen_bruto'   => $margenBruto,
                'total_gastos'   => $totalGastos,
                'resultado_neto' => $resultadoNeto,
                'estado'         => $estado,
            ],
        ];
    }

    /**
     * Obtiene indicadores consolidados para el Dashboard ejecutivo del Contador
     * sobre la empresa cliente y período fiscal activos.
     *
     * @param int $empresaId
     * @param int $periodoId
     * @return array<string, mixed>
     */
    public function obtenerResumenDashboard(int $empresaId, int $periodoId): array
    {
        if ($empresaId <= 0 || $periodoId <= 0) {
            throw new InvalidArgumentException('Los parámetros empresa_id y periodo_id son obligatorios.');
        }

        $balance    = $this->obtenerBalanceTributario($empresaId, $periodoId);
        $resultados = $this->obtenerEstadoResultados($empresaId, $periodoId);

        // Total de asientos registrados en el período
        $stmtAsientos = $this->pdo->prepare("
            SELECT COUNT(*) FROM asientos
            WHERE empresa_id = :empresa_id AND periodo_id = :periodo_id
        ");
        $stmtAsientos->execute([
            ':empresa_id' => $empresaId,
            ':periodo_id' => $periodoId,
        ]);
        $totalAsientos = (int)$stmtAsientos->fetchColumn();

        // Retención BHE 14.25% vigente del período (F29 Código 151)
        $stmtBhe = $this->pdo->prepare("
            SELECT COALESCE(SUM(monto_bruto), 0) AS total_bruto,
                    COALESCE(SUM(monto_retencion), 0) AS total_retencion
            FROM libro_honorarios
            WHERE empresa_id = :empresa_id
                AND periodo_id = :periodo_id
                AND estado = 'VIGENTE'
        ");
        $stmtBhe->execute([
            ':empresa_id' => $empresaId,
            ':periodo_id' => $periodoId,
        ]);
        $bhe = $stmtBhe->fetch(PDO::FETCH_ASSOC);

        return [
            'total_activo'         => $balance['subtotales']['activo'],
            'total_pasivo'         => $balance['subtotales']['pasivo'],
            'balance_cuadrado'     => $balance['cuadrado'],
            'total_ingresos'       => $resultados['resumen']['total_ingresos'],
            'total_costos'         => $resultados['resumen']['total_costos'],
            'total_gastos'         => $resultados['resumen']['total_gastos'],
            'resultado_neto'       => $resultados['resumen']['resultado_neto'],
            'estado_resultado'     => $resultados['resumen']['estado'],
            'total_asientos'       => $totalAsientos,
            'honorarios_bruto'     => $this->bcround((string)($bhe['total_bruto'] ?? '0'), 0),
            'retencion_bhe_f29'    => $this->bcround((string)($bhe['total_retencion'] ?? '0'), 0),
        ];
    }

    private function esFechaValida(string $fecha): bool
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
        return $dt !== false && $dt->format('Y-m-d') === $fecha;
    }

    private function bcround(string $number, int $scale = 0): string
    {
        if (strpos($number, '.') === false) {
            return bcadd($number, '0', $scale);
        }

        $offset = '0.' . str_repeat('0', $scale) . '5';

        if (bccomp($number, '0', 4) >= 0) {
            return bcadd($number, $offset, $scale);
        }

        return bcsub($number, $offset, $scale);
    }
}