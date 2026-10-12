<?php
declare(strict_types=1);

namespace App\Models;

use Database;
use MongoDB\BSON\UTCDateTime;

require_once __DIR__ . '/../../config/database.php';

/**
 * AuditLog
 * NiXon.fear — Bitácora de auditoría (Fase A1 Parcial II, puerta 7 Evidencia).
 *
 * Colección: `audit_log`
 *   {
 *     fecha: UTCDateTime (server-side, nunca de entrada),
 *     evento: string (p.ej. admin.notes.search),
 *     resultado: string (ok | denegado | error),
 *     actor: { user_id: ?string, email: string, rol: string },
 *     recurso: object (saneado: NUNCA password_hash ni tokens),
 *     http: { method: string, uri: string, ip: string, ua: string }
 *   }
 *
 * registrar() es best-effort try/catch: un fallo de bitácora nunca rompe
 * el flujo que la invoca. listar() es solo lectura (find + sort fecha desc).
 * Sin secretos ni credenciales en este archivo.
 */
final class AuditLog
{
    private const MAX_LIMIT = 100;

    /** Claves que NUNCA se persisten en `recurso` (case-insensitive). */
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'passwd',
        'pwd',
        'secret',
        'token',
        'csrf_token',
        'csrf',
        'session_id',
        'cookie',
        'authorization',
        'api_key',
        'apikey',
        'access_token',
        'refresh_token',
        'id_token',
    ];

    /**
     * Registra un evento. Nunca lanza.
     *
     * @param array<string, mixed> $recurso datos extra ya saneados aquí de nuevo
     */
    public static function registrar(string $evento, string $resultado, array $recurso = []): void
    {
        try {
            $evento = trim($evento) === '' ? 'desconocido' : mb_substr(trim($evento), 0, 64, 'UTF-8');
            $resultado = trim($resultado) === '' ? 'ok' : mb_substr(trim($resultado), 0, 32, 'UTF-8');

            $actor = [
                'user_id' => isset($_SESSION['user_id']) && is_string($_SESSION['user_id'])
                    ? mb_substr($_SESSION['user_id'], 0, 64, 'UTF-8')
                    : null,
                'email' => isset($_SESSION['user_email']) && is_string($_SESSION['user_email']) && $_SESSION['user_email'] !== ''
                    ? mb_substr($_SESSION['user_email'], 0, 254, 'UTF-8')
                    : 'anonimo',
                'rol' => isset($_SESSION['user_rol']) && is_string($_SESSION['user_rol']) && $_SESSION['user_rol'] !== ''
                    ? mb_substr($_SESSION['user_rol'], 0, 32, 'UTF-8')
                    : 'anonimo',
            ];

            $ip = '';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]));
            } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
                $ip = (string) $_SERVER['REMOTE_ADDR'];
            }

            $doc = [
                'fecha'     => new UTCDateTime(),
                'evento'    => $evento,
                'resultado' => $resultado,
                'actor'     => $actor,
                'recurso'   => self::sanitize($recurso),
                'http'      => [
                    'method' => mb_substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'), 0, 16, 'UTF-8'),
                    'uri'    => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 256, 'UTF-8'),
                    'ip'     => mb_substr($ip, 0, 64, 'UTF-8'),
                    'ua'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 256, 'UTF-8'),
                ],
            ];

            Database::getConnection()->selectCollection('audit_log')->insertOne($doc);
        } catch (\Throwable $e) {
            // Best-effort: la auditoría nunca rompe el flujo principal.
        }
    }

    /**
     * Lista eventos (solo lectura). Filtros soportados: evento, resultado,
     * email (= actor.email). Claves desconocidas se ignoran.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $filtros = [], int $limit = 50): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $filter = [];

        if (isset($filtros['evento']) && is_string($filtros['evento']) && trim($filtros['evento']) !== '') {
            $filter['evento'] = mb_substr(trim($filtros['evento']), 0, 64, 'UTF-8');
        }
        if (isset($filtros['resultado']) && is_string($filtros['resultado']) && trim($filtros['resultado']) !== '') {
            $filter['resultado'] = mb_substr(trim($filtros['resultado']), 0, 32, 'UTF-8');
        }
        if (isset($filtros['email']) && is_string($filtros['email']) && trim($filtros['email']) !== '') {
            $filter['actor.email'] = mb_substr(trim($filtros['email']), 0, 254, 'UTF-8');
        }

        try {
            $cursor = Database::getConnection()->selectCollection('audit_log')->find(
                $filter,
                ['sort' => ['fecha' => -1], 'limit' => $limit]
            );
            $out = [];
            foreach ($cursor as $doc) {
                $out[] = self::serialize($doc);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Crea los índices de la bitácora. Idempotente.
     *   db.audit_log.createIndex({ fecha: -1 })
     *   db.audit_log.createIndex({ evento: 1, fecha: -1 })
     *   db.audit_log.createIndex({ "actor.email": 1 })
     */
    public static function ensureIndexes(): void
    {
        try {
            $col = Database::getConnection()->selectCollection('audit_log');
            $col->createIndex(['fecha' => -1]);
            $col->createIndex(['evento' => 1, 'fecha' => -1]);
            $col->createIndex(['actor.email' => 1]);
        } catch (\Throwable $e) {
            // Atlas ya los tiene o sin permisos: no es fatal.
        }
    }

    /**
     * Elimina recursivamente claves sensibles de un array.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sanitize($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), self::SENSITIVE_KEYS, true)) {
                    continue;
                }
                $out[$k] = self::sanitize($v);
            }
            return $out;
        }
        if (is_string($value) && mb_strlen($value, 'UTF-8') > 512) {
            return mb_substr($value, 0, 512, 'UTF-8');
        }
        return $value;
    }

    /**
     * @param mixed $doc
     * @return array<string, mixed>
     */
    private static function serialize($doc): array
    {
        $arr = json_decode(json_encode($doc), true) ?? [];
        $fecha = null;
        if (($doc['fecha'] ?? null) instanceof UTCDateTime) {
            $fecha = $doc['fecha']->toDateTime()->format('c');
        } elseif (isset($arr['fecha']['$date'])) {
            $fecha = (string) $arr['fecha']['$date'];
        }

        return [
            'id'        => isset($arr['_id']['$oid']) ? (string) $arr['_id']['$oid'] : (string) ($doc['_id'] ?? ''),
            'fecha'     => $fecha,
            'evento'    => (string) ($arr['evento'] ?? ''),
            'resultado' => (string) ($arr['resultado'] ?? ''),
            'actor'     => is_array($arr['actor'] ?? null) ? $arr['actor'] : [],
            'recurso'   => is_array($arr['recurso'] ?? null) ? $arr['recurso'] : [],
            'http'      => is_array($arr['http'] ?? null) ? $arr['http'] : [],
        ];
    }
}
