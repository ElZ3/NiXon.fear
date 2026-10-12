<?php
declare(strict_types=1);

/**
 * public/api/notes_update.php
 * NiXon.fear — Edita una nota propia del Archivero (colección `notes`).
 *
 * Petición (JSON): PUT|PATCH { id, note_text, category?, impact?, csrf_token }
 * Respuesta (JSON): 200 { status, message } | error 400/401/403/404/405/409/500
 * Ownership: solo la nota con {_id, user_id} del jugador en sesión.
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_update(string $status, int $httpCode, string $message): void
{
    http_response_code($httpCode);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_update('error', 401, 'SESIÓN NO AUTORIZADA.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'PUT' && $method !== 'PATCH') {
    // Divergencia intencional jugador vs GM: este endpoint jugador solo
    // acepta PUT/PATCH; el GM (AdminNotesController::update) acepta además
    // POST por compatibilidad con formularios. Allow exacto según lo aceptado.
    header('Allow: PUT, PATCH');
    respond_update('error', 405, 'MÉTODO NO PERMITIDO.');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!$auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
    respond_update('error', 403, 'TOKEN DE SEGURIDAD INVÁLIDO.');
}

$id = trim((string) ($payload['id'] ?? ($payload['_id'] ?? ($payload['note_id'] ?? ''))));
$text = trim((string) ($payload['note_text'] ?? ($payload['text'] ?? '')));
$category = isset($payload['category']) && trim((string) $payload['category']) !== ''
    ? trim((string) $payload['category'])
    : null;
$impact = isset($payload['impact']) && trim((string) $payload['impact']) !== ''
    ? trim((string) $payload['impact'])
    : null;

if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
    respond_update('error', 400, 'IDENTIFICADOR INVÁLIDO.');
}
if ($text === '' || mb_strlen($text, 'UTF-8') > 50) {
    respond_update('error', 400, 'LA NOTA DEBE TENER ENTRE 1 Y 50 CARACTERES.');
}
if ($category !== null && !in_array($category, NotesModel::CATEGORIES, true)) {
    respond_update('error', 400, 'CATEGORÍA INVÁLIDA.');
}
if ($impact !== null && !in_array($impact, NotesModel::IMPACTS, true)) {
    respond_update('error', 400, 'IMPACTO INVÁLIDO.');
}

$userId = $auth->getCurrentUserId();
if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
    respond_update('error', 401, 'SESIÓN NO AUTORIZADA.');
}

// Rama admin-ajena: el param user_id solo lo puede usar un admin
// (jugador → 403, anónimo ya rechazado arriba con 401). El admin salta
// ownership (getNoteAny/updateNoteAny) pero nunca auth ni CSRF.
$isAdminTarget = false;
$rawTarget = $payload['user_id'] ?? null;
if ($rawTarget !== null && trim((string) $rawTarget) !== '') {
    if (!$auth->isAdmin()) {
        respond_update('error', 403, 'ACCESO DENEGADO.');
    }
    $userId = trim((string) $rawTarget);
    if (preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
        respond_update('error', 400, 'USUARIO DESTINO INVÁLIDO.');
    }
    $isAdminTarget = true;
}

if ($isAdminTarget) {
    if (NotesModel::getNoteAny($id) === null) {
        respond_update('error', 404, 'NOTA NO ENCONTRADA.');
    }

    if (!NotesModel::updateNoteAny($id, $text, $category, $impact)) {
        respond_update('error', 409, 'NO SE PUDO EDITAR. POSIBLE NOTA DUPLICADA.');
    }

    respond_update('success', 200, 'NOTA ACTUALIZADA.');
}

// Puerta IDOR (Parcial II §6): obtenerNotaSegura() valida ownership según
// rol. Si devuelve null y la nota existe con otro dueño → 403 ACCESO
// DENEGADO (jugador ajeno); si no existe → 404. El admin (con o sin target
// explícito) opera sobre el dueño real vía *Any, con auth + CSRF exigidos.
$notaSegura = NotesModel::obtenerNotaSegura($id, $userId, $auth->getRole());
if ($notaSegura === null) {
    if (NotesModel::getNoteAny($id) !== null) {
        respond_update('error', 403, 'ACCESO DENEGADO.');
    }
    respond_update('error', 404, 'NOTA NO ENCONTRADA.');
}

if ($auth->isAdmin()) {
    if (!NotesModel::updateNoteAny($id, $text, $category, $impact)) {
        respond_update('error', 409, 'NO SE PUDO EDITAR. POSIBLE NOTA DUPLICADA.');
    }
} elseif (!NotesModel::updateNote($userId, $id, $text, $category, $impact)) {
    respond_update('error', 409, 'NO SE PUDO EDITAR. POSIBLE NOTA DUPLICADA.');
}

respond_update('success', 200, 'NOTA ACTUALIZADA.');
