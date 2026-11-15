<?php

declare(strict_types=1);

$tituloPagina = 'Libro Diario / Asientos';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Contabilidad/AsientoService.php';

/** @var PDO $pdo */
/** @var array<string, mixed> $usuarioActual */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed>|null $periodoActivo */

$empresaId = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$periodoId = $periodoActivo !== null ? (int)$periodoActivo['id'] : 0;

$auditoriaService   = new AuditoriaService($pdo);
$planCuentasService = new PlanCuentasService($pdo, $auditoriaService);
$asientoService     = new AsientoService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId > 0 && $periodoId > 0) {
    try {
        validarCsrf($_POST['csrf_token'] ?? null);
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'registrar_asiento') {
            if (($periodoActivo['estado'] ?? '') !== 'ABIERTO') {
                throw new RuntimeException('No se pueden registrar asientos en un período fiscal CERRADO.');
            }

            $fecha = trim((string)($_POST['fecha'] ?? ''));
            $glosa = trim((string)($_POST['glosa'] ?? ''));

            $cuentasPost = $_POST['cuenta_id'] ?? [];
            $debesPost   = $_POST['debe'] ?? [];
            $haberesPost = $_POST['haber'] ?? [];
            $glosasPost  = $_POST['glosa_linea'] ?? [];

            if (!is_array($cuentasPost) || count($cuentasPost) < 2) {
                throw new InvalidArgumentException('Debe ingresar al menos dos líneas contables para cumplir la partida doble.');
            }

            $lineas = [];
            $totalFilas = count($cuentasPost);

            for ($i = 0; $i < $totalFilas; $i++) {
                $cuentaId = (int)($cuentasPost[$i] ?? 0);
                $debeRaw  = preg_replace('/[^0-9]/', '', (string)($debesPost[$i] ?? '0'));
                $haberRaw = preg_replace('/[^0-9]/', '', (string)($haberesPost[$i] ?? '0'));

                $debeStr  = $debeRaw !== '' ? $debeRaw : '0';
                $haberStr = $haberRaw !== '' ? $haberRaw : '0';

                // Omitir filas completamente vacías
                if ($cuentaId <= 0 && bccomp($debeStr, '0', 0) === 0 && bccomp($haberStr, '0', 0) === 0) {
                    continue;
                }

                $glosaLinea = trim((string)($glosasPost[$i] ?? ''));
                $lineas[] = [
                    'cuenta_id'   => $cuentaId,
                    'debe'        => bcadd($debeStr, '0', 4),
                    'haber'       => bcadd($haberStr, '0', 4),
                    'glosa_linea' => $glosaLinea !== '' ? $glosaLinea : null,
                ];
            }

            $asientoId = $asientoService->registrarAsiento(
                $empresaId,
                $periodoId,
                $fecha,
                $glosa,
                $lineas
            );

            $_SESSION['flash_exito'] = "Asiento contable registrado correctamente (ID #{$asientoId}).";
            header('Location: transaccion.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: transaccion.php');
        exit;
    }
}

// Obtener cuentas imputables activas de la empresa
$todasLasCuentas   = $empresaId > 0 ? $planCuentasService->listarPlanCuentas($empresaId, true) : [];
$cuentasImputables = array_values(array_filter(
    $todasLasCuentas,
    static fn(array $c): bool => (int)$c['es_imputable'] === 1
));

// Fecha por defecto dentro del período fiscal activo
$fechaPorDefecto = date('Y-m-d');
if ($periodoActivo !== null) {
    $anioPer = (int)$periodoActivo['anio'];
    $mesPer  = (int)$periodoActivo['mes'];
    if ((int)date('Y') !== $anioPer || (int)date('n') !== $mesPer) {
        $fechaPorDefecto = sprintf('%04d-%02d-01', $anioPer, $mesPer);
    }
}

// Consultar asientos y sus líneas del período activo
$asientosLibro = [];
if ($empresaId > 0 && $periodoId > 0) {
    $stmtAsientos = $pdo->prepare("
        SELECT a.id, a.numero_asiento, a.fecha, a.tipo_asiento, a.glosa,
                da.id AS detalle_id, da.debe, da.haber, da.glosa_linea,
                pc.codigo AS cuenta_codigo, pc.nombre AS cuenta_nombre
        FROM asientos a
        INNER JOIN detalle_asientos da ON da.asiento_id = a.id
        INNER JOIN plan_cuentas pc ON pc.id = da.cuenta_id
        WHERE a.empresa_id = :empresa_id
            AND a.periodo_id = :periodo_id
        ORDER BY a.fecha DESC, a.numero_asiento DESC, da.id ASC
    ");
    $stmtAsientos->execute([
        ':empresa_id' => $empresaId,
        ':periodo_id' => $periodoId,
    ]);

    foreach ($stmtAsientos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $aid = (int)$fila['id'];
        if (!isset($asientosLibro[$aid])) {
            $asientosLibro[$aid] = [
                'id'             => $aid,
                'numero_asiento' => (int)$fila['numero_asiento'],
                'fecha'          => (string)$fila['fecha'],
                'tipo_asiento'   => (string)$fila['tipo_asiento'],
                'glosa'          => (string)$fila['glosa'],
                'total_debe'     => '0',
                'total_haber'    => '0',
                'lineas'         => [],
            ];
        }

        $debeEntero  = bcadd((string)$fila['debe'], '0', 0);
        $haberEntero = bcadd((string)$fila['haber'], '0', 0);

        $asientosLibro[$aid]['total_debe']  = bcadd($asientosLibro[$aid]['total_debe'], $debeEntero, 0);
        $asientosLibro[$aid]['total_haber'] = bcadd($asientosLibro[$aid]['total_haber'], $haberEntero, 0);
        $asientosLibro[$aid]['lineas'][]    = [
            'cuenta_codigo' => (string)$fila['cuenta_codigo'],
            'cuenta_nombre' => (string)$fila['cuenta_nombre'],
            'glosa_linea'   => $fila['glosa_linea'],
            'debe'          => $debeEntero,
            'haber'         => $haberEntero,
        ];
    }
}
?>

<div class="page-header">
    <div>
        <h1>Libro Diario y Comprobantes Contables</h1>
        <?php if ($empresaActiva !== null && $periodoActivo !== null): ?>
            <p>
                <?= e($empresaActiva['razon_social']) ?> &bull;
                Período: <strong><?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?></strong>
            </p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-secundario no-print" onclick="window.print()">Imprimir Libro Diario</button>
</div>

<?php if ($periodoActivo === null): ?>
    <div class="card">
        <p style="color: var(--text-muted);">Debe abrir un período fiscal en <a href="empresas.php">Cartera de Empresas</a> para registrar asientos.</p>
    </div>
<?php else: ?>
    <!-- Formulario Nuevo Asiento de Partida Doble -->
    <?php if ($periodoActivo['estado'] === 'ABIERTO'): ?>
        <div class="card no-print">
            <h2 class="card-titulo">Registrar Comprobante Contable (Partida Doble)</h2>

            <form method="POST" action="transaccion.php" id="form-asiento">
                <?= campoCsrf() ?>
                <input type="hidden" name="accion" value="registrar_asiento">

                <div class="form-grid" style="margin-bottom: 16px;">
                    <div class="campo" style="max-width: 220px;">
                        <label for="fecha">Fecha Contable *</label>
                        <input type="date" id="fecha" name="fecha" value="<?= e($fechaPorDefecto) ?>" required>
                    </div>

                    <div class="campo" style="grid-column: span 2;">
                        <label for="glosa">Glosa General del Comprobante *</label>
                        <input type="text" id="glosa" name="glosa" placeholder="Ej: Centralización pago proveedores / Ajuste apertura" required>
                    </div>
                </div>

                <div class="tabla-responsive">
                    <table class="tabla-contable" id="tabla-lineas-asiento">
                        <thead>
                            <tr>
                                <th style="width: 38%;">Cuenta Imputable *</th>
                                <th style="width: 26%;">Glosa Específica (Opcional)</th>
                                <th class="num" style="width: 15%;">Debe ($)</th>
                                <th class="num" style="width: 15%;">Haber ($)</th>
                                <th style="width: 6%;"></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-lineas">
                            <?php for ($filaIdx = 0; $filaIdx < 2; $filaIdx++): ?>
                                <tr class="fila-apunte">
                                    <td>
                                        <select name="cuenta_id[]" style="width: 100%; padding: 6px; border: 1px solid var(--border-color); border-radius: 4px;" required>
                                            <option value="">-- Seleccione Cuenta Imputable --</option>
                                            <?php foreach ($cuentasImputables as $cta): ?>
                                                <option value="<?= (int)$cta['id'] ?>">
                                                    <?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="glosa_linea[]" placeholder="Detalle línea"
                                                style="width: 100%; padding: 6px; border: 1px solid var(--border-color); border-radius: 4px;">
                                    </td>
                                    <td>
                                        <input type="number" name="debe[]" class="input-debe" min="0" step="1" value="0"
                                                style="width: 100%; text-align: right; padding: 6px; border: 1px solid var(--border-color); border-radius: 4px;">
                                    </td>
                                    <td>
                                        <input type="number" name="haber[]" class="input-haber" min="0" step="1" value="0"
                                                style="width: 100%; text-align: right; padding: 6px; border: 1px solid var(--border-color); border-radius: 4px;">
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="button" class="btn btn-secundario btn-sm" onclick="eliminarFilaAsiento(this)" title="Quitar línea">&times;</button>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-subtotal">
                                <td colspan="2">
                                    <button type="button" class="btn btn-secundario btn-sm" onclick="agregarFilaAsiento()">+ Agregar Línea</button>
                                    <span id="estado-cuadratura" style="margin-left: 14px; font-size: 12px;"></span>
                                </td>
                                <td class="num" id="total-debe-preview">$0</td>
                                <td class="num" id="total-haber-preview">$0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primario" id="btn-guardar-asiento">Contabilizar Asiento</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="alerta alerta-error no-print">
            Este período fiscal se encuentra <strong>CERRADO</strong>. Los asientos existentes son inmutables (ADR-002).
        </div>
    <?php endif; ?>

    <!-- Listado Cronológico del Libro Diario -->
    <div class="card">
        <h2 class="card-titulo">Asientos Contabilizados en el Período (<?= count($asientosLibro) ?>)</h2>

        <?php if (empty($asientosLibro)): ?>
            <p style="color: var(--text-muted);">No hay comprobantes registrados en este período fiscal.</p>
        <?php else: ?>
            <div class="tabla-responsive">
                <table class="tabla-contable">
                    <thead>
                        <tr>
                            <th>Asiento / Fecha</th>
                            <th>Cuenta Contable</th>
                            <th>Glosa / Detalle</th>
                            <th class="num">Debe</th>
                            <th class="num">Haber</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($asientosLibro as $asiento): ?>
                            <tr style="background-color: #f1f5f9;">
                                <td colspan="3">
                                    <strong>Asiento N° <?= (int)$asiento['numero_asiento'] ?></strong>
                                    &bull; <?= e($asiento['fecha']) ?>
                                    &bull; <span class="badge badge-regimen"><?= e($asiento['tipo_asiento']) ?></span>
                                    &mdash; <em><?= e($asiento['glosa']) ?></em>
                                </td>
                                <td class="num"><strong><?= e(formatoClp($asiento['total_debe'])) ?></strong></td>
                                <td class="num"><strong><?= e(formatoClp($asiento['total_haber'])) ?></strong></td>
                            </tr>
                            <?php foreach ($asiento['lineas'] as $linea): ?>
                                <tr>
                                    <td></td>
                                    <td><code><?= e($linea['cuenta_codigo']) ?></code> &mdash; <?= e($linea['cuenta_nombre']) ?></td>
                                    <td style="color: var(--text-muted);"><?= e($linea['glosa_linea'] ?? '') ?></td>
                                    <td class="num"><?= bccomp($linea['debe'], '0', 0) > 0 ? e(formatoClp($linea['debe'])) : '' ?></td>
                                    <td class="num"><?= bccomp($linea['haber'], '0', 0) > 0 ? e(formatoClp($linea['haber'])) : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function formatearPesos(valor) {
    return '$' + Math.round(valor).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function recalcularCuadratura() {
    let sumaDebe = 0;
    let sumaHaber = 0;

    document.querySelectorAll('.input-debe').forEach(inp => {
        sumaDebe += parseInt(inp.value || '0', 10) || 0;
    });
    document.querySelectorAll('.input-haber').forEach(inp => {
        sumaHaber += parseInt(inp.value || '0', 10) || 0;
    });

    const elDebe = document.getElementById('total-debe-preview');
    const elHaber = document.getElementById('total-haber-preview');
    const elEstado = document.getElementById('estado-cuadratura');

    if (elDebe) elDebe.textContent = formatearPesos(sumaDebe);
    if (elHaber) elHaber.textContent = formatearPesos(sumaHaber);

    if (elEstado) {
        if (sumaDebe === 0 && sumaHaber === 0) {
            elEstado.textContent = 'Ingrese montos en Debe y Haber';
            elEstado.style.color = '#64748b';
        } else if (sumaDebe === sumaHaber) {
            elEstado.textContent = 'CUADRADO (' + formatearPesos(sumaDebe) + ')';
            elEstado.style.color = '#166534';
        } else {
            const diff = Math.abs(sumaDebe - sumaHaber);
            elEstado.textContent = 'DESCUADRADO por ' + formatearPesos(diff);
            elEstado.style.color = '#991b1b';
        }
    }
}

function agregarFilaAsiento() {
    const tbody = document.getElementById('tbody-lineas');
    if (!tbody || tbody.rows.length === 0) return;

    const nuevaFila = tbody.rows[0].cloneNode(true);
    nuevaFila.querySelectorAll('select').forEach(sel => sel.selectedIndex = 0);
    nuevaFila.querySelectorAll('input[type="text"]').forEach(inp => inp.value = '');
    nuevaFila.querySelectorAll('input[type="number"]').forEach(inp => inp.value = '0');
    tbody.appendChild(nuevaFila);
    recalcularCuadratura();
}

function eliminarFilaAsiento(boton) {
    const tbody = document.getElementById('tbody-lineas');
    if (!tbody || tbody.rows.length <= 2) {
        alert('Un asiento contable debe tener al menos 2 líneas.');
        return;
    }
    boton.closest('tr').remove();
    recalcularCuadratura();
}

document.addEventListener('input', function (e) {
    if (e.target.classList.contains('input-debe')) {
        const val = parseInt(e.target.value || '0', 10) || 0;
        if (val > 0) {
            const fila = e.target.closest('tr');
            const haberInp = fila ? fila.querySelector('.input-haber') : null;
            if (haberInp) haberInp.value = '0';
        }
        recalcularCuadratura();
    } else if (e.target.classList.contains('input-haber')) {
        const val = parseInt(e.target.value || '0', 10) || 0;
        if (val > 0) {
            const fila = e.target.closest('tr');
            const debeInp = fila ? fila.querySelector('.input-debe') : null;
            if (debeInp) debeInp.value = '0';
        }
        recalcularCuadratura();
    }
});

recalcularCuadratura();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>