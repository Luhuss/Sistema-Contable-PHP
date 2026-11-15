<?php

declare(strict_types=1);

$tituloPagina = 'Compras y Ventas (RCV)';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Contabilidad/AsientoService.php';
require_once __DIR__ . '/../src/Tributario/LibroComprasVentasService.php';

/** @var PDO $pdo */
/** @var array<string, mixed> $usuarioActual */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$empresaId = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$periodoId = $periodoActivo !== null ? (int)$periodoActivo['id'] : 0;
$usuarioId = (int)$usuarioActual['id'];

$auditoriaService   = new AuditoriaService($pdo);
$planCuentasService = new PlanCuentasService($pdo, $auditoriaService);
$asientoService     = new AsientoService($pdo);
$rcvService         = new LibroComprasVentasService($pdo, $asientoService, $auditoriaService);

$filtroOperacion = strtoupper(trim((string)($_GET['tipo'] ?? 'TODOS')));
if (!in_array($filtroOperacion, ['TODOS', 'COMPRA', 'VENTA'], true)) {
    $filtroOperacion = 'TODOS';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId > 0 && $periodoId > 0) {
    try {
        validarCsrf($_POST['csrf_token'] ?? null);
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'registrar_dte') {
            if (($periodoActivo['estado'] ?? '') !== 'ABIERTO') {
                throw new RuntimeException('No se pueden registrar documentos en un período fiscal CERRADO.');
            }

            $modoIngreso = strtoupper(trim((string)($_POST['modo_ingreso'] ?? 'TOTAL')));
            $datosDte = [
                'tipo_operacion'                => trim((string)($_POST['tipo_operacion'] ?? 'COMPRA')),
                'tipo_documento_sii'            => (int)($_POST['tipo_documento_sii'] ?? 33),
                'folio'                         => (int)($_POST['folio'] ?? 0),
                'rut_contraparte'               => trim((string)($_POST['rut_contraparte'] ?? '')),
                'razon_social_contraparte'      => trim((string)($_POST['razon_social_contraparte'] ?? '')),
                'fecha_emision'                 => trim((string)($_POST['fecha_emision'] ?? '')),
                'fecha_recepcion_sii'           => trim((string)($_POST['fecha_recepcion_sii'] ?? '')) ?: null,
                'modo_ingreso'                  => $modoIngreso,
                'monto_total'                   => trim((string)($_POST['monto_total'] ?? '0')),
                'monto_exento'                  => trim((string)($_POST['monto_exento'] ?? '0')) ?: '0',
                'monto_neto'                    => $modoIngreso === 'DESGLOSADO' ? trim((string)($_POST['monto_neto'] ?? '0')) : null,
                'monto_iva'                     => ($modoIngreso === 'DESGLOSADO' && trim((string)($_POST['monto_iva'] ?? '')) !== '')
                    ? trim((string)$_POST['monto_iva'])
                    : null,
                'iva_no_recuperable'            => trim((string)($_POST['iva_no_recuperable'] ?? '0')) ?: '0',
                'codigo_impuesto_adicional'     => trim((string)($_POST['codigo_impuesto_adicional'] ?? '')) ?: null,
                'monto_impuesto_adicional'      => trim((string)($_POST['monto_impuesto_adicional'] ?? '0')) ?: '0',
                'glosa'                         => trim((string)($_POST['glosa'] ?? '')),
                'cuenta_contraparte_id'         => (int)($_POST['cuenta_contraparte_id'] ?? 0),
                'cuenta_resultado_id'           => (int)($_POST['cuenta_resultado_id'] ?? 0),
                'cuenta_iva_id'                 => !empty($_POST['cuenta_iva_id']) ? (int)$_POST['cuenta_iva_id'] : null,
                'cuenta_impuesto_adicional_id'  => !empty($_POST['cuenta_impuesto_adicional_id']) ? (int)$_POST['cuenta_impuesto_adicional_id'] : null,
                'usuario_id'                    => $usuarioId,
            ];

            $resultado = $rcvService->registrarDocumento($empresaId, $periodoId, $datosDte);

            $_SESSION['flash_exito'] = sprintf(
                'DTE Tipo %d Folio N° %d contabilizado correctamente (Doc #%d / Asiento #%d).',
                $datosDte['tipo_documento_sii'],
                $datosDte['folio'],
                $resultado['documento_id'],
                $resultado['asiento_id']
            );
            header('Location: libro_compras.php?tipo=' . urlencode($datosDte['tipo_operacion']));
            exit;
        }

        if ($accion === 'anular_dte') {
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            $motivo      = trim((string)($_POST['motivo'] ?? ''));

            $rcvService->anularDocumento($empresaId, $documentoId, $usuarioId, $motivo);
            $_SESSION['flash_exito'] = "Documento tributario #{$documentoId} anulado mediante contra-asiento contable.";

            header('Location: libro_compras.php?tipo=' . urlencode($filtroOperacion));
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: libro_compras.php?tipo=' . urlencode($filtroOperacion));
        exit;
    }
}

$todasLasCuentas   = $empresaId > 0 ? $planCuentasService->listarPlanCuentas($empresaId, true) : [];
$cuentasImputables = array_values(array_filter(
    $todasLasCuentas,
    static fn(array $c): bool => (int)$c['es_imputable'] === 1
));

$resumenF29 = ($empresaId > 0 && $periodoId > 0)
    ? $rcvService->obtenerResumenF29Periodo($empresaId, $periodoId)
    : null;

$documentosRcv = ($empresaId > 0 && $periodoId > 0)
    ? $rcvService->listarDocumentosPeriodo($empresaId, $periodoId, $filtroOperacion === 'TODOS' ? null : $filtroOperacion)
    : [];

$fechaPorDefecto = date('Y-m-d');
if ($periodoActivo !== null) {
    $anioPer = (int)$periodoActivo['anio'];
    $mesPer  = (int)$periodoActivo['mes'];
    if ((int)date('Y') !== $anioPer || (int)date('n') !== $mesPer) {
        $fechaPorDefecto = sprintf('%04d-%02d-01', $anioPer, $mesPer);
    }
}

$nombresDte = [
    33 => '33 - Factura Electrónica',
    34 => '34 - Factura Exenta Electrónica',
    39 => '39 - Boleta Electrónica',
    41 => '41 - Boleta Exenta Electrónica',
    46 => '46 - Factura de Compra Electrónica',
    56 => '56 - Nota de Débito Electrónica',
    61 => '61 - Nota de Crédito Electrónica',
];
?>

<div class="page-header">
    <div>
        <h1>Registro de Compras y Ventas (RCV SII)</h1>
        <?php if ($empresaActiva !== null && $periodoActivo !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull;
                Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
            </p>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 6px;" class="no-print">
        <a href="libro_compras.php?tipo=TODOS" class="btn <?= $filtroOperacion === 'TODOS' ? 'btn-primario' : 'btn-secundario' ?> btn-sm">Todos</a>
        <a href="libro_compras.php?tipo=COMPRA" class="btn <?= $filtroOperacion === 'COMPRA' ? 'btn-primario' : 'btn-secundario' ?> btn-sm">Libro Compras</a>
        <a href="libro_compras.php?tipo=VENTA" class="btn <?= $filtroOperacion === 'VENTA' ? 'btn-primario' : 'btn-secundario' ?> btn-sm">Libro Ventas</a>
    </div>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <p style="color: var(--text-muted);">Abra un período fiscal en <a href="empresas.php">Cartera de Empresas</a> para registrar DTEs.</p>
    </div>
<?php else: ?>
    <!-- Resumen Tributario IVA del Período -->
    <?php if ($resumenF29 !== null): ?>
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Ventas Netas</div>
                <div class="kpi-valor" style="font-size: 18px;"><?= e(formatoClp($resumenF29['ventas_netas'])) ?></div>
                <small style="color: var(--text-muted);">IVA Débito: <?= e(formatoClp($resumenF29['iva_debito_fiscal'])) ?></small>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Compras Netas</div>
                <div class="kpi-valor" style="font-size: 18px;"><?= e(formatoClp($resumenF29['compras_netas'])) ?></div>
                <small style="color: var(--text-muted);">IVA Crédito: <?= e(formatoClp($resumenF29['iva_credito_fiscal'])) ?></small>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">IVA No Recuperable</div>
                <div class="kpi-valor" style="font-size: 18px;"><?= e(formatoClp($resumenF29['iva_no_recuperable'])) ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">
                    <?= bccomp($resumenF29['iva_determinado_f29'], '0', 0) >= 0 ? 'IVA a Pagar F29' : 'Remanente Crédito' ?>
                </div>
                <div class="kpi-valor" style="font-size: 18px; color: #1d4ed8;">
                    <?= e(formatoClp(ltrim($resumenF29['iva_determinado_f29'], '-'))) ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formulario Registro DTE -->
    <?php if ($periodoActivo['estado'] === 'ABIERTO'): ?>
        <div class="card no-print">
            <h2 class="card-titulo">Registrar Documento Tributario Electrónico (DTE)</h2>

            <form method="POST" action="libro_compras.php?tipo=<?= e($filtroOperacion) ?>">
                <?= campoCsrf() ?>
                <input type="hidden" name="accion" value="registrar_dte">

                <div class="form-grid">
                    <div class="campo">
                        <label for="tipo_operacion">Libro / Operación *</label>
                        <select id="tipo_operacion" name="tipo_operacion" required>
                            <option value="COMPRA" <?= $filtroOperacion === 'COMPRA' ? 'selected' : '' ?>>COMPRA (Proveedor)</option>
                            <option value="VENTA" <?= $filtroOperacion === 'VENTA' ? 'selected' : '' ?>>VENTA (Cliente)</option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="tipo_documento_sii">Tipo Documento SII *</label>
                        <select id="tipo_documento_sii" name="tipo_documento_sii" required>
                            <?php foreach ($nombresDte as $cod => $desc): ?>
                                <option value="<?= $cod ?>"><?= e($desc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="folio">Folio DTE *</label>
                        <input type="number" id="folio" name="folio" min="1" placeholder="Ej: 1045" required>
                    </div>

                    <div class="campo">
                        <label for="fecha_emision">Fecha Emisión *</label>
                        <input type="date" id="fecha_emision" name="fecha_emision" value="<?= e($fechaPorDefecto) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="rut_contraparte">RUT Emisor / Receptor *</label>
                        <input type="text" id="rut_contraparte" name="rut_contraparte" placeholder="76.543.210-K" required>
                    </div>

                    <div class="campo">
                        <label for="razon_social_contraparte">Razón Social Contraparte *</label>
                        <input type="text" id="razon_social_contraparte" name="razon_social_contraparte" placeholder="Proveedor / Cliente SpA" required>
                    </div>

                    <div class="campo">
                        <label for="modo_ingreso">Modo de Cálculo *</label>
                        <select id="modo_ingreso" name="modo_ingreso" onchange="toggleModoDte()">
                            <option value="TOTAL">Desde Monto Total (Calcula Neto + IVA 19%)</option>
                            <option value="DESGLOSADO">Desglosado Manual (Neto / Exento / IVA)</option>
                        </select>
                    </div>

                    <div class="campo" id="grupo-monto-total">
                        <label for="monto_total">Monto Total Bruto ($) *</label>
                        <input type="number" id="monto_total" name="monto_total" min="0" step="1" value="0">
                    </div>

                    <div class="campo" id="grupo-monto-neto" style="display: none;">
                        <label for="monto_neto">Monto Neto Afecto ($)</label>
                        <input type="number" id="monto_neto" name="monto_neto" min="0" step="1" value="0">
                    </div>

                    <div class="campo">
                        <label for="monto_exento">Monto Exento ($)</label>
                        <input type="number" id="monto_exento" name="monto_exento" min="0" step="1" value="0">
                    </div>

                    <div class="campo" id="grupo-monto-iva" style="display: none;">
                        <label for="monto_iva">Monto IVA 19% ($)</label>
                        <input type="number" id="monto_iva" name="monto_iva" min="0" step="1" placeholder="Auto 19% si vacío">
                    </div>

                    <div class="campo">
                        <label for="iva_no_recuperable">IVA No Recuperable ($)</label>
                        <input type="number" id="iva_no_recuperable" name="iva_no_recuperable" min="0" step="1" value="0">
                    </div>

                    <div class="campo">
                        <label for="cuenta_contraparte_id">Cuenta Contraparte (Proveedores/Clientes) *</label>
                        <select id="cuenta_contraparte_id" name="cuenta_contraparte_id" required>
                            <option value="">-- Seleccione Cuenta --</option>
                            <?php foreach ($cuentasImputables as $cta): ?>
                                <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="cuenta_resultado_id">Cuenta Resultado / Activo (Gasto/Ingreso) *</label>
                        <select id="cuenta_resultado_id" name="cuenta_resultado_id" required>
                            <option value="">-- Seleccione Cuenta --</option>
                            <?php foreach ($cuentasImputables as $cta): ?>
                                <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="cuenta_iva_id">Cuenta IVA (Crédito / Débito Fiscal)</label>
                        <select id="cuenta_iva_id" name="cuenta_iva_id">
                            <option value="">-- Sin IVA / Exento --</option>
                            <?php foreach ($cuentasImputables as $cta): ?>
                                <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo" style="grid-column: span 2;">
                        <label for="glosa">Glosa Contable *</label>
                        <input type="text" id="glosa" name="glosa" placeholder="Ej: Compra insumos computacionales / Venta servicios consultoría" required>
                    </div>
                </div>

                <div style="margin-top: 16px;">
                    <button type="submit" class="btn btn-primario">Registrar DTE y Generar Asiento</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Tabla de Documentos Registrados -->
    <div class="card">
        <h2 class="card-titulo">Documentos Tributarios del Período (<?= count($documentosRcv) ?>)</h2>

        <?php if (empty($documentosRcv)): ?>
            <p style="color: var(--text-muted);">No hay documentos registrados bajo el filtro actual.</p>
        <?php else: ?>
            <div class="tabla-responsive">
                <table class="tabla-contable">
                    <thead>
                        <tr>
                            <th>Operación</th>
                            <th>Tipo / Folio</th>
                            <th>Fecha</th>
                            <th>RUT / Contraparte</th>
                            <th class="num">Exento</th>
                            <th class="num">Neto</th>
                            <th class="num">IVA</th>
                            <th class="num">Total</th>
                            <th>Estado</th>
                            <th class="no-print">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documentosRcv as $doc): ?>
                            <?php $estaAnulado = (($doc['estado'] ?? 'VIGENTE') === 'ANULADO'); ?>
                            <tr style="<?= $estaAnulado ? 'opacity: 0.65;' : '' ?>">
                                <td><span class="badge badge-regimen"><?= e($doc['tipo_operacion']) ?></span></td>
                                <td><strong>DTE <?= (int)$doc['tipo_documento_sii'] ?></strong> #<?= (int)$doc['folio'] ?></td>
                                <td><?= e($doc['fecha_emision']) ?></td>
                                <td>
                                    <strong><?= e($doc['rut_contraparte']) ?></strong><br>
                                    <small style="color: var(--text-muted);"><?= e($doc['razon_social_contraparte']) ?></small>
                                </td>
                                <td class="num"><?= e(formatoClp(bcadd((string)$doc['monto_exento'], '0', 0))) ?></td>
                                <td class="num"><?= e(formatoClp(bcadd((string)$doc['monto_neto'], '0', 0))) ?></td>
                                <td class="num"><?= e(formatoClp(bcadd((string)$doc['monto_iva'], '0', 0))) ?></td>
                                <td class="num"><strong><?= e(formatoClp(bcadd((string)$doc['monto_total'], '0', 0))) ?></strong></td>
                                <td>
                                    <span class="badge <?= $estaAnulado ? 'badge-anulado' : 'badge-vigente' ?>">
                                        <?= e($doc['estado']) ?>
                                    </span>
                                </td>
                                <td class="no-print">
                                    <?php if (!$estaAnulado && $periodoActivo['estado'] === 'ABIERTO'): ?>
                                        <form method="POST" action="libro_compras.php?tipo=<?= e($filtroOperacion) ?>"
                                                onsubmit="const m = prompt('Ingrese el motivo de anulación del DTE:'); if(!m || m.trim().length < 5){ alert('Debe ingresar un motivo válido (mín. 5 caracteres).'); return false; } this.motivo.value = m.trim(); return true;">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="accion" value="anular_dte">
                                            <input type="hidden" name="documento_id" value="<?= (int)$doc['id'] ?>">
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
function toggleModoDte() {
    const modo = document.getElementById('modo_ingreso').value;
    const grpTotal = document.getElementById('grupo-monto-total');
    const grpNeto  = document.getElementById('grupo-monto-neto');
    const grpIva   = document.getElementById('grupo-monto-iva');

    if (modo === 'DESGLOSADO') {
        grpTotal.style.display = 'none';
        grpNeto.style.display  = 'flex';
        grpIva.style.display   = 'flex';
    } else {
        grpTotal.style.display = 'flex';
        grpNeto.style.display  = 'none';
        grpIva.style.display   = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>