<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

$paginaActual = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
$tituloCompleto = isset($tituloPagina) && $tituloPagina !== ''
    ? $tituloPagina . ' | ' . (defined('APP_NAME') ? APP_NAME : 'Contabilidad Chile SII')
    : (defined('APP_NAME') ? APP_NAME : 'Contabilidad Chile SII');

$flashExito = $_SESSION['flash_exito'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_exito'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloCompleto) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-layout">
    <!-- Barra Lateral de Navegación Contable -->
    <aside class="sidebar no-print">
        <div class="sidebar-brand">
            <span class="brand-badge">SII 2026</span>
            <h2>Contabilidad CL</h2>
            <small>Estudio Contable Multi-Empresa</small>
        </div>

        <nav class="sidebar-nav" aria-label="Menú Principal">
            <div class="nav-seccion">Gestión General</div>
            <a href="dashboard.php" class="nav-link <?= $paginaActual === 'dashboard.php' ? 'activo' : '' ?>">
                Panel Ejecutivo
            </a>
            <a href="empresas.php" class="nav-link <?= $paginaActual === 'empresas.php' ? 'activo' : '' ?>">
                Cartera de Empresas
                <span class="nav-pill"><?= (int)$cupoLicencia['usadas'] ?>/<?= (int)$cupoLicencia['maximo'] ?></span>
            </a>
            <a href="cuentas.php" class="nav-link <?= $paginaActual === 'cuentas.php' ? 'activo' : '' ?>">
                Plan de Cuentas
            </a>

            <div class="nav-seccion">Operación Diaria</div>
            <a href="transaccion.php" class="nav-link <?= $paginaActual === 'transaccion.php' ? 'activo' : '' ?>">
                Libro Diario / Asientos
            </a>
            <a href="libro_compras.php" class="nav-link <?= $paginaActual === 'libro_compras.php' ? 'activo' : '' ?>">
                Compras y Ventas (RCV)
            </a>
            <a href="libro_honorarios.php" class="nav-link <?= $paginaActual === 'libro_honorarios.php' ? 'activo' : '' ?>">
                Honorarios (BHE 14,25%)
            </a>
            <a href="activo_fijo.php" class="nav-link <?= $paginaActual === 'activo_fijo.php' ? 'activo' : '' ?>">
                Activo Fijo (SII)
            </a>

            <div class="nav-seccion">Estados Financieros</div>
            <a href="libroMayor.php" class="nav-link <?= $paginaActual === 'libroMayor.php' ? 'activo' : '' ?>">
                Libro Mayor
            </a>
            <a href="balanceGral.php" class="nav-link <?= $paginaActual === 'balanceGral.php' ? 'activo' : '' ?>">
                Balance 8 Columnas
            </a>
            <a href="resultados.php" class="nav-link <?= $paginaActual === 'resultados.php' ? 'activo' : '' ?>">
                Estado de Resultados
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="contador-info">
                <strong><?= e($usuarioActual['nombre_completo']) ?></strong>
                <span>RUT: <?= e($usuarioActual['rut']) ?></span>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </aside>

    <!-- Área Principal -->
    <div class="main-wrapper">
        <!-- Barra Superior de Contexto Multi-Tenant -->
        <header class="topbar no-print">
            <?php if ($empresaActiva !== null): ?>
                <form method="POST" action="<?= e($_SERVER['REQUEST_URI'] ?? 'dashboard.php') ?>" class="contexto-form">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion_contexto" value="cambiar_empresa_periodo">

                    <div class="contexto-grupo">
                        <label for="selector_empresa">Empresa Activa:</label>
                        <select id="selector_empresa" name="empresa_id" onchange="this.form.submit()">
                            <?php foreach ($empresasCartera as $emp): ?>
                                <option value="<?= (int)$emp['id'] ?>" <?= (int)$emp['id'] === (int)$empresaActiva['id'] ? 'selected' : '' ?>>
                                    <?= e($emp['razon_social']) ?> (<?= e($emp['rut']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($periodosEmpresa)): ?>
                        <div class="contexto-grupo">
                            <label for="selector_periodo">Período Fiscal:</label>
                            <select id="selector_periodo" name="periodo_id" onchange="this.form.submit()">
                                <?php foreach ($periodosEmpresa as $per): ?>
                                    <option value="<?= (int)$per['id'] ?>" <?= ($periodoActivo && (int)$per['id'] === (int)$periodoActivo['id']) ? 'selected' : '' ?>>
                                        <?= e(nombreMesChile((int)$per['mes'])) ?> <?= (int)$per['anio'] ?> [<?= e($per['estado']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="contexto-meta">
                    <span class="badge badge-regimen"><?= e($empresaActiva['regimen_tributario']) ?></span>
                    <?php if ($periodoActivo !== null): ?>
                        <span class="badge <?= $periodoActivo['estado'] === 'ABIERTO' ? 'badge-abierto' : 'badge-cerrado' ?>">
                            Período <?= e($periodoActivo['estado']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="contexto-vacio">
                    <span>Sin empresa seleccionada — Registre su primera empresa cliente para comenzar.</span>
                </div>
            <?php endif; ?>
        </header>

        <!-- Encabezado exclusivo para impresión de reportes -->
        <?php if ($empresaActiva !== null): ?>
            <div class="print-header only-print">
                <strong><?= e($empresaActiva['razon_social']) ?></strong> | RUT: <?= e($empresaActiva['rut']) ?> |
                Giro: <?= e($empresaActiva['giro']) ?>
                <?php if ($periodoActivo !== null): ?>
                    | Período: <?= e(nombreMesChile((int)$periodoActivo['mes'])) ?> <?= (int)$periodoActivo['anio'] ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <main class="contenido-principal">
            <?php if ($flashExito !== null): ?>
                <div class="alerta alerta-exito no-print" role="alert"><?= e($flashExito) ?></div>
            <?php endif; ?>

            <?php if ($flashError !== null): ?>
                <div class="alerta alerta-error no-print" role="alert"><?= e($flashError) ?></div>
            <?php endif; ?>