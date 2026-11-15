<?php

declare(strict_types=1);

$tituloPagina = 'Balance 8 Columnas';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Reportes/ReportesServices.php';

/** @var PDO $pdo */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$empresaId = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$periodoId = $periodoActivo !== null ? (int)$periodoActivo['id'] : 0;

$reportesService = new ReportesService($pdo);

$filasBalance = [];
$sumas = [
    'debitos'        => '0',
    'creditos'       => '0',
    'saldo_deudor'   => '0',
    'saldo_acreedor' => '0',
    'activo'         => '0',
    'pasivo'         => '0',
    'perdida'        => '0',
    'ganancia'       => '0',
];

if ($empresaId > 0 && $periodoId > 0) {
    $rawBalance = $reportesService->obtenerBalanceTributario($empresaId, $periodoId);
    $listaFilas = isset($rawBalance['filas']) && is_array($rawBalance['filas'])
        ? $rawBalance['filas']
        : (isset($rawBalance['cuentas']) && is_array($rawBalance['cuentas'])
            ? $rawBalance['cuentas']
            : (is_array($rawBalance) ? $rawBalance : []));

    foreach ($listaFilas as $fila) {
        if (!is_array($fila) || !isset($fila['codigo'])) {
            continue;
        }

        $debitos       = bcadd((string)($fila['debitos'] ?? $fila['total_debe'] ?? $fila['debe'] ?? '0'), '0', 0);
        $creditos      = bcadd((string)($fila['creditos'] ?? $fila['total_haber'] ?? $fila['haber'] ?? '0'), '0', 0);
        $saldoDeudor   = bcadd((string)($fila['saldo_deudor'] ?? $fila['deudor'] ?? '0'), '0', 0);
        $saldoAcreedor = bcadd((string)($fila['saldo_acreedor'] ?? $fila['acreedor'] ?? '0'), '0', 0);
        $activo        = bcadd((string)($fila['activo'] ?? '0'), '0', 0);
        $pasivo        = bcadd((string)($fila['pasivo'] ?? '0'), '0', 0);
        $perdida       = bcadd((string)($fila['perdida'] ?? '0'), '0', 0);
        $ganancia      = bcadd((string)($fila['ganancia'] ?? '0'), '0', 0);

        $filasBalance[] = [
            'codigo'         => (string)$fila['codigo'],
            'nombre'         => (string)($fila['nombre'] ?? ''),
            'debitos'        => $debitos,
            'creditos'       => $creditos,
            'saldo_deudor'   => $saldoDeudor,
            'saldo_acreedor' => $saldoAcreedor,
            'activo'         => $activo,
            'pasivo'         => $pasivo,
            'perdida'        => $perdida,
            'ganancia'       => $ganancia,
        ];

        $sumas['debitos']        = bcadd($sumas['debitos'], $debitos, 0);
        $sumas['creditos']       = bcadd($sumas['creditos'], $creditos, 0);
        $sumas['saldo_deudor']   = bcadd($sumas['saldo_deudor'], $saldoDeudor, 0);
        $sumas['saldo_acreedor'] = bcadd($sumas['saldo_acreedor'], $saldoAcreedor, 0);
        $sumas['activo']         = bcadd($sumas['activo'], $activo, 0);
        $sumas['pasivo']         = bcadd($sumas['pasivo'], $pasivo, 0);
        $sumas['perdida']        = bcadd($sumas['perdida'], $perdida, 0);
        $sumas['ganancia']       = bcadd($sumas['ganancia'], $ganancia, 0);
    }
}

// Cálculo del Resultado del Ejercicio (Utilidad o Pérdida)
$diferenciaResultado = bcsub($sumas['ganancia'], $sumas['perdida'], 0);
$esUtilidad          = (bccomp($diferenciaResultado, '0', 0) >= 0);
$montoResultadoAbs   = $esUtilidad ? $diferenciaResultado : substr($diferenciaResultado, 1);

$ajustePasivo   = $esUtilidad ? $montoResultadoAbs : '0';
$ajusteActivo   = !$esUtilidad ? $montoResultadoAbs : '0';
$ajustePerdida  = $esUtilidad ? $montoResultadoAbs : '0';
$ajusteGanancia = !$esUtilidad ? $montoResultadoAbs : '0';

$totalesIguales = [
    'debitos'        => $sumas['debitos'],
    'creditos'       => $sumas['creditos'],
    'saldo_deudor'   => $sumas['saldo_deudor'],
    'saldo_acreedor' => $sumas['saldo_acreedor'],
    'activo'         => bcadd($sumas['activo'], $ajusteActivo, 0),
    'pasivo'         => bcadd($sumas['pasivo'], $ajustePasivo, 0),
    'perdida'        => bcadd($sumas['perdida'], $ajustePerdida, 0),
    'ganancia'       => bcadd($sumas['ganancia'], $ajusteGanancia, 0),
];

$cuadrado = (
    bccomp($totalesIguales['debitos'], $totalesIguales['creditos'], 0) === 0 &&
    bccomp($totalesIguales['saldo_deudor'], $totalesIguales['saldo_acreedor'], 0) === 0 &&
    bccomp($totalesIguales['activo'], $totalesIguales['pasivo'], 0) === 0 &&
    bccomp($totalesIguales['perdida'], $totalesIguales['ganancia'], 0) === 0
);
?>

<div class="page-header">
    <div>
        <h1>Balance Tributario de 8 Columnas (SII Chile)</h1>
        <?php if ($empresaActiva !== null && $periodoActivo !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull; RUT <?= e($empresaActiva['rut']) ?> &bull;
                Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
            </p>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
        <?php if (!empty($filasBalance)): ?>
            <span class="badge <?= $cuadrado ? 'badge-abierto' : 'badge-cerrado' ?>">
                <?= $cuadrado ? 'Balance Cuadrado' : 'Descuadre Detectado' ?>
            </span>
        <?php endif; ?>
        <button type="button" class="btn btn-secundario no-print" onclick="window.print()">Imprimir Balance</button>
    </div>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <p style="color: var(--text-muted);">Seleccione o abra un período fiscal en <a href="empresas.php">Cartera de Empresas</a> para generar el Balance de 8 Columnas.</p>
    </div>
<?php elseif (empty($filasBalance)): ?>
    <div class="card">
        <p style="color: var(--text-muted);">No hay saldos contables registrados en el período seleccionado.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="tabla-responsive">
            <table class="tabla-contable" style="font-size: 12px;">
                <thead>
                    <tr>
                        <th rowspan="2">Código</th>
                        <th rowspan="2">Cuenta Contable</th>
                        <th colspan="2" style="text-align: center; border-left: 1px solid var(--border-color);">Sumas</th>
                        <th colspan="2" style="text-align: center; border-left: 1px solid var(--border-color);">Saldos</th>
                        <th colspan="2" style="text-align: center; border-left: 1px solid var(--border-color);">Inventario</th>
                        <th colspan="2" style="text-align: center; border-left: 1px solid var(--border-color);">Resultado</th>
                    </tr>
                    <tr>
                        <th class="num" style="border-left: 1px solid var(--border-color);">Débitos</th>
                        <th class="num">Créditos</th>
                        <th class="num" style="border-left: 1px solid var(--border-color);">Deudor</th>
                        <th class="num">Acreedor</th>
                        <th class="num" style="border-left: 1px solid var(--border-color);">Activo</th>
                        <th class="num">Pasivo</th>
                        <th class="num" style="border-left: 1px solid var(--border-color);">Pérdida</th>
                        <th class="num">Ganancia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filasBalance as $f): ?>
                        <tr>
                            <td><code><?= e($f['codigo']) ?></code></td>
                            <td><?= e($f['nombre']) ?></td>
                            <td class="num" style="border-left: 1px solid #f1f5f9;"><?= bccomp($f['debitos'], '0', 0) > 0 ? e(formatoClp($f['debitos'])) : '-' ?></td>
                            <td class="num"><?= bccomp($f['creditos'], '0', 0) > 0 ? e(formatoClp($f['creditos'])) : '-' ?></td>
                            <td class="num" style="border-left: 1px solid #f1f5f9;"><?= bccomp($f['saldo_deudor'], '0', 0) > 0 ? e(formatoClp($f['saldo_deudor'])) : '-' ?></td>
                            <td class="num"><?= bccomp($f['saldo_acreedor'], '0', 0) > 0 ? e(formatoClp($f['saldo_acreedor'])) : '-' ?></td>
                            <td class="num" style="border-left: 1px solid #f1f5f9;"><?= bccomp($f['activo'], '0', 0) > 0 ? e(formatoClp($f['activo'])) : '-' ?></td>
                            <td class="num"><?= bccomp($f['pasivo'], '0', 0) > 0 ? e(formatoClp($f['pasivo'])) : '-' ?></td>
                            <td class="num" style="border-left: 1px solid #f1f5f9;"><?= bccomp($f['perdida'], '0', 0) > 0 ? e(formatoClp($f['perdida'])) : '-' ?></td>
                            <td class="num"><?= bccomp($f['ganancia'], '0', 0) > 0 ? e(formatoClp($f['ganancia'])) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fila-subtotal">
                        <td colspan="2">Sumas</td>
                        <td class="num"><?= e(formatoClp($sumas['debitos'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['creditos'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['saldo_deudor'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['saldo_acreedor'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['activo'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['pasivo'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['perdida'])) ?></td>
                        <td class="num"><?= e(formatoClp($sumas['ganancia'])) ?></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <strong><?= $esUtilidad ? 'Utilidad del Ejercicio' : 'Pérdida del Ejercicio' ?></strong>
                        </td>
                        <td class="num">&mdash;</td>
                        <td class="num">&mdash;</td>
                        <td class="num">&mdash;</td>
                        <td class="num">&mdash;</td>
                        <td class="num"><?= bccomp($ajusteActivo, '0', 0) > 0 ? e(formatoClp($ajusteActivo)) : '&mdash;' ?></td>
                        <td class="num"><?= bccomp($ajustePasivo, '0', 0) > 0 ? e(formatoClp($ajustePasivo)) : '&mdash;' ?></td>
                        <td class="num"><?= bccomp($ajustePerdida, '0', 0) > 0 ? e(formatoClp($ajustePerdida)) : '&mdash;' ?></td>
                        <td class="num"><?= bccomp($ajusteGanancia, '0', 0) > 0 ? e(formatoClp($ajusteGanancia)) : '&mdash;' ?></td>
                    </tr>
                    <tr class="fila-total">
                        <td colspan="2">Totales Iguales</td>
                        <td class="num"><?= e(formatoClp($totalesIguales['debitos'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['creditos'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['saldo_deudor'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['saldo_acreedor'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['activo'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['pasivo'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['perdida'])) ?></td>
                        <td class="num"><?= e(formatoClp($totalesIguales['ganancia'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>