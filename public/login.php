<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();

if ($auth->isAuthenticated()) {
    header('Location: welcome.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $auth->handleLogin($_POST);
    if ($result['ok']) {
        header('Location: welcome.php');
        exit;
    }
    $error = $result['message'];
}

$csrfToken = $auth->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NIXON.FEAR // TERMINAL DE ACCESO</title>
<link href="assets/css/nixon-retro.css" rel="stylesheet">
</head>
<body class="crt-body">
<div class="crt-overlay"></div>

<div data-flicker style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
  <div class="win95-window login-window">
    <div class="win95-titlebar">
      <span>NIXON.FEAR // TERMINAL DE ACCESO</span>
    </div>
    <div class="win95-body">

      <p class="terminal-warning">
        SISTEMA RESTRINGIDO.<br>
        Toda actividad queda registrada.<br>
        Toda identidad puede ser reconstruida.<br>
        Si no está autorizado para estar aquí, apague el terminal y abandone las instalaciones.
      </p>

      <pre class="terminal-status">&gt; &gt; ENLACE A BASE DE DATOS: ATLAS/MONGODB
&gt; &gt; AUDITORÍA: ACTIVA
&gt; &gt; ESTADO: <span class="terminal-status-blink">ESPERANDO CREDENCIALES</span></pre>

      <?php if ($error): ?>
        <div class="terminal-error" style="margin-top:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="off" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="mb-3" style="margin-bottom:14px;">
          <label for="email" class="terminal-label">CORREO DE AGENTE:</label>
          <input type="email" id="email" name="email" class="win95-input"
                 placeholder="agente@nixon.fear" required autofocus>
        </div>

        <div class="mb-3" style="margin-bottom:14px;">
          <label for="password" class="terminal-label">CLAVE:</label>
          <input type="password" id="password" name="password" class="win95-input"
                 placeholder="Introduzca contraseña..." required>
        </div>

        <button type="submit" class="win95-button" style="width:100%;">[ AUTORIZAR ACCESO ]</button>
      </form>

      <p class="auth-switch-link">
        ¿Agente no registrado? <a href="register.php">Crear credenciales nuevas</a>
      </p>

    </div>
  </div>
</div>

<script src="js/core/flicker.js" defer></script>
</body>
</html>