<?php
require_once __DIR__ . '/../vendor/autoload.php';

class Database {
    private static $client = null;
    private static $db = null;

    public static function getConnection() {
        if (self::$db === null) {
            // Modifica esta cadena con tus credenciales reales
            $uri = "mongodb+srv://admin_NiXon:NiXon123!@nixonfear.fvwsnmj.mongodb.net/?appName=NiXonFear";
            self::$client = new MongoDB\Client($uri);
            self::$db = self::$client->selectDatabase('nixon_fear_db');
        }
        return self::$db;
    }
}