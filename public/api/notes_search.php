<?php
declare(strict_types=1);

/**
 * public/api/notes_search.php
 * NiXon.fear — Búsqueda del Archivero (envoltorio fino MVC + HTMX).
 *
 * Contrato:
 *   GET ?level=todos|level1|level2[&category=...][&q=...][&sort=asc|desc][&limit=1..100]
 *   Con cabecera HX-Request → parcial HTML (200, no-store).
 *   Sin HX-Request      → JSON { status, message, count, notes } (200/400/401).
 *   Resto de métodos     → 405 (GET-only).
 *
 * Sin lógica duplicada: todo lo resuelve NotesController::search().
 * El escape HTML se hace en las vistas, nunca aquí.
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Controllers/NotesController.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $isHtmx = !empty($_SERVER['HTTP_HX_REQUEST']);
    if ($isHtmx) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
        $htmxState = 'error';
        $htmxMessage = 'MÉTODO NO PERMITIDO.';
        $count = 0;
        $loginHref = 'login.php';
        require __DIR__ . '/../views/notes/_respuesta_htmx.php';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'status'  => 'error',
        'message' => 'MÉTODO NO PERMITIDO.',
        'count'   => 0,
        'notes'   => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

(new \App\Controllers\NotesController())->search();
