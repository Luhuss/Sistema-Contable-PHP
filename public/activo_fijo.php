<?php

declare(strict_types=1);

$tituloPagina = 'Activo Fijo (SII)';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Contabilidad/AsientoService.php';
require_once __DIR__ . '/../src/ActivoFijo/ActivoFijoService.php';

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
$activoFijoService  = new ActivoFijoService($pdo, $asientoService, $auditoriaService);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId > 0) {
    try {
        validarCsrf($_POST['csrf_token'] ?? null);
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'crear_activo') {
            $datos = [
                'codigo_interno'          => trim((string)($_POST['codigo_interno'] ?? '')),
                'nombre'                  => trim((string)($_POST['nombre'] ?? '')),
                'categoria'               => trim((string)($_POST['categoria'] ?? '')),
                'fecha_adquisicion'       => trim((string)($_POST['fecha_adquisicion'] ?? '')),
                'fecha_puesta_marcha'     => trim((string)($_POST['fecha_puesta_marcha'] ?? '')),
                'regimen_depreciacion'    => trim((string)($_POST['regimen_depreciacion'] ?? 'NORMAL')),
                'vida_util_normal_meses'  => (int)($_POST['vida_util_normal_meses'] ?? 0),
                'valor_adquisicion'       => trim((string)($_POST['valor_adquisicion'] ?? '0')),
                'cuenta_activo_id'        => (int)($_POST['cuenta_activo_id'] ?? 0),
                'cuenta_dep_acumulada_id' => (int)($_POST['cuenta_dep_acumulada_id'] ?? 0),
                'cuenta_gasto_dep_id'     => (int)($_POST['cuenta_gasto_dep_id'] ?? 0),
            ];

            $nuevoId = $activoFijoService->registrarActivo($empresaId, $datos, $usuarioId);
            $_SESSION['flash_exito'] = "Activo fijo #{$nuevoId} ({$datos['codigo_interno']}) incorporado al inventario tributario.";

            header('Location: activo_fijo.php');
            exit;
        }

        if ($accion === 'centralizar_depreciacion') {
            if ($periodoId <= 0) {
                throw new RuntimeException('Debe tener un período fiscal activo para centralizar depreciación.');
            }

            $ipcInput = str_replace(',', '.', trim((string)($_POST['variacion_ipc_pct'] ?? '0.00')));
            if (!is_numeric($ipcInput)) {
                throw new InvalidArgumentException('El porcentaje de variación IPC debe ser numérico.');
            }

            $res = $activoFijoService->contabilizarDepreciacionPeriodo(
                $empresaId,
                $periodoId,
                $usuarioId,
                bcadd($ipcInput, '0', 4)
            );

            $_SESSION['flash_exito'] = sprintf(
                'Depreciación centralizada: %d bienes procesados por %s (Asiento #%d).',
                $res['procesados'],
                formatoClp($res['total_depreciacion']),
                $res['asiento_id']
            );
            header('Location: activo_fijo.php');
            exit;
        }

        if ($accion === 'baja_activo') {
            $activoId = (int)($_POST['activo_id'] ?? 0);
            $tipoBaja = trim((string)($_POST['tipo_baja'] ?? 'DADO_DE_BAJA'));
            $motivo   = trim((string)($_POST['motivo'] ?? ''));

            $activoFijoService->darDeBajaActivo($empresaId, $activoId, $motivo, $tipoBaja, $usuarioId);
            $_SESSION['flash_exito'] = "Activo fijo #{$activoId} dado de baja correctamente con registro en auditoría.";

            header('Location: activo_fijo.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: activo_fijo.php');
        exit;
    }
}

$todasLasCuentas   = $empresaId > 0 ? $planCuentasService->listarPlanCuentas($empresaId, true) : [];
$cuentasImputables = array_values(array_filter(
    $todasLasCuentas,
    static fn(array $c): bool => (int)$c['es_imputable'] === 1
));

$activos = $empresaId > 0 ? $activoFijoService->listarActivos($empresaId) : [];
$resumen = $activoFijoService->obtenerResumen($activos);

$regimenEmpresa    = (string)($empresaActiva['regimen_tributario'] ?? '14D_N3');
$esProPyme         = str_starts_with($regimenEmpresa, '14D');
$regimenPorDefecto = $esProPyme ? 'INSTANTANEA_14D' : 'NORMAL';

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
        <h1>Control de Activo Fijo y Depreciación (SII Chile)</h1>
        <?php if ($empresaActiva !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull; Régimen:
                <strong><?= e($regimenEmpresa) ?></strong>
                <?= $esProPyme ? '(Elegible Depreciación Instantánea Art. 14 D)' : '(Depreciación Normal / Acelerada Art. 31 N° 5)' ?>
            </p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-secundario no-print" onclick="window.print()">Imprimir Inventario</button>
</div>

<!-- KPIs Consolidados Activo Fijo -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Bienes Vigentes</div>
        <div class="kpi-valor" style="font-size: 19px;"><?= (int)$resumen['vigentes'] ?></div>
        <small style="color: var(--text-muted);">En inventario operativo</small>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Valor Bruto Actualizado</div>
        <div class="kpi-valor" style="font-size: 19px;"><?= e(formatoClp((string)$resumen['total_bruto'])) ?></div>
        <small style="color: var(--text-muted);">Adquisición + Corrección Monetaria</small>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Depreciación Acumulada</div>
        <div class="kpi-valor" style="font-size: 19px; color: #991b1b;"><?= e(formatoClp((string)$resumen['total_dep_acum'])) ?></div>
        <small style="color: var(--text-muted);">Amortización tributaria acumulada</small>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Valor Libro Neto</div>
        <div class="kpi-valor" style="font-size: 19px; color: #166534;"><?= e(formatoClp((string)$resumen['total_libro'])) ?></div>
        <small style="color: var(--text-muted);">Valor residual mínimo: $1 CLP</small>
    </div>
</div>

<!-- Panel de Centralización Mensual de Depreciación -->
<?php if ($periodoActivo !== null && $periodoActivo['estado'] === 'ABIERTO'): ?>
    <div class="card no-print" style="border-left: 4px solid #2563eb;">
        <h2 class="card-titulo">Centralización de Depreciación del Período (<?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?>)</h2>
        <form method="POST" action="activo_fijo.php" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
            <?= campoCsrf() ?>
            <input type="hidden" name="accion" value="centralizar_depreciacion">

            <div class="campo" style="max-width: 240px;">
                <label for="variacion_ipc_pct">Variación IPC del Período (%)</label>
                <input type="text" id="variacion_ipc_pct" name="variacion_ipc_pct" value="0.00" placeholder="0.00">
            </div>

            <button type="submit" class="btn btn-primario"
                    onclick="return confirm('¿Ejecutar cálculo de depreciación del período y generar el asiento contable automático?');">
                Contabilizar Depreciación del Mes
            </button>

            <small style="color: var(--text-muted); flex-basis: 100%;">
                En régimen Pro Pyme (Art. 14 D) mantenga la variación IPC en <strong>0.00</strong>. El sistema evita duplicar depreciaciones dentro del mismo período fiscal.
            </small>
        </form>
    </div>
<?php endif; ?>

<!-- Alta de Nuevo Activo Fijo -->
<div class="card no-print">
    <h2 class="card-titulo">Incorporar Bien al Inventario de Activo Fijo</h2>

    <form method="POST" action="activo_fijo.php">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="crear_activo">

        <div class="form-grid">
            <div class="campo">
                <label for="codigo_interno">Código Interno / Etiqueta *</label>
                <input type="text" id="codigo_interno" name="codigo_interno" placeholder="Ej: AF-001" required>
            </div>

            <div class="campo">
                <label for="nombre">Descripción del Bien *</label>
                <input type="text" id="nombre" name="nombre" placeholder="Ej: MacBook Pro M4 / Camioneta Trabajo" required>
            </div>

            <div class="campo">
                <label for="categoria">Tabla Vida Útil SII (Referencia)</label>
                <select id="categoria" name="categoria" onchange="asignarVidaUtilSii(this)">
                    <option value="Equipos Computacionales" data-meses="72">Equipos Computacionales (72 meses / 6 años SII)</option>
                    <option value="Vehículos Motorizados" data-meses="84">Vehículos Motorizados (84 meses / 7 años SII)</option>
                    <option value="Muebles y Útiles" data-meses="84">Muebles y Útiles (84 meses / 7 años SII)</option>
                    <option value="Herramientas Pesadas" data-meses="96">Herramientas Pesadas (96 meses / 8 años SII)</option>
                    <option value="Instalaciones Generales" data-meses="120">Instalaciones Generales (120 meses / 10 años SII)</option>
                    <option value="Maquinaria Industrial" data-meses="180">Maquinaria Industrial (180 meses / 15 años SII)</option>
                </select>
            </div>

            <div class="campo">
                <label for="vida_util_normal_meses">Vida Útil Normal SII (Meses) *</label>
                <input type="number" id="vida_util_normal_meses" name="vida_util_normal_meses" min="1" max="1200" value="72" required>
            </div>

            <div class="campo">
                <label for="regimen_depreciacion">Régimen Depreciación Tributaria *</label>
                <select id="regimen_depreciacion" name="regimen_depreciacion">
                    <option value="NORMAL" <?= $regimenPorDefecto === 'NORMAL' ? 'selected' : '' ?>>Normal (100% Vida Útil SII)</option>
                    <option value="ACELERADA">Acelerada (1/3 Vida Útil SII - Art. 31 N° 5)</option>
                    <option value="INSTANTANEA_14D" <?= $regimenPorDefecto === 'INSTANTANEA_14D' ? 'selected' : '' ?>>Instantánea 100% (Pro Pyme Art. 14 D)</option>
                </select>
            </div>

            <div class="campo">
                <label for="valor_adquisicion">Valor Adquisición Neto ($) *</label>
                <input type="number" id="valor_adquisicion" name="valor_adquisicion" min="2" step="1" placeholder="Ej: 1500000" required>
            </div>

            <div class="campo">
                <label for="fecha_adquisicion">Fecha Adquisición *</label>
                <input type="date" id="fecha_adquisicion" name="fecha_adquisicion" value="<?= e($fechaPorDefecto) ?>" required>
            </div>

            <div class="campo">
                <label for="fecha_puesta_marcha">Fecha Puesta en Marcha *</label>
                <input type="date" id="fecha_puesta_marcha" name="fecha_puesta_marcha" value="<?= e($fechaPorDefecto) ?>" required>
            </div>

            <div class="campo">
                <label for="cuenta_activo_id">Cuenta Activo Fijo (Activo) *</label>
                <select id="cuenta_activo_id" name="cuenta_activo_id" required>
                    <option value="">-- Seleccione Cuenta Activo --</option>
                    <?php foreach ($cuentasImputables as $cta): ?>
                        <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label for="cuenta_dep_acumulada_id">Cuenta Depreciación Acumulada *</label>
                <select id="cuenta_dep_acumulada_id" name="cuenta_dep_acumulada_id" required>
                    <option value="">-- Seleccione Dep. Acumulada --</option>
                    <?php foreach ($cuentasImputables as $cta): ?>
                        <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label for="cuenta_gasto_dep_id">Cuenta Gasto Depreciación (Resultado) *</label>
                <select id="cuenta_gasto_dep_id" name="cuenta_gasto_dep_id" required>
                    <option value="">-- Seleccione Cuenta Gasto --</option>
                    <?php foreach ($cuentasImputables as $cta): ?>
                        <option value="<?= (int)$cta['id'] ?>"><?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="margin-top: 16px;">
            <button type="submit" class="btn btn-primario">Registrar Activo Fijo</button>
        </div>
    </form>
</div>

<!-- Tabla Inventario de Activos Fijos -->
<div class="card">
    <h2 class="card-titulo">Inventario Tributario de Activo Fijo (<?= count($activos) ?>)</h2>

    <?php if (empty($activos)): ?>
        <p style="color: var(--text-muted);">No hay bienes registrados en el inventario de activo fijo de esta empresa.</p>
    <?php else: ?>
        <div class="tabla-responsive">
            <table class="tabla-contable">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Bien / Categoría</th>
                        <th>Régimen</th>
                        <th>Vida Útil</th>
                        <th class="num">Valor Bruto</th>
                        <th class="num">Dep. Acum.</th>
                        <th class="num">Valor Libro</th>
                        <th>Estado</th>
                        <th class="no-print">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activos as $af): ?>
                        <?php $esBaja = in_array($af['estado'], ['DADO_DE_BAJA', 'VENDIDO'], true); ?>
                        <tr style="<?= $esBaja ? 'opacity: 0.6;' : '' ?>">
                            <td><code><?= e($af['codigo_interno']) ?></code></td>
                            <td>
                                <strong><?= e($af['nombre']) ?></strong><br>
                                <small style="color: var(--text-muted);"><?= e($af['categoria']) ?> &bull; Marcha: <?= e($af['fecha_puesta_marcha']) ?></small>
                            </td>
                            <td><span class="badge badge-regimen"><?= e($af['regimen_depreciacion']) ?></span></td>
                            <td><?= (int)$af['meses_depreciados'] ?> / <?= (int)$af['vida_util_aplicada_meses'] ?> m</td>
                            <td class="num"><?= e(formatoClp((string)$af['valor_bruto_entero'])) ?></td>
                            <td class="num"><?= e(formatoClp((string)$af['dep_acum_entero'])) ?></td>
                            <td class="num"><strong><?= e(formatoClp((string)$af['valor_libro_entero'])) ?></strong></td>
                            <td>
                                <span class="badge <?= $af['estado'] === 'ACTIVO' ? 'badge-abierto' : 'badge-cerrado' ?>">
                                    <?= e($af['estado']) ?>
                                </span>
                            </td>
                            <td class="no-print">
                                <?php if (!$esBaja): ?>
                                    <form method="POST" action="activo_fijo.php"
                                            onsubmit="const m = prompt('Ingrese motivo de baja o castigo del activo:'); if(!m || m.trim().length < 5){ alert('Motivo inválido (mín. 5 caracteres).'); return false; } this.motivo.value = m.trim(); return true;">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="accion" value="baja_activo">
                                        <input type="hidden" name="activo_id" value="<?= (int)$af['id'] ?>">
                                        <input type="hidden" name="tipo_baja" value="DADO_DE_BAJA">
                                        <input type="hidden" name="motivo" value="">
                                        <button type="submit" class="btn btn-peligro btn-sm">Dar de Baja</button>
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

<script>
function asignarVidaUtilSii(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const meses = opt ? opt.getAttribute('data-meses') : null;
    if (meses) {
        document.getElementById('vida_util_normal_meses').value = meses;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>