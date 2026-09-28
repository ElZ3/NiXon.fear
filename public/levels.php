<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();
$auth->requireAuth('login.php');

$progress = $auth->getProgress() ?? [];
$level1 = $progress['level1'] ?? null;
$level1Step = (int) ($level1['step'] ?? 0);
$level1Completed = (bool) ($level1['completed'] ?? false);

// Estado de medallas derivado del progreso real guardado en Mongo.
$medalStatus = function (int $stepIndex) use ($level1Step, $level1Completed): string {
    if ($level1Completed || $level1Step > $stepIndex) return 'RECUPERADA';
    if ($level1Step === $stepIndex) return 'EN CURSO';
    return 'BLOQUEADA';
};

$medalClass = function (string $status): string {
    return match ($status) {
        'RECUPERADA' => 'medal-recovered',
        'EN CURSO'   => 'medal-unregistered',
        default      => 'medal-locked',
    };
};

$medals = [
    ['label' => 'ACERTIJO 1 — DOCUMENTO INICIAL', 'status' => $medalStatus(0)],
    ['label' => 'ACERTIJO 2 — ARCHIVERO',          'status' => $medalStatus(1)],
    ['label' => 'ACERTIJO 3 — SEGUNDO DOCUMENTO',  'status' => $medalStatus(2)],
    ['label' => 'ACERTIJO 4 — COMPUTADORA',        'status' => $medalStatus(3)],
    ['label' => 'ACERTIJO 5 — CAJA FUERTE',        'status' => $medalStatus(4)],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NIXON.FEAR // ARCHIVO DE EVIDENCIAS</title>
<link href="assets/css/nixon-retro.css" rel="stylesheet">
</head>
<body class="crt-body">
<div class="crt-overlay"></div>

<div data-flicker style="padding:24px;">
  <div class="win95-window">
    <div class="win95-titlebar">
      <span>ARCHIVO DE EVIDENCIAS</span>
    </div>

    <div class="win95-body">

      <h1 class="terminal-title">ARCHIVO DE EVIDENCIAS</h1>
      <p class="terminal-subtext">INCIDENTES ABIERTOS // ACCESO POR CADENA DE PRUEBAS</p>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); gap:16px; margin-top:16px;">

        <div class="level-folder-card" onclick="openModal('modal-level1')" role="button" tabindex="0"
             onkeydown="if(event.key==='Enter') openModal('modal-level1')">
          <span class="folder-icon">🗂</span>
          <div>
            <p class="level-status" style="margin:0;">INCIDENTE 01 — LA OFICINA DESIERTA</p>
            <p class="terminal-ghost" style="margin:2px 0 0;">
              <?= $level1Completed ? 'RESUELTO' : ($level1Step > 0 ? "EN CURSO ({$level1Step}/5)" : 'EVIDENCIA DISPONIBLE') ?>
            </p>
          </div>
        </div>

        <div class="level-folder-card level-folder-card--locked">
          <span class="folder-icon">🗄</span>
          <div>
            <p class="level-status level-status--locked" style="margin:0;">INCIDENTE 02 — CLASIFICADO</p>
            <p class="terminal-ghost" style="margin:2px 0 0;">Requiere completar INCIDENTE 01.</p>
          </div>
        </div>

      </div>

      <hr class="win95-divider">

      <h2 class="terminal-title">REGISTRO DE CONDUCTA</h2>
      <p class="terminal-subtext">PATENTES DE INVESTIGACIÓN // MÉRITOS // ANOMALÍAS</p>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:12px; margin-top:12px;">
        <?php foreach ($medals as $m): ?>
          <div class="medal-slot <?= $medalClass($m['status']) ?>">
            <div class="medal-label"><?= htmlspecialchars($m['label']) ?></div>
            <div class="medal-badge">[ <?= htmlspecialchars($m['status']) ?> ]</div>
          </div>
        <?php endforeach; ?>
      </div>

      <p class="terminal-warning" style="margin-top:20px;">
        Algunas cosas que encuentre aquí no estaban diseñadas para ser encontradas.
      </p>

      <button type="button" class="win95-button" style="margin-top:8px;" onclick="window.location.href='welcome.php'">
        [ VOLVER AL TERMINAL ]
      </button>

    </div>
  </div>
</div>

<!-- Modal: detalle del Incidente 01 -->
<div class="win95-modal-overlay" id="modal-level1">
  <div class="win95-window win95-modal">
    <div class="win95-titlebar">
      <span>INCIDENTE 01 — LA OFICINA DESIERTA</span>
      <button type="button" class="win95-close-btn" onclick="closeModal('modal-level1')">X</button>
    </div>
    <div class="win95-body">
      <p class="terminal-subtext" style="margin-top:0;">
        Una muerte. Un terminal encendido. Cinco bloqueos entre usted y la verdad.
      </p>
      <p class="terminal-ghost">
        <?= $level1Step > 0 && !$level1Completed
            ? "Progreso guardado: {$level1Step}/5 pasos. Continuará donde lo dejó."
            : ($level1Completed ? 'Este incidente ya fue resuelto. Puede reingresar para revisar la escena.' : 'Sin progreso registrado todavía.') ?>
      </p>

      <div class="win95-modal-actions">
        <button type="button" class="win95-button" onclick="closeModal('modal-level1')">[ CANCELAR ]</button>
        <button type="button" class="win95-button" onclick="window.location.href='levels/level1.php'">[ JUGAR ]</button>
      </div>
    </div>
  </div>
</div>

<script>
  function openModal(id) {
    document.getElementById(id)?.classList.add('is-open');
  }
  function closeModal(id) {
    document.getElementById(id)?.classList.remove('is-open');
  }
  document.querySelectorAll('.win95-modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('is-open');
    });
  });
</script>
<script src="js/core/flicker.js" defer></script>
</body>
</html>