<?php
declare(strict_types=1);

/**
 * public/api/validate_step.php
 * NiXon.fear — Nivel 1: valida un paso de la secuencia de acertijos.
 *
 * Petición esperada (JSON):
 *   POST { "step": "Documento1", "csrf_token": "..." }
 *
 * Respuesta (JSON):
 *   { "status": "success" | "error", "progress": <0-100>, "message": "...", "completed": bool }
 *
 * El progreso vive en la sesión del servidor (autoridad de estado), no en
 * el cliente: level1.js solo informa qué objeto se clicó y refleja lo que
 * este controlador responde. Por eso no acepta param user_id (ni siquiera
 * admin): el admin también opera sobre su propia sesión, con auth + CSRF
 * exigidos igualmente.
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Orden estricto — debe coincidir exactamente con PROGRESS_ORDER en level1.js.
const STEP_ORDER = ['Documento1', 'Archivero', 'Documento 2', 'Computadora', 'Cajafuerte'];

function respond(string $status, int $progress, string $message, bool $completed = false, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'status'    => $status,
        'progress'  => $progress,
        'message'   => $message,
        'completed' => $completed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new AuthController();
$auth->bootSession();

if (!$auth->isAuthenticated()) {
    respond('error', 0, 'SESIÓN NO AUTORIZADA.', false, 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond('error', (int) round((($_SESSION['level1_step'] ?? 0) / count(STEP_ORDER)) * 100), 'MÉTODO NO PERMITIDO.', false, 405);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    $payload = $_POST; // compatibilidad con application/x-www-form-urlencoded
}

$csrfToken = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
$step = trim((string) ($payload['step'] ?? ''));

if (!$auth->validateCsrf(is_string($csrfToken) ? $csrfToken : null)) {
    respond('error', (int) round((($_SESSION['level1_step'] ?? 0) / count(STEP_ORDER)) * 100), 'TOKEN DE SEGURIDAD INVÁLIDO.', false, 403);
}

if ($step === '' || !in_array($step, STEP_ORDER, true)) {
    respond('error', (int) round((($_SESSION['level1_step'] ?? 0) / count(STEP_ORDER)) * 100), 'OBJETO NO RECONOCIDO.', false, 400);
}

// Estado de progreso persistido en sesión.
if (!isset($_SESSION['level1_step']) || !is_int($_SESSION['level1_step'])) {
    $_SESSION['level1_step'] = 0;
}

$currentIndex = $_SESSION['level1_step'];
$totalSteps = count(STEP_ORDER);
$currentPercent = (int) round(($currentIndex / $totalSteps) * 100);

// Nivel ya completado: cualquier clic adicional se informa como éxito, sin error.
if ($currentIndex >= $totalSteps) {
    respond('success', 100, 'INCIDENTE YA RESUELTO.', true);
}

$expectedStep = STEP_ORDER[$currentIndex];

if ($step !== $expectedStep) {
    respond('error', $currentPercent, 'ORDEN INCORRECTO.', false);
}

// Paso correcto: avanza el progreso en sesión (cada paso = 20%).
$_SESSION['level1_step'] = $currentIndex + 1;
$newPercent = (int) round(($_SESSION['level1_step'] / $totalSteps) * 100);
$completed = $_SESSION['level1_step'] >= $totalSteps;

if ($completed) {
    $_SESSION['level1_completed'] = true;
}

respond('success', $newPercent, 'Paso registrado correctamente.', $completed);
