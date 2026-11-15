<?php

declare(strict_types=1);

$tituloPagina = 'Estado de Resultados';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Reportes/ReportesServices.php';

/** @var PDO $pdo */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$empresaId = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$periodoId = $periodoActivo !== null ? (int)$periodoActivo['id'] : 0;

$reportesService = new ReportesService($pdo);

$ingresos = [];
$costos   = [];
$gastos   = [];

$totalIngresos = '0';
$totalCostos   = '0';
$totalGastos   = '0';

if ($empresaId > 0 && $periodoId > 0) {
    $rawEr = $reportesService->obtenerEstadoResultados($empresaId, $periodoId);

    // Si ReportesServices ya entrega secciones separadas
    if (isset($rawEr['ingresos']) || isset($rawEr['costos']) || isset($rawEr['gastos'])) {
        foreach ((array)($rawEr['ingresos'] ?? []) as $item) {
            $monto = bcadd((string)($item['saldo'] ?? $item['monto'] ?? '0'), '0', 0);
            $ingresos[] = [
                'codigo' => (string)($item['codigo'] ?? ''),
                'nombre' => (string)($item['nombre'] ?? ''),
                'monto'  => $monto,
            ];
            $totalIngresos = bcadd($totalIngresos, $monto, 0);
        }

        foreach ((array)($rawEr['costos'] ?? []) as $item) {
            $monto = bcadd((string)($item['saldo'] ?? $item['monto'] ?? '0'), '0', 0);
            $costos[] = [
                'codigo' => (string)($item['codigo'] ?? ''),
                'nombre' => (string)($item['nombre'] ?? ''),
                'monto'  => $monto,
            ];
            $totalCostos = bcadd($totalCostos, $monto, 0);
        }

        foreach ((array)($rawEr['gastos'] ?? []) as $item) {
            $monto = bcadd((string)($item['saldo'] ?? $item['monto'] ?? '0'), '0', 0);
            $gastos[] = [
                'codigo' => (string)($item['codigo'] ?? ''),
                'nombre' => (string)($item['nombre'] ?? ''),
                'monto'  => $monto,
            ];
            $totalGastos = bcadd($totalGastos, $monto, 0);
        }
    } elseif (is_array($rawEr)) {
        // Si retorna arreglo plano de cuentas de resultado
        foreach ($rawEr as $item) {
            if (!is_array($item) || !isset($item['tipo'])) {
                continue;
            }
            $tipo  = strtoupper((string)$item['tipo']);
            $monto = bcadd((string)($item['saldo'] ?? $item['monto'] ?? '0'), '0', 0);
            $registro = [
                'codigo' => (string)($item['codigo'] ?? ''),
                'nombre' => (string)($item['nombre'] ?? ''),
                'monto'  => $monto,
            ];

            if ($tipo === 'INGRESO') {
                $ingresos[]    = $registro;
                $totalIngresos = bcadd($totalIngresos, $monto, 0);
            } elseif ($tipo === 'COSTO') {
                $costos[]    = $registro;
                $totalCostos = bcadd($totalCostos, $monto, 0);
            } elseif ($tipo === 'GASTO') {
                $gastos[]    = $registro;
                $totalGastos = bcadd($totalGastos, $monto, 0);
            }
        }
    }
}

$margenBruto    = bcsub($totalIngresos, $totalCostos, 0);
$resultadoNeto  = bcsub($margenBruto, $totalGastos, 0);
$esUtilidadNeta = (bccomp($resultadoNeto, '0', 0) >= 0);
?>

<div class="page-header">
    <div>
        <h1>Estado de Resultados (Pérdidas y Ganancias)</h1>
        <?php if ($empresaActiva !== null && $periodoActivo !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull; RUT <?= e($empresaActiva['rut']) ?> &bull;
                Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
            </p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-secundario no-print" onclick="window.print()">Imprimir Estado de Resultados</button>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <p style="color: var(--text-muted);">Seleccione o abra un período fiscal en <a href="empresas.php">Cartera de Empresas</a> para visualizar el Estado de Resultados.</p>
    </div>
<?php else: ?>
    <!-- Tarjetas Resumen Ejecutivo -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Ingresos de Explotación</div>
            <div class="kpi-valor" style="font-size: 18px;"><?= e(formatoClp($totalIngresos)) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Costos Directos</div>
            <div class="kpi-valor" style="font-size: 18px;"><?= e(formatoClp($totalCostos)) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Margen Bruto</div>
            <div class="kpi-valor" style="font-size: 18px;"><?= e(formatoClp($margenBruto)) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label"><?= $esUtilidadNeta ? 'Utilidad del Período' : 'Pérdida del Período' ?></div>
            <div class="kpi-valor" style="font-size: 18px; color: <?= $esUtilidadNeta ? '#166534' : '#991b1b' ?>;">
                <?= e(formatoClp($resultadoNeto)) ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="tabla-responsive">
            <table class="tabla-contable">
                <thead>
                    <tr>
                        <th style="width: 18%;">Código</th>
                        <th>Concepto / Cuenta de Resultado</th>
                        <th class="num" style="width: 22%;">Monto ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- 1. INGRESOS -->
                    <tr style="background-color: #f8fafc;">
                        <td colspan="3"><strong>1. INGRESOS DE EXPLOTACIÓN</strong></td>
                    </tr>
                    <?php if (empty($ingresos)): ?>
                        <tr>
                            <td colspan="3" style="color: var(--text-muted);">Sin ingresos registrados en el período.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ingresos as $ing): ?>
                            <tr>
                                <td><code><?= e($ing['codigo']) ?></code></td>
                                <td><?= e($ing['nombre']) ?></td>
                                <td class="num"><?= e(formatoClp($ing['monto'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="fila-subtotal">
                        <td colspan="2">Total Ingresos de Explotación</td>
                        <td class="num"><?= e(formatoClp($totalIngresos)) ?></td>
                    </tr>

                    <!-- 2. COSTOS -->
                    <tr style="background-color: #f8fafc;">
                        <td colspan="3"><strong>2. COSTOS DE EXPLOTACIÓN / VENTAS</strong></td>
                    </tr>
                    <?php if (empty($costos)): ?>
                        <tr>
                            <td colspan="3" style="color: var(--text-muted);">Sin costos directos registrados en el período.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($costos as $cos): ?>
                            <tr>
                                <td><code><?= e($cos['codigo']) ?></code></td>
                                <td><?= e($cos['nombre']) ?></td>
                                <td class="num"><?= e(formatoClp($cos['monto'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="fila-subtotal">
                        <td colspan="2">Total Costos de Explotación</td>
                        <td class="num"><?= e(formatoClp($totalCostos)) ?></td>
                    </tr>

                    <!-- MARGEN BRUTO -->
                    <tr style="background-color: #eff6ff; font-weight: 700;">
                        <td colspan="2">MARGEN BRUTO (Ingresos - Costos)</td>
                        <td class="num"><?= e(formatoClp($margenBruto)) ?></td>
                    </tr>

                    <!-- 3. GASTOS -->
                    <tr style="background-color: #f8fafc;">
                        <td colspan="3"><strong>3. GASTOS DE ADMINISTRACIÓN Y VENTAS</strong></td>
                    </tr>
                    <?php if (empty($gastos)): ?>
                        <tr>
                            <td colspan="3" style="color: var(--text-muted);">Sin gastos operacionales registrados en el período.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($gastos as $gas): ?>
                            <tr>
                                <td><code><?= e($gas['codigo']) ?></code></td>
                                <td><?= e($gas['nombre']) ?></td>
                                <td class="num"><?= e(formatoClp($gas['monto'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="fila-subtotal">
                        <td colspan="2">Total Gastos de Administración y Ventas</td>
                        <td class="num"><?= e(formatoClp($totalGastos)) ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fila-total">
                        <td colspan="2">
                            <?= $esUtilidadNeta ? 'UTILIDAD NETA ANTES DE IMPUESTOS' : 'PÉRDIDA NETA DEL EJERCICIO' ?>
                        </td>
                        <td class="num"><?= e(formatoClp($resultadoNeto)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>