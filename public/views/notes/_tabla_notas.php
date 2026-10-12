<?php
declare(strict_types=1);

/**
 * public/views/notes/_tabla_notas.php
 * Parcial HTMX: grilla de notas + contador con actualización fuera de banda.
 *
 * Lo requiere index.php (render inicial) y lo devuelve api/notes_search.php
 * ante peticiones con cabecera HX-Request (hx-swap="outerHTML" sobre
 * #tabla-notas). Variables de entrada (todas opcionales):
 *   $notes (array<int, array{...}>), $sort (asc|desc),
 *   $loginHref (?string, se propaga a _respuesta_htmx en rama vacía).
 * Sin <html>/<head>. Todo el texto se escapa aquí, nunca en el modelo.
 * Emite UNA sola raíz <div id="tabla-notas"> por respuesta: en vacío
 * delega a _respuesta_htmx.php sin wrapper propio (evita IDs duplicados).
 */

if (!isset($notes) || !is_array($notes)) {
    $notes = [];
}
$sort = (isset($sort) && $sort === 'desc') ? 'desc' : 'asc';

// Orden de presentación (el modelo ya ordena por created_at; esto solo
// aplica asc/desc como respaldo sin tocar la lógica de negocio).
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
  <?php $htmxState = 'empty'; require __DIR__ . '/_respuesta_htmx.php'; ?>
<?php else: ?>
<div id="tabla-notas">
  <div class="win95-panel-inset" style="padding:10px;">
      <?php foreach ($rows as $note): ?>
        <article class="vance-block" style="margin:0 0 10px; padding:8px 10px;">
          <p style="margin:0;" class="terminal-label"><?= $e($note['note_text'] ?? '') ?></p>
          <p class="terminal-ghost" style="margin:4px 0 0;">
            NIVEL: <?= $e($note['level'] ?? '?') ?>
            // CATEGORÍA: <?= $e($note['category'] ?? '?') ?>
            // IMPACTO: <?= $e($note['impact'] ?? 'normal') ?>
            <?php if (!empty($note['created_at'])): ?>
              // <?= $e($note['created_at']) ?>
            <?php endif; ?>
          </p>
        </article>
      <?php endforeach; ?>
    </div>
  <span id="contador-notas" hx-swap-oob="true">NOTAS: <?= $count ?></span>
</div>
<?php endif; ?>
