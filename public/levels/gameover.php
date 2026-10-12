<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();
$auth->requireAuth('../login.php');

// Flag one-time fijado por reset_level.php (flujo legítimo
// finishCollapse → reset_level → gameover). Directo sin perder → levels.php.
if (empty($_SESSION['level1_gameover'])) {
    header('Location: ../levels.php');
    exit;
}
unset($_SESSION['level1_gameover']);

// Sanitización estricta del motivo (allowlist + escape en la vista).
$reason = $_GET['reason'] ?? 'unknown';
if (!in_array($reason, ['time', 'paranoia'], true)) {
    $reason = ($reason === 'time') ? 'time' : 'paranoia';
}
$text = ($reason === 'time') ? 'EL TIEMPO SE AGOTÓ.' : 'COLAPSO MENTAL DETECTADO.';
?>
<body style="background: #110000; color: red; font-family: monospace; text-align: center; padding-top: 20%;">
    <h1>AGENTE PERDIDO</h1>
    <p><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></p>
    <p>EL ARCHIVO LO ESPERA PARA UN NUEVO INTENTO.</p>
    <a href="../levels.php" style="color: red;">[ VOLVER A EVIDENCIAS ]</a>
</body>
