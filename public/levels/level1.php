<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();
$auth->requireAuth('../login.php');

// ?reset=1 reinicia explícitamente el progreso del nivel (usado por el
// botón "REINTENTAR" de gameover.php).
if (isset($_GET['reset'])) {
    unset($_SESSION['level1_step'], $_SESSION['level1_completed']);
}

$csrfToken = $auth->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NIXON.FEAR // NIVEL 1: LA OFICINA DESIERTA</title>
  <link rel="stylesheet" href="../assets/css/nixon-retro.css">
</head>
<body>

  <div id="game-container"></div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/phaser/3.80.1/phaser.min.js"></script>
  <script>
    // Puente de datos servidor -> cliente. level1.js lee esto para
    // construir sus peticiones a la API sin exponer lógica de sesión.
    window.NIXON = {
      csrfToken: <?= json_encode($csrfToken) ?>,
      apiEndpoint: '../api/validate_step.php'
    };
  </script>
  <script type="module" src="../js/levels/level1_levels.js"></script>

</body>
</html>
