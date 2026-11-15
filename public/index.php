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

function e(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validarRutModulo11(string $rut): string
{
    $rutLimpio = preg_replace('/[^0-9kK]/', '', trim($rut));
    if (strlen($rutLimpio) < 7 || strlen($rutLimpio) > 9) {
        throw new InvalidArgumentException("El RUT '{$rut}' no tiene un largo válido.");
    }

    $cuerpo = substr($rutLimpio, 0, -1);
    $dv     = strtoupper(substr($rutLimpio, -1));

    if (!ctype_digit($cuerpo)) {
        throw new InvalidArgumentException("El cuerpo del RUT '{$rut}' debe contener solo dígitos.");
    }

    $suma = 0;
    $multiplicador = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += (int)$cuerpo[$i] * $multiplicador;
        $multiplicador = ($multiplicador === 7) ? 2 : $multiplicador + 1;
    }

    $resto = $suma % 11;
    $dvEsperado = 11 - $resto;
    $dvCalculado = match ($dvEsperado) {
        11      => '0',
        10      => 'K',
        default => (string)$dvEsperado,
    };

    if ($dv !== $dvCalculado) {
        throw new InvalidArgumentException("El RUT '{$rut}' es inválido según Módulo 11 del SII.");
    }

    return $cuerpo . '-' . $dv;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Si ya hay sesión iniciada, ir directo al panel
if (!empty($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] > 0) {
    header('Location: dashboard.php');
    exit;
}

$pdo = Database::getConnection();
$totalUsuarios = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$modoSetupInicial = ($totalUsuarios === 0);

$error = null;
$mensajeInfo = match ($_GET['error'] ?? '') {
    'requiere_autenticacion' => 'Inicie sesión para acceder al sistema contable.',
    'cuenta_inactiva'        => 'Su cuenta de usuario se encuentra desactivada.',
    default                  => (isset($_GET['sesion_cerrada']) ? 'Sesión cerrada correctamente.' : null),
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $tokenPost = $_POST['csrf_token'] ?? '';
        if (!is_string($tokenPost) || !hash_equals($_SESSION['csrf_token'], $tokenPost)) {
            throw new RuntimeException('Token de seguridad CSRF inválido. Recargue la página.');
        }

        $accion = $_POST['accion'] ?? 'login';

        if ($accion === 'setup_inicial' && $modoSetupInicial) {
            $rutRaw         = trim((string)($_POST['rut'] ?? ''));
            $nombreCompleto = trim((string)($_POST['nombre_completo'] ?? ''));
            $email          = strtolower(trim((string)($_POST['email'] ?? '')));
            $password       = (string)($_POST['password'] ?? '');
            $passwordConf   = (string)($_POST['password_confirm'] ?? '');

            $rutNormalizado = validarRutModulo11($rutRaw);

            if (mb_strlen($nombreCompleto) < 3) {
                throw new InvalidArgumentException('Ingrese el nombre completo del Contador titular.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo electrónico ingresado no es válido.');
            }
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
            }
            if ($password !== $passwordConf) {
                throw new InvalidArgumentException('Las contraseñas ingresadas no coinciden.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmtInsert = $pdo->prepare("
                INSERT INTO usuarios (rut, nombre_completo, email, password_hash, rol, activo, creado_en)
                VALUES (:rut, :nombre, :email, :hash, 'CONTADOR', 1, NOW())
            ");
            $stmtInsert->execute([
                ':rut'    => $rutNormalizado,
                ':nombre' => mb_substr($nombreCompleto, 0, 150),
                ':email'  => mb_substr($email, 0, 150),
                ':hash'   => $hash,
            ]);

            $nuevoUsuarioId = (int)$pdo->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $nuevoUsuarioId;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['flash_exito'] = 'Cuenta de Contador titular creada correctamente. Registre ahora su primera empresa cliente.';

            header('Location: empresas.php');
            exit;
        }

        // Flujo normal de Login
        $identificador = trim((string)($_POST['identificador'] ?? ''));
        $password      = (string)($_POST['password'] ?? '');

        if ($identificador === '' || $password === '') {
            throw new InvalidArgumentException('Ingrese su correo o RUT y su contraseña.');
        }

        $rutBusqueda = preg_replace('/[^0-9kK]/', '', $identificador);
        if (strlen($rutBusqueda) >= 7) {
            $rutBusqueda = substr($rutBusqueda, 0, -1) . '-' . strtoupper(substr($rutBusqueda, -1));
        }

        $stmtUser = $pdo->prepare("
            SELECT id, rut, nombre_completo, email, password_hash, activo
            FROM usuarios
            WHERE email = :email OR rut = :rut
            LIMIT 1
        ");
        $stmtUser->execute([
            ':email' => strtolower($identificador),
            ':rut'   => $rutBusqueda,
        ]);
        $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$usuario || !password_verify($password, (string)$usuario['password_hash'])) {
            throw new RuntimeException('Credenciales incorrectas.');
        }

        if ((int)$usuario['activo'] !== 1) {
            throw new RuntimeException('La cuenta del Contador está inactiva.');
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modoSetupInicial ? 'Configuración Inicial' : 'Acceso Contador' ?> | Contabilidad Chile SII</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
        }
        .login-box {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: 10px;
            padding: 28px 32px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25);
        }
        .login-header {
            text-align: center;
            margin-bottom: 22px;
        }
        .login-header h1 {
            font-size: 20px;
            color: #0f172a;
            margin-top: 8px;
        }
        .login-header p {
            color: #64748b;
            font-size: 13px;
            margin-top: 4px;
        }
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-box">
        <div class="login-header">
            <span class="brand-badge">SISTEMA CONTABLE MULTI-EMPRESAS</span>
            <h1><?= $modoSetupInicial ? 'Configuración Inicial' : 'Sistema Contable Multi-Empresa' ?></h1>
            <p>
                <?= $modoSetupInicial
                    ? 'Registro Contador'
                    : 'Ingrese con sus credenciales de Contador titular' ?>
            </p>
        </div>

        <?php if ($mensajeInfo !== null && $error === null): ?>
            <div class="alerta alerta-exito"><?= e($mensajeInfo) ?></div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="alerta alerta-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($modoSetupInicial): ?>
            <form method="POST" action="index.php" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="setup_inicial">

                <div class="campo">
                    <label for="rut">RUT Contador Titular</label>
                    <input type="text" id="rut" name="rut" placeholder="Ej: 12.345.678-5" required
                            value="<?= e($_POST['rut'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="nombre_completo">Nombre Completo</label>
                    <input type="text" id="nombre_completo" name="nombre_completo" placeholder="Nombre y Apellidos" required
                            value="<?= e($_POST['nombre_completo'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" id="email" name="email" placeholder="contador@estudio.cl" required
                            value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="password">Contraseña (mínimo 8 caracteres)</label>
                    <input type="password" id="password" name="password" minlength="8" required>
                </div>

                <div class="campo">
                    <label for="password_confirm">Confirmar Contraseña</label>
                    <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-primario" style="width: 100%; margin-top: 6px;">
                    Crear Cuenta y Comenzar
                </button>
            </form>
        <?php else: ?>
            <form method="POST" action="index.php" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="login">

                <div class="campo">
                    <label for="identificador">Correo Electrónico o RUT</label>
                    <input type="text" id="identificador" name="identificador" placeholder="contador@estudio.cl o 12.345.678-5" required
                            value="<?= e($_POST['identificador'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit" class="btn btn-primario" style="width: 100%; margin-top: 6px;">
                    Ingresar al Sistema
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>