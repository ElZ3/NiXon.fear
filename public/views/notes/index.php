<?php
declare(strict_types=1);

/**
 * public/views/notes/index.php
 * NiXon.fear — Panel de Notas MVC + HTMX 2.0.11 (solo frontend).
 *
 * La sirve NotesController (src/Controllers/NotesController.php) vía require,
 * con estas variables ya disponibles (todas opcionales, con defecto seguro):
 *   $notes (array), $q (string), $level (todos|level1|level2),
 *   $category (todas|pistas_safe|terminal|documentos|libreta),
 *   $sort (asc|desc), $email (?string), $isAuthenticated (?bool).
 *
 * También funciona por acceso directo: arranca sesión, exige auth (muestra
 * enlace a login si no hay) y toma los filtros de $_GET como estado inicial.
 * NO fija user_id aquí: lo resuelve el controlador / api/notes_search.php.
 */

// --- Sesión solo si nadie la arrancó (el controlador suele hacerlo antes).
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// --- Autenticación: respeta la que inyecte el controlador; si no, deduce.
if (!isset($isAuthenticated)) {
    $isAuthenticated = !empty($_SESSION['auth']) && !empty($_SESSION['user_id']);
}
if (!isset($email)) {
    $email = $_SESSION['user_email'] ?? null;
}

// --- Estado inicial de filtros (controlador > $_GET > defecto).
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
$sort = isset($sort) ? (string) $sort : (string) ($_GET['sort'] ?? 'asc');
if ($sort !== 'asc' && $sort !== 'desc') {
    $sort = 'asc';
}
if (!isset($notes) || !is_array($notes)) {
    $notes = [];
}

// --- Rutas: el controlador (public/notes.php) inyecta $searchEndpoint /
// --- $cssHref / $loginHref / $welcomeHref / $panelSelf relativos a public/.
// --- Los fallbacks '../../...' solo aplican en acceso directo a esta vista.
// --- HTMX apunta SIEMPRE al panel ($panelSelf): el controlador devuelve el
// --- fragmento ante HX-Request, y hx-push-url refleja notes.php?q=... para
// --- refresh/bookmark reproducibles (index lee $_GET).
$searchEndpoint = $searchEndpoint ?? '../../api/notes_search.php';
$cssHref = $cssHref ?? '../../assets/css/nixon-retro.css';
$loginHref = $loginHref ?? '../../login.php';
$welcomeHref = $welcomeHref ?? '../../welcome.php';
$panelSelf = $panelSelf ?? basename($_SERVER['PHP_SELF'] ?? 'notes.php');
$htmxCdn = 'https://unpkg.com/htmx.org@2.0.11/dist/htmx.min.js';
$htmxFallback = $htmxFallback ?? '../../assets/js/htmx.min.js';

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// CSRF para HTMX: la búsqueda es GET (lectura, sin CSRF), pero toda
// mutación (crear/editar/borrar vía JS) debe enviar el token. Se expone
// como meta + cabecera hx-headers y como hidden incluible en formularios.
$csrfToken = $csrfToken ?? (string) ($_SESSION['csrf_token'] ?? '');
$csrfHeaders = json_encode(['X-CSRF-Token' => $csrfToken], JSON_UNESCAPED_SLASHES);

// --- Acceso directo sin sesión: redirige al login en vez de solo mostrar
// --- el enlace. El controlador (NotesController::index) ya autentica antes
// --- del require e inyecta $isAuthenticated=true, así que el flujo normal
// --- no se ve afectado. Se conserva $isAuthenticated para la despedida
// --- (bloque unauthorized de la plantilla).
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
<title>NIXON.FEAR // ARCHIVERO DE NOTAS</title>
<link href="<?= $e($cssHref) ?>" rel="stylesheet">
<script src="<?= $e($htmxCdn) ?>"></script>
<script>
  // Fallback local si el CDN falla (se sirve ../../assets/js/htmx.min.js
  // cuando el despliegue lo incluye; si no existe, el panel sigue
  // funcionando por envío GET clásico del formulario).
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
      <span>ARCHIVERO DE NOTAS // TERMINAL DE CONSULTA</span>
      <button type="button" class="win95-close-btn" onclick="window.location.href='<?= $e($welcomeHref) ?>'">X</button>
    </div>

    <div class="win95-body">
      <?php if (!$isAuthenticated): ?>
        <p class="terminal-title" style="color:var(--terminal-yellow);">SESIÓN NO AUTORIZADA</p>
        <p class="terminal-subtext">Debe identificarse para consultar el archivero.</p>
        <p><a class="win95-button" style="text-decoration:none;" href="<?= $e($loginHref) ?>">[ IR AL LOGIN ]</a></p>
      <?php else: ?>
        <p class="terminal-subtext" style="margin-top:0;">AGENTE: <?= $e($email ?? 'DESCONOCIDO') ?> — Escriba para filtrar sin recargar. La URL refleja los filtros.</p>

        <form id="form-notas"
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
            <span class="terminal-status-blink">▓ BUSCANDO EN EL ARCHIVERO...</span>
          </div>
          <noscript>
            <p style="margin:10px 0 0;"><button class="win95-button" type="submit">[ BUSCAR ]</button></p>
          </noscript>
        </form>

        <hr class="win95-divider">

        <?php require __DIR__ . '/_tabla_notas.php'; ?>

        <div class="win95-modal-actions">
          <button type="button" class="win95-button" onclick="window.location.href='<?= $e($welcomeHref) ?>'">[ VOLVER AL TERMINAL ]</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="../../js/core/flicker.js" defer></script>
</body>
</html>
