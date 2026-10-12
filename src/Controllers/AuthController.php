<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Security\Csrf;
use App\Security\Sesion;
use Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Security/Sesion.php';
require_once __DIR__ . '/../Security/Csrf.php';

/**
 * AuthController
 * NiXon.fear — Control de acceso, sesión segura, CSRF y persistencia de
 * progreso, respaldado por MongoDB Atlas (colección "users").
 *
 * Equivalencia documentada: la colección se llama `users` (NO `usuarios`).
 * El campo de rol es `rol` con valores 'admin' | 'jugador' (default 'jugador').
 *
 * Esquema de documento de usuario:
 *   {
 *     _id: ObjectId,
 *     email: string,
 *     password_hash: string (password_hash/PASSWORD_DEFAULT, nunca SHA casero),
 *     rol: 'admin' | 'jugador',
 *     created_at: UTCDateTime,
 *     progress: { level1: { step: int, completed: bool, ... }, ... }
 *   }
 */
final class AuthController
{
    public const DEFAULT_ROLE = 'jugador';
    public const ALLOWED_ROLES = ['admin', 'jugador'];

    // Rate-limit de auth en $_SESSION: 5 intentos fallidos → bloqueo de
    // 60s × nº de bloqueos. El mensaje de login sigue siendo genérico.
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_BASE_SECONDS = 60;

    private ?Collection $usersCollection = null;

    // ---------------------------------------------------------------
    // SESIÓN
    // ---------------------------------------------------------------

    public function bootSession(): void
    {
        $this->sendSecurityHeaders();
        Sesion::start();
        Sesion::enforceTimeout();
        Csrf::ensure();
    }

    /**
     * Cabeceras mínimas de seguridad. CSP básica compatible con el juego:
     * htmx vía unpkg.com, Phaser vía cdnjs.cloudflare.com e inline-scripts
     * propios (Phaser/level1 usan <script> en línea).
     */
    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; script-src 'self' unpkg.com cdnjs.cloudflare.com 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'");
    }

    // ---------------------------------------------------------------
    // RATE-LIMIT (login/register, solo $_SESSION, sin persistencia)
    // ---------------------------------------------------------------

    /**
     * @return array{count:int, blocked_until:int, blocks:int, last:int}
     */
    private function rateState(string $scope): array
    {
        $s = $_SESSION['auth_rate'][$scope] ?? null;
        if (!is_array($s)) {
            return ['count' => 0, 'blocked_until' => 0, 'blocks' => 0, 'last' => 0];
        }
        return [
            'count'         => (int) ($s['count'] ?? 0),
            'blocked_until' => (int) ($s['blocked_until'] ?? 0),
            'blocks'        => (int) ($s['blocks'] ?? 0),
            'last'          => (int) ($s['last'] ?? 0),
        ];
    }

    private function isRateBlocked(string $scope): bool
    {
        return $this->rateState($scope)['blocked_until'] > time();
    }

    private function recordAuthFailure(string $scope): void
    {
        $s = $this->rateState($scope);
        $s['count']++;
        $s['last'] = time();
        if ($s['count'] >= self::RATE_LIMIT_MAX_ATTEMPTS) {
            $s['blocks']++;
            $s['blocked_until'] = time() + (self::RATE_LIMIT_BASE_SECONDS * $s['blocks']);
            $s['count'] = 0;
        }
        $_SESSION['auth_rate'][$scope] = $s;
    }

    private function clearRateLimit(string $scope): void
    {
        unset($_SESSION['auth_rate'][$scope]);
    }

    // ---------------------------------------------------------------
    // CSRF
    // ---------------------------------------------------------------

    private function ensureCsrfToken(): void
    {
        Csrf::ensure();
    }

    public function getCsrfToken(): string
    {
        return Csrf::get();
    }

    public function validateCsrf(?string $token): bool
    {
        // Los clientes JSON (fetch) envían el token por cabecera
        // X-CSRF-Token; los formularios clásicos, por campo csrf_token.
        if (($token === null || $token === '') && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $header = $_SERVER['HTTP_X_CSRF_TOKEN'];
            $token = is_string($header) ? $header : null;
        }
        return Csrf::validate($token);
    }

    public function rotateCsrf(): void
    {
        Csrf::rotate();
    }

    // ---------------------------------------------------------------
    // ESTADO DE AUTENTICACIÓN
    // ---------------------------------------------------------------

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['auth']) && !empty($_SESSION['user_id']);
    }

    public function getCurrentUserId(): ?string
    {
        return $_SESSION['user_id'] ?? null;
    }

    public function getCurrentEmail(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    public function requireAuth(string $redirectTo): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    // ---------------------------------------------------------------
    // ROLES (admin | jugador, default jugador + fallback de lectura)
    // ---------------------------------------------------------------

    public function getRole(): string
    {
        $rol = $_SESSION['user_rol'] ?? self::DEFAULT_ROLE;
        return in_array($rol, self::ALLOWED_ROLES, true) ? $rol : self::DEFAULT_ROLE;
    }

    public function isAdmin(): bool
    {
        return $this->isAuthenticated() && $this->getRole() === 'admin';
    }

    public function requireRole(string $role, string $redirectTo = 'login.php'): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: ' . $redirectTo);
            exit;
        }
        if ($this->getRole() !== $role) {
            http_response_code(403);
            exit('ACCESO DENEGADO.');
        }
    }

    public static function normalizeRole($rol): string
    {
        return in_array($rol, self::ALLOWED_ROLES, true) ? $rol : self::DEFAULT_ROLE;
    }

    // ---------------------------------------------------------------
    // REGISTRO
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: ?string}
     */
    public function register(array $post): array
    {
        if ($this->isRateBlocked('register')) {
            return ['ok' => false, 'message' => 'DEMASIADOS INTENTOS. INTENTE MÁS TARDE.'];
        }

        $csrf = $post['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!$this->validateCsrf(is_string($csrf) ? $csrf : null)) {
            return ['ok' => false, 'message' => 'TOKEN DE SEGURIDAD INVÁLIDO.'];
        }

        $email = strtolower(trim((string) ($post['email'] ?? '')));
        $password = (string) ($post['password'] ?? '');
        $passwordConfirm = (string) ($post['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->recordAuthFailure('register');
            return ['ok' => false, 'message' => 'CORREO INVÁLIDO.'];
        }

        if ($password === '' || $password !== $passwordConfirm) {
            $this->recordAuthFailure('register');
            return ['ok' => false, 'message' => 'LAS CONTRASEÑAS NO COINCIDEN.'];
        }

        // Política mínima: 8+ caracteres, al menos una letra y un número.
        if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $password)) {
            $this->recordAuthFailure('register');
            return ['ok' => false, 'message' => 'LA CLAVE DEBE TENER 8+ CARACTERES, CON LETRAS Y NÚMEROS.'];
        }

        // bcrypt (PASSWORD_DEFAULT) trunca a 72 bytes: se rechaza antes de
        // password_hash para no crear hashes ambiguos.
        if (mb_strlen($password, 'UTF-8') > 72) {
            $this->recordAuthFailure('register');
            return ['ok' => false, 'message' => 'LA CLAVE ES DEMASIADO LARGA (MÁXIMO 72 CARACTERES).'];
        }

        try {
            $existing = $this->users()->findOne(['email' => $email]);
            if ($existing !== null) {
                // Parcial II §4.1: también en registro el fallo es genérico.
                // Decisión: 'Credenciales inválidas' en lugar de 'correo ya
                // registrado' para no permitir enumerar cuentas existentes
                // (mismo tradeoff que en login; se prioriza la especificación
                // sobre la UX del mensaje). No revela si el correo existe.
                $this->recordAuthFailure('register');
                return ['ok' => false, 'message' => 'Credenciales inválidas.'];
            }

            $result = $this->users()->insertOne([
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'rol'           => self::DEFAULT_ROLE,
                'created_at'    => new UTCDateTime(),
                'progress'      => new \stdClass(), // se completa por nivel a medida que se juega
            ]);

            $insertedId = $result->getInsertedId();
            if ($insertedId === null) {
                $this->recordAuthFailure('register');
                return ['ok' => false, 'message' => 'NO SE PUDO CREAR EL AGENTE. INTENTE DE NUEVO.'];
            }

            // Registro exitoso: inicia sesión automáticamente.
            Sesion::regenerate();
            $_SESSION['auth'] = true;
            $_SESSION['user_id'] = (string) $insertedId;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_rol'] = self::DEFAULT_ROLE;
            $_SESSION['last_activity'] = time();
            $this->clearRateLimit('register');
            $this->rotateCsrf();

            return ['ok' => true, 'message' => 'AGENTE REGISTRADO. ACCESO CONCEDIDO.'];
        } catch (\Throwable $e) {
            $this->recordAuthFailure('register');
            return ['ok' => false, 'message' => 'ERROR DE CONEXIÓN A LA BASE DE DATOS.'];
        }
    }

    // ---------------------------------------------------------------
    // LOGIN
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: ?string}
     */
    public function handleLogin(array $post): array
    {
        // Bloqueo: mensaje genérico idéntico al de credenciales inválidas
        // para no revelar si el correo existe ni el estado del rate-limit.
        if ($this->isRateBlocked('login')) {
            return ['ok' => false, 'message' => 'Credenciales inválidas.'];
        }

        $csrf = $post['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!$this->validateCsrf(is_string($csrf) ? $csrf : null)) {
            return ['ok' => false, 'message' => 'TOKEN DE SEGURIDAD INVÁLIDO.'];
        }

        $email = strtolower(trim((string) ($post['email'] ?? '')));
        $password = (string) ($post['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->recordAuthFailure('login');
            return ['ok' => false, 'message' => 'Credenciales inválidas.'];
        }

        try {
            $user = $this->users()->findOne(['email' => $email]);

            // Mensaje genérico deliberado (Parcial II §4.1: TODO fallo de
            // autenticación responde únicamente 'Credenciales inválidas'): no
            // revela si el correo existe o si fue la clave la que falló
            // (evita enumeración de usuarios). CSRF y error de BD mantienen
            // su mensaje: no son vectores de enumeración y enmascararlos
            // impediría el diagnóstico operativo.
            if ($user === null || !password_verify($password, $user['password_hash'])) {
                $this->recordAuthFailure('login');
                return ['ok' => false, 'message' => 'Credenciales inválidas.'];
            }

            Sesion::regenerate();
            $_SESSION['auth'] = true;
            $_SESSION['user_id'] = (string) $user['_id'];
            $_SESSION['user_email'] = $user['email'];
            // Fallback de lectura: documentos antiguos sin 'rol' → jugador.
            $_SESSION['user_rol'] = self::normalizeRole($user['rol'] ?? null);
            $_SESSION['last_activity'] = time();
            $this->clearRateLimit('login');
            $this->rotateCsrf();

            return ['ok' => true, 'message' => 'ACCESO CONCEDIDO.'];
        } catch (\Throwable $e) {
            $this->recordAuthFailure('login');
            return ['ok' => false, 'message' => 'ERROR DE CONEXIÓN A LA BASE DE DATOS.'];
        }
    }

    public function logout(): void
    {
        $this->destroySession();
    }

    private function destroySession(): void
    {
        Sesion::destroy();
        // Rota el CSRF tras el logout: la sesión anterior queda destruida y
        // el siguiente formulario (login) usa un token fresco, no el antiguo.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        Csrf::rotate();
    }

    // ---------------------------------------------------------------
    // PROGRESO DEL JUGADOR
    // ---------------------------------------------------------------

    /**
     * Persiste el progreso exacto de un nivel en el documento del jugador.
     * @param array<string, mixed> $data
     * @param string|null $asUserId override solo-admin (rama admin-ajena):
     *   guarda en otro usuario; devuelve false si no es admin o el id es inválido.
     */
    public function saveProgress(string $levelKey, array $data, ?string $asUserId = null): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return false;
        }

        if ($asUserId !== null && trim($asUserId) !== '') {
            if (!$this->isAdmin()) {
                return false;
            }
            $asUserId = trim($asUserId);
            if (preg_match('/^[a-f0-9]{24}$/i', $asUserId) !== 1) {
                return false;
            }
            $userId = $asUserId;
        }

        try {
            $result = $this->users()->updateOne(
                ['_id' => new ObjectId($userId)],
                ['$set' => ["progress.$levelKey" => $data]]
            );
            return $result->getMatchedCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Recupera el progreso guardado. Sin $levelKey devuelve el árbol completo.
     * @return array<string, mixed>|null
     */
    public function getProgress(?string $levelKey = null, ?string $asUserId = null): ?array
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return null;
        }

        if ($asUserId !== null && trim($asUserId) !== '') {
            if (!$this->isAdmin()) {
                return null;
            }
            $asUserId = trim($asUserId);
            if (preg_match('/^[a-f0-9]{24}$/i', $asUserId) !== 1) {
                return null;
            }
            $userId = $asUserId;
        }

        try {
            $doc = $this->users()->findOne(
                ['_id' => new ObjectId($userId)],
                ['projection' => ['progress' => 1]]
            );

            if ($doc === null || !isset($doc['progress'])) {
                return $levelKey !== null ? null : [];
            }

            // Convierte BSONDocument/BSONArray a arrays PHP planos.
            $progress = json_decode(json_encode($doc['progress']), true) ?? [];

            return $levelKey !== null ? ($progress[$levelKey] ?? null) : $progress;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // COLECCIÓN MONGO
    // ---------------------------------------------------------------

    private function users(): Collection
    {
        if ($this->usersCollection === null) {
            $this->usersCollection = Database::getConnection()->selectCollection('users');
        }
        return $this->usersCollection;
    }
}