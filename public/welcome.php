<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();
$auth->requireAuth('login.php');

// Cierre de sesión: se procesa aquí mismo (form del modal de confirmación).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if ($auth->validateCsrf($_POST['csrf_token'] ?? null)) {
        $auth->logout();
    }
    header('Location: login.php');
    exit;
}

$csrfToken = $auth->getCsrfToken();
$email = $auth->getCurrentEmail();
$progress = $auth->getProgress(); // ej. ['level1' => ['step' => 3, 'completed' => false]]
$hasProgress = !empty($progress);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NIXON.FEAR // INCIDENT RESPONSE TERMINAL</title>
<link href="assets/css/nixon-retro.css" rel="stylesheet">
</head>
<body class="crt-body">
<div class="crt-overlay"></div>

<div data-flicker style="padding:24px;">
  <div class="win95-window">
    <div class="win95-titlebar">
      <span>NIXON.FEAR // INCIDENT RESPONSE TERMINAL</span>
    </div>

    <div class="win95-body">
      <div style="display:flex; gap:24px; flex-wrap:wrap;">

        <div style="flex:2; min-width:280px;">

          <h1 class="terminal-status-blink" style="margin:0 0 4px;">ESTADO DEL SISTEMA: NOMINAL</h1>
          <p class="terminal-subtext">No se detectan errores críticos.</p>
          <p class="terminal-ghost">La estabilidad del sistema no implica estabilidad del entorno.</p>

          <div class="vance-block" style="margin:16px 0;">
            <p style="margin:0;">AGENTE: <?= htmlspecialchars($email ?? 'DESCONOCIDO') ?></p>
            <p style="margin:0;">UNIDAD DE INVESTIGACIONES ESPECIALES</p>
            <p style="margin:0;">PROGRESO GUARDADO: <?= $hasProgress ? 'SÍ' : 'SIN DATOS AÚN' ?></p>
          </div>

          <div style="display:flex; flex-direction:column; gap:12px; max-width:420px; margin-top:20px;">

            <button type="button" class="win95-button" onclick="window.location.href='levels.php'">
              [ NUEVA PARTIDA ]
              <span class="btn-subtext">=&gt; INICIAR UN NUEVO INCIDENTE</span>
            </button>

            <button type="button" class="win95-button" onclick="window.location.href='levels.php'">
              [ CONTINUAR PARTIDA ]
              <span class="btn-subtext">=&gt; RECUPERAR PROGRESO DESDE LA BASE DE DATOS</span>
            </button>

            <button type="button" class="win95-button" onclick="openModal('modal-config')">
              [ CONFIGURAR ]
              <span class="btn-subtext">=&gt; AJUSTAR PARÁMETROS DEL TERMINAL</span>
            </button>

            <button type="button" class="win95-button win95-button--danger" onclick="openModal('modal-logout')">
              [ CERRAR SESIÓN ]
              <span class="btn-subtext">=&gt; SELLAR EVIDENCIA Y SALIR</span>
            </button>

          </div>
        </div>

        <div style="flex:1; min-width:220px;">
          <div class="character-sprite-container" onclick="openModal('modal-soon')" role="button" tabindex="0"
               onkeydown="if(event.key==='Enter') openModal('modal-soon')">
            <img src="assets/images/player_detective.png" alt="Agente detective" class="sprite-idle"
                 style="max-width:120px; width:100%;">
            <span class="character-sprite-label">PERFIL DE AGENTE</span>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Modal: Configuración (PRÓXIMAMENTE) -->
<div class="win95-modal-overlay" id="modal-soon">
  <div class="win95-window win95-modal">
    <div class="win95-titlebar">
      <span>PERFIL DE AGENTE</span>
      <button type="button" class="win95-close-btn" onclick="closeModal('modal-soon')">X</button>
    </div>
    <div class="win95-body">
      <p class="terminal-title" style="color:var(--terminal-yellow);">PRÓXIMAMENTE</p>
      <p class="terminal-subtext">CARACTERÍSTICA BETA — el perfil de agente aún está en construcción.</p>
      <div class="win95-modal-actions">
        <button type="button" class="win95-button" onclick="closeModal('modal-soon')">[ CERRAR ]</button>
      </div>
    </div>
  </div>
</div>

<div class="win95-modal-overlay" id="modal-config">
  <div class="win95-window win95-modal">
    <div class="win95-titlebar">
      <span>CONFIGURACIÓN</span>
      <button type="button" class="win95-close-btn" onclick="closeModal('modal-config')">X</button>
    </div>
    <div class="win95-body">
      <p class="terminal-title" style="color:var(--terminal-yellow);">PRÓXIMAMENTE</p>
      <p class="terminal-subtext">CARACTERÍSTICA BETA — los ajustes del terminal aún no están disponibles.</p>
      <div class="win95-modal-actions">
        <button type="button" class="win95-button" onclick="closeModal('modal-config')">[ CERRAR ]</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Confirmar cierre de sesión -->
<div class="win95-modal-overlay" id="modal-logout">
  <div class="win95-window win95-modal">
    <div class="win95-titlebar">
      <span>CONFIRMAR SALIDA</span>
      <button type="button" class="win95-close-btn" onclick="closeModal('modal-logout')">X</button>
    </div>
    <div class="win95-body">
      <p class="terminal-warning" style="margin-top:0;">¿SEGURO QUE DESEA CERRAR SESIÓN?</p>
      <p class="terminal-subtext">El progreso se ha guardado de forma segura en la base de datos.</p>

      <form method="post" action="welcome.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="logout">
        <div class="win95-modal-actions">
          <button type="button" class="win95-button" onclick="closeModal('modal-logout')">[ CANCELAR ]</button>
          <button type="submit" class="win95-button win95-button--danger">[ SELLAR Y SALIR ]</button>
        </div>
      </form>
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
  // Cierra el modal abierto al hacer clic fuera de la ventana.
  document.querySelectorAll('.win95-modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('is-open');
    });
  });
</script>
<script src="js/core/flicker.js" defer></script>
</body>
</html>