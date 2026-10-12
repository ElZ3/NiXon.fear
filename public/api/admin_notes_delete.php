<?php
declare(strict_types=1);

/**
 * public/api/admin_notes_delete.php
 * NiXon.fear — Borrado GM de una nota ajena (solo admin).
 *
 * Petición: POST|DELETE con JSON { id, csrf_token } en el cuerpo
 * (nunca por query string). Respuesta (JSON): 200 | error 400/403/404/405/500.
 * Auth + CSRF exigidos; el evento queda en audit_log. Reutiliza
 * NotesModel::deleteNoteAny() vía AdminNotesController::delete().
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Controllers/AdminNotesController.php';

(new \App\Controllers\AdminNotesController())->delete();
