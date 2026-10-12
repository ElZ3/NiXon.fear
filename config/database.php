<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env.php';

class Database {
    private static $client = null;
    private static $db = null;

    public static function getConnection() {
        if (self::$db === null) {
            // Carga .env solo como conveniencia local (vlucas/phpdotenv si
            // está instalado, si no el loader mínimo de config/env.php).
            // El entorno real siempre tiene prioridad y nunca se sobreescribe.
            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
            } else {
                loadEnv(__DIR__ . '/..');
            }
            $uri = getenv('MONGODB_URI');
            if ($uri === false || trim($uri) === '') {
                throw new \RuntimeException(
                    'MONGODB_URI no configurada. Define la variable de entorno ' .
                    'MONGODB_URI o crea un archivo .env (ver .env.example).'
                );
            }
            self::$client = new MongoDB\Client($uri);
            self::$db = self::$client->selectDatabase('nixon_fear_db');
        }
        return self::$db;
    }
}