<?php
declare(strict_types=1);

/**
 * public/api/notes_create.php
 * NiXon.fear — Crea una nota del Archivero (colección `notes`).
 *
 * Petición (JSON): POST { level, category?, note_text, impact?, csrf_token }
 *   level: level1|level2 | category: pistas_safe|terminal|documentos|libreta
 *   (default libreta) | note_text: 1..50 (trim) | impact: critico|normal
 *   (default normal)
 * Respuesta (JSON): 201 { status, id, message } | error 400/401/403/405/500
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_create(string $status, int $httpCode, string $message, ?string $id = null): void
{
    http_response_code($httpCode);
    $body = ['status' => $status, 'message' => $message];
    if ($id !== null) {
        $body['id'] = $id;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_create('error', 401, 'SESIÓN NO AUTORIZADA.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond_create('error', 405, 'MÉTODO NO PERMITIDO.');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!$auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
    respond_create('error', 403, 'TOKEN DE SEGURIDAD INVÁLIDO.');
}

$level = trim((string) ($payload['level'] ?? ''));
$category = trim((string) ($payload['category'] ?? 'libreta'));
$impact = trim((string) ($payload['impact'] ?? 'normal'));
$text = trim((string) ($payload['note_text'] ?? ($payload['text'] ?? '')));

if (!in_array($level, NotesModel::LEVELS, true)) {
    respond_create('error', 400, 'NIVEL INVÁLIDO. Use level1 o level2.');
}
if (!in_array($category, NotesModel::CATEGORIES, true)) {
    respond_create('error', 400, 'CATEGORÍA INVÁLIDA.');
}
if (!in_array($impact, NotesModel::IMPACTS, true)) {
    respond_create('error', 400, 'IMPACTO INVÁLIDO.');
}
if ($text === '' || mb_strlen($text, 'UTF-8') > 50) {
    respond_create('error', 400, 'LA NOTA DEBE TENER ENTRE 1 Y 50 CARACTERES.');
}

$userId = $auth->getCurrentUserId();
if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
    respond_create('error', 401, 'SESIÓN NO AUTORIZADA.');
}

// Rama admin-ajena: el param user_id solo lo puede usar un admin
// (jugador → 403, anónimo ya rechazado arriba con 401).
$rawTarget = $payload['user_id'] ?? null;
if ($rawTarget !== null && trim((string) $rawTarget) !== '') {
    if (!$auth->isAdmin()) {
        respond_create('error', 403, 'ACCESO DENEGADO.');
    }
    $userId = trim((string) $rawTarget);
    if (preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
        respond_create('error', 400, 'USUARIO DESTINO INVÁLIDO.');
    }
}

$id = NotesModel::createNote($userId, $level, $category, $text, $impact);
if ($id === null) {
    respond_create('error', 500, 'NO SE PUDO CREAR LA NOTA.');
}

respond_create('success', 201, 'NOTA REGISTRADA EN EL ARCHIVERO.', $id);
