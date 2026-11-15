<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Escapa texto de forma segura para salida HTML (prevención XSS).
 */
if (!function_exists('e')) {
    function e(mixed $valor): string
    {
        return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Formatea un entero monetario CLP (string o int) con separador de miles sin usar float.
 * Ejemplo: "119000" -> "$119.000" | "-15200" -> "-$15.200"
 */
if (!function_exists('formatoClp')) {
    function formatoClp(string|int $monto): string
    {
        $entero = bcadd((string)$monto, '0', 0);
        $esNegativo = (bccomp($entero, '0', 0) < 0);
        $absoluto = $esNegativo ? substr($entero, 1) : $entero;

        $longitud = strlen($absoluto);
        $formateado = '';
        for ($i = 0; $i < $longitud; $i++) {
            if ($i > 0 && ($longitud - $i) % 3 === 0) {
                $formateado .= '.';
            }
            $formateado .= $absoluto[$i];
        }

        return ($esNegativo ? '-$' : '$') . $formateado;
    }
}

/**
 * Retorna el nombre en español del mes calendario (1..12).
 */
if (!function_exists('nombreMesChile')) {
    function nombreMesChile(int $mes): string
    {
        return match ($mes) {
            1  => 'Enero',
            2  => 'Febrero',
            3  => 'Marzo',
            4  => 'Abril',
            5  => 'Mayo',
            6  => 'Junio',
            7  => 'Julio',
            8  => 'Agosto',
            9  => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
            default => "Mes {$mes}",
        };
    }
}

/**
 * Valida el token CSRF en peticiones POST.
 */
if (!function_exists('validarCsrf')) {
    function validarCsrf(?string $token): void
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            throw new RuntimeException('Token de seguridad CSRF inválido o expirado. Recargue la página e intente nuevamente.');
        }
    }
}

/**
 * Genera el campo oculto HTML con el token CSRF activo.
 */
if (!function_exists('campoCsrf')) {
    function campoCsrf(): string
    {
        $token = $_SESSION['csrf_token'] ?? '';
        return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
    }
}

// 1. Verificación de sesión autenticada
if (empty($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
    header('Location: index.php?error=requiere_autenticacion');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** @var PDO $pdo */
$pdo = Database::getConnection();
$usuarioIdSesion = (int)$_SESSION['usuario_id'];

// 2. Validar vigencia del Contador en base de datos
$stmtUsuario = $pdo->prepare("
    SELECT id, rut, nombre_completo, email, rol, activo
    FROM usuarios
    WHERE id = :id
    LIMIT 1
");
$stmtUsuario->execute([':id' => $usuarioIdSesion]);
$usuarioActual = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

if (!$usuarioActual || (int)$usuarioActual['activo'] !== 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?error=cuenta_inactiva');
    exit;
}

// 3. Procesar cambio de Empresa Cliente / Período Fiscal desde el selector superior (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_contexto'] ?? '') === 'cambiar_empresa_periodo') {
    validarCsrf($_POST['csrf_token'] ?? null);

    $nuevaEmpresaId = isset($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : 0;
    $nuevoPeriodoId = isset($_POST['periodo_id']) ? (int)$_POST['periodo_id'] : 0;

    if ($nuevaEmpresaId > 0) {
        $stmtCheckEmp = $pdo->prepare("
            SELECT e.id
            FROM empresas e
            INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
            WHERE ue.usuario_id = :usuario_id
                AND e.id = :empresa_id
                AND e.activa = 1
            LIMIT 1
        ");
        $stmtCheckEmp->execute([
            ':usuario_id' => $usuarioIdSesion,
            ':empresa_id' => $nuevaEmpresaId,
        ]);

        if ($stmtCheckEmp->fetchColumn()) {
            $empresaAnterior = (int)($_SESSION['empresa_activa_id'] ?? 0);
            $_SESSION['empresa_activa_id'] = $nuevaEmpresaId;

            if ($empresaAnterior !== $nuevaEmpresaId) {
                unset($_SESSION['periodo_activo_id']);
            }
        }
    }

    if ($nuevoPeriodoId > 0 && !empty($_SESSION['empresa_activa_id'])) {
        $stmtCheckPer = $pdo->prepare("
            SELECT id
            FROM periodos_fiscales
            WHERE id = :periodo_id
                AND empresa_id = :empresa_id
            LIMIT 1
        ");
        $stmtCheckPer->execute([
            ':periodo_id' => $nuevoPeriodoId,
            ':empresa_id' => (int)$_SESSION['empresa_activa_id'],
        ]);

        if ($stmtCheckPer->fetchColumn()) {
            $_SESSION['periodo_activo_id'] = $nuevoPeriodoId;
        }
    }

    $destino = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
    header('Location: ' . $destino);
    exit;
}

// 4. Obtener cartera de empresas asignadas al Contador titular
$stmtEmpresas = $pdo->prepare("
    SELECT e.id, e.rut, e.razon_social, e.giro, e.regimen_tributario, e.tasa_ppm
    FROM empresas e
    INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
    WHERE ue.usuario_id = :usuario_id
        AND e.activa = 1
    ORDER BY e.razon_social ASC
");
$stmtEmpresas->execute([':usuario_id' => $usuarioIdSesion]);
$empresasCartera = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);

$maxEmpresasLicencia = defined('LICENCIA_MAX_EMPRESAS') ? (int)LICENCIA_MAX_EMPRESAS : 5;
$empresasUsadas = count($empresasCartera);

$cupoLicencia = [
    'usadas'      => $empresasUsadas,
    'maximo'      => $maxEmpresasLicencia,
    'disponibles' => max(0, $maxEmpresasLicencia - $empresasUsadas),
    'completo'    => ($empresasUsadas >= $maxEmpresasLicencia),
];

// 5. Resolver empresa activa en sesión
/** @var array<string, mixed>|null $empresaActiva */
$empresaActiva = null;
$empresaSesionId = (int)($_SESSION['empresa_activa_id'] ?? 0);

foreach ($empresasCartera as $emp) {
    if ((int)$emp['id'] === $empresaSesionId) {
        $empresaActiva = $emp;
        break;
    }
}

if ($empresaActiva === null && !empty($empresasCartera)) {
    $empresaActiva = $empresasCartera[0];
    $_SESSION['empresa_activa_id'] = (int)$empresaActiva['id'];
}

// Si el Contador aún no tiene empresas creadas, redirigir al gestor de cartera
$scriptActual = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($empresaActiva === null && !in_array($scriptActual, ['empresas.php', 'logout.php'], true)) {
    header('Location: empresas.php?sin_empresa=1');
    exit;
}

// 6. Resolver períodos fiscales de la empresa activa
$periodosEmpresa = [];
/** @var array<string, mixed>|null $periodoActivo */
$periodoActivo   = null;

if ($empresaActiva !== null) {
    $stmtPeriodos = $pdo->prepare("
        SELECT id, empresa_id, anio, mes, estado
        FROM periodos_fiscales
        WHERE empresa_id = :empresa_id
        ORDER BY anio DESC, mes DESC
    ");
    $stmtPeriodos->execute([':empresa_id' => (int)$empresaActiva['id']]);
    $periodosEmpresa = $stmtPeriodos->fetchAll(PDO::FETCH_ASSOC);

    $periodoSesionId = (int)($_SESSION['periodo_activo_id'] ?? 0);

    foreach ($periodosEmpresa as $per) {
        if ((int)$per['id'] === $periodoSesionId) {
            $periodoActivo = $per;
            break;
        }
    }

    // Si no hay período en sesión, priorizar el último período ABIERTO
    if ($periodoActivo === null && !empty($periodosEmpresa)) {
        foreach ($periodosEmpresa as $per) {
            if ($per['estado'] === 'ABIERTO') {
                $periodoActivo = $per;
                break;
            }
        }
        if ($periodoActivo === null) {
            $periodoActivo = $periodosEmpresa[0];
        }
        $_SESSION['periodo_activo_id'] = (int)$periodoActivo['id'];
    }
}