<?php
declare(strict_types=1);

/**
 * scripts/crear_usuarios_demo.php
 * NiXon.fear — Crea (idempotente) los usuarios demo de la Actividad 3:
 *   admin_nixon@nixon.fear  / Admin123!  / admin
 *   player_nixon@nixon.fear / Player123! / jugador
 *
 * Reglas:
 *   - SOLO CLI (web → 403).
 *   - password_hash() con PASSWORD_DEFAULT (nunca SHA casero).
 *   - Idempotente: si el email existe, SOLO rellena rol/hash ausentes;
 *     NUNCA borra ni toca `progress`.
 *   - Migración incluida: updateMany { rol ausente } → 'jugador'.
 *   - Equivalencia: colección `users` (NO se renombra a `usuarios`).
 *
 * Uso: php scripts/crear_usuarios_demo.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('SOLO CLI.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Models/NotesModel.php';

use App\Models\NotesModel;
use MongoDB\BSON\UTCDateTime;

$demo = [
    ['email' => 'admin_nixon@nixon.fear', 'password' => 'Admin123!', 'rol' => 'admin'],
    ['email' => 'player_nixon@nixon.fear', 'password' => 'Player123!', 'rol' => 'jugador'],
];

NotesModel::ensureUserIndexes();

$users = Database::getConnection()->selectCollection('users');

$created = 0;
$updated = 0;
$intact = 0;

foreach ($demo as $d) {
    $email = strtolower(trim($d['email']));
    $doc = $users->findOne(['email' => $email]);

    if ($doc === null) {
        $users->insertOne([
            'email'         => $email,
            'password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),
            'rol'           => $d['rol'],
            'created_at'    => new UTCDateTime(),
            'progress'      => new \stdClass(),
        ]);
        echo "CREADO: {$email} [{$d['rol']}]\n";
        $created++;
        continue;
    }

    // Existe: solo rellena lo ausente, sin tocar progress jamás.
    $set = [];
    $rolActual = $doc['rol'] ?? null;
    if (!in_array($rolActual, ['admin', 'jugador'], true)) {
        // Si el doc ya trae un rol válido distinto al demo, se respeta.
        // Solo se asigna el demo cuando falta o es inválido.
        $set['rol'] = $d['rol'];
    }
    $hashActual = $doc['password_hash'] ?? null;
    if (!is_string($hashActual) || $hashActual === '' || !str_starts_with($hashActual, '$')) {
        $set['password_hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
    }

    if ($set === []) {
        echo "INTACTO: {$email} (rol/hash presentes, progreso preservado)\n";
        $intact++;
    } else {
        $users->updateOne(['_id' => $doc['_id']], ['$set' => $set]);
        echo 'ACTUALIZADO: ' . $email . ' (' . implode(',', array_keys($set)) . ")\n";
        $updated++;
    }
}

// Migración: todo usuario sin rol → 'jugador' (default + fallback).
$migr = $users->updateMany(
    ['rol' => ['$exists' => false]],
    ['$set' => ['rol' => 'jugador']]
);
echo "MIGRACIÓN ROL: {$migr->getModifiedCount()} documento(s) sin rol → jugador.\n";
echo "RESUMEN: creados={$created} actualizados={$updated} intactos={$intact}\n";
exit(0);
