<?php

declare(strict_types=1);

$tituloPagina = 'Cartera de Empresas';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Empresas/EmpresaService.php';

/** @var PDO $pdo */
/** @var array<string, mixed> $usuarioActual */
/** @var array<string, mixed>|null $empresaActiva */
/** @var array<string, mixed> $cupoLicencia */
/** @var array<int, array<string, mixed>> $empresasCartera */
/** @var array<int, array<string, mixed>> $periodosEmpresa */

$auditoriaService = new AuditoriaService($pdo);
$maxPermitidas    = defined('LICENCIA_MAX_EMPRESAS') ? (int)LICENCIA_MAX_EMPRESAS : 5;
$empresaService   = new EmpresaService($pdo, $auditoriaService, $maxPermitidas);
$usuarioId        = (int)$usuarioActual['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validarCsrf($_POST['csrf_token'] ?? null);
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'crear_empresa') {
            $datosEmpresa = [
                'rut'                => trim((string)($_POST['rut'] ?? '')),
                'razon_social'       => trim((string)($_POST['razon_social'] ?? '')),
                'giro'               => trim((string)($_POST['giro'] ?? '')),
                'direccion'          => trim((string)($_POST['direccion'] ?? '')),
                'comuna'             => trim((string)($_POST['comuna'] ?? '')),
                'regimen_tributario' => trim((string)($_POST['regimen_tributario'] ?? '14D_N3')),
                'tasa_ppm'           => trim((string)($_POST['tasa_ppm'] ?? '0.25')),
            ];

            $nuevaEmpresaId = $empresaService->crearEmpresa($datosEmpresa, $usuarioId);

            // Asegurar vínculo Contador <-> Empresa si el servicio no lo insertó
            $stmtCheckVinculo = $pdo->prepare("
                SELECT 1 FROM usuario_empresa
                WHERE usuario_id = :usuario_id AND empresa_id = :empresa_id
                LIMIT 1
            ");
            $stmtCheckVinculo->execute([
                ':usuario_id' => $usuarioId,
                ':empresa_id' => $nuevaEmpresaId,
            ]);
            if (!$stmtCheckVinculo->fetchColumn()) {
                $stmtVinculo = $pdo->prepare("
                    INSERT INTO usuario_empresa (usuario_id, empresa_id, rol_empresa)
                    VALUES (:usuario_id, :empresa_id, 'TITULAR')
                ");
                $stmtVinculo->execute([
                    ':usuario_id' => $usuarioId,
                    ':empresa_id' => $nuevaEmpresaId,
                ]);
            }

            // Crear período fiscal inicial si aún no existe ninguno para la nueva empresa
            $anioInicial = (int)($_POST['anio_inicial'] ?? date('Y'));
            $mesInicial  = (int)($_POST['mes_inicial'] ?? date('n'));

            if ($anioInicial >= 2000 && $anioInicial <= 2100 && $mesInicial >= 1 && $mesInicial <= 12) {
                $stmtCheckPeriodo = $pdo->prepare("
                    SELECT id FROM periodos_fiscales
                    WHERE empresa_id = :empresa_id
                    LIMIT 1
                ");
                $stmtCheckPeriodo->execute([':empresa_id' => $nuevaEmpresaId]);
                if (!$stmtCheckPeriodo->fetchColumn()) {
                    $stmtPer = $pdo->prepare("
                        INSERT INTO periodos_fiscales (empresa_id, anio, mes, estado)
                        VALUES (:empresa_id, :anio, :mes, 'ABIERTO')
                    ");
                    $stmtPer->execute([
                        ':empresa_id' => $nuevaEmpresaId,
                        ':anio'       => $anioInicial,
                        ':mes'        => $mesInicial,
                    ]);
                }
            }

            $_SESSION['empresa_activa_id'] = $nuevaEmpresaId;
            unset($_SESSION['periodo_activo_id']);
            $_SESSION['flash_exito'] = 'Empresa cliente registrada y activada correctamente.';

            header('Location: empresas.php');
            exit;
        }

        if ($accion === 'seleccionar_empresa') {
            $empresaSeleccionadaId = (int)($_POST['empresa_id'] ?? 0);
            $stmtPermiso = $pdo->prepare("
                SELECT e.id
                FROM empresas e
                INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
                WHERE ue.usuario_id = :usuario_id AND e.id = :empresa_id AND e.activa = 1
                LIMIT 1
            ");
            $stmtPermiso->execute([
                ':usuario_id' => $usuarioId,
                ':empresa_id' => $empresaSeleccionadaId,
            ]);

            if (!$stmtPermiso->fetchColumn()) {
                throw new RuntimeException('No tiene acceso a la empresa seleccionada.');
            }

            $_SESSION['empresa_activa_id'] = $empresaSeleccionadaId;
            unset($_SESSION['periodo_activo_id']);
            $_SESSION['flash_exito'] = 'Contexto cambiado a la empresa seleccionada.';

            header('Location: dashboard.php');
            exit;
        }

        if ($accion === 'crear_periodo') {
            if ($empresaActiva === null) {
                throw new RuntimeException('Debe seleccionar una empresa activa primero.');
            }

            $empresaId = (int)$empresaActiva['id'];
            $anio      = (int)($_POST['anio'] ?? date('Y'));
            $mes       = (int)($_POST['mes'] ?? date('n'));

            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
                throw new InvalidArgumentException('El año o mes del período fiscal no es válido.');
            }

            $stmtExiste = $pdo->prepare("
                SELECT id FROM periodos_fiscales
                WHERE empresa_id = :empresa_id AND anio = :anio AND mes = :mes
                LIMIT 1
            ");
            $stmtExiste->execute([
                ':empresa_id' => $empresaId,
                ':anio'       => $anio,
                ':mes'        => $mes,
            ]);

            if ($stmtExiste->fetchColumn()) {
                throw new RuntimeException('Ese período fiscal ya existe para la empresa activa.');
            }

            $stmtInsertPer = $pdo->prepare("
                INSERT INTO periodos_fiscales (empresa_id, anio, mes, estado)
                VALUES (:empresa_id, :anio, :mes, 'ABIERTO')
            ");
            $stmtInsertPer->execute([
                ':empresa_id' => $empresaId,
                ':anio'       => $anio,
                ':mes'        => $mes,
            ]);

            $nuevoPeriodoId = (int)$pdo->lastInsertId();
            $_SESSION['periodo_activo_id'] = $nuevoPeriodoId;

            $auditoriaService->registrarEvento(
                $empresaId,
                'APERTURA_PERIODO',
                'periodos_fiscales',
                $nuevoPeriodoId,
                [
                    'usuario_id'   => $usuarioId,
                    'datos_nuevos' => ['anio' => $anio, 'mes' => $mes, 'estado' => 'ABIERTO'],
                ]
            );

            $_SESSION['flash_exito'] = 'Período fiscal ' . nombreMesChile($mes) . " {$anio} abierto correctamente.";
            header('Location: empresas.php');
            exit;
        }

        if ($accion === 'cambiar_estado_periodo') {
            if ($empresaActiva === null) {
                throw new RuntimeException('No hay empresa activa seleccionada.');
            }

            $empresaId   = (int)$empresaActiva['id'];
            $periodoId   = (int)($_POST['periodo_id'] ?? 0);
            $nuevoEstado = (string)($_POST['nuevo_estado'] ?? '');

            if (!in_array($nuevoEstado, ['ABIERTO', 'CERRADO'], true)) {
                throw new InvalidArgumentException('Estado de período inválido.');
            }

            $stmtPerActual = $pdo->prepare("
                SELECT id, anio, mes, estado
                FROM periodos_fiscales
                WHERE id = :id AND empresa_id = :empresa_id
                LIMIT 1
            ");
            $stmtPerActual->execute([
                ':id'         => $periodoId,
                ':empresa_id' => $empresaId,
            ]);
            $perActual = $stmtPerActual->fetch(PDO::FETCH_ASSOC);

            if (!$perActual) {
                throw new RuntimeException('El período fiscal no pertenece a la empresa activa.');
            }

            $stmtUpd = $pdo->prepare("
                UPDATE periodos_fiscales
                SET estado = :estado
                WHERE id = :id AND empresa_id = :empresa_id
            ");
            $stmtUpd->execute([
                ':estado'     => $nuevoEstado,
                ':id'         => $periodoId,
                ':empresa_id' => $empresaId,
            ]);

            $accionAuditoria = ($nuevoEstado === 'CERRADO') ? 'CIERRE_PERIODO' : 'APERTURA_PERIODO';

            $auditoriaService->registrarEvento(
                $empresaId,
                $accionAuditoria,
                'periodos_fiscales',
                $periodoId,
                [
                    'usuario_id'      => $usuarioId,
                    'estado_anterior' => $perActual['estado'],
                    'estado_nuevo'    => $nuevoEstado,
                ]
            );

            $_SESSION['flash_exito'] = 'Estado del período actualizado a ' . $nuevoEstado . '.';
            header('Location: empresas.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: empresas.php');
        exit;
    }
}

$estadoCupo = $empresaService->obtenerEstadoCupoLicencia();
?>

<div class="page-header">
    <div>
        <h1>Cartera de Empresas Clientes</h1>
        <p>Administración multi-empresa y apertura de períodos fiscales mensuales</p>
    </div>
    <div>
        <span class="badge badge-regimen">
            Licencia: <?= (int)$estadoCupo['activas'] ?> / <?= (int)$estadoCupo['maximo'] ?> empresas activas
        </span>
    </div>
</div>

<?php if (isset($_GET['sin_empresa'])): ?>
    <div class="alerta alerta-error">
        Aún no tiene empresas registradas. Complete el formulario inferior para crear su primera empresa cliente y generar su Plan de Cuentas SII.
    </div>
<?php endif; ?>

<!-- Alta de Nueva Empresa Cliente -->
<div class="card">
    <h2 class="card-titulo">Registrar Nueva Empresa Cliente</h2>

    <?php if (!empty($cupoLicencia['completo'])): ?>
        <p style="color: var(--danger-text); font-weight: 600;">
            Ha alcanzado el máximo de <?= (int)$cupoLicencia['maximo'] ?> empresas permitidas por su licencia actual.
        </p>
    <?php else: ?>
        <form method="POST" action="empresas.php">
            <?= campoCsrf() ?>
            <input type="hidden" name="accion" value="crear_empresa">

            <div class="form-grid">
                <div class="campo">
                    <label for="rut">RUT Empresa *</label>
                    <input type="text" id="rut" name="rut" placeholder="Ej: 76.123.456-0" required>
                </div>

                <div class="campo">
                    <label for="razon_social">Razón Social *</label>
                    <input type="text" id="razon_social" name="razon_social" placeholder="Nombre Legal SpA / Ltda." required>
                </div>

                <div class="campo">
                    <label for="giro">Giro Comercial SII *</label>
                    <input type="text" id="giro" name="giro" placeholder="Ej: Servicios de Ingeniería y Software" required>
                </div>

                <div class="campo">
                    <label for="regimen_tributario">Régimen Tributario *</label>
                    <select id="regimen_tributario" name="regimen_tributario" required>
                        <option value="14D_N3">Pro Pyme General (Art. 14 D N°3)</option>
                        <option value="14D_N8">Pro Pyme Transparente (Art. 14 D N°8)</option>
                        <option value="14A">Régimen General Semi Integrado (Art. 14 A)</option>
                        <option value="PRESUNTA">Renta Presunta</option>
                    </select>
                </div>

                <div class="campo">
                    <label for="tasa_ppm">Tasa PPM Inicial (%)</label>
                    <input type="text" id="tasa_ppm" name="tasa_ppm" value="0.25" placeholder="0.25">
                </div>

                <div class="campo">
                    <label for="direccion">Dirección Comercial</label>
                    <input type="text" id="direccion" name="direccion" placeholder="Av. Providencia 1234">
                </div>

                <div class="campo">
                    <label for="comuna">Comuna</label>
                    <input type="text" id="comuna" name="comuna" placeholder="Santiago">
                </div>

                <div class="campo">
                    <label for="mes_inicial">Mes Período Inicial</label>
                    <select id="mes_inicial" name="mes_inicial">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === (int)date('n') ? 'selected' : '' ?>>
                                <?= e(nombreMesChile($m)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="anio_inicial">Año Período Inicial</label>
                    <input type="number" id="anio_inicial" name="anio_inicial" min="2020" max="2035" value="<?= (int)date('Y') ?>">
                </div>
            </div>

            <div style="margin-top: 16px;">
                <button type="submit" class="btn btn-primario">Crear Empresa y Plan de Cuentas Base</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Listado de Empresas en Cartera -->
<div class="card">
    <h2 class="card-titulo">Empresas Asignadas al Estudio</h2>

    <?php if (empty($empresasCartera)): ?>
        <p style="color: var(--text-muted);">No hay empresas registradas en su cartera.</p>
    <?php else: ?>
        <div class="tabla-responsive">
            <table class="tabla-contable">
                <thead>
                    <tr>
                        <th>RUT</th>
                        <th>Razón Social</th>
                        <th>Giro</th>
                        <th>Régimen</th>
                        <th class="num">PPM (%)</th>
                        <th>Contexto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($empresasCartera as $emp): ?>
                        <?php $esActiva = ($empresaActiva !== null && (int)$emp['id'] === (int)$empresaActiva['id']); ?>
                        <tr>
                            <td><strong><?= e($emp['rut']) ?></strong></td>
                            <td><?= e($emp['razon_social']) ?></td>
                            <td><?= e($emp['giro']) ?></td>
                            <td><span class="badge badge-regimen"><?= e($emp['regimen_tributario']) ?></span></td>
                            <td class="num"><?= e((string)$emp['tasa_ppm']) ?>%</td>
                            <td>
                                <?php if ($esActiva): ?>
                                    <span class="badge badge-abierto">Empresa Activa</span>
                                <?php else: ?>
                                    <form method="POST" action="empresas.php" style="display: inline;">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="accion" value="seleccionar_empresa">
                                        <input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
                                        <button type="submit" class="btn btn-secundario btn-sm">Trabajar Aquí</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Gestión de Períodos Fiscales de la Empresa Activa -->
<?php if ($empresaActiva !== null): ?>
    <div class="card">
        <h2 class="card-titulo">Períodos Fiscales de <?= e($empresaActiva['razon_social']) ?></h2>

        <form method="POST" action="empresas.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 18px;">
            <?= campoCsrf() ?>
            <input type="hidden" name="accion" value="crear_periodo">

            <div class="campo">
                <label for="mes_nuevo">Mes</label>
                <select id="mes_nuevo" name="mes">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int)date('n') ? 'selected' : '' ?>>
                            <?= e(nombreMesChile($m)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="campo">
                <label for="anio_nuevo">Año</label>
                <input type="number" id="anio_nuevo" name="anio" min="2020" max="2035" value="<?= (int)date('Y') ?>" style="width: 110px;">
            </div>

            <button type="submit" class="btn btn-primario">Abrir Nuevo Período</button>
        </form>

        <?php if (!empty($periodosEmpresa)): ?>
            <div class="tabla-responsive">
                <table class="tabla-contable">
                    <thead>
                        <tr>
                            <th>Período</th>
                            <th>Año</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periodosEmpresa as $per): ?>
                            <tr>
                                <td><strong><?= e(nombreMesChile((int)$per['mes'])) ?></strong></td>
                                <td><?= (int)$per['anio'] ?></td>
                                <td>
                                    <span class="badge <?= $per['estado'] === 'ABIERTO' ? 'badge-abierto' : 'badge-cerrado' ?>">
                                        <?= e($per['estado']) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="empresas.php" style="display: inline;">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="accion" value="cambiar_estado_periodo">
                                        <input type="hidden" name="periodo_id" value="<?= (int)$per['id'] ?>">
                                        <?php if ($per['estado'] === 'ABIERTO'): ?>
                                            <input type="hidden" name="nuevo_estado" value="CERRADO">
                                            <button type="submit" class="btn btn-secundario btn-sm">Cerrar Período</button>
                                        <?php else: ?>
                                            <input type="hidden" name="nuevo_estado" value="ABIERTO">
                                            <button type="submit" class="btn btn-secundario btn-sm">Reabrir Período</button>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>