<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond_progress(string $status, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond_progress('error', 'SESIÓN NO AUTORIZADA.', 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond_progress('error', 'MÉTODO NO PERMITIDO.', 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!$auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
    respond_progress('error', 'TOKEN DE SEGURIDAD INVÁLIDO.', 403);
}

// Allowlist estricta de nivel (sin interpolación directa del cliente).
$level = (string) ($payload['level'] ?? '');
if ($level !== 'level1') {
    respond_progress('error', 'NIVEL NO VÁLIDO.', 400);
}

$step = filter_var(
    $payload['step'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 5]]
);
if ($step === false) {
    respond_progress('error', 'PASO NO VÁLIDO.', 400);
}

$completed = filter_var($payload['completed'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($completed === null) {
    respond_progress('error', 'ESTADO NO VÁLIDO.', 400);
}

// Rama admin-ajena: el param user_id solo lo puede usar un admin
// (jugador >>403, anónimo ya rechazado arriba con 401).
$asUserId = null;
$rawTarget = $payload['user_id'] ?? null;
if ($rawTarget !== null && trim((string) $rawTarget) !== '') {
    if (!$auth->isAdmin()) {
        respond_progress('error', 'ACCESO DENEGADO.', 403);
    }
    $asUserId = trim((string) $rawTarget);
    if (preg_match('/^[a-f0-9]{24}$/i', $asUserId) !== 1) {
        respond_progress('error', 'USUARIO DESTINO INVÁLIDO.', 400);
    }
}

// Fusión con lo existente: conserva notes/notes_updated del archivero.
$existing = $auth->getProgress('level1', $asUserId);
if (!is_array($existing)) {
    $existing = [];
}
$data = $existing;
$data['step'] = $step;
$data['completed'] = $completed;

if (!$auth->saveProgress('level1', $data, $asUserId)) {
    respond_progress('error', 'NO SE PUDO GUARDAR EL PROGRESO.', 500);
}

// Espejo en sesión (autoridad de estado de validate_step.php).
// Solo para el propio usuario: la rama admin-ajena no toca la sesión.
if ($asUserId === null) {
    $_SESSION['level1_step'] = $step;
    if ($completed) {
        $_SESSION['level1_completed'] = true;
    }
}

respond_progress('success', 'PROGRESO GUARDADO.');
