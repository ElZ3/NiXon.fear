<?php
declare(strict_types=1);

namespace App\Security;

/**
 * Csrf — Token anti-CSRF de sesión (NiXon.fear).
 *
 * Comparación con hash_equals. El token vive en $_SESSION['csrf_token'].
 */
final class Csrf
{
    public static function ensure(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function get(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }

    public static function rotate(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
