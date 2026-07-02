<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL_BASE . 'index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $error = 'La sesión del formulario expiró, intente nuevamente.';
    } else {
        $usuario  = trim($_POST['usuario'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($usuario === '' || $password === '') {
            $error = 'Ingrese usuario y contraseña.';
        } else {
            [$ok, $msg] = intentarLogin($usuario, $password);
            if ($ok) {
                $next = $_GET['next'] ?? (APP_URL_BASE . 'index.php');
                header('Location: ' . (strpos($next, 'login.php') !== false ? APP_URL_BASE . 'index.php' : $next));
                exit;
            }
            $error = $msg;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ingresar · <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= APP_URL_BASE ?>assets/css/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
<div class="login-wrap">
    <div class="login-visual">
        <svg class="seismic-lines" viewBox="0 0 500 500" preserveAspectRatio="none">
            <polyline points="0,120 60,120 80,60 100,180 120,120 500,120" fill="none" stroke="#f0a63a" stroke-width="2"/>
            <polyline points="0,260 90,260 110,220 130,320 150,260 500,260" fill="none" stroke="#4d63b0" stroke-width="2"/>
            <polyline points="0,400 70,400 95,350 115,440 140,400 500,400" fill="none" stroke="#4d63b0" stroke-width="1.5"/>
        </svg>
        <div class="flex items-center gap-12">
            <div class="mark"><i class="bi bi-buildings"></i></div>
            <div>
                <strong style="display:block;font-family:var(--font-display);font-size:16px;">Post-Sismo</strong>
                <span style="font-size:12px;color:#9fabd6;">Instrumento de inspección estructural</span>
            </div>
        </div>
        <div class="quote">
            Cada edificación evaluada es una decisión que protege vidas. <span>Registre, verifique y clasifique</span> con el mismo protocolo, en todo momento.
        </div>
        <div style="font-size:12px;color:#8f9ac2;">© <?= date('Y') ?> · Sistema de Inspección de Edificaciones Afectadas por Sismos</div>
    </div>

    <div class="login-panel">
        <div class="login-box">
            <div class="mark"><i class="bi bi-buildings"></i></div>
            <h1 style="font-size:22px;">Ingresar al sistema</h1>
            <p class="text-muted text-sm" style="margin:6px 0 22px;">Use sus credenciales institucionales para continuar.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i><div><?= e($error) ?></div></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <div class="field" style="margin-bottom:16px;">
                    <label class="req">Usuario o correo</label>
                    <input type="text" name="usuario" class="form-control" required autofocus value="<?= e($_POST['usuario'] ?? '') ?>">
                </div>
                <div class="field" style="margin-bottom:20px;position:relative;">
                    <label class="req">Contraseña</label>
                    <input id="password-input" type="password" name="password" class="form-control" required>
                    <button id="toggle-password" type="button" class="password-toggle" aria-label="Mostrar contraseña" style="position:absolute;right:12px;top:38px;border:none;background:none;color:var(--gris-700);cursor:pointer;font-size:18px;">
                        <i class="bi bi-eye-fill"></i>
                    </button>
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">
                    <i class="bi bi-box-arrow-in-right"></i> Ingresar
                </button>
            </form>

            <div class="text-sm text-muted" style="margin-top:22px;padding-top:18px;border-top:1px solid var(--gris-300);">
                Usuario demo administrador: <code>admin</code> / <code>Admin#2026</code>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    const pwd = document.getElementById('password-input');
    const toggle = document.getElementById('toggle-password');
    if (pwd && toggle) {
        toggle.addEventListener('click', function() {
            const type = pwd.type === 'password' ? 'text' : 'password';
            pwd.type = type;
            this.innerHTML = type === 'password' ? '<i class="bi bi-eye-fill"></i>' : '<i class="bi bi-eye-slash-fill"></i>';
            this.setAttribute('aria-label', type === 'password' ? 'Mostrar contraseña' : 'Ocultar contraseña');
        });
    }
})();
</script>
