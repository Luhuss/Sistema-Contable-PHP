<?php

declare(strict_types=1);

$tituloPagina = 'Plan de Cuentas';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';

/** @var PDO $pdo */
/** @var array<string, mixed> $usuarioActual */
/** @var array<string, mixed>|null $empresaActiva */

$auditoriaService   = new AuditoriaService($pdo);
$planCuentasService = new PlanCuentasService($pdo, $auditoriaService);
$empresaId          = $empresaActiva !== null ? (int)$empresaActiva['id'] : 0;
$usuarioId          = (int)$usuarioActual['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $empresaId > 0) {
    try {
        validarCsrf($_POST['csrf_token'] ?? null);
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'crear_cuenta') {
            $datosCuenta = [
                'codigo'          => trim((string)($_POST['codigo'] ?? '')),
                'nombre'          => trim((string)($_POST['nombre'] ?? '')),
                'tipo'            => trim((string)($_POST['tipo'] ?? '')),
                'naturaleza'      => trim((string)($_POST['naturaleza'] ?? '')),
                'nivel'           => (int)($_POST['nivel'] ?? 4),
                'cuenta_padre_id' => !empty($_POST['cuenta_padre_id']) ? (int)$_POST['cuenta_padre_id'] : null,
                'es_imputable'    => isset($_POST['es_imputable']) && $_POST['es_imputable'] === '1',
                'codigo_sii'      => trim((string)($_POST['codigo_sii'] ?? '')) ?: null,
            ];

            $planCuentasService->crearCuenta($empresaId, $datosCuenta, $usuarioId);
            $_SESSION['flash_exito'] = "Cuenta contable '{$datosCuenta['codigo']} - {$datosCuenta['nombre']}' creada correctamente.";

            header('Location: cuentas.php');
            exit;
        }

        if ($accion === 'cambiar_estado') {
            $cuentaId = (int)($_POST['cuenta_id'] ?? 0);
            $activar  = (($_POST['nuevo_estado'] ?? '0') === '1');

            $planCuentasService->cambiarEstadoCuenta($empresaId, $cuentaId, $activar, $usuarioId);
            $_SESSION['flash_exito'] = 'Estado de la cuenta actualizado correctamente.';

            header('Location: cuentas.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: cuentas.php');
        exit;
    }
}

$cuentas = $empresaId > 0 ? $planCuentasService->listarPlanCuentas($empresaId, false) : [];
?>

<div class="page-header">
    <div>
        <h1>Plan de Cuentas Contable (SII Chile)</h1>
        <?php if ($empresaActiva !== null): ?>
            <p><?= e($empresaActiva['razon_social']) ?> &bull; RUT <?= e($empresaActiva['rut']) ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Formulario Creación de Cuenta -->
<div class="card">
    <h2 class="card-titulo">Agregar Nueva Cuenta al Plan</h2>

    <form method="POST" action="cuentas.php">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="crear_cuenta">

        <div class="form-grid">
            <div class="campo">
                <label for="codigo">Código Contable *</label>
                <input type="text" id="codigo" name="codigo" placeholder="Ej: 1.1.01.003" required>
            </div>

            <div class="campo">
                <label for="nombre">Nombre de la Cuenta *</label>
                <input type="text" id="nombre" name="nombre" placeholder="Ej: Banco Santander Cta. Cte." required>
            </div>

            <div class="campo">
                <label for="tipo">Clasificación *</label>
                <select id="tipo" name="tipo" required>
                    <option value="ACTIVO">ACTIVO</option>
                    <option value="PASIVO">PASIVO</option>
                    <option value="PATRIMONIO">PATRIMONIO</option>
                    <option value="INGRESO">INGRESO</option>
                    <option value="COSTO">COSTO</option>
                    <option value="GASTO">GASTO</option>
                </select>
            </div>

            <div class="campo">
                <label for="naturaleza">Naturaleza *</label>
                <select id="naturaleza" name="naturaleza" required>
                    <option value="DEUDORA">DEUDORA (Aumenta al Debe)</option>
                    <option value="ACREEDORA">ACREEDORA (Aumenta al Haber)</option>
                </select>
            </div>

            <div class="campo">
                <label for="cuenta_padre_id">Cuenta Padre (Opcional)</label>
                <select id="cuenta_padre_id" name="cuenta_padre_id">
                    <option value="">-- Sin cuenta padre (Raíz) --</option>
                    <?php foreach ($cuentas as $cta): ?>
                        <?php if ((int)$cta['es_imputable'] === 0): ?>
                            <option value="<?= (int)$cta['id'] ?>">
                                <?= e($cta['codigo']) ?> - <?= e($cta['nombre']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label for="nivel">Nivel Jerárquico</label>
                <input type="number" id="nivel" name="nivel" min="1" max="6" value="4">
            </div>

            <div class="campo">
                <label for="es_imputable">¿Acepta Asientos?</label>
                <select id="es_imputable" name="es_imputable">
                    <option value="1">Sí (Cuenta Imputable / Movimiento)</option>
                    <option value="0">No (Cuenta Agrupadora / Título)</option>
                </select>
            </div>

            <div class="campo">
                <label for="codigo_sii">Código Homologación SII (Opcional)</label>
                <input type="text" id="codigo_sii" name="codigo_sii" placeholder="Ej: 1010100">
            </div>
        </div>

        <div style="margin-top: 16px;">
            <button type="submit" class="btn btn-primario">Guardar Cuenta Contable</button>
        </div>
    </form>
</div>

<!-- Tabla Jerárquica del Plan de Cuentas -->
<div class="card">
    <h2 class="card-titulo">Estructura del Plan de Cuentas (<?= count($cuentas) ?> cuentas)</h2>

    <?php if (empty($cuentas)): ?>
        <p style="color: var(--text-muted);">No hay cuentas configuradas para esta empresa.</p>
    <?php else: ?>
        <div class="tabla-responsive">
            <table class="tabla-contable">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Naturaleza</th>
                        <th>Imputable</th>
                        <th>Cód. SII</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cuentas as $cta): ?>
                        <?php
                        $esAgrupadora = ((int)$cta['es_imputable'] === 0);
                        $nivel        = max(1, (int)$cta['nivel']);
                        $sangria      = ($nivel - 1) * 16;
                        ?>
                        <tr style="<?= $esAgrupadora ? 'background-color: #f8fafc; font-weight: 600;' : '' ?>">
                            <td><code><?= e($cta['codigo']) ?></code></td>
                            <td style="padding-left: <?= 10 + $sangria ?>px;"><?= e($cta['nombre']) ?></td>
                            <td><?= e($cta['tipo']) ?></td>
                            <td><?= e($cta['naturaleza']) ?></td>
                            <td><?= $esAgrupadora ? 'Agrupadora' : 'Imputable' ?></td>
                            <td><?= e($cta['codigo_sii'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= (int)$cta['activa'] === 1 ? 'badge-abierto' : 'badge-cerrado' ?>">
                                    <?= (int)$cta['activa'] === 1 ? 'Activa' : 'Inactiva' ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="cuentas.php" style="display: inline;">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="cuenta_id" value="<?= (int)$cta['id'] ?>">
                                    <?php if ((int)$cta['activa'] === 1): ?>
                                        <input type="hidden" name="nuevo_estado" value="0">
                                        <button type="submit" class="btn btn-secundario btn-sm">Desactivar</button>
                                    <?php else: ?>
                                        <input type="hidden" name="nuevo_estado" value="1">
                                        <button type="submit" class="btn btn-secundario btn-sm">Activar</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>