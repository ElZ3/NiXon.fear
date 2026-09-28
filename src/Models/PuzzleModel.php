<?php
require_once __DIR__ . '/../../config/database.php';

class PuzzleModel {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function verifyAnswer($puzzleId, $inputAnswer) {
        $puzzle = $this->db->puzzles->findOne(['puzzle_id' => $puzzleId]);
        if (!$puzzle) return false;

        // Comparación segura de hashes (SHA-256)
        return hash_equals($puzzle['answer_hash'], hash('sha256', strtolower(trim($inputAnswer))));
    }

    public function getHint($puzzleId, $unlockedLevel) {
        $puzzle = $this->db->puzzles->findOne(['puzzle_id' => $puzzleId]);
        if (!$puzzle || !isset($puzzle['hints'][$unlockedLevel])) {
            return "No hay pistas adicionales disponibles.";
        }
        return $puzzle['hints'][$unlockedLevel];
    }
}