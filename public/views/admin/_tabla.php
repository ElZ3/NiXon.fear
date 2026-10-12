<?php
declare(strict_types=1);

/**
 * public/views/admin/_tabla.php
 * Parcial HTMX del panel Game Master: grilla global de notas + contador OOB.
 *
 * Lo requiere el dashboard GM (render inicial) y lo devuelve
 * AdminNotesController::search() ante HX-Request (hx-swap="outerHTML"
 * sobre #tabla-notas). Variables de entrada (todas opcionales):
 *   $notes (array<int, array{...}>), $sort (asc|desc),
 *   $filterEmail (?string, pista de dueño cuando la nota no trae email),
 *   $updateEndpoint/?$deleteEndpoint (?string, URLs POST JSON con CSRF),
 *   $isAuthenticated (?bool, solo admin ve acciones).
 * Sin <html>/<head>. Todo el texto se escapa aquí, nunca en el modelo.
 * Muestra dueño (email si la nota lo trae, si no user_id) + botones
 * CORREGIR/ELIMINAR (POST JSON + CSRF vía gmCorregir/gmEliminar del
 * dashboard; solo admin). Emite UNA sola raíz <div id="tabla-notas">;
 * en vacío delega al parcial de estados compartido (evita IDs duplicados).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!isset($notes) || !is_array($notes)) {
    $notes = [];
}
$sort = (isset($sort) && $sort === 'desc') ? 'desc' : 'asc';

// Solo admin ve las acciones (defensa en vista; el gate real vive en el
// controlador/API con requireRole + CSRF). En search() HTMX el controlador
// ya denegó a no-admin antes de llegar aquí.
$isAdminView = isset($isAuthenticated)
    ? (bool) $isAuthenticated
    : (!empty($_SESSION['user_rol']) && ($_SESSION['user_rol'] === 'admin'));

// Pista de dueño cuando se filtra por correo: las notas solo traen user_id,
// así que si la nota no trae email y el GM filtró por uno, se muestra como
// referencia (no sustituye al user_id real).
$filterEmailView = isset($filterEmail) ? strtolower(trim((string) $filterEmail)) : strtolower(trim((string) ($_GET['email'] ?? '')));

$updateEndpoint = $updateEndpoint ?? '../api/admin_notes_update.php';
$deleteEndpoint = $deleteEndpoint ?? '../api/admin_notes_delete.php';

$rows = array_values($notes);
usort($rows, static function (array $a, array $b) use ($sort): int {
    $ta = (string) ($a['created_at'] ?? '');
    $tb = (string) ($b['created_at'] ?? '');
    $cmp = $ta <=> $tb;
    return $sort === 'desc' ? -$cmp : $cmp;
});
$count = count($rows);

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<?php if ($count === 0): ?>
  <?php $htmxState = 'empty'; $htmxMessage = ''; $loginHref = $loginHref ?? '../login.php'; require __DIR__ . '/../notes/_respuesta_htmx.php'; ?>
<?php else: ?>
<div id="tabla-notas">
  <div class="win95-panel-inset gm-table" style="padding:10px;">
      <?php foreach ($rows as $note): ?>
        <?php
          $ownerEmail = $note['owner_email'] ?? ($note['user_email'] ?? ($note['email'] ?? null));
          $ownerEmail = is_string($ownerEmail) && trim($ownerEmail) !== '' ? trim($ownerEmail) : null;
          $ownerId = (string) ($note['user_id'] ?? '?');
          $noteId = (string) ($note['id'] ?? '');
        ?>
        <article class="vance-block gm-row" style="margin:0 0 10px; padding:8px 10px;" data-note-id="<?= $e($noteId) ?>">
          <p style="margin:0;" class="terminal-label"><?= $e($note['note_text'] ?? '') ?></p>
          <p class="terminal-ghost gm-owner" style="margin:4px 0 0;">
            DUEÑO: <?= $ownerEmail !== null ? $e($ownerEmail) . ' (' . $e($ownerId) . ')' : $e($ownerId) ?><?php if ($ownerEmail === null && $filterEmailView !== ''): ?> // FILTRO: <?= $e($filterEmailView) ?><?php endif; ?>
            // NIVEL: <?= $e($note['level'] ?? '?') ?>
            // CATEGORÍA: <?= $e($note['category'] ?? '?') ?>
            // IMPACTO: <?= $e($note['impact'] ?? 'normal') ?>
            <?php if (!empty($note['created_at'])): ?>
              // <?= $e($note['created_at']) ?>
            <?php endif; ?>
          </p>
          <?php if ($isAdminView && $noteId !== '' && preg_match('/^[a-f0-9]{24}$/i', $noteId) === 1): ?>
            <div class="gm-row-actions">
              <button type="button" class="win95-button win95-button--small"
                      data-note-id="<?= $e($noteId) ?>"
                      data-note-text="<?= $e($note['note_text'] ?? '') ?>"
                      onclick="return gmCorregir(this)">[ CORREGIR ]</button>
              <button type="button" class="win95-button win95-button--small win95-button--danger"
                      data-note-id="<?= $e($noteId) ?>"
                      onclick="return gmEliminar(this)">[ ELIMINAR ]</button>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <span id="contador-notas" hx-swap-oob="true">NOTAS: <?= $count ?></span>
</div>
<?php endif; ?>
