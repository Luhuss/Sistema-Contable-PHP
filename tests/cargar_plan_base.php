<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script debe ejecutarse únicamente desde la terminal (CLI).\n");
}

// 1. Bootstrap desde config/app.php y config/database.php
require_once __DIR__ . '/../config/app.php';
$dbReturn = require_once __DIR__ . '/../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if ($dbReturn instanceof PDO) {
        $pdo = $dbReturn;
    } elseif (class_exists('Database') && method_exists('Database', 'getConnection')) {
        $pdo = Database::getConnection();
    } elseif (class_exists('Database') && method_exists('Database', 'conectar')) {
        $pdo = Database::conectar();
    } else {
        fwrite(STDERR, "[FATAL] No se pudo obtener una instancia PDO desde config/database.php\n");
        exit(1);
    }
}

require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasPlantillaService.php';

$empresaId = isset($argv[1]) ? (int) $argv[1] : 0;
$usuarioId = isset($argv[2]) ? (int) $argv[2] : 1;

if ($empresaId <= 0) {
    fwrite(STDERR, "Uso: php tests/cargar_plan_base.php <empresa_id> [usuario_id]\n");
    exit(1);
}

$auditoriaService = new AuditoriaService($pdo);
$planCuentasService = new PlanCuentasService($pdo, $auditoriaService);
$plantillaService = new PlanCuentasPlantillaService($pdo, $planCuentasService);

$creadas = $plantillaService->cargarPlanBase($empresaId, $usuarioId);
echo "[OK] Se cargaron {$creadas} cuentas jerárquicas SII 2026 en empresa_id={$empresaId}.\n";