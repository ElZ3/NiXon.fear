<?php
declare(strict_types=1);

/**
 * scripts/migrate_progress_to_notes.php
 * NiXon.fear — Migración one-shot e idempotente:
 *   users.progress.<level>.notes[] (string) → colección `notes`.
 *
 * Inferencia de categoría/impacto por origen:
 *   - Textos con "UTF-13" o "7-10-2030" → pistas_safe / critico
 *     (combinación del archivero / caja fuerte: pistas críticas del safe).
 *   - Resto → libreta / normal (apuntes del Archivero, parámetro por defecto).
 *
 * Idempotencia: createNote() no duplica (user_id + level + note_text
 * único); re-ejecutar solo reporta "omitidas (ya existían)".
 *
 * Uso: php scripts/migrate_progress_to_notes.php
 * save_notes.php queda intacto; el juego Phaser no se toca.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('SOLO CLI.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Models/NotesModel.php';

use App\Models\NotesModel;

function infer_note_origin(string $text): array
{
    if (stripos($text, 'UTF-13') !== false || stripos($text, '7-10-2030') !== false) {
        return ['pistas_safe', 'critico'];
    }
    return ['libreta', 'normal'];
}

NotesModel::ensureIndexes();

$users = Database::getConnection()->selectCollection('users')->find(
    ['progress' => ['$exists' => true]],
    ['projection' => ['progress' => 1]]
);

$migrated = 0;
$skipped = 0;
$usersTouched = 0;

foreach ($users as $user) {
    $uid = (string) $user['_id'];
    $progress = json_decode(json_encode($user['progress'] ?? []), true) ?? [];
    if (!is_array($progress)) {
        continue;
    }

    $touched = false;
    foreach ($progress as $levelKey => $levelData) {
        if (!in_array($levelKey, NotesModel::LEVELS, true)) {
            continue;
        }
        $texts = is_array($levelData) && isset($levelData['notes']) && is_array($levelData['notes'])
            ? $levelData['notes']
            : [];

        $existingTexts = [];
        foreach (NotesModel::listNotes($uid, $levelKey, null, null, 100) as $n) {
            $existingTexts[(string) ($n['note_text'] ?? '')] = true;
        }

        foreach ($texts as $raw) {
            if (!is_string($raw) && !is_numeric($raw)) {
                continue;
            }
            $text = trim((string) $raw);
            if ($text === '' || mb_strlen($text, 'UTF-8') > 50) {
                continue;
            }
            if (isset($existingTexts[$text])) {
                $skipped++;
                continue;
            }

            [$category, $impact] = infer_note_origin($text);

            $id = NotesModel::createNote($uid, $levelKey, $category, $text, $impact);
            if ($id !== null) {
                $migrated++;
                $touched = true;
                $existingTexts[$text] = true;
            }
        }
    }
    if ($touched) {
        $usersTouched++;
    }
}

echo "MIGRACIÓN COMPLETA. Nuevas: {$migrated} | Omitidas (ya existían): {$skipped} | Usuarios con altas: {$usersTouched}\n";
exit(0);
