<?php
declare(strict_types=1);

/**
 * public/api/admin_notes_update.php
 * NiXon.fear — Corrección GM de una nota ajena (solo admin).
 *
 * Petición (JSON): POST|PUT|PATCH { id, note_text, category?, impact?, csrf_token }
 * Respuesta (JSON): 200 { status, message } | error 400/403/404/405/409
 * Auth + CSRF exigidos; el evento queda en audit_log. Reutiliza
 * NotesModel::updateNoteAny() vía AdminNotesController::update().
 */

require_once __DIR__ . '/../../src/Controllers/AuthController.php';
require_once __DIR__ . '/../../src/Controllers/AdminNotesController.php';

(new \App\Controllers\AdminNotesController())->update();
