<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();
$auth->requireAuth('../login.php');

// Gate de victoria real: exige haber completado el nivel (flag de sesión
// que fija validate_step.php al 5º paso O completed:true persistido por
// onLevelComplete → save_progress.php). Sin ambos → vuelta a level1.php.
// No se limpia el flag aquí: levels.php lo usa para las medallas.
$level1Prog = $auth->getProgress('level1');
$persistedDone = is_array($level1Prog) && ($level1Prog['completed'] ?? null) === true;
if (empty($_SESSION['level1_completed']) && !$persistedDone) {
    header('Location: level1.php');
    exit;
}
?>
<body style="background: #001100; color: lime; font-family: monospace; text-align: center; padding-top: 20%;">
    <h1>SECUENCIA COMPLETADA</h1>
    <p>DATOS RECUPERADOS EXITOSAMENTE.</p>
    <a href="../levels.php" style="color: lime;">[ VOLVER A EVIDENCIAS ]</a>
    <script>
      // Retorno automático a la vista de evidencias (gane o pierda se
      // vuelve a levels.php). El enlace manual queda como respaldo.
      setTimeout(function () { window.location.href = '../levels.php'; }, 5000);
    </script>
</body>
