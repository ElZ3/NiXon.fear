<?php
declare(strict_types=1);

namespace App\Models;

use Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

require_once __DIR__ . '/../../config/database.php';

/**
 * NotesModel
 * NiXon.fear — Persistencia de notas del Archivero (MongoDB Atlas).
 *
 * Colección principal: `notes`
 *   {
 *     _id: ObjectId,
 *     user_id: ObjectId,
 *     level: "level1" | "level2",
 *     category: "pistas_safe" | "terminal" | "documentos" | "libreta",
 *     note_text: string (1..50, trim),
 *     impact: "critico" | "normal",
 *     created_at: UTCDateTime,
 *     updated_at: UTCDateTime
 *   }
 *
 * Índices (crear en Atlas o con NotesModel::ensureIndexes()):
 *   db.notes.createIndex({ user_id: 1, level: 1, created_at: 1 })
 *   db.notes.createIndex({ user_id: 1, level: 1, note_text: 1 }, { unique: true })
 *   db.notes.createIndex({ created_at: -1 }) // Fase A1 Parcial II: listado global GM
 *   db.users.createIndex({ email: 1 }, { unique: true })
 *   db.users.createIndex({ rol: 1 })
 * Unicidad cross-level: el mismo texto puede existir en level1 y level2
 * del mismo usuario; el duplicado se evalúa por (user_id, level, note_text).
 *
 * Dual-write (no rompe el juego Phaser): cada mutación CRUD sobre `notes`
 * reconstruye además `users.progress.<level>.notes` (string[], últimas 20)
 * + `users.progress.<level>.notes_updated`, que es lo que lee
 * onLevelComplete / save_notes.php. El escape HTML se hace en la vista,
 * nunca aquí (se guarda texto plano).
 */
final class NotesModel
{
    private const MAX_NOTES = 20;
    private const MAX_LEN = 50;

    public const LEVELS = ['level1', 'level2'];
    public const CATEGORIES = ['pistas_safe', 'terminal', 'documentos', 'libreta'];
    public const IMPACTS = ['critico', 'normal'];

    // ---------------------------------------------------------------
    // CRUD sobre `notes` (Actividad 1)
    // ---------------------------------------------------------------

    /**
     * Crea una nota. Idempotente: si ya existe (user_id + level + note_text),
     * devuelve el _id existente en lugar de duplicar.
     */
    public static function createNote(
        string $userId,
        string $level,
        string $category,
        string $text,
        string $impact = 'normal'
    ): ?string {
        $text = trim($text);
        if (!self::isValidObjectId($userId)
            || !in_array($level, self::LEVELS, true)
            || !in_array($category, self::CATEGORIES, true)
            || !in_array($impact, self::IMPACTS, true)
            || $text === ''
            || mb_strlen($text, 'UTF-8') > self::MAX_LEN
        ) {
            return null;
        }

        try {
            $notes = Database::getConnection()->selectCollection('notes');
            $uid = new ObjectId($userId);

            $existing = $notes->findOne(['user_id' => $uid, 'level' => $level, 'note_text' => $text]);
            if ($existing !== null && isset($existing['_id'])) {
                return (string) $existing['_id'];
            }

            $now = new UTCDateTime();
            $result = $notes->insertOne([
                'user_id'    => $uid,
                'level'      => $level,
                'category'   => $category,
                'note_text'  => $text,
                'impact'     => $impact,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = $result->getInsertedId();
            if ($id === null) {
                return null;
            }

            self::syncProgressArray($userId, $level);

            return (string) $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Lista notas del jugador en un nivel, con filtro opcional por
     * categoría y búsqueda de texto. Orden cronológico (asc: más antiguas
     * primero; desc: más recientes primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listNotes(
        string $userId,
        string $level,
        ?string $category = null,
        ?string $q = null,
        int $limit = 50,
        string $sort = 'asc'
    ): array {
        if (!self::isValidObjectId($userId) || !in_array($level, self::LEVELS, true)) {
            return [];
        }
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $direction = ($sort === 'desc') ? -1 : 1;

        $filter = ['user_id' => new ObjectId($userId), 'level' => $level];
        if ($category !== null) {
            $filter['category'] = $category;
        }
        if ($q !== null && trim($q) !== '') {
            $qq = mb_substr(trim($q), 0, self::MAX_LEN, 'UTF-8');
            $filter['note_text'] = new \MongoDB\BSON\Regex(preg_quote($qq, '/'), 'i');
        }

        try {
            $cursor = Database::getConnection()->selectCollection('notes')->find(
                $filter,
                ['sort' => ['created_at' => $direction], 'limit' => $limit]
            );
            $out = [];
            foreach ($cursor as $doc) {
                $out[] = self::serializeNote($doc);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene una nota verificando propiedad ({_id, user_id}).
     *
     * @return array<string, mixed>|null
     */
    public static function getNote(string $userId, string $noteId): ?array
    {
        if (!self::isValidObjectId($userId) || !self::isValidObjectId($noteId)) {
            return null;
        }

        try {
            $doc = Database::getConnection()->selectCollection('notes')->findOne([
                '_id'     => new ObjectId($noteId),
                'user_id' => new ObjectId($userId),
            ]);
            return $doc === null ? null : self::serializeNote($doc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * obtenerNotaSegura — Control de Propiedad de Registros (Parcial II §6,
     * prevención de IDOR). La localización por `_id` NO concede autorización:
     * la consulta valida la relación recurso ↔ usuario autenticado.
     *
     * Equivalencia documentada (docs/mapeo_tecnico_parcial2.md §2): el campo
     * exigido como `propietario_id` es el `user_id` real del esquema Act.1
     * (ObjectId, ref `users._id`). NO se renombra: renombrar rompería la
     * colección `notes`, sus índices y el dual-write con el juego.
     *
     *   - rol `admin`  → findOne({_id}) — acceso global (soporte GM).
     *   - rol jugador  → findOne({_id, user_id}) — solo notas propias.
     *
     * Valida ambos ObjectIds; si alguno es inválido devuelve null.
     * Si devuelve null, el llamador distingue la causa con getNoteAny():
     * existe con otro dueño → 403 ACCESO DENEGADO; no existe → 404.
     *
     * @return array<string, mixed>|null nota serializada o null
     */
    public static function obtenerNotaSegura(
        string $idNota,
        string $idUsuario,
        string $rolUsuario
    ): ?array {
        if (!self::isValidObjectId($idNota) || !self::isValidObjectId($idUsuario)) {
            return null;
        }

        try {
            $filter = ['_id' => new ObjectId($idNota)];
            if ($rolUsuario !== 'admin') {
                $filter['user_id'] = new ObjectId($idUsuario);
            }
            $doc = Database::getConnection()->selectCollection('notes')->findOne($filter);
            return $doc === null ? null : self::serializeNote($doc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Edita el texto (y opcionalmente categoría/impacto) de una nota propia.
     * Rechaza duplicados (user_id + level + note_text único).
     */
    public static function updateNote(
        string $userId,
        string $noteId,
        string $text,
        ?string $category = null,
        ?string $impact = null
    ): bool {
        $text = trim($text);
        if (!self::isValidObjectId($userId)
            || !self::isValidObjectId($noteId)
            || $text === ''
            || mb_strlen($text, 'UTF-8') > self::MAX_LEN
            || ($category !== null && !in_array($category, self::CATEGORIES, true))
            || ($impact !== null && !in_array($impact, self::IMPACTS, true))
        ) {
            return false;
        }

        try {
            $notes = Database::getConnection()->selectCollection('notes');
            $filter = ['_id' => new ObjectId($noteId), 'user_id' => new ObjectId($userId)];

            $current = $notes->findOne($filter);
            if ($current === null) {
                return false;
            }

            $dupLevel = isset($current['level']) ? (string) $current['level'] : '';
            $dup = $notes->findOne([
                'user_id'   => new ObjectId($userId),
                'level'     => $dupLevel,
                'note_text' => $text,
            ]);
            if ($dup !== null && (string) $dup['_id'] !== $noteId) {
                return false;
            }

            $set = ['note_text' => $text, 'updated_at' => new UTCDateTime()];
            if ($category !== null) {
                $set['category'] = $category;
            }
            if ($impact !== null) {
                $set['impact'] = $impact;
            }

            $result = $notes->updateOne($filter, ['$set' => $set]);
            if ($result->getMatchedCount() === 0) {
                return false;
            }

            $level = isset($current['level']) ? (string) $current['level'] : '';
            if (in_array($level, self::LEVELS, true)) {
                self::syncProgressArray($userId, $level);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Elimina una nota propia y resincroniza el progreso.
     */
    public static function deleteNote(string $userId, string $noteId): bool
    {
        if (!self::isValidObjectId($userId) || !self::isValidObjectId($noteId)) {
            return false;
        }

        try {
            $notes = Database::getConnection()->selectCollection('notes');
            $filter = ['_id' => new ObjectId($noteId), 'user_id' => new ObjectId($userId)];

            $current = $notes->findOne($filter);
            if ($current === null) {
                return false;
            }

            $result = $notes->deleteOne($filter);
            if ($result->getDeletedCount() === 0) {
                return false;
            }

            $level = isset($current['level']) ? (string) $current['level'] : '';
            if (in_array($level, self::LEVELS, true)) {
                self::syncProgressArray($userId, $level);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Borra todas las notas propias de un nivel (usado por reset_level.php
     * al perder) y resincroniza el espejo users.progress.<level>.notes.
     * Devuelve el nº de notas eliminadas o null si la entrada es inválida.
     */
    public static function clearLevelNotes(string $userId, string $level): ?int
    {
        if (!self::isValidObjectId($userId) || !in_array($level, self::LEVELS, true)) {
            return null;
        }

        try {
            $result = Database::getConnection()->selectCollection('notes')->deleteMany([
                'user_id' => new ObjectId($userId),
                'level'   => $level,
            ]);
            self::syncProgressArray($userId, $level);
            return $result->getDeletedCount();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Crea los índices documentados. Idempotente.
     * Incluye users {email unique} y {rol} (roles admin/jugador).
     */
    public static function ensureIndexes(): void
    {
        try {
            $notes = Database::getConnection()->selectCollection('notes');
            $notes->createIndex(['user_id' => 1, 'level' => 1, 'created_at' => 1]);
            $notes->createIndex(['user_id' => 1, 'level' => 1, 'note_text' => 1], ['unique' => true]);
            // Fase A1 Parcial II (panel GM): listado global ordenado por fecha.
            $notes->createIndex(['created_at' => -1]);
        } catch (\Throwable $e) {
            // Atlas ya los tiene o sin permisos: no es fatal.
        }
        self::ensureUserIndexes();
    }

    /**
     * Índices de la colección `users` (equivalencia: NO se renombra a
     * `usuarios`). Idempotente.
     *   db.users.createIndex({ email: 1 }, { unique: true })
     *   db.users.createIndex({ rol: 1 })
     */
    public static function ensureUserIndexes(): void
    {
        try {
            $users = Database::getConnection()->selectCollection('users');
            $users->createIndex(['email' => 1], ['unique' => true]);
            $users->createIndex(['rol' => 1]);
        } catch (\Throwable $e) {
            // Atlas ya los tiene o sin permisos: no es fatal.
        }
    }

    // ---------------------------------------------------------------
    // Rama admin: operaciones sin filtro user_id (solo admin).
    // El controlador/API verifica isAdmin() ANTES de llamar aquí: estas
    // funciones saltan ownership pero NUNCA auth ni CSRF (esos se exigen
    // en la capa API igualmente).
    // ---------------------------------------------------------------

    /**
     * Lista notas GLOBAL (panel GM, solo admin). Sin filtro user_id salvo
     * $ownerId explícito (resuelto en 2 pasos desde el email en el
     * controlador: email → users.findOne → ownerId; si no existe → []).
     * Misma validación/Regex/límite que listNotes(): level/category contra
     * listas blancas, q con preg_quote (i) truncada a 50, limit 1..100,
     * sort asc|desc por created_at. Entrada inválida → [] (deny by default).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listNotesAny(
        ?string $level = null,
        ?string $category = null,
        ?string $q = null,
        ?string $ownerId = null,
        int $limit = 50,
        string $sort = 'asc'
    ): array {
        $level = ($level === null || $level === '' || $level === 'todos') ? null : trim($level);
        $category = ($category === null || $category === '' || $category === 'todas') ? null : trim((string) $category);
        $q = ($q === null || trim((string) $q) === '') ? null : trim((string) $q);
        $ownerId = ($ownerId === null || trim((string) $ownerId) === '') ? null : trim((string) $ownerId);

        if ($level !== null && !in_array($level, self::LEVELS, true)) {
            return [];
        }
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            return [];
        }
        if ($ownerId !== null && !self::isValidObjectId($ownerId)) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $direction = ($sort === 'desc') ? -1 : 1;

        $filter = [];
        if ($level !== null) {
            $filter['level'] = $level;
        }
        if ($category !== null) {
            $filter['category'] = $category;
        }
        if ($ownerId !== null) {
            $filter['user_id'] = new ObjectId($ownerId);
        }
        if ($q !== null) {
            $qq = mb_substr($q, 0, self::MAX_LEN, 'UTF-8');
            $filter['note_text'] = new \MongoDB\BSON\Regex(preg_quote($qq, '/'), 'i');
        }

        try {
            $cursor = Database::getConnection()->selectCollection('notes')->find(
                $filter,
                ['sort' => ['created_at' => $direction], 'limit' => $limit]
            );
            $out = [];
            foreach ($cursor as $doc) {
                $out[] = self::serializeNote($doc);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene una nota por _id sin verificar propiedad. Solo admin.
     *
     * @return array<string, mixed>|null
     */
    public static function getNoteAny(string $noteId): ?array
    {
        if (!self::isValidObjectId($noteId)) {
            return null;
        }

        try {
            $doc = Database::getConnection()->selectCollection('notes')->findOne([
                '_id' => new ObjectId($noteId),
            ]);
            return $doc === null ? null : self::serializeNote($doc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Edita una nota por _id sin verificar propiedad. Solo admin.
     * Resincroniza el espejo users.progress del dueño real.
     */
    public static function updateNoteAny(
        string $noteId,
        string $text,
        ?string $category = null,
        ?string $impact = null
    ): bool {
        $text = trim($text);
        if (!self::isValidObjectId($noteId)
            || $text === ''
            || mb_strlen($text, 'UTF-8') > self::MAX_LEN
            || ($category !== null && !in_array($category, self::CATEGORIES, true))
            || ($impact !== null && !in_array($impact, self::IMPACTS, true))
        ) {
            return false;
        }

        try {
            $notes = Database::getConnection()->selectCollection('notes');
            $filter = ['_id' => new ObjectId($noteId)];

            $current = $notes->findOne($filter);
            if ($current === null) {
                return false;
            }

            $ownerId = isset($current['user_id']) ? (string) $current['user_id'] : '';
            $dupLevel = isset($current['level']) ? (string) $current['level'] : '';
            if ($ownerId !== '' && $dupLevel !== '') {
                $dup = $notes->findOne([
                    'user_id'   => new ObjectId($ownerId),
                    'level'     => $dupLevel,
                    'note_text' => $text,
                ]);
                if ($dup !== null && (string) $dup['_id'] !== $noteId) {
                    return false;
                }
            }

            $set = ['note_text' => $text, 'updated_at' => new UTCDateTime()];
            if ($category !== null) {
                $set['category'] = $category;
            }
            if ($impact !== null) {
                $set['impact'] = $impact;
            }

            $result = $notes->updateOne($filter, ['$set' => $set]);
            if ($result->getMatchedCount() === 0) {
                return false;
            }

            $level = isset($current['level']) ? (string) $current['level'] : '';
            if ($ownerId !== '' && in_array($level, self::LEVELS, true)) {
                self::syncProgressArray($ownerId, $level);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Elimina una nota por _id sin verificar propiedad. Solo admin.
     * Resincroniza el espejo users.progress del dueño real.
     */
    public static function deleteNoteAny(string $noteId): bool
    {
        if (!self::isValidObjectId($noteId)) {
            return false;
        }

        try {
            $notes = Database::getConnection()->selectCollection('notes');
            $filter = ['_id' => new ObjectId($noteId)];

            $current = $notes->findOne($filter);
            if ($current === null) {
                return false;
            }

            $result = $notes->deleteOne($filter);
            if ($result->getDeletedCount() === 0) {
                return false;
            }

            $ownerId = isset($current['user_id']) ? (string) $current['user_id'] : '';
            $level = isset($current['level']) ? (string) $current['level'] : '';
            if ($ownerId !== '' && in_array($level, self::LEVELS, true)) {
                self::syncProgressArray($ownerId, $level);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Dual-write: espejo hacia users.progress (no romper Phaser)
    // ---------------------------------------------------------------

    /**
     * Reconstruye users.progress.<level>.notes (string[], últimas 20) y
     * users.progress.<level>.notes_updated desde la colección `notes`.
     * Nunca lanza: un fallo de espejo no debe romper el CRUD.
     */
    private static function syncProgressArray(string $userId, string $level): void
    {
        $safeLevel = preg_replace('/[^A-Za-z0-9_]/', '', $level) ?? '';
        if ($safeLevel === '' || !self::isValidObjectId($userId)) {
            return;
        }

        try {
            $cursor = Database::getConnection()->selectCollection('notes')->find(
                ['user_id' => new ObjectId($userId), 'level' => $safeLevel],
                ['sort' => ['created_at' => 1], 'projection' => ['note_text' => 1]]
            );

            $texts = [];
            foreach ($cursor as $doc) {
                if (isset($doc['note_text'])) {
                    $texts[] = (string) $doc['note_text'];
                }
            }
            $texts = array_slice($texts, -self::MAX_NOTES);

            Database::getConnection()->selectCollection('users')->updateOne(
                ['_id' => new ObjectId($userId)],
                ['$set' => [
                    "progress.$safeLevel.notes"         => $texts,
                    "progress.$safeLevel.notes_updated" => new UTCDateTime(),
                ]]
            );
        } catch (\Throwable $e) {
            // Espejo best-effort: se ignora sin romper el CRUD.
        }
    }

    /**
     * Serializa un documento BSON de `notes` a array PHP plano con fechas ISO.
     *
     * @param mixed $doc
     * @return array<string, mixed>
     */
    private static function serializeNote($doc): array
    {
        $arr = json_decode(json_encode($doc), true) ?? [];
        $fmt = static function ($v): ?string {
            if ($v instanceof UTCDateTime) {
                return $v->toDateTime()->format('c');
            }
            if (is_array($v) && isset($v['$date'])) {
                return (string) $v['$date'];
            }
            return is_string($v) ? $v : null;
        };

        return [
            'id'         => isset($arr['_id']['$oid']) ? (string) $arr['_id']['$oid'] : (string) ($doc['_id'] ?? ''),
            'user_id'    => isset($arr['user_id']['$oid']) ? (string) $arr['user_id']['$oid'] : '',
            'level'      => (string) ($arr['level'] ?? ''),
            'category'   => (string) ($arr['category'] ?? ''),
            'note_text'  => (string) ($arr['note_text'] ?? ''),
            'impact'     => (string) ($arr['impact'] ?? 'normal'),
            'created_at' => $fmt($doc['created_at'] ?? null),
            'updated_at' => $fmt($doc['updated_at'] ?? null),
        ];
    }

    // ---------------------------------------------------------------
    // Wrappers legacy: bulk en users.progress (los usa save_notes.php)
    // ---------------------------------------------------------------

    /**
     * @param string[] $notes
     */
    public static function saveNotes(string $userId, string $levelKey, array $notes): bool
    {
        $levelKey = preg_replace('/[^A-Za-z0-9_]/', '', $levelKey) ?? '';
        if ($levelKey === '' || !self::isValidObjectId($userId)) {
            return false;
        }

        $clean = [];
        foreach ($notes as $n) {
            if (!is_string($n) && !is_numeric($n)) {
                continue;
            }
            $t = trim((string) $n);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t, 'UTF-8') > self::MAX_LEN) {
                $t = mb_substr($t, 0, self::MAX_LEN, 'UTF-8');
            }
            if (!in_array($t, $clean, true)) {
                $clean[] = $t;
            }
            if (count($clean) >= self::MAX_NOTES) {
                break;
            }
        }

        try {
            $result = Database::getConnection()->selectCollection('users')->updateOne(
                ['_id' => new ObjectId($userId)],
                ['$set' => [
                    "progress.$levelKey.notes" => $clean,
                    "progress.$levelKey.notes_updated" => new UTCDateTime(),
                ]]
            );
            return $result->getMatchedCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lee las notas guardadas. Sin $levelKey devuelve mapa nivel => notas.
     * @return array<int|string, mixed>
     */
    public static function loadNotes(string $userId, ?string $levelKey = null): array
    {
        if (!self::isValidObjectId($userId)) {
            return [];
        }

        try {
            $doc = Database::getConnection()->selectCollection('users')->findOne(
                ['_id' => new ObjectId($userId)],
                ['projection' => ['progress' => 1]]
            );

            if ($doc === null || !isset($doc['progress'])) {
                return [];
            }

            $progress = json_decode(json_encode($doc['progress']), true) ?? [];

            if ($levelKey !== null) {
                $notes = $progress[$levelKey]['notes'] ?? [];
                return is_array($notes) ? array_values($notes) : [];
            }

            $out = [];
            foreach ($progress as $k => $v) {
                if (is_array($v) && isset($v['notes']) && is_array($v['notes'])) {
                    $out[$k] = array_values($v['notes']);
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function isValidObjectId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{24}$/i', $id) === 1;
    }
}
