<?php
declare(strict_types=1);

namespace App\Controllers;

use Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

require_once __DIR__ . '/../../config/database.php';

/**
 * AuthController
 * NiXon.fear — Control de acceso, sesión segura, CSRF y persistencia de
 * progreso, respaldado por MongoDB Atlas (colección "users").
 *
 * Esquema de documento de usuario:
 *   {
 *     _id: ObjectId,
 *     email: string,
 *     password_hash: string,
 *     created_at: UTCDateTime,
 *     progress: { level1: { step: int, completed: bool, ... }, ... }
 *   }
 */
final class AuthController
{
    private const SESSION_LIFETIME = 3600; // 1 hora

    private ?Collection $usersCollection = null;

    // ---------------------------------------------------------------
    // SESIÓN
    // ---------------------------------------------------------------

    public function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_start();
        $this->enforceSessionTimeout();
        $this->ensureCsrfToken();
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }

    private function enforceSessionTimeout(): void
    {
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > self::SESSION_LIFETIME) {
            $this->destroySession();
        }
        $_SESSION['last_activity'] = $now;
    }

    // ---------------------------------------------------------------
    // CSRF
    // ---------------------------------------------------------------

    private function ensureCsrfToken(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function getCsrfToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    public function validateCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
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
    // REGISTRO
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: ?string}
     */
    public function register(array $post): array
    {
        if (!$this->validateCsrf($post['csrf_token'] ?? null)) {
            return ['ok' => false, 'message' => 'TOKEN DE SEGURIDAD INVÁLIDO.'];
        }

        $email = strtolower(trim((string) ($post['email'] ?? '')));
        $password = (string) ($post['password'] ?? '');
        $passwordConfirm = (string) ($post['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'CORREO INVÁLIDO.'];
        }

        if ($password === '' || $password !== $passwordConfirm) {
            return ['ok' => false, 'message' => 'LAS CONTRASEÑAS NO COINCIDEN.'];
        }

        // Política mínima: 8+ caracteres, al menos una letra y un número.
        if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $password)) {
            return ['ok' => false, 'message' => 'LA CLAVE DEBE TENER 8+ CARACTERES, CON LETRAS Y NÚMEROS.'];
        }

        try {
            $existing = $this->users()->findOne(['email' => $email]);
            if ($existing !== null) {
                return ['ok' => false, 'message' => 'ESE CORREO YA ESTÁ REGISTRADO.'];
            }

            $result = $this->users()->insertOne([
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at'    => new UTCDateTime(),
                'progress'      => new \stdClass(), // se completa por nivel a medida que se juega
            ]);

            $insertedId = $result->getInsertedId();
            if ($insertedId === null) {
                return ['ok' => false, 'message' => 'NO SE PUDO CREAR EL AGENTE. INTENTE DE NUEVO.'];
            }

            // Registro exitoso: inicia sesión automáticamente.
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['user_id'] = (string) $insertedId;
            $_SESSION['user_email'] = $email;
            $_SESSION['last_activity'] = time();
            $this->ensureCsrfToken();

            return ['ok' => true, 'message' => 'AGENTE REGISTRADO. ACCESO CONCEDIDO.'];
        } catch (\Throwable $e) {
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
        if (!$this->validateCsrf($post['csrf_token'] ?? null)) {
            return ['ok' => false, 'message' => 'TOKEN DE SEGURIDAD INVÁLIDO.'];
        }

        $email = strtolower(trim((string) ($post['email'] ?? '')));
        $password = (string) ($post['password'] ?? '');

        if ($email === '' || $password === '') {
            return ['ok' => false, 'message' => 'ACCESO DENEGADO. Las credenciales no coinciden.'];
        }

        try {
            $user = $this->users()->findOne(['email' => $email]);

            // Mensaje genérico deliberado: no revela si el correo existe o
            // si fue la contraseña la que falló (evita enumeración de usuarios).
            if ($user === null || !password_verify($password, $user['password_hash'])) {
                return ['ok' => false, 'message' => 'ACCESO DENEGADO. Las credenciales no coinciden.'];
            }

            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['user_id'] = (string) $user['_id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['last_activity'] = time();
            $this->ensureCsrfToken();

            return ['ok' => true, 'message' => 'ACCESO CONCEDIDO.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'ERROR DE CONEXIÓN A LA BASE DE DATOS.'];
        }
    }

    public function logout(): void
    {
        $this->destroySession();
    }

    private function destroySession(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    // ---------------------------------------------------------------
    // PROGRESO DEL JUGADOR
    // ---------------------------------------------------------------

    /**
     * Persiste el progreso exacto de un nivel en el documento del jugador.
     * @param array<string, mixed> $data
     */
    public function saveProgress(string $levelKey, array $data): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return false;
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
    public function getProgress(?string $levelKey = null): ?array
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return null;
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