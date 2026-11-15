<?php

declare(strict_types=1);

$tituloPagina = 'Panel Ejecutivo';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Contabilidad/AsientoService.php';
require_once __DIR__ . '/../src/Tributario/LibroComprasVentasService.php';
require_once __DIR__ . '/../src/Reportes/ReportesServices.php';

/** @var PDO $pdo */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$resumenContable = null;
$resumenF29      = null;
$ultimosAsientos = [];

if ($empresaActiva !== null && $periodoActivo !== null) {
    $empresaId = (int)$empresaActiva['id'];
    $periodoId = (int)$periodoActivo['id'];

    $auditoriaService = new AuditoriaService($pdo);
    $asientoService = new AsientoService($pdo);
    $rcvService       = new LibroComprasVentasService($pdo, $asientoService, $auditoriaService);
    $reportesService  = new ReportesService($pdo);

    $resumenContable = $reportesService->obtenerResumenDashboard($empresaId, $periodoId);
    $resumenF29      = $rcvService->obtenerResumenF29Periodo($empresaId, $periodoId);

    $stmtUltimos = $pdo->prepare("
        SELECT a.id, a.numero_asiento, a.fecha, a.tipo_asiento, a.glosa,
                COALESCE(SUM(da.debe), 0) AS total_debe
        FROM asientos a
        INNER JOIN detalle_asientos da ON da.asiento_id = a.id
        WHERE a.empresa_id = :empresa_id
            AND a.periodo_id = :periodo_id
        GROUP BY a.id, a.numero_asiento, a.fecha, a.tipo_asiento, a.glosa
        ORDER BY a.fecha DESC, a.numero_asiento DESC
        LIMIT 8
    ");
    $stmtUltimos->execute([
        ':empresa_id' => $empresaId,
        ':periodo_id' => $periodoId,
    ]);
    $ultimosAsientos = $stmtUltimos->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="page-header">
    <div>
        <h1>Panel Ejecutivo Contable y Tributario</h1>
        <?php if ($empresaActiva !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull; RUT <?= e($empresaActiva['rut']) ?>
                <?php if ($periodoActivo !== null): ?>
                    &bull; Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="transaccion.php" class="btn btn-primario">+ Nuevo Asiento</a>
        <a href="libro_compras.php" class="btn btn-secundario">+ Registrar DTE</a>
        <a href="libro_honorarios.php" class="btn btn-secundario">+ Boleta Honorarios</a>
    </div>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <h3 class="card-titulo">Sin Período Fiscal Activo</h3>
        <p style="margin-bottom: 14px; color: var(--text-muted);">
            La empresa seleccionada aún no tiene períodos fiscales abiertos. Cree un período (año/mes) en el módulo de empresas para comenzar a contabilizar.
        </p>
        <a href="empresas.php" class="btn btn-primario">Configurar Período Fiscal</a>
    </div>
<?php else: ?>
    <!-- Indicadores Financieros Principales -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Total Activos</div>
            <div class="kpi-valor"><?= e(formatoClp($resumenContable['total_activo'])) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total Pasivos y Patrimonio</div>
            <div class="kpi-valor"><?= e(formatoClp($resumenContable['total_pasivo'])) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Ingresos del Período</div>
            <div class="kpi-valor"><?= e(formatoClp($resumenContable['total_ingresos'])) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Resultado Neto (<?= e($resumenContable['estado_resultado']) ?>)</div>
            <div class="kpi-valor" style="color: <?= bccomp($resumenContable['resultado_neto'], '0', 0) >= 0 ? '#166534' : '#991b1b' ?>;">
                <?= e(formatoClp($resumenContable['resultado_neto'])) ?>
            </div>
        </div>
    </div>

    <!-- Pre-liquidación Mensual Formulario 29 (SII) -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h2 style="font-size: 16px; font-weight: 700;">Resumen Tributario Mensual (Base Formulario 29 SII)</h2>
            <span class="badge <?= $resumenContable['balance_cuadrado'] ? 'badge-abierto' : 'badge-cerrado' ?>">
                <?= $resumenContable['balance_cuadrado'] ? 'Partida Doble Cuadrada' : 'Revisar Cuadratura' ?>
            </span>
        </div>

        <div class="kpi-grid" style="margin-bottom: 0;">
            <div class="kpi-card" style="background: #f8fafc;">
                <div class="kpi-label">IVA Débito Fiscal (Ventas)</div>
                <div class="kpi-valor" style="font-size: 19px;"><?= e(formatoClp($resumenF29['iva_debito_fiscal'])) ?></div>
                <small style="color: var(--text-muted);">Neto Ventas: <?= e(formatoClp($resumenF29['ventas_netas'])) ?></small>
            </div>
            <div class="kpi-card" style="background: #f8fafc;">
                <div class="kpi-label">IVA Crédito Fiscal (Compras)</div>
                <div class="kpi-valor" style="font-size: 19px;"><?= e(formatoClp($resumenF29['iva_credito_fiscal'])) ?></div>
                <small style="color: var(--text-muted);">Neto Compras: <?= e(formatoClp($resumenF29['compras_netas'])) ?></small>
            </div>
            <div class="kpi-card" style="background: #f8fafc;">
                <div class="kpi-label">
                    <?= bccomp($resumenF29['iva_determinado_f29'], '0', 0) >= 0 ? 'IVA Determinado a Pagar' : 'Remanente Crédito Fiscal' ?>
                </div>
                <div class="kpi-valor" style="font-size: 19px; color: #1d4ed8;">
                    <?= e(formatoClp(ltrim($resumenF29['iva_determinado_f29'], '-'))) ?>
                </div>
                <small style="color: var(--text-muted);">No recuperable: <?= e(formatoClp($resumenF29['iva_no_recuperable'])) ?></small>
            </div>
            <div class="kpi-card" style="background: #f8fafc;">
                <div class="kpi-label">Retención BHE 14,25% (Cód. 151)</div>
                <div class="kpi-valor" style="font-size: 19px;"><?= e(formatoClp($resumenContable['retencion_bhe_f29'])) ?></div>
                <small style="color: var(--text-muted);">Bruto BHE: <?= e(formatoClp($resumenContable['honorarios_bruto'])) ?></small>
            </div>
        </div>
    </div>

    <!-- Últimos Asientos del Libro Diario -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h2 style="font-size: 16px; font-weight: 700;">Últimos Asientos Contables (<?= (int)$resumenContable['total_asientos'] ?> en el período)</h2>
            <a href="transaccion.php" class="btn btn-secundario btn-sm">Ver Libro Diario</a>
        </div>

        <?php if (empty($ultimosAsientos)): ?>
            <p style="color: var(--text-muted);">No hay asientos registrados en el período fiscal seleccionado.</p>
        <?php else: ?>
            <div class="tabla-responsive">
                <table class="tabla-contable">
                    <thead>
                        <tr>
                            <th>N° Asiento</th>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Glosa</th>
                            <th class="num">Monto Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimosAsientos as $asiento): ?>
                            <tr>
                                <td><strong>#<?= (int)$asiento['numero_asiento'] ?></strong></td>
                                <td><?= e($asiento['fecha']) ?></td>
                                <td><span class="badge badge-regimen"><?= e($asiento['tipo_asiento']) ?></span></td>
                                <td><?= e($asiento['glosa']) ?></td>
                                <td class="num"><?= e(formatoClp(bcadd((string)$asiento['total_debe'], '0', 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>