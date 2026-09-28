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
    $result = $auth->register($_POST);
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
<title>NIXON.FEAR // ALTA DE NUEVO AGENTE</title>
<link href="assets/css/nixon-retro.css" rel="stylesheet">
</head>
<body class="crt-body">
<div class="crt-overlay"></div>

<div data-flicker style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
  <div class="win95-window login-window">
    <div class="win95-titlebar">
      <span>ALTA DE NUEVO AGENTE</span>
    </div>
    <div class="win95-body">

      <p class="terminal-subtext">
        Cree sus credenciales para acceder al sistema NIXON.FEAR.<br>
        Su progreso quedará vinculado a este correo en la base de datos.
      </p>

      <?php if ($error): ?>
        <div class="terminal-error" style="margin-bottom:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="register.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div style="margin-bottom:14px;">
          <label for="email" class="terminal-label">CORREO:</label>
          <input type="email" id="email" name="email" class="win95-input"
                 placeholder="agente@nixon.fear" required autofocus>
        </div>

        <div style="margin-bottom:14px;">
          <label for="password" class="terminal-label">CLAVE (8+ caracteres, letras y números):</label>
          <input type="password" id="password" name="password" class="win95-input"
                 placeholder="••••••••" minlength="8" required>
        </div>

        <div style="margin-bottom:14px;">
          <label for="password_confirm" class="terminal-label">CONFIRMAR CLAVE:</label>
          <input type="password" id="password_confirm" name="password_confirm" class="win95-input"
                 placeholder="••••••••" minlength="8" required>
        </div>

        <button type="submit" class="win95-button" style="width:100%;">[ REGISTRAR AGENTE ]</button>
      </form>

      <p class="auth-switch-link">
        ¿Ya tiene credenciales? <a href="login.php">Volver al acceso</a>
      </p>

    </div>
  </div>
</div>

<script src="js/core/flicker.js" defer></script>
</body>
</html>