<?php
declare(strict_types=1);

/**
 * public/views/notes/_respuesta_htmx.php
 * Parcial HTMX de estados (estética terminal win95, sin <html>/<head>).
 *
 * Entrada:
 *   $htmxState ('empty'|'error'|'unauthorized', defecto 'empty'),
 *   $htmxMessage (?string, solo para 'error'; se escapa aquí),
 *   $loginHref (?string, defecto '../../login.php'),
 *   $count (int, para el contador OOB; defecto 0).
 * Siempre emite raíz <div id="tabla-notas"> para que hx-swap="outerHTML"
 * la sustituya, más <span id="contador-notas" hx-swap-oob="true">.
 */

$htmxState = $htmxState ?? 'empty';
if (!in_array($htmxState, ['empty', 'error', 'unauthorized'], true)) {
    $htmxState = 'empty';
}
$htmxMessage = isset($htmxMessage) ? trim((string) $htmxMessage) : '';
$loginHref = $loginHref ?? '../../login.php';
$count = isset($count) ? (int) $count : 0;

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<div id="tabla-notas">
  <?php if ($htmxState === 'unauthorized'): ?>
    <div class="win95-window">
      <div class="win95-titlebar"><span>ACCESO DENEGADO</span></div>
      <div class="win95-body">
        <p class="terminal-warning" style="margin-top:0;">SESIÓN NO AUTORIZADA</p>
        <p class="terminal-subtext">Su credencial ha expirado o no existe. Vuelva a identificarse.</p>
        <p><a class="win95-button" style="text-decoration:none;" href="<?= $e($loginHref) ?>">[ IR AL LOGIN ]</a></p>
      </div>
    </div>
  <?php elseif ($htmxState === 'error'): ?>
    <div class="win95-window">
      <div class="win95-titlebar"><span>ERROR DE CONSULTA</span></div>
      <div class="win95-body">
        <p class="terminal-error">FILTRO INVÁLIDO<?= $htmxMessage !== '' ? ': ' . $e($htmxMessage) : '.' ?></p>
        <p class="terminal-subtext">Revise nivel, categoría y texto (máx. 50 caracteres).</p>
      </div>
    </div>
  <?php else: ?>
    <div class="win95-window">
      <div class="win95-titlebar"><span>ARCHIVERO // CONSULTA</span></div>
      <div class="win95-body">
        <p class="terminal-title" style="color:var(--terminal-yellow);">SIN DATOS</p>
        <p class="terminal-subtext">Ninguna nota coincide con esos filtros. Ajuste la búsqueda.</p>
      </div>
    </div>
  <?php endif; ?>
  <span id="contador-notas" hx-swap-oob="true">NOTAS: <?= $count ?></span>
</div>
