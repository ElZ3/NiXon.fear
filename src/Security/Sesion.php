<?php
declare(strict_types=1);

namespace App\Security;

/**
 * Sesion — Arranque y ciclo de vida de la sesión PHP (NiXon.fear).
 *
 * Timeout de inactividad: 1200 s (20 min). Cookie HttpOnly + SameSite Strict,
 * flag Secure automático (solo si la petición va por HTTPS).
 */
final class Sesion
{
    public const LIFETIME = 1200; // 20 minutos

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_start();
        self::enforceTimeout();
    }

    public static function enforceTimeout(): void
    {
        $now = time();
        if (isset($_SESSION['last_activity'])
            && ($now - (int) $_SESSION['last_activity']) > self::LIFETIME
        ) {
            self::destroy();
            // Tras destruir, la sesión sigue "no activa" hasta el próximo
            // arranque; se deja last_activity fresco para el request actual.
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $_SESSION = [];
        }
        $_SESSION['last_activity'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function isHttps(): bool
    {
        // Detrás de un proxy/reverse-proxy TLS, $_SERVER['HTTPS'] no se fija:
        // se respeta X-Forwarded-Proto (toma el primer valor si hay lista).
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
            if ($proto === 'https') {
                return true;
            }
        }
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }
}
