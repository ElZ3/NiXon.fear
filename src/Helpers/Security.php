<?php
declare(strict_types=1);

/**
 * Sal fija del sistema. Sustituir por un valor propio y mantenerlo fuera
 * del control de versiones (variable de entorno, archivo de config, etc.).
 */
const APP_SALT = 'x7Q!vB92_r#Lm4Zs';

/**
 * Devuelve el SHA-256 de un string combinado con una sal.
 *
 * @param string      $input  Texto de entrada.
 * @param string|null $salt   Sal a usar. Si es null, se usa APP_SALT.
 * @param bool        $binary true → 32 bytes crudos; false → 64 chars hex.
 */
function saltedHash(string $input, ?string $salt = null, bool $binary = false): string
{
    $salt = $salt ?? APP_SALT;

    // Separador \x00 para evitar colisiones por concatenación ambigua
    // (p.ej. "ab"+"c" vs "a"+"bc").
    return hash('sha256', $salt . "\0" . $input, $binary);
}

/**
 * Variante HMAC-SHA256: preferible cuando la sal actúa como clave secreta,
 * ya que evita ataques de extensión de longitud.
 */
function saltedHmac(string $input, ?string $key = null, bool $binary = false): string
{
    return hash_hmac('sha256', $input, $key ?? APP_SALT, $binary);
}

/**
 * Comparación en tiempo constante contra un digest conocido.
 */
function verifySaltedHash(string $input, string $expectedHex, ?string $salt = null): bool
{
    return hash_equals($expectedHex, saltedHash($input, $salt));
}

/**
 * Genera una sal aleatoria criptográficamente segura (hex).
 *
 * @param int $bytes Bytes de entropía (16 = 128 bits).
 */
function generateSalt(int $bytes = 16): string
{
    if ($bytes < 1) {
        throw new InvalidArgumentException('El número de bytes debe ser >= 1.');
    }

    return bin2hex(random_bytes($bytes));
}

/* ------------------------------------------------------------------
   Ejemplo de uso (eliminar en producción)
   ------------------------------------------------------------------ */

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $plain = 'hola_mundo';

    echo 'SHA-256 + sal : ' . saltedHash($plain)               . PHP_EOL;
    echo 'HMAC-SHA256   : ' . saltedHmac($plain)               . PHP_EOL;
    echo 'Sal aleatoria : ' . generateSalt()                   . PHP_EOL;
    echo 'Verificación  : ' . (verifySaltedHash($plain, saltedHash($plain)) ? 'OK' : 'FAIL') . PHP_EOL;
}