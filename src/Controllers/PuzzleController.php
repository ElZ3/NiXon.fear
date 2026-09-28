<?php
session_start();
require_once __DIR__ . '/../Models/PuzzleModel.php';

class PuzzleController {
    private $model;

    public function __construct() {
        $this->model = new PuzzleModel();
    }

    public function submitAttempt($puzzleId, $userAnswer) {
        header('Content-Type: application/json');

        // 1. Anti-Brute Force (Máximo 1 intento cada 2 segundos)
        $now = microtime(true);
        if (isset($_SESSION['last_attempt_time']) && ($now - $_SESSION['last_attempt_time']) < 1.5) {
            echo json_encode(['status' => 'error', 'message' => 'Frenesí detectado. Espera un momento.']);
            return;
        }
        $_SESSION['last_attempt_time'] = $now;

        // 2. Validación de respuesta en servidor
        $isCorrect = $this->model->verifyAnswer($puzzleId, $userAnswer);

        if ($isCorrect) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Acceso concedido. Pista/Siguiente área desbloqueada.'
            ]);
        } else {
            echo json_encode([
                'status' => 'fail', 
                'message' => 'Código/Respuesta incorrecta. La paranoia aumenta.'
            ]);
        }
    }
}