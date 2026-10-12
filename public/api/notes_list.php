<?php
declare(strict_types=1);

/**
 * public/api/notes_list.php
 * NiXon.fear — Lista notas del Archivero (colección `notes`, solo lectura).
 *
 * Petición: GET ?level=level1|level2[&category=...][&q=...][&limit=1..100]
 * Respuesta (JSON): 200 { status, count, notes: [...] } | error 400/401/405
 * Sin CSRF (lectura). El escape HTML se hace en la vista, no aquí.
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Models/NotesModel.php';

use App\Controllers\AuthController;
use App\Models\NotesModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_list(string $status, int $httpCode, string $message, array $notes = []): void
{
    http_response_code($httpCode);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'count'   => count($notes),
        'notes'   => $notes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_list('error', 401, 'SESIÓN NO AUTORIZADA.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond_list('error', 405, 'MÉTODO NO PERMITIDO.');
}

$level = trim((string) ($_GET['level'] ?? ''));
$category = isset($_GET['category']) && trim((string) $_GET['category']) !== ''
    ? trim((string) $_GET['category'])
    : null;
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : null;
$limit = 50;
if (isset($_GET['limit']) && trim((string) $_GET['limit']) !== '') {
    if (!ctype_digit((string) $_GET['limit'])) {
        respond_list('error', 400, 'LÍMITE INVÁLIDO.');
    }
    $limit = (int) $_GET['limit'];
    if ($limit < 1 || $limit > 100) {
        respond_list('error', 400, 'LÍMITE INVÁLIDO. Use 1..100.');
    }
}

if (!in_array($level, NotesModel::LEVELS, true)) {
    respond_list('error', 400, 'NIVEL INVÁLIDO. Use level1 o level2.');
}
if ($category !== null && !in_array($category, NotesModel::CATEGORIES, true)) {
    respond_list('error', 400, 'CATEGORÍA INVÁLIDA.');
}
if ($q !== null && mb_strlen($q, 'UTF-8') > 50) {
    $q = mb_substr($q, 0, 50, 'UTF-8');
}

$userId = $auth->getCurrentUserId();
if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
    respond_list('error', 401, 'SESIÓN NO AUTORIZADA.');
}

$notes = NotesModel::listNotes($userId, $level, $category, $q, $limit);
respond_list('success', 200, 'ARCHIVERO RECUPERADO.', $notes);
