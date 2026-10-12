<?php
declare(strict_types=1);

/**
 * public/views/admin/dashboard.php
 * NiXon.fear — Panel Game Master (Fase A1 Parcial II, backoffice admin).
 *
 * La sirve AdminNotesController (src/Controllers/AdminNotesController.php)
 * vía require, con estas variables ya disponibles (todas opcionales):
 *   $notes (array), $q (string), $level (todos|level1|level2),
 *   $category (todas|pistas_safe|terminal|documentos|libreta),
 *   $filterEmail (string, filtro por correo de jugador),
 *   $sort (asc|desc), $limit (int),
 *   $email (?string, admin en sesión), $isAuthenticated (?bool).
 *
 * Archivo separado del panel jugador (sin ?vista=gm). El gate admin vive
 * en el controlador/entry (requireRole + 403); aquí solo se renderiza.
 * Todo el texto se escapa en esta vista, nunca en el modelo.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!isset($isAuthenticated)) {
    $isAuthenticated = !empty($_SESSION['auth']) && !empty($_SESSION['user_id'])
        && (($_SESSION['user_rol'] ?? '') === 'admin');
}
if (!isset($email)) {
    $email = $_SESSION['user_email'] ?? null;
}

$q = isset($q) ? trim((string) $q) : trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q, 'UTF-8') > 50) {
    $q = mb_substr($q, 0, 50, 'UTF-8');
}
$allowedLevels = ['todos', 'level1', 'level2'];
$level = isset($level) ? (string) $level : (string) ($_GET['level'] ?? 'todos');
if (!in_array($level, $allowedLevels, true)) {
    $level = 'todos';
}
$allowedCategories = ['todas', 'pistas_safe', 'terminal', 'documentos', 'libreta'];
$category = isset($category) ? (string) $category : (string) ($_GET['category'] ?? 'todas');
if (!in_array($category, $allowedCategories, true)) {
    $category = 'todas';
}
$filterEmail = isset($filterEmail) ? strtolower(trim((string) $filterEmail)) : strtolower(trim((string) ($_GET['email'] ?? '')));
if (mb_strlen($filterEmail, 'UTF-8') > 254) {
    $filterEmail = mb_substr($filterEmail, 0, 254, 'UTF-8');
}
if ($filterEmail !== '' && filter_var($filterEmail, FILTER_VALIDATE_EMAIL) === false) {
    $filterEmail = '';
}
$sort = isset($sort) ? (string) $sort : (string) ($_GET['sort'] ?? 'asc');
if ($sort !== 'asc' && $sort !== 'desc') {
    $sort = 'asc';
}
if (!isset($notes) || !is_array($notes)) {
    $notes = [];
}

// Rutas: el controlador (public/admin/notes.php) inyecta $searchEndpoint /
// $cssHref / $loginHref / $welcomeHref / $panelSelf relativos a public/admin/.
// HTMX apunta SIEMPRE al panel ($panelSelf): el controlador devuelve el
// parcial ante HX-Request y hx-push-url refleja notes.php?... (reproducible).
$searchEndpoint = $searchEndpoint ?? '../api/admin_notes_search.php';
$updateEndpoint = $updateEndpoint ?? '../api/admin_notes_update.php';
$deleteEndpoint = $deleteEndpoint ?? '../api/admin_notes_delete.php';
$auditLimit = isset($auditLimit) ? max(1, min(100, (int) $auditLimit)) : 20;
$cssHref = $cssHref ?? '../assets/css/nixon-retro.css';
$loginHref = $loginHref ?? '../login.php';
$welcomeHref = $welcomeHref ?? '../welcome.php';
$panelSelf = $panelSelf ?? basename($_SERVER['PHP_SELF'] ?? 'notes.php');
$htmxCdn = 'https://unpkg.com/htmx.org@2.0.11/dist/htmx.min.js';
$htmxFallback = $htmxFallback ?? '../assets/js/htmx.min.js';

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$csrfToken = $csrfToken ?? (string) ($_SESSION['csrf_token'] ?? '');
$csrfHeaders = json_encode(['X-CSRF-Token' => $csrfToken], JSON_UNESCAPED_SLASHES);

$isDirectAccess = isset($_SERVER['SCRIPT_FILENAME'])
    ? realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
    : (basename((string) ($_SERVER['PHP_SELF'] ?? '')) === basename(__FILE__));
if (!$isAuthenticated && $isDirectAccess) {
    header('Location: ' . $loginHref);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($csrfToken) ?>">
<title>NIXON.FEAR // PANEL GAME MASTER</title>
<link href="<?= $e($cssHref) ?>" rel="stylesheet">
<script src="<?= $e($htmxCdn) ?>"></script>
<script>
  if (typeof window.htmx === 'undefined') {
    var _htmx = document.createElement('script');
    _htmx.src = <?= json_encode($htmxFallback, JSON_UNESCAPED_SLASHES) ?>;
    _htmx.defer = true;
    document.head.appendChild(_htmx);
  }
</script>
</head>
<body class="crt-body">
<div class="crt-overlay"></div>

<div data-flicker style="padding:24px; max-width:900px; margin:0 auto;">
  <div class="win95-window">
    <div class="win95-titlebar">
      <span>PANEL GAME MASTER // ARCHIVERO GLOBAL</span>
      <button type="button" class="win95-close-btn" onclick="window.location.href='<?= $e($welcomeHref) ?>'">X</button>
    </div>

    <div class="win95-body">
      <?php if (!$isAuthenticated): ?>
        <p class="terminal-title" style="color:var(--terminal-yellow);">SESIÓN NO AUTORIZADA</p>
        <p class="terminal-subtext">Panel exclusivo del equipo de soporte (rol admin).</p>
        <p><a class="win95-button" style="text-decoration:none;" href="<?= $e($loginHref) ?>">[ IR AL LOGIN ]</a></p>
      <?php else: ?>
        <p class="terminal-subtext" style="margin-top:0;">GM: <?= $e($email ?? 'DESCONOCIDO') ?> — Soporte global. El correo filtra por jugador (2 pasos: email → ownerId).</p>

        <form id="form-admin-notas"
              method="get"
              action="<?= $e($panelSelf) ?>"
              hx-get="<?= $e($panelSelf) ?>"
              hx-target="#tabla-notas"
              hx-swap="outerHTML"
              hx-push-url="true"
              hx-indicator="#cargando"
              hx-headers='<?= $e($csrfHeaders) ?>'
              onsubmit="return false;">
          <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <label class="terminal-label" style="flex:2; min-width:200px;">JUGADOR (CORREO)
              <input class="win95-input" type="search" name="email" value="<?= $e($filterEmail) ?>"
                     placeholder="jugador@ejemplo.com"
                     autocomplete="off"
                     hx-get="<?= $e($panelSelf) ?>"
                     hx-target="#tabla-notas"
                     hx-swap="outerHTML"
                     hx-push-url="true"
                     hx-trigger="keyup changed delay:400ms"
                     hx-include="closest form">
            </label>
            <label class="terminal-label" style="flex:2; min-width:200px;">BUSCAR
              <input class="win95-input" type="search" name="q" value="<?= $e($q) ?>"
                     placeholder="texto de la nota..."
                     autocomplete="off"
                     hx-get="<?= $e($panelSelf) ?>"
                     hx-target="#tabla-notas"
                     hx-swap="outerHTML"
                     hx-push-url="true"
                     hx-trigger="keyup changed delay:400ms"
                     hx-include="closest form">
            </label>
            <label class="terminal-label">NIVEL
              <select class="win95-input" name="level"
                      hx-get="<?= $e($panelSelf) ?>"
                      hx-target="#tabla-notas"
                      hx-swap="outerHTML"
                      hx-push-url="true"
                      hx-trigger="change"
                      hx-include="closest form">
                <option value="todos"<?= $level === 'todos' ? ' selected' : '' ?>>todos</option>
                <option value="level1"<?= $level === 'level1' ? ' selected' : '' ?>>level1</option>
                <option value="level2"<?= $level === 'level2' ? ' selected' : '' ?>>level2</option>
              </select>
            </label>
            <label class="terminal-label">CATEGORÍA
              <select class="win95-input" name="category"
                      hx-get="<?= $e($panelSelf) ?>"
                      hx-target="#tabla-notas"
                      hx-swap="outerHTML"
                      hx-push-url="true"
                      hx-trigger="change"
                      hx-include="closest form">
                <option value="todas"<?= $category === 'todas' ? ' selected' : '' ?>>todas</option>
                <option value="pistas_safe"<?= $category === 'pistas_safe' ? ' selected' : '' ?>>pistas_safe</option>
                <option value="terminal"<?= $category === 'terminal' ? ' selected' : '' ?>>terminal</option>
                <option value="documentos"<?= $category === 'documentos' ? ' selected' : '' ?>>documentos</option>
                <option value="libreta"<?= $category === 'libreta' ? ' selected' : '' ?>>libreta</option>
              </select>
            </label>
            <label class="terminal-label">ORDEN
              <select class="win95-input" name="sort"
                      hx-get="<?= $e($panelSelf) ?>"
                      hx-target="#tabla-notas"
                      hx-swap="outerHTML"
                      hx-push-url="true"
                      hx-trigger="change"
                      hx-include="closest form">
                <option value="asc"<?= $sort === 'asc' ? ' selected' : '' ?>>asc</option>
                <option value="desc"<?= $sort === 'desc' ? ' selected' : '' ?>>desc</option>
              </select>
            </label>
          </div>

          <div id="cargando" class="htmx-indicator" role="status" aria-live="polite">
            <span class="terminal-status-blink">▓ BUSCANDO EN EL ARCHIVERO GLOBAL...</span>
          </div>
          <noscript>
            <p style="margin:10px 0 0;"><button class="win95-button" type="submit">[ BUSCAR ]</button></p>
          </noscript>
        </form>

        <hr class="win95-divider">

        <?php require __DIR__ . '/_tabla.php'; ?>

        <p id="gm-status" class="terminal-subtext gm-status" role="status" aria-live="polite"></p>

        <div class="win95-modal-actions">
          <button type="button" class="win95-button" onclick="window.location.href='<?= $e($welcomeHref) ?>'">[ VOLVER AL TERMINAL ]</button>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isAuthenticated): ?>
  <div class="win95-window" style="margin-top:16px;">
    <div class="win95-titlebar"><span>BITÁCORA // AUDIT_LOG (SOLO ADMIN, FECHA DESC)</span></div>
    <div class="win95-body">
      <?php
        $gmAuditRows = [];
        if (!class_exists('App\\Models\\AuditLog') && is_file(__DIR__ . '/../../../src/Models/AuditLog.php')) {
            require_once __DIR__ . '/../../../src/Models/AuditLog.php';
        }
        if (class_exists('App\\Models\\AuditLog')) {
            try {
                $gmAuditRows = \App\Models\AuditLog::listar([], $auditLimit);
            } catch (\Throwable $e) {
                $gmAuditRows = [];
            }
        }
        if (!is_array($gmAuditRows)) {
            $gmAuditRows = [];
        }
      ?>
      <?php if ($gmAuditRows === []): ?>
        <p class="terminal-title" style="color:var(--terminal-yellow);">SIN EVENTOS</p>
        <p class="terminal-subtext" style="margin-bottom:0;">La bitácora aún no registra movimientos o no está disponible.</p>
      <?php else: ?>
        <div class="win95-panel-inset gm-audit-list">
          <?php foreach ($gmAuditRows as $gmEv): ?>
            <?php
              $gmActor = is_array($gmEv['actor'] ?? null) ? $gmEv['actor'] : [];
              $gmFecha = (string) ($gmEv['fecha'] ?? '');
              $gmEvento = (string) ($gmEv['evento'] ?? '');
              $gmResultado = (string) ($gmEv['resultado'] ?? '');
              $gmEmail = (string) ($gmActor['email'] ?? 'anonimo');
              $gmRol = (string) ($gmActor['rol'] ?? 'anonimo');
              $gmResClass = $gmResultado === 'ok' ? 'gm-res-ok' : ($gmResultado === 'denegado' ? 'gm-res-denied' : 'gm-res-err');
            ?>
            <div class="gm-audit-row">
              <span class="terminal-ghost"><?= $e($gmFecha) ?></span>
              <span class="terminal-label"><?= $e($gmEvento) ?></span>
              <span class="<?= $e($gmResClass) ?>">[<?= $e($gmResultado) ?>]</span>
              <span class="terminal-ghost"><?= $e($gmEmail) ?> (<?= $e($gmRol) ?>)</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
  // Acciones GM (solo vista): POST JSON + CSRF a las APIs admin existentes,
  // luego refresco HTMX de #tabla-notas sin recargar. Sin tocar backend.
  var GM_UPDATE_URL = <?= json_encode($updateEndpoint, JSON_UNESCAPED_SLASHES) ?>;
  var GM_DELETE_URL = <?= json_encode($deleteEndpoint, JSON_UNESCAPED_SLASHES) ?>;
  var GM_PANEL_URL = <?= json_encode($panelSelf, JSON_UNESCAPED_SLASHES) ?>;
  var GM_CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

  function gmSay(msg, isErr) {
    var el = document.getElementById('gm-status');
    if (!el) { if (msg) { alert(msg); } return; }
    el.textContent = msg || '';
    el.style.color = isErr ? 'var(--terminal-red)' : '';
  }

  async function gmPost(url, payload) {
    var res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': GM_CSRF },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });
    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    return { ok: res.ok, status: res.status, data: data };
  }

  function gmRefresh() {
    var form = document.getElementById('form-admin-notas');
    if (form && typeof window.htmx !== 'undefined' && htmx.ajax) {
      var qs = new URLSearchParams(new FormData(form)).toString();
      htmx.ajax('GET', GM_PANEL_URL + (qs ? '?' + qs : ''), { target: '#tabla-notas', swap: 'outerHTML' });
      return;
    }
    window.location.reload();
  }

  async function gmCorregir(btn) {
    var id = btn && btn.getAttribute ? (btn.getAttribute('data-note-id') || '') : '';
    var actual = btn && btn.getAttribute ? (btn.getAttribute('data-note-text') || '') : '';
    if (!/^[a-f0-9]{24}$/i.test(id)) { gmSay('IDENTIFICADOR INVÁLIDO.', true); return false; }
    var nuevo = window.prompt('CORREGIR NOTA (1..50 caracteres):', actual);
    if (nuevo === null) { return false; }
    nuevo = nuevo.trim();
    if (nuevo === '' || nuevo.length > 50) { gmSay('LA NOTA DEBE TENER ENTRE 1 Y 50 CARACTERES.', true); return false; }
    gmSay('CORRIGIENDO NOTA...');
    try {
      var r = await gmPost(GM_UPDATE_URL, { id: id, note_text: nuevo, csrf_token: GM_CSRF });
      var msg = (r.data && r.data.message) ? r.data.message : (r.ok ? 'NOTA ACTUALIZADA.' : 'NO SE PUDO CORREGIR.');
      gmSay(msg, !r.ok);
    } catch (e) {
      gmSay('ERROR DE RED AL CORREGIR.', true);
    }
    gmRefresh();
    return false;
  }

  async function gmEliminar(btn) {
    var id = btn && btn.getAttribute ? (btn.getAttribute('data-note-id') || '') : '';
    if (!/^[a-f0-9]{24}$/i.test(id)) { gmSay('IDENTIFICADOR INVÁLIDO.', true); return false; }
    if (!window.confirm('¿ELIMINAR ESTA NOTA DEL ARCHIVERO GLOBAL?')) { return false; }
    gmSay('ELIMINANDO NOTA...');
    try {
      var r = await gmPost(GM_DELETE_URL, { id: id, csrf_token: GM_CSRF });
      var msg = (r.data && r.data.message) ? r.data.message : (r.ok ? 'NOTA ELIMINADA.' : 'NO SE PUDO ELIMINAR.');
      gmSay(msg, !r.ok);
    } catch (e) {
      gmSay('ERROR DE RED AL ELIMINAR.', true);
    }
    gmRefresh();
    return false;
  }
</script>

<script src="../js/core/flicker.js" defer></script>
</body>
</html>
