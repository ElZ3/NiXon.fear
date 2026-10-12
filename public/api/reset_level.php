<?php
declare(strict_types=1);

/**
 * public/api/reset_level.php
 * NiXon.fear — Reinicia el progreso del Nivel 1 al perder:
 *   - users.progress.level1 → { step: 0, completed: false, notes: [] }
 *   - colección `notes`: deleteMany por (user_id + level)
 *   - limpia $_SESSION level1_step / level1_completed / level_notes
 *
 * Petición esperada (JSON):
 *   POST { "level": "level1", "csrf_token": "..." }
 *
 * Respuesta (JSON):
 *   { "status": "success"|"error", "message": "..." }
 *
 * Consultas Mongo solo en Modelos/AuthController (aquí solo delegación
 * + sanitización). Llamado best-effort desde triggerGameOver() antes de
 * redirigir a gameover.php. El reset real es este POST; level1.php?reset=1
 * solo limpia sesión por compatibilidad (ver comentario allí).
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_reset(string $status, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_reset('error', 'SESIÓN NO AUTORIZADA.', 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond_reset('error', 'MÉTODO NO PERMITIDO.', 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!$auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
    respond_reset('error', 'TOKEN DE SEGURIDAD INVÁLIDO.', 403);
}

// Allowlist estricta de nivel (sin interpolación directa del cliente).
$level = (string) ($payload['level'] ?? '');
if ($level !== 'level1') {
    respond_reset('error', 'NIVEL NO VÁLIDO.', 400);
}

$userId = $auth->getCurrentUserId();
if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
    respond_reset('error', 'SESIÓN NO AUTORIZADA.', 401);
}

// Rama admin-ajena: el param user_id solo lo puede usar un admin
// (jugador → 403, anónimo ya rechazado arriba con 401).
$asUserId = null;
$rawTarget = $payload['user_id'] ?? null;
if ($rawTarget !== null && trim((string) $rawTarget) !== '') {
    if (!$auth->isAdmin()) {
        respond_reset('error', 'ACCESO DENEGADO.', 403);
    }
    $asUserId = trim((string) $rawTarget);
    if (preg_match('/^[a-f0-9]{24}$/i', $asUserId) !== 1) {
        respond_reset('error', 'USUARIO DESTINO INVÁLIDO.', 400);
    }
    $userId = $asUserId;
}

// 1) Borra notas creadas en el intento (colección `notes`, por nivel).
NotesModel::clearLevelNotes($userId, 'level1');

// 2) Reinicia el progreso persistido (conserva la forma que lee levels.php).
if (!$auth->saveProgress('level1', ['step' => 0, 'completed' => false, 'notes' => []], $asUserId)) {
    respond_reset('error', 'NO SE PUDO REINICIAR EL NIVEL.', 500);
}

// 3) Limpia el espejo de sesión (solo propio; la rama admin-ajena no la toca).
// Flag one-time para gameover.php: el flujo legítimo
// finishCollapse → reset_level → gameover lo presenta y lo consume.
if ($asUserId === null) {
    unset($_SESSION['level1_step'], $_SESSION['level1_completed'], $_SESSION['level_notes']);
    $gameoverReason = $payload['reason'] ?? null;
    $_SESSION['level1_gameover'] = in_array($gameoverReason, ['time', 'paranoia'], true)
        ? $gameoverReason
        : true;
}

respond_reset('success', 'NIVEL REINICIADO.');
