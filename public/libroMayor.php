<?php

declare(strict_types=1);

$tituloPagina = 'Libro Mayor';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Reportes/ReportesServices.php';

/** @var PDO $pdo */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$empresaId = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$periodoId = $periodoActivo !== null ? (int)$periodoActivo['id'] : 0;

$auditoriaService   = new AuditoriaService($pdo);
$planCuentasService = new PlanCuentasService($pdo, $auditoriaService);
$reportesService    = new ReportesService($pdo);

$cuentaFiltroId = isset($_GET['cuenta_id']) && (int)$_GET['cuenta_id'] > 0
    ? (int)$_GET['cuenta_id']
    : null;

$todasLasCuentas   = $empresaId > 0 ? $planCuentasService->listarPlanCuentas($empresaId, false) : [];
$cuentasImputables = array_values(array_filter(
    $todasLasCuentas,
    static fn(array $c): bool => (int)$c['es_imputable'] === 1
));

$cuentasMayor = [];

if ($empresaId > 0 && $periodoId > 0) {
    /** @var int[] $cuentasAConsultar */
    $cuentasAConsultar = [];

    if ($cuentaFiltroId !== null) {
        $cuentasAConsultar = [$cuentaFiltroId];
    } else {
        $stmtCuentasMov = $pdo->prepare("
            SELECT DISTINCT da.cuenta_id
            FROM detalle_asientos da
            INNER JOIN asientos a ON a.id = da.asiento_id
            INNER JOIN plan_cuentas pc ON pc.id = da.cuenta_id
            WHERE a.empresa_id = :empresa_id
                AND a.periodo_id = :periodo_id
            ORDER BY pc.codigo ASC
        ");
        $stmtCuentasMov->execute([
            ':empresa_id' => $empresaId,
            ':periodo_id' => $periodoId,
        ]);

        foreach ($stmtCuentasMov->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cuentasAConsultar[] = (int)$cid;
        }
    }

    foreach ($cuentasAConsultar as $ctaId) {
        $rawMayor = $reportesService->obtenerLibroMayor($empresaId, $ctaId, $periodoId);
        if (!is_array($rawMayor) || empty($rawMayor)) {
            continue;
        }

        $infoCuenta = isset($rawMayor['cuenta']) && is_array($rawMayor['cuenta'])
            ? $rawMayor['cuenta']
            : $rawMayor;

        $naturaleza   = strtoupper((string)($infoCuenta['naturaleza'] ?? 'DEUDORA'));
        $saldoInicial = bcadd((string)($rawMayor['saldo_anterior'] ?? $rawMayor['saldo_inicial'] ?? $infoCuenta['saldo_inicial'] ?? '0'), '0', 0);
        $saldoRunning = $saldoInicial;
        $sumaDebe     = '0';
        $sumaHaber    = '0';
        $movimientos  = [];

        $rawMovs = is_array($rawMayor['movimientos'] ?? null) ? $rawMayor['movimientos'] : [];
        foreach ($rawMovs as $mov) {
            if (!is_array($mov)) {
                continue;
            }
            $debe  = bcadd((string)($mov['debe'] ?? '0'), '0', 0);
            $haber = bcadd((string)($mov['haber'] ?? '0'), '0', 0);

            $sumaDebe  = bcadd($sumaDebe, $debe, 0);
            $sumaHaber = bcadd($sumaHaber, $haber, 0);

            if ($naturaleza === 'ACREEDORA') {
                $saldoRunning = bcsub(bcadd($saldoRunning, $haber, 0), $debe, 0);
            } else {
                $saldoRunning = bcsub(bcadd($saldoRunning, $debe, 0), $haber, 0);
            }

            $movimientos[] = [
                'fecha'          => (string)($mov['fecha'] ?? ''),
                'numero_asiento' => (int)($mov['numero_asiento'] ?? 0),
                'glosa'          => (string)($mov['glosa_linea'] ?? $mov['glosa'] ?? ''),
                'debe'           => $debe,
                'haber'          => $haber,
                'saldo'          => isset($mov['saldo'])
                    ? bcadd((string)$mov['saldo'], '0', 0)
                    : $saldoRunning,
            ];
        }

        $cuentasMayor[] = [
            'codigo'        => (string)($infoCuenta['codigo'] ?? ''),
            'nombre'        => (string)($infoCuenta['nombre'] ?? ''),
            'tipo'          => (string)($infoCuenta['tipo'] ?? ''),
            'naturaleza'    => $naturaleza,
            'saldo_inicial' => $saldoInicial,
            'total_debe'    => isset($rawMayor['total_debe']) ? bcadd((string)$rawMayor['total_debe'], '0', 0) : $sumaDebe,
            'total_haber'   => isset($rawMayor['total_haber']) ? bcadd((string)$rawMayor['total_haber'], '0', 0) : $sumaHaber,
            'saldo_final'   => isset($rawMayor['saldo_final']) ? bcadd((string)$rawMayor['saldo_final'], '0', 0) : $saldoRunning,
            'movimientos'   => $movimientos,
        ];
    }
}
?>

<div class="page-header">
    <div>
        <h1>Libro Mayor General</h1>
        <?php if ($empresaActiva !== null && $periodoActivo !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull; RUT <?= e($empresaActiva['rut']) ?> &bull;
                Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
            </p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-secundario no-print" onclick="window.print()">Imprimir Libro Mayor</button>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <p style="color: var(--text-muted);">Seleccione o abra un período fiscal en <a href="empresas.php">Cartera de Empresas</a> para consultar el Libro Mayor.</p>
    </div>
<?php else: ?>
    <!-- Filtro por Cuenta Contable -->
    <div class="card no-print">
        <form method="GET" action="libroMayor.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
            <div class="campo" style="min-width: 320px; flex: 1;">
                <label for="cuenta_id">Filtrar por Cuenta Contable</label>
                <select id="cuenta_id" name="cuenta_id">
                    <option value="">-- Todas las cuentas con movimiento --</option>
                    <?php foreach ($cuentasImputables as $cta): ?>
                        <option value="<?= (int)$cta['id'] ?>" <?= $cuentaFiltroId === (int)$cta['id'] ? 'selected' : '' ?>>
                            <?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primario">Consultar</button>
            <?php if ($cuentaFiltroId !== null): ?>
                <a href="libroMayor.php" class="btn btn-secundario">Ver Todas</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($cuentasMayor)): ?>
        <div class="card">
            <p style="color: var(--text-muted);">No se registran movimientos contables en el Libro Mayor para el filtro seleccionado.</p>
        </div>
    <?php else: ?>
        <?php foreach ($cuentasMayor as $mayor): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                    <div>
                        <h2 style="font-size: 16px; font-weight: 700;">
                            <code><?= e($mayor['codigo']) ?></code> &mdash; <?= e($mayor['nombre']) ?>
                        </h2>
                        <small style="color: var(--text-muted);">
                            Clasificación: <?= e($mayor['tipo']) ?> &bull; Naturaleza: <?= e($mayor['naturaleza']) ?>
                        </small>
                    </div>
                    <div style="text-align: right;">
                        <span class="badge badge-regimen">Saldo Final: <?= e(formatoClp($mayor['saldo_final'])) ?></span>
                    </div>
                </div>

                <div class="tabla-responsive">
                    <table class="tabla-contable">
                        <thead>
                            <tr>
                                <th style="width: 12%;">Fecha</th>
                                <th style="width: 12%;">Asiento</th>
                                <th>Glosa / Detalle</th>
                                <th class="num" style="width: 15%;">Debe</th>
                                <th class="num" style="width: 15%;">Haber</th>
                                <th class="num" style="width: 16%;">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (bccomp($mayor['saldo_inicial'], '0', 0) !== 0): ?>
                                <tr style="background-color: #f8fafc;">
                                    <td colspan="3"><em>Saldo Inicial Arrastrado</em></td>
                                    <td class="num"></td>
                                    <td class="num"></td>
                                    <td class="num"><strong><?= e(formatoClp($mayor['saldo_inicial'])) ?></strong></td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($mayor['movimientos'] as $mov): ?>
                                <tr>
                                    <td><?= e($mov['fecha']) ?></td>
                                    <td>#<?= (int)$mov['numero_asiento'] ?></td>
                                    <td><?= e($mov['glosa']) ?></td>
                                    <td class="num"><?= bccomp($mov['debe'], '0', 0) > 0 ? e(formatoClp($mov['debe'])) : '' ?></td>
                                    <td class="num"><?= bccomp($mov['haber'], '0', 0) > 0 ? e(formatoClp($mov['haber'])) : '' ?></td>
                                    <td class="num"><?= e(formatoClp($mov['saldo'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-subtotal">
                                <td colspan="3">Totales Cuenta <?= e($mayor['codigo']) ?></td>
                                <td class="num"><?= e(formatoClp($mayor['total_debe'])) ?></td>
                                <td class="num"><?= e(formatoClp($mayor['total_haber'])) ?></td>
                                <td class="num"><?= e(formatoClp($mayor['saldo_final'])) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>