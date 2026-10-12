<?php
declare(strict_types=1);

/**
 * public/api/notes_delete.php
 * NiXon.fear — Elimina una nota propia del Archivero (colección `notes`).
 *
 * Petición: DELETE (o POST por compat) con JSON { id, csrf_token } en el cuerpo.
 * El csrf_token SOLO se acepta en el cuerpo JSON (nunca por query string).
 * Respuesta (JSON): 200 { status, message } | error 400/401/403/404/405/500
 * Ownership: solo la nota con {_id, user_id} del jugador en sesión.
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_delete(string $status, int $httpCode, string $message): void
{
    http_response_code($httpCode);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_delete('error', 401, 'SESIÓN NO AUTORIZADA.');
}

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['POST', 'DELETE'], true)) {
    header('Allow: POST, DELETE');
    respond_delete('error', 405, 'MÉTODO NO PERMITIDO.');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}
// CSRF solo por cuerpo JSON o cabecera X-CSRF-Token (nunca por query string).
// El id SOLO se acepta en el cuerpo JSON (sin fallback por query string).
$id = trim((string) ($payload['id'] ?? ($payload['_id'] ?? ($payload['note_id'] ?? ''))));
$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

if (!$auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
    respond_delete('error', 403, 'TOKEN DE SEGURIDAD INVÁLIDO.');
}

if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
    respond_delete('error', 400, 'IDENTIFICADOR INVÁLIDO.');
}

$userId = $auth->getCurrentUserId();
if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
    respond_delete('error', 401, 'SESIÓN NO AUTORIZADA.');
}

// Rama admin-ajena: el param user_id solo lo puede usar un admin
// (jugador → 403, anónimo ya rechazado arriba con 401). El admin salta
// ownership (getNoteAny/deleteNoteAny) pero nunca auth ni CSRF.
$isAdminTarget = false;
$rawTarget = $payload['user_id'] ?? null;
if ($rawTarget !== null && trim((string) $rawTarget) !== '') {
    if (!$auth->isAdmin()) {
        respond_delete('error', 403, 'ACCESO DENEGADO.');
    }
    $userId = trim((string) $rawTarget);
    if (preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
        respond_delete('error', 400, 'USUARIO DESTINO INVÁLIDO.');
    }
    $isAdminTarget = true;
}

if ($isAdminTarget) {
    if (NotesModel::getNoteAny($id) === null) {
        respond_delete('error', 404, 'NOTA NO ENCONTRADA.');
    }

    if (!NotesModel::deleteNoteAny($id)) {
        respond_delete('error', 500, 'NO SE PUDO ELIMINAR LA NOTA.');
    }

    respond_delete('success', 200, 'NOTA ELIMINADA DEL ARCHIVERO.');
}

// Puerta IDOR (Parcial II §6): obtenerNotaSegura() valida ownership según
// rol. Si devuelve null y la nota existe con otro dueño → 403 ACCESO
// DENEGADO (jugador ajeno); si no existe → 404. El admin (con o sin target
// explícito) opera sobre el dueño real vía *Any, con auth + CSRF exigidos.
$notaSegura = NotesModel::obtenerNotaSegura($id, $userId, $auth->getRole());
if ($notaSegura === null) {
    if (NotesModel::getNoteAny($id) !== null) {
        respond_delete('error', 403, 'ACCESO DENEGADO.');
    }
    respond_delete('error', 404, 'NOTA NO ENCONTRADA.');
}

if ($auth->isAdmin()) {
    if (!NotesModel::deleteNoteAny($id)) {
        respond_delete('error', 500, 'NO SE PUDO ELIMINAR LA NOTA.');
    }
} elseif (!NotesModel::deleteNote($userId, $id)) {
    respond_delete('error', 500, 'NO SE PUDO ELIMINAR LA NOTA.');
}

respond_delete('success', 200, 'NOTA ELIMINADA DEL ARCHIVERO.');
