<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/AuthController.php';

/**
 * Level1Controller
 * NiXon.fear — "EL INCIDENTE DE LA HABITACIÓN 4G"
 * Validación por AJAX de los 5 acertijos. Responde siempre JSON.
 */
final class Level1Controller
{
    private AuthController $auth;

    public function __construct()
    {
        $this->auth = new AuthController();
        $this->auth->bootSession();
    }

    /** Punto de entrada único invocado desde el endpoint público. */
    public function handleRequest(array $post): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->auth->isAuthenticated()) {
            $this->respond(false, 'SESIÓN NO AUTORIZADA.', 401);
        }

        if (!$this->auth->validateCsrf($post['csrf_token'] ?? null)) {
            $this->respond(false, 'TOKEN DE SEGURIDAD INVÁLIDO.', 403);
        }

        $puzzle = (int)($post['puzzle'] ?? 0);

        $result = match ($puzzle) {
            1 => $this->checkPuzzle1($post),
            2 => $this->checkPuzzle2($post),
            3 => $this->checkPuzzle3($post),
            4 => $this->checkPuzzle4($post),
            5 => $this->checkPuzzle5($post),
            default => ['success' => false, 'message' => 'ACERTIJO NO RECONOCIDO.'],
        };

        if ($result['success']) {
            $_SESSION['level1_progress'][$puzzle] = true;
        }

        $this->respond($result['success'], $result['message']);
    }

    // -----------------------------------------------------------
    // ACERTIJO 1 — "LA CLAVE ESTÁ A LA VISTA"
    // -----------------------------------------------------------

    private function checkPuzzle1(array $post): array
    {
        $user = strtoupper(trim((string)($post['username'] ?? '')));
        $pass = strtoupper(trim((string)($post['password'] ?? '')));

        if ($user === 'E.VALE' && $pass === 'M110998') {
            return [
                'success' => true,
                'message' => "C:> whoami\nEVALE\n\n\"Alguien ya estuvo buscando antes que yo.\"",
            ];
        }

        return [
            'success' => false,
            'message' => "ACCESO DENEGADO.\n\"La pista no está escondida. Está organizada.\"",
        ];
    }

    // -----------------------------------------------------------
    // ACERTIJO 2 — "ALGUIEN ESTÁ ESCUCHANDO"
    // -----------------------------------------------------------

    private function checkPuzzle2(array $post): array
    {
        $cmd = strtolower(trim((string)($post['command'] ?? '')));
        $cmd = preg_replace('/\s+/', ' ', $cmd);

        if ($cmd === 'taskkill /pid 744') {
            return [
                'success' => true,
                'message' => "TERMINATING PROCESS 744...\nMONITOR.EXE\nPROCESS TERMINATED.\n\n\"Esto no empezó anoche.\"",
            ];
        }

        if ($cmd === 'del monitor.exe') {
            return [
                'success' => false,
                'message' => "ERROR.\nARCHIVO BLOQUEADO POR PROCESO ACTIVO.",
            ];
        }

        return [
            'success' => false,
            'message' => 'COMANDO INCORRECTO. Revise la lista de procesos activos.',
        ];
    }

    // -----------------------------------------------------------
    // ACERTIJO 3 — "LO BORRADO SIGUE OCUPANDO ESPACIO"
    // -----------------------------------------------------------

    private function checkPuzzle3(array $post): array
    {
        $answer = strtoupper(trim((string)($post['confirm'] ?? '')));

        if ($answer === 'Y') {
            return [
                'success' => true,
                'message' => "Reconstructing...\n[##########] 100%\n\nOUTPUT: C:\\RECOVERED\\CASE.DB",
            ];
        }

        return [
            'success' => false,
            'message' => 'RECONSTRUCCIÓN CANCELADA. El archivo permanece dañado.',
        ];
    }

    // -----------------------------------------------------------
    // ACERTIJO 4 — "LA PUERTA NO ESTÁ CERRADA"
    // -----------------------------------------------------------

    private function checkPuzzle4(array $post): array
    {
        $user = strtolower(trim((string)($post['username'] ?? '')));
        $pass = trim((string)($post['password'] ?? ''));

        // Normaliza comillas rectas/tipográficas para aceptar variantes de la
        // misma condición booleana tautológica sembrada en el lore.
        $normalized = strtolower(str_replace(['"', '`', '´'], "'", $pass));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        $isTautology = (bool)preg_match(
            "/^'?\s*or\s*'?1'?\s*=\s*'?1'?\s*-{0,2}\s*$/",
            $normalized
        );

        if ($user === 'admin' && $isTautology) {
            return [
                'success' => true,
                'message' =>
                    "QUERY ACCEPTED\nBOOLEAN CONDITION: TRUE\nAUTHENTICATION: BYPASSED\n\n" .
                    "WELCOME, ADMIN.\n\n" .
                    "TABLA: INCIDENTS\nSUBJECT: ELIAS VALE\nSTATUS: CLOSED\nOFFICIAL CAUSE: SUICIDE\n" .
                    "INTERNAL COMMENT: \"DO NOT RELEASE TO LOCAL PD\"\n" .
                    "AUTHORIZATION: R. MERCER\nINCIDENT TYPE: INTERNAL TERMINATION\n\n" .
                    "\"Terminación.\"\n\"Eso no es lenguaje de Recursos Humanos.\"",
            ];
        }

        return [
            'success' => false,
            'message' => "LEGACY AUTH REJECTED.\n\"En 2003 todavía hay sistemas que confían en que nadie escribirá algo que no debería.\"",
        ];
    }

    // -----------------------------------------------------------
    // ACERTIJO 5 — "EL ÚLTIMO MENSAJE" (César, desplazamiento 4)
    // -----------------------------------------------------------

    private function checkPuzzle5(array $post): array
    {
        $answer = strtoupper(trim((string)($post['decrypted'] ?? '')));
        $answer = preg_replace('/\s+/', ' ', $answer);

        $expectedLines = [
            'THIS IS NOT A SUICIDE',
            'THE KILLER KNOWS THIS ROOM',
            'SOMEONE IS STILL HERE',
        ];

        $normalizedExpected = implode(' ', $expectedLines);
        $acceptFirstLine = $expectedLines[0];

        if ($answer === $normalizedExpected || $answer === $acceptFirstLine) {
            return [
                'success' => true,
                'message' =>
                    "SOMEONE IS STILL HERE, D. VANCE.\n\n" .
                    "REMOTE SESSION DETECTED\nSOURCE: LOCAL\n\nCAMERA DEVICE: ACTIVE\n\n" .
                    "YOU TOOK 05:43.\n\n" .
                    "INCIDENT 01 COMPLETE\nEVIDENCE RECOVERED: 5/5\nCASE STATUS: REOPENED",
            ];
        }

        return [
            'success' => false,
            'message' => "ERROR: INVALID CHARACTER SET.\n\"No uses herramientas nuevas. Piensa como piensa una máquina vieja.\"",
        ];
    }

    // -----------------------------------------------------------
    // UTILIDAD
    // -----------------------------------------------------------

    private function respond(bool $success, string $message, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        echo json_encode([
            'success' => $success,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -----------------------------------------------------------------
// Endpoint público: public/ajax/level1_check.php debe requerir este
// archivo e invocar el controlador así:
//
//   require_once __DIR__ . '/../../src/Controllers/Level1Controller.php';
//   (new \App\Controllers\Level1Controller())->handleRequest($_POST);
// -----------------------------------------------------------------
