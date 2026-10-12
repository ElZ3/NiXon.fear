<?php
// Loader mínimo de .env (fallback sin vlucas/phpdotenv).
// - Solo líneas KEY=VALUE, ignora vacías y comentarios (#).
// - No sobreescribe variables ya presentes en el entorno real.
// - Soporta valores con/sin comillas simples o dobles.
function loadEnv(string $dir): void {
    $file = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        // Quita comillas envolventes si existen.
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            continue; // el entorno real manda
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}
