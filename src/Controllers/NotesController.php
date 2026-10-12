<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../Models/NotesModel.php';

use App\Models\NotesModel;

/**
 * NotesController
 * NiXon.fear — Panel de Notas MVC + HTMX (Actividad 2).
 *
 * Sirve la vista de consulta (index) y el endpoint de búsqueda (search).
 * No contiene consultas Mongo: toda la lectura va por NotesModel::listNotes.
 * El escape HTML se hace en las vistas, nunca aquí.
 *
 * Profundidad asumida del punto de entrada público que invoca index():
 *   public/notes.php (1 nivel bajo public/). Por eso los relativos
 *   inyectados son 'api/notes_search.php', 'assets/...' y 'login.php'.
 *   La vista (public/views/notes/index.php) trae por defecto '../../...' para
 *   acceso directo; el controlador los sobreescribe según desde dónde sirva.
 */
final class NotesController
{
    private AuthController $auth;

    public function __construct()
    {
        $this->auth = new AuthController();
        $this->auth->bootSession();
    }

    // ---------------------------------------------------------------
    // Página completa del panel (sin HX-Request).
    // ---------------------------------------------------------------

    public function index(): void
    {
        $isHtmx = $this->isHtmx();

        if (!$this->auth->isAuthenticated()) {
            $this->handleUnauthenticated('index', $isHtmx);
        }

        // Si HTMX pide la URL de la página (hx-push-url), devuelve el parcial.
        if ($isHtmx) {
            $this->search();
            return;
        }

        // index() sanea con fallbacks: nunca rompe la página por un filtro malo.
        $level = trim((string) ($_GET['level'] ?? 'todos'));
        if (!in_array($level, ['todos', 'level1', 'level2'], true)) {
            $level = 'todos';
        }
        $category = trim((string) ($_GET['category'] ?? 'todas'));
        if (!in_array($category, array_merge(['todas'], NotesModel::CATEGORIES), true)) {
            $category = 'todas';
        }
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        if (mb_strlen($q, 'UTF-8') > 50) {
            $q = mb_substr($q, 0, 50, 'UTF-8');
        }
        $sort = trim((string) ($_GET['sort'] ?? 'asc'));
        if ($sort !== 'asc' && $sort !== 'desc') {
            $sort = 'asc';
        }
        $limit = $this->parseLimitOrDefault();

        $userId = $this->auth->getCurrentUserId();
        if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
            $this->handleUnauthenticated('index', false);
        }

        $notes = $this->fetchNotes($userId, $level, $category, $q, $limit, $sort);

        // Vars que espera public/views/notes/index.php (+ parciales).
        $email = $this->auth->getCurrentEmail();
        $isAuthenticated = true;
        $csrfToken = $this->auth->getCsrfToken();
        $searchEndpoint = 'api/notes_search.php';
        $cssHref = 'assets/css/nixon-retro.css';
        $loginHref = 'login.php';
        $welcomeHref = 'welcome.php';
        $panelSelf = 'notes.php';

        require __DIR__ . '/../../public/views/notes/index.php';
        exit;
    }

    // ---------------------------------------------------------------
    // Búsqueda: HTMX → parcial HTML; sin HX-Request → JSON como notes_list.
    // ---------------------------------------------------------------

    public function search(): void
    {
        $isHtmx = $this->isHtmx();

        if (!$this->auth->isAuthenticated()) {
            $this->handleUnauthenticated('search', $isHtmx);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->respondMethodNotAllowed($isHtmx);
        }

        $level = trim((string) ($_GET['level'] ?? 'todos'));
        if (!in_array($level, ['todos', 'level1', 'level2'], true)) {
            $this->respondInvalid($isHtmx, 'NIVEL INVÁLIDO. Use todos, level1 o level2.');
        }
        $categoryRaw = trim((string) ($_GET['category'] ?? 'todas'));
        if (!in_array($categoryRaw, array_merge(['todas'], NotesModel::CATEGORIES), true)) {
            $this->respondInvalid($isHtmx, 'CATEGORÍA INVÁLIDA.');
        }
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        if (mb_strlen($q, 'UTF-8') > 50) {
            $q = mb_substr($q, 0, 50, 'UTF-8');
        }
        $sort = trim((string) ($_GET['sort'] ?? 'asc'));
        if ($sort !== 'asc' && $sort !== 'desc') {
            $this->respondInvalid($isHtmx, 'ORDEN INVÁLIDO. Use asc o desc.');
        }
        $limit = 50;
        if (isset($_GET['limit']) && trim((string) $_GET['limit']) !== '') {
            if (!ctype_digit((string) $_GET['limit'])) {
                $this->respondInvalid($isHtmx, 'LÍMITE INVÁLIDO.');
            }
            $limit = (int) $_GET['limit'];
            if ($limit < 1 || $limit > 100) {
                $this->respondInvalid($isHtmx, 'LÍMITE INVÁLIDO. Use 1..100.');
            }
        }

        $userId = $this->auth->getCurrentUserId();
        if ($userId === null || preg_match('/^[a-f0-9]{24}$/i', $userId) !== 1) {
            $this->handleUnauthenticated('search', $isHtmx);
        }

        $notes = $this->fetchNotes($userId, $level, $categoryRaw, $q, $limit, $sort);

        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Vary: HX-Request');
            if ($notes === []) {
                $htmxState = 'empty';
                $htmxMessage = '';
                $count = 0;
                $loginHref = 'login.php';
                require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            } else {
                require __DIR__ . '/../../public/views/notes/_tabla_notas.php';
            }
            exit;
        }

        // Sin HX-Request: JSON idéntico en forma a notes_list.php.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
        echo json_encode([
            'status'  => 'success',
            'message' => 'ARCHIVERO RECUPERADO.',
            'count'   => count($notes),
            'notes'   => $notes,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------
    // Utilidades privadas (sin consultas: solo composición/saneado).
    // ---------------------------------------------------------------

    private function isHtmx(): bool
    {
        return !empty($_SERVER['HTTP_HX_REQUEST']);
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return stripos($accept, 'application/json') !== false;
    }

    /**
     * Sin auth: HTMX → 200 HTML unauthorized + HX-Redirect al login;
     * sin HTMX → redirect al login (página) o 401 JSON (API / petición JSON).
     */
    private function handleUnauthenticated(string $context, bool $isHtmx): void
    {
        $loginHref = 'login.php';
        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('HX-Redirect: ' . $loginHref);
            http_response_code(200);
            $htmxState = 'unauthorized';
            $htmxMessage = '';
            $count = 0;
            require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            exit;
        }

        if ($context === 'search' || $this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            http_response_code(401);
            echo json_encode([
                'status'  => 'error',
                'message' => 'SESIÓN NO AUTORIZADA.',
                'count'   => 0,
                'notes'   => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . $loginHref);
        exit;
    }

    private function respondInvalid(bool $isHtmx, string $message): void
    {
        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Vary: HX-Request');
            http_response_code(200);
            $htmxState = 'error';
            $htmxMessage = $message;
            $count = 0;
            $loginHref = 'login.php';
            require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'message' => $message,
            'count'   => 0,
            'notes'   => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function respondMethodNotAllowed(bool $isHtmx): void
    {
        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            http_response_code(200);
            $htmxState = 'error';
            $htmxMessage = 'MÉTODO NO PERMITIDO.';
            $count = 0;
            $loginHref = 'login.php';
            require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Allow: GET');
        http_response_code(405);
        echo json_encode([
            'status'  => 'error',
            'message' => 'MÉTODO NO PERMITIDO.',
            'count'   => 0,
            'notes'   => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function parseLimitOrDefault(): int
    {
        if (isset($_GET['limit']) && trim((string) $_GET['limit']) !== ''
            && ctype_digit((string) $_GET['limit'])
        ) {
            $n = (int) $_GET['limit'];
            if ($n >= 1 && $n <= 100) {
                return $n;
            }
        }
        return 50;
    }

    /**
     * Lee vía el Modelo. 'todos'/'todas'/'' se traducen aquí; el orden y el
     * límite se aplican por nivel en el Modelo y se fusionan aquí.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchNotes(
        string $userId,
        string $level,
        string $categoryRaw,
        string $q,
        int $limit,
        string $sort
    ): array {
        $category = ($categoryRaw === 'todas') ? null : $categoryRaw;
        $qq = ($q === '') ? null : $q;

        $levels = ($level === 'todos') ? NotesModel::LEVELS : [$level];
        $merged = [];
        foreach ($levels as $lv) {
            foreach (NotesModel::listNotes($userId, $lv, $category, $qq, $limit, $sort) as $n) {
                $merged[] = $n;
            }
        }

        // Reordena la fusión por created_at (ISO) y recorta al límite.
        usort($merged, static function (array $a, array $b) use ($sort): int {
            $cmp = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
            return $sort === 'desc' ? -$cmp : $cmp;
        });

        return array_slice(array_values($merged), 0, $limit);
    }
}
