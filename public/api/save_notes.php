<?php
declare(strict_types=1);

/**
 * public/api/save_notes.php
 * NiXon.fear — Persiste las notas del Archivero (nivel 1) en MongoDB Atlas.
 *
 * Petición esperada (JSON):
 *   POST { "notes": ["..."], "csrf_token": "..." }
 *
 * Respuesta (JSON):
 *   { "status": "success"|"error", "saved": <int>, "message": "..." }
 *
 * Solo se guarda al ganar (el cliente lo llama desde onLevelComplete).
 * Game over y ?reset=1 no llaman a este endpoint (destrucción en sesión).
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_notes(string $status, int $saved, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'status' => $status,
        'saved' => $saved,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_notes('error', 0, 'SESIÓN NO AUTORIZADA.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond_notes('error', 0, 'MÉTODO NO PERMITIDO.', 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST; // compatibilidad con application/x-www-form-urlencoded
}

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!$auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
    respond_notes('error', 0, 'TOKEN DE SEGURIDAD INVÁLIDO.', 403);
}

$notes = $payload['notes'] ?? [];
if (!is_array($notes)) {
    respond_notes('error', 0, 'FORMATO DE NOTAS INVÁLIDO.', 400);
}

// Sanitización estricta: solo escalares, trim, max 50 chars, max 20 notas.
$clean = [];
foreach ($notes as $n) {
    if (!is_string($n) && !is_numeric($n)) {
        continue;
    }
    $t = trim((string) $n);
    if ($t === '') {
        continue;
    }
    if (mb_strlen($t, 'UTF-8') > 50) {
        $t = mb_substr($t, 0, 50, 'UTF-8');
    }
    if (!in_array($t, $clean, true)) {
        $clean[] = $t;
    }
    if (count($clean) >= 20) {
        break;
    }
}

$userId = $auth->getCurrentUserId();
if ($userId === null) {
    respond_notes('error', 0, 'SESIÓN NO AUTORIZADA.', 401);
}

// Rama admin-ajena: el param user_id solo lo puede usar un admin
// (jugador → 403, anónimo ya rechazado arriba con 401).
$rawTarget = $payload['user_id'] ?? null;
if ($rawTarget !== null && trim((string) $rawTarget) !== '') {
    if (!$auth->isAdmin()) {
        respond_notes('error', 0, 'ACCESO DENEGADO.', 403);
    }
    $userId = trim((string) $rawTarget);
    if (preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
        respond_notes('error', 0, 'USUARIO DESTINO INVÁLIDO.', 400);
    }
}

$ok = NotesModel::saveNotes($userId, 'level1', $clean);
if (!$ok) {
    respond_notes('error', 0, 'NO SE PUDO GUARDAR EL ARCHIVERO.', 500);
}

respond_notes('success', count($clean), 'ARCHIVERO GUARDADO.');
