<?php

declare(strict_types=1);

/**
 * Configuración Global de la Aplicación, Licenciamiento y Estándares Tributarios (Chile 2026).
 *
 * Define parámetros de licencia comercial (Plan Base: 1 Contador / hasta 5 empresas),
 * constantes tributarias SII 2026, validación de BCMath y seguridad web.
 */

// 1. Verificación Fail-Fast de extensiones obligatorias (ADR-001)
if (!extension_loaded('bcmath')) {
    http_response_code(500);
    exit('Error crítico de infraestructura: La extensión PHP "bcmath" es obligatoria para el motor financiero.');
}

if (!extension_loaded('pdo_mysql')) {
    http_response_code(500);
    exit('Error crítico de infraestructura: El controlador PHP "pdo_mysql" no está habilitado.');
}

// 2. Configuración Regional y de Entorno
date_default_timezone_set('America/Santiago');
mb_internal_encoding('UTF-8');

define('APP_NAME', 'Sistema Contable y Tributario PHP');
define('APP_VERSION', '1.0.0-2026');
define('APP_ENV', getenv('APP_ENV') ?: 'production'); // 'development' | 'production'
define('BASE_PATH', dirname(__DIR__));

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// 3. Parámetros de Licenciamiento Comercial (Escalables según Plan Contratado)
// Plan Base: 1 Contador titular + hasta 5 empresas clientes en cartera
$maxEmpresasLicencia = (int)(getenv('LICENCIA_MAX_EMPRESAS') ?: 5);
$maxUsuariosLicencia = (int)(getenv('LICENCIA_MAX_USUARIOS') ?: 1);

define('LICENCIA_PLAN_NOMBRE', getenv('LICENCIA_PLAN_NOMBRE') ?: 'PLAN INDEPENDIENTE (5 EMPRESAS)');
define('MAX_EMPRESAS_TENANT', max(1, $maxEmpresasLicencia));
define('MAX_USUARIOS_TENANT', max(1, $maxUsuariosLicencia));

// 4. Constantes de Arquitectura y Normativa Tributaria Chile 2026
define('BCMATH_ESCALA_INTERNA', 4);
define('TASA_RETENCION_BHE_2026', '0.1425');   // 14.25% Ley N° 21.133 (Año Comercial 2026)
define('FACTOR_LIQUIDO_BHE_2026', '0.8575');   // 1 - 0.1425
define('TASA_IVA_CHILE', '0.19');              // 19% IVA General

// 5. Inicialización de Sesión Segura (Endurecida para Producción Web)
if (session_status() === PHP_SESSION_NONE) {
    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $esHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('SII_CONTABLE_SID');
    session_start();
}

// 6. Funciones Auxiliares Transversales (Matemática Financiera y Seguridad Web)

if (!function_exists('bcround')) {
    /**
     * Redondeo aritmético exacto (Round Half Up) sobre cadenas BCMath.
     *
     * @param string $numero    Número decimal en formato string
     * @param int    $precision Cantidad de decimales destino (0 para pesos CLP enteros)
     * @return string
     */
    function bcround(string $numero, int $precision = 0): string
    {
        $numero = trim($numero);
        if (!is_numeric($numero)) {
            throw new InvalidArgumentException("Valor no numérico recibido en bcround: '{$numero}'");
        }

        if (strpos($numero, '.') === false) {
            return bcadd($numero, '0', $precision);
        }

        $esNegativo = $numero[0] === '-';
        $factor = '0.' . str_repeat('0', $precision) . '5';

        if ($esNegativo) {
            return bcsub($numero, $factor, $precision);
        }

        return bcadd($numero, $factor, $precision);
    }
}

if (!function_exists('e')) {
    /**
     * Escapa cadenas de salida HTML para prevenir ataques XSS.
     *
     * @param string|int|float|null $valor
     * @return string
     */
    function e(string|int|float|null $valor): string
    {
        return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Genera o recupera el token CSRF activo de la sesión.
     *
     * @return string
     */
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('verificar_csrf')) {
    /**
     * Valida en tiempo constante el token CSRF enviado por formulario POST.
     *
     * @param string|null $tokenRecibido
     * @return bool
     */
    function verificar_csrf(?string $tokenRecibido): bool
    {
        if (empty($_SESSION['_csrf_token']) || empty($tokenRecibido)) {
            return false;
        }

        return hash_equals($_SESSION['_csrf_token'], $tokenRecibido);
    }
}