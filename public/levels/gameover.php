<?php
$reason = $_GET['reason'] ?? 'unknown';
$text = ($reason === 'time') ? 'EL TIEMPO SE AGOTÓ.' : 'COLAPSO MENTAL DETECTADO.';
?>
<body style="background: #110000; color: red; font-family: monospace; text-align: center; padding-top: 20%;">
    <h1>AGENTE PERDIDO</h1>
    <p><?= $text ?></p>
    <a href="levels/level1.php" style="color: red;">[ REINICIAR ]</a>
</body>