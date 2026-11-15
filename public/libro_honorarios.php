<?php

declare(strict_types=1);

$tituloPagina = 'Honorarios (BHE 14,25%)';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Contabilidad/AsientoService.php';

$rutaCalcBhe = __DIR__ . '/../src/Tributario/CalculadoraHonorariosService.php';
if (file_exists($rutaCalcBhe)) {
    require_once $rutaCalcBhe;
}

require_once __DIR__ . '/../src/Tributario/LibroHonorariosService.php';
require_once __DIR__ . '/../src/Tributario/ServicioAnulacionHonorariosService.php';

/** @var PDO $pdo */
/** @var array<string, mixed> $usuarioActual */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$empresaId = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$periodoId = $periodoActivo !== null ? (int)$periodoActivo['id'] : 0;
$usuarioId = (int)$usuarioActual['id'];

$auditoriaService       = new AuditoriaService($pdo);
$planCuentasService     = new PlanCuentasService($pdo, $auditoriaService);
$asientoService         = new AsientoService($pdo);
$honorariosService      = new LibroHonorariosService($pdo, $asientoService);
$anulacionHonorariosSrv = new ServicioAnulacionHonorariosService($pdo, $asientoService);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId > 0 && $periodoId > 0) {
    try {
        validarCsrf($_POST['csrf_token'] ?? null);
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'registrar_bhe') {
            if (($periodoActivo['estado'] ?? '') !== 'ABIERTO') {
                throw new RuntimeException('No se pueden registrar boletas de honorarios en un período fiscal CERRADO.');
            }

            $datosBhe = [
                'numero_boleta'       => (int)($_POST['numero_boleta'] ?? 0),
                'rut_profesional'     => trim((string)($_POST['rut_profesional'] ?? '')),
                'razon_social'        => trim((string)($_POST['razon_social'] ?? '')),
                'fecha_emision'       => trim((string)($_POST['fecha_emision'] ?? '')),
                'modo_calculo'        => strtoupper(trim((string)($_POST['modo_calculo'] ?? 'BRUTO'))),
                'monto_ingresado'     => trim((string)($_POST['monto_ingresado'] ?? '0')),
                'glosa'               => trim((string)($_POST['glosa'] ?? '')),
                'cuenta_gasto_id'     => (int)($_POST['cuenta_gasto_id'] ?? 0),
                'cuenta_retencion_id' => (int)($_POST['cuenta_retencion_id'] ?? 0),
                'cuenta_por_pagar_id' => (int)($_POST['cuenta_por_pagar_id'] ?? 0),
                'usuario_id'          => $usuarioId,
            ];

            $resultado = $honorariosService->registrarBoleta($empresaId, $periodoId, $datosBhe);

            $_SESSION['flash_exito'] = sprintf(
                'Boleta de Honorarios N° %d contabilizada correctamente (BHE #%d / Asiento #%d).',
                $datosBhe['numero_boleta'],
                $resultado['boleta_id'],
                $resultado['asiento_id']
            );
            header('Location: libro_honorarios.php');
            exit;
        }

        if ($accion === 'anular_bhe') {
            if (($periodoActivo['estado'] ?? '') !== 'ABIERTO') {
                throw new RuntimeException('No se pueden anular boletas en un período fiscal CERRADO.');
            }

            $boletaId = (int)($_POST['boleta_id'] ?? 0);
            $motivo   = trim((string)($_POST['motivo'] ?? ''));

            $anulacionHonorariosSrv->anularBoleta($empresaId, $boletaId, $usuarioId, $motivo);
            $_SESSION['flash_exito'] = "Boleta de Honorarios #{$boletaId} anulada mediante contra-asiento contable.";

            header('Location: libro_honorarios.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: libro_honorarios.php');
        exit;
    }
}

$todasLasCuentas   = $empresaId > 0 ? $planCuentasService->listarPlanCuentas($empresaId, true) : [];
$cuentasImputables = array_values(array_filter(
    $todasLasCuentas,
    static fn(array $c): bool => (int)$c['es_imputable'] === 1
));

// Listar boletas del período y calcular totales vigentes con BCMath
$boletasPeriodo = [];
$totalBruto     = '0';
$totalRetencion = '0';
$totalLiquido   = '0';

if ($empresaId > 0 && $periodoId > 0) {
    $stmtBhe = $pdo->prepare("
        SELECT id, numero_boleta, rut_profesional, razon_social, fecha_emision,
                monto_bruto, monto_retencion, monto_liquido, tasa_retencion,
                modo_calculo, glosa, estado
        FROM libro_honorarios
        WHERE empresa_id = :empresa_id
            AND periodo_id = :periodo_id
        ORDER BY fecha_emision DESC, numero_boleta DESC
    ");
    $stmtBhe->execute([
        ':empresa_id' => $empresaId,
        ':periodo_id' => $periodoId,
    ]);
    $boletasPeriodo = $stmtBhe->fetchAll(PDO::FETCH_ASSOC);

    foreach ($boletasPeriodo as $bhe) {
        if (($bhe['estado'] ?? 'VIGENTE') !== 'ANULADO') {
            $totalBruto     = bcadd($totalBruto, bcadd((string)$bhe['monto_bruto'], '0', 0), 0);
            $totalRetencion = bcadd($totalRetencion, bcadd((string)$bhe['monto_retencion'], '0', 0), 0);
            $totalLiquido   = bcadd($totalLiquido, bcadd((string)$bhe['monto_liquido'], '0', 0), 0);
        }
    }
}

$fechaPorDefecto = date('Y-m-d');
if ($periodoActivo !== null) {
    $anioPer = (int)$periodoActivo['anio'];
    $mesPer  = (int)$periodoActivo['mes'];
    if ((int)date('Y') !== $anioPer || (int)date('n') !== $mesPer) {
        $fechaPorDefecto = sprintf('%04d-%02d-01', $anioPer, $mesPer);
    }
}
?>

<div class="page-header">
    <div>
        <h1>Libro de Honorarios Electrónicos (BHE SII 2026)</h1>
        <?php if ($empresaActiva !== null && $periodoActivo !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull;
                Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
                &bull; Tasa Retención Vigente: <strong>14,25%</strong>
            </p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-secundario no-print" onclick="window.print()">Imprimir Libro BHE</button>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <p style="color: var(--text-muted);">Abra un período fiscal en <a href="empresas.php">Cartera de Empresas</a> para registrar Boletas de Honorarios.</p>
    </div>
<?php else: ?>
    <!-- Resumen Mensual BHE / F29 Código 151 -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Total Honorarios Brutos</div>
            <div class="kpi-valor" style="font-size: 19px;"><?= e(formatoClp($totalBruto)) ?></div>
            <small style="color: var(--text-muted);">Gasto tributario del período</small>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Retención 14,25% (F29 Cód. 151)</div>
            <div class="kpi-valor" style="font-size: 19px; color: #1d4ed8;"><?= e(formatoClp($totalRetencion)) ?></div>
            <small style="color: var(--text-muted);">Impuesto único segunda categoría a declarar</small>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total Líquido a Pagar</div>
            <div class="kpi-valor" style="font-size: 19px;"><?= e(formatoClp($totalLiquido)) ?></div>
            <small style="color: var(--text-muted);">Monto neto transferible a prestadores</small>
        </div>
    </div>

    <!-- Formulario Registro BHE -->
    <?php if ($periodoActivo['estado'] === 'ABIERTO'): ?>
        <div class="card no-print">
            <h2 class="card-titulo">Registrar Boleta de Honorarios Electrónica (BHE)</h2>

            <form method="POST" action="libro_honorarios.php">
                <?= campoCsrf() ?>
                <input type="hidden" name="accion" value="registrar_bhe">

                <div class="form-grid">
                    <div class="campo">
                        <label for="numero_boleta">N° Boleta Honorarios *</label>
                        <input type="number" id="numero_boleta" name="numero_boleta" min="1" placeholder="Ej: 154" required>
                    </div>

                    <div class="campo">
                        <label for="fecha_emision">Fecha Emisión *</label>
                        <input type="date" id="fecha_emision" name="fecha_emision" value="<?= e($fechaPorDefecto) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="rut_profesional">RUT Prestador / Profesional *</label>
                        <input type="text" id="rut_profesional" name="rut_profesional" placeholder="12.345.678-5" required>
                    </div>

                    <div class="campo">
                        <label for="razon_social">Nombre / Razón Social Prestador *</label>
                        <input type="text" id="razon_social" name="razon_social" placeholder="Nombre completo del profesional" required>
                    </div>

                    <div class="campo">
                        <label for="modo_calculo">Base de Cálculo *</label>
                        <select id="modo_calculo" name="modo_calculo" onchange="recalcularBhePreview()">
                            <option value="BRUTO">Desde Monto Bruto (Descuenta 14,25%)</option>
                            <option value="LIQUIDO">Desde Monto Líquido Pactado (Agrega retención)</option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="monto_ingresado">Monto Base ($) *</label>
                        <input type="number" id="monto_ingresado" name="monto_ingresado" min="1" step="1" value="0"
                                oninput="recalcularBhePreview()" required>
                    </div>

                    <div class="campo">
                        <label for="cuenta_gasto_id">Cuenta Gasto Honorarios (Debe) *</label>
                        <select id="cuenta_gasto_id" name="cuenta_gasto_id" required>
                            <option value="">-- Seleccione Cuenta Gasto --</option>
                            <?php foreach ($cuentasImputables as $cta): ?>
                                <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="cuenta_retencion_id">Cuenta Retención 14,25% F29 (Haber) *</label>
                        <select id="cuenta_retencion_id" name="cuenta_retencion_id" required>
                            <option value="">-- Seleccione Pasivo Retención --</option>
                            <?php foreach ($cuentasImputables as $cta): ?>
                                <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="cuenta_por_pagar_id">Cuenta Honorarios por Pagar / Banco (Haber) *</label>
                        <select id="cuenta_por_pagar_id" name="cuenta_por_pagar_id" required>
                            <option value="">-- Seleccione Cuenta Pago --</option>
                            <?php foreach ($cuentasImputables as $cta): ?>
                                <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo" style="grid-column: span 2;">
                        <label for="glosa">Glosa / Descripción del Servicio Profesional *</label>
                        <input type="text" id="glosa" name="glosa" placeholder="Ej: Asesoría contable y tributaria mensual" required>
                    </div>
                </div>

                <!-- Vista previa de desglose tributario 14,25% -->
                <div style="margin-top: 14px; padding: 12px 16px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; display: flex; flex-wrap: wrap; gap: 24px; font-size: 13px;">
                    <div>Bruto: <strong id="prev-bhe-bruto">$0</strong></div>
                    <div>Retención (14,25%): <strong id="prev-bhe-ret" style="color: #1d4ed8;">$0</strong></div>
                    <div>Líquido a Pagar: <strong id="prev-bhe-liq" style="color: #166534;">$0</strong></div>
                </div>

                <div style="margin-top: 16px;">
                    <button type="submit" class="btn btn-primario">Registrar BHE y Generar Asiento</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Tabla de Boletas Registradas -->
    <div class="card">
        <h2 class="card-titulo">Boletas de Honorarios del Período (<?= count($boletasPeriodo) ?>)</h2>

        <?php if (empty($boletasPeriodo)): ?>
            <p style="color: var(--text-muted);">No hay boletas de honorarios registradas en este período fiscal.</p>
        <?php else: ?>
            <div class="tabla-responsive">
                <table class="tabla-contable">
                    <thead>
                        <tr>
                            <th>N° BHE</th>
                            <th>Fecha</th>
                            <th>RUT / Prestador</th>
                            <th>Glosa</th>
                            <th class="num">Bruto</th>
                            <th class="num">Ret. 14,25%</th>
                            <th class="num">Líquido</th>
                            <th>Estado</th>
                            <th class="no-print">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($boletasPeriodo as $bhe): ?>
                            <?php $estaAnulada = (($bhe['estado'] ?? 'VIGENTE') === 'ANULADO'); ?>
                            <tr style="<?= $estaAnulada ? 'opacity: 0.65;' : '' ?>">
                                <td><strong>#<?= (int)$bhe['numero_boleta'] ?></strong></td>
                                <td><?= e($bhe['fecha_emision']) ?></td>
                                <td>
                                    <strong><?= e($bhe['rut_profesional']) ?></strong><br>
                                    <small style="color: var(--text-muted);"><?= e($bhe['razon_social']) ?></small>
                                </td>
                                <td><?= e($bhe['glosa']) ?></td>
                                <td class="num"><?= e(formatoClp(bcadd((string)$bhe['monto_bruto'], '0', 0))) ?></td>
                                <td class="num"><?= e(formatoClp(bcadd((string)$bhe['monto_retencion'], '0', 0))) ?></td>
                                <td class="num"><strong><?= e(formatoClp(bcadd((string)$bhe['monto_liquido'], '0', 0))) ?></strong></td>
                                <td>
                                    <span class="badge <?= $estaAnulada ? 'badge-anulado' : 'badge-vigente' ?>">
                                        <?= e($bhe['estado']) ?>
                                    </span>
                                </td>
                                <td class="no-print">
                                    <?php if (!$estaAnulada && $periodoActivo['estado'] === 'ABIERTO'): ?>
                                        <form method="POST" action="libro_honorarios.php"
                                                onsubmit="const m = prompt('Ingrese el motivo de anulación de la BHE:'); if(!m || m.trim().length < 5){ alert('Debe ingresar un motivo válido (mín. 5 caracteres).'); return false; } this.motivo.value = m.trim(); return true;">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="accion" value="anular_bhe">
                                            <input type="hidden" name="boleta_id" value="<?= (int)$bhe['id'] ?>">
                                            <input type="hidden" name="motivo" value="">
                                            <button type="submit" class="btn btn-peligro btn-sm">Anular</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function formatearClpJs(valor) {
    return '$' + Math.round(valor).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function recalcularBhePreview() {
    const modo = document.getElementById('modo_calculo').value;
    const monto = parseInt(document.getElementById('monto_ingresado').value || '0', 10) || 0;

    let bruto = 0;
    let retencion = 0;
    let liquido = 0;

    if (monto > 0) {
        if (modo === 'BRUTO') {
            bruto = monto;
            retencion = Math.round(bruto * 0.1425);
            liquido = bruto - retencion;
        } else {
            liquido = monto;
            bruto = Math.round(liquido / 0.8575);
            retencion = bruto - liquido;
        }
    }

    document.getElementById('prev-bhe-bruto').textContent = formatearClpJs(bruto);
    document.getElementById('prev-bhe-ret').textContent   = formatearClpJs(retencion);
    document.getElementById('prev-bhe-liq').textContent   = formatearClpJs(liquido);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>