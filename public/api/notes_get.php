<?php
declare(strict_types=1);

/**
 * public/api/notes_get.php
 * NiXon.fear — Lectura de UNA nota por _id (colección `notes`, solo lectura).
 *
 * Cubre el hueco de lectura por id del Parcial II §6: hasta ahora solo
 * existían listado/búsqueda y los gates de escritura (update/delete); este
 * endpoint expone la lectura individual con la misma puerta IDOR.
 *
 * Petición: GET ?id=<ObjectId24hex>
 * Respuesta (JSON): 200 { status, message, note } | error 400/401/403/404/405
 * Sin CSRF (lectura, igual que notes_list/notes_search).
 * Ownership: obtenerNotaSegura($id, $userId, $rol) — jugador ajeno → 403
 * ACCESO DENEGADO; inexistente → 404; admin → lectura global.
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * @param array<string, mixed>|null $note
 */
function respond_get(string $status, int $httpCode, string $message, ?array $note = null): void
{
    http_response_code($httpCode);
    $body = ['status' => $status, 'message' => $message];
    if ($note !== null) {
        $body['note'] = $note;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_get('error', 401, 'SESIÓN NO AUTORIZADA.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond_get('error', 405, 'MÉTODO NO PERMITIDO.');
}

$id = trim((string) ($_GET['id'] ?? ''));
if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
    respond_get('error', 400, 'IDENTIFICADOR INVÁLIDO.');
}

$userId = $auth->getCurrentUserId();
if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
    respond_get('error', 401, 'SESIÓN NO AUTORIZADA.');
}

// Puerta IDOR (Parcial II §6): null por ownership (existe con otro dueño)
// → 403 ACCESO DENEGADO; null por inexistencia → 404.
$nota = NotesModel::obtenerNotaSegura($id, $userId, $auth->getRole());
if ($nota === null) {
    if (NotesModel::getNoteAny($id) !== null) {
        respond_get('error', 403, 'ACCESO DENEGADO.');
    }
    respond_get('error', 404, 'NOTA NO ENCONTRADA.');
}

respond_get('success', 200, 'NOTA RECUPERADA.', $nota);
