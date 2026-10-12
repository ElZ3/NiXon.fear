<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../Models/NotesModel.php';
require_once __DIR__ . '/../Models/AuditLog.php';

use App\Models\AuditLog;
use App\Models\NotesModel;
use Database;

/**
 * AdminNotesController
 * NiXon.fear — Panel Game Master (Fase A1 Parcial II, backoffice admin).
 *
 * Archivos separados del panel jugador (sin ?vista=gm): este controlador
 * solo sirve public/admin/notes.php (dashboard) y
 * public/api/admin_notes_search.php + corrección admin (update/delete).
 * Deny by default: requireRole('admin') primero en cada acción; el jugador
 * recibe 403 ACCESO DENEGADO y el evento queda en audit_log.
 *
 * Lectura global vía NotesModel::listNotesAny() (sin user_id salvo ownerId
 * resuelto en 2 pasos desde el email). Corrección vía updateNoteAny /
 * deleteNoteAny (POST/PUT/DELETE + CSRF, con evento de auditoría).
 * El escape HTML se hace en las vistas admin, nunca aquí.
 */
final class AdminNotesController
{
    private AuthController $auth;

    public function __construct()
    {
        $this->auth = new AuthController();
        $this->auth->bootSession();
    }

    // ---------------------------------------------------------------
    // Dashboard GM (página completa, sin HX-Request).
    // ---------------------------------------------------------------

    public function dashboard(): void
    {
        $isHtmx = $this->isHtmx();

        // Deny en entry: admin primero (audita el denegado best-effort).
        $this->denyIfNotAdmin('admin.notes.dashboard', [], $isHtmx, 'page');
        $this->auth->requireRole('admin', '../login.php');

        // HTMX al panel (hx-push-url): devuelve el parcial de búsqueda.
        if ($isHtmx) {
            $this->search();
            return;
        }

        // dashboard() sanea con fallbacks: nunca rompe la página.
        [$level, $category, $q, $filterEmail, $sort, $limit] = $this->sanitizeFiltersWithFallbacks();
        $notes = $this->fetchGlobal($level, $category, $q, $filterEmail, $limit, $sort);

        AuditLog::registrar('admin.notes.dashboard', 'ok', [
            'level' => $level,
            'category' => $category,
            'sort' => $sort,
            'limit' => $limit,
            'count' => count($notes),
            'email_filtro' => $filterEmail,
        ]);

        $email = $this->auth->getCurrentEmail();
        $isAuthenticated = true;
        $csrfToken = $this->auth->getCsrfToken();
        $searchEndpoint = '../api/admin_notes_search.php';
        $cssHref = '../assets/css/nixon-retro.css';
        $loginHref = '../login.php';
        $welcomeHref = '../welcome.php';
        $panelSelf = 'notes.php';

        require __DIR__ . '/../../public/views/admin/dashboard.php';
        exit;
    }

    // ---------------------------------------------------------------
    // Búsqueda global: HTMX → parcial admin; sin HX-Request → JSON.
    // ---------------------------------------------------------------

    public function search(): void
    {
        $isHtmx = $this->isHtmx();

        $this->denyIfNotAdmin('admin.notes.search', [], $isHtmx, 'search');
        $this->auth->requireRole('admin', '../login.php');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            AuditLog::registrar('admin.notes.search', 'error', ['motivo' => 'metodo_no_permitido']);
            $this->respondMethodNotAllowed($isHtmx);
        }

        $level = trim((string) ($_GET['level'] ?? 'todos'));
        if (!in_array($level, ['todos', 'level1', 'level2'], true)) {
            $this->respondInvalid($isHtmx, 'NIVEL INVÁLIDO. Use todos, level1 o level2.', 'admin.notes.search');
        }
        $categoryRaw = trim((string) ($_GET['category'] ?? 'todas'));
        if (!in_array($categoryRaw, array_merge(['todas'], NotesModel::CATEGORIES), true)) {
            $this->respondInvalid($isHtmx, 'CATEGORÍA INVÁLIDA.', 'admin.notes.search');
        }
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        if (mb_strlen($q, 'UTF-8') > 50) {
            $q = mb_substr($q, 0, 50, 'UTF-8');
        }
        $filterEmail = isset($_GET['email']) ? strtolower(trim((string) $_GET['email'])) : '';
        if (mb_strlen($filterEmail, 'UTF-8') > 254) {
            $filterEmail = mb_substr($filterEmail, 0, 254, 'UTF-8');
        }
        if ($filterEmail !== '' && filter_var($filterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->respondInvalid($isHtmx, 'CORREO INVÁLIDO.', 'admin.notes.search');
        }
        $sort = trim((string) ($_GET['sort'] ?? 'asc'));
        if ($sort !== 'asc' && $sort !== 'desc') {
            $this->respondInvalid($isHtmx, 'ORDEN INVÁLIDO. Use asc o desc.', 'admin.notes.search');
        }
        $limit = 50;
        if (isset($_GET['limit']) && trim((string) $_GET['limit']) !== '') {
            if (!ctype_digit((string) $_GET['limit'])) {
                $this->respondInvalid($isHtmx, 'LÍMITE INVÁLIDO.', 'admin.notes.search');
            }
            $limit = (int) $_GET['limit'];
            if ($limit < 1 || $limit > 100) {
                $this->respondInvalid($isHtmx, 'LÍMITE INVÁLIDO. Use 1..100.', 'admin.notes.search');
            }
        }

        $notes = $this->fetchGlobal($level, $categoryRaw, $q, $filterEmail, $limit, $sort);

        AuditLog::registrar('admin.notes.search', 'ok', [
            'level' => $level,
            'category' => $categoryRaw,
            'sort' => $sort,
            'limit' => $limit,
            'count' => count($notes),
            'email_filtro' => $filterEmail,
        ]);

        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Vary: HX-Request');
            if ($notes === []) {
                $htmxState = 'empty';
                $htmxMessage = '';
                $count = 0;
                $loginHref = '../login.php';
                require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            } else {
                require __DIR__ . '/../../public/views/admin/_tabla.php';
            }
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
        echo json_encode([
            'status'  => 'success',
            'message' => 'ARCHIVERO GLOBAL RECUPERADO.',
            'count'   => count($notes),
            'notes'   => $notes,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------
    // Corrección admin: edita una nota ajena (POST/PUT/PATCH + CSRF).
    // ---------------------------------------------------------------

    public function update(): void
    {
        $isHtmx = $this->isHtmx();
        $this->denyIfNotAdmin('admin.notes.update', [], $isHtmx, 'api');
        $this->auth->requireRole('admin', '../login.php');

        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            AuditLog::registrar('admin.notes.update', 'error', ['motivo' => 'metodo_no_permitido']);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            // Divergencia intencional GM vs jugador: el GM acepta POST además
            // de PUT/PATCH por compatibilidad con formularios; el endpoint
            // jugador (public/api/notes_update.php) solo acepta PUT/PATCH.
            header('Allow: POST, PUT, PATCH');
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'MÉTODO NO PERMITIDO.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = $this->readPayload();
        $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!$this->auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
            AuditLog::registrar('admin.notes.update', 'denegado', ['motivo' => 'csrf_invalido']);
            $this->respondJson('error', 403, 'TOKEN DE SEGURIDAD INVÁLIDO.');
        }

        $id = trim((string) ($payload['id'] ?? ($payload['_id'] ?? ($payload['note_id'] ?? ''))));
        $text = trim((string) ($payload['note_text'] ?? ($payload['text'] ?? '')));
        $category = isset($payload['category']) && trim((string) $payload['category']) !== ''
            ? trim((string) $payload['category'])
            : null;
        $impact = isset($payload['impact']) && trim((string) $payload['impact']) !== ''
            ? trim((string) $payload['impact'])
            : null;

        if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
            $this->respondJson('error', 400, 'IDENTIFICADOR INVÁLIDO.');
        }
        if ($text === '' || mb_strlen($text, 'UTF-8') > 50) {
            $this->respondJson('error', 400, 'LA NOTA DEBE TENER ENTRE 1 Y 50 CARACTERES.');
        }
        if ($category !== null && !in_array($category, NotesModel::CATEGORIES, true)) {
            $this->respondJson('error', 400, 'CATEGORÍA INVÁLIDA.');
        }
        if ($impact !== null && !in_array($impact, NotesModel::IMPACTS, true)) {
            $this->respondJson('error', 400, 'IMPACTO INVÁLIDO.');
        }

        if (NotesModel::getNoteAny($id) === null) {
            AuditLog::registrar('admin.notes.update', 'error', ['note_id' => $id, 'motivo' => 'no_encontrada']);
            $this->respondJson('error', 404, 'NOTA NO ENCONTRADA.');
        }
        if (!NotesModel::updateNoteAny($id, $text, $category, $impact)) {
            AuditLog::registrar('admin.notes.update', 'error', ['note_id' => $id, 'motivo' => 'duplicada_o_fallo']);
            $this->respondJson('error', 409, 'NO SE PUDO EDITAR. POSIBLE NOTA DUPLICADA.');
        }

        AuditLog::registrar('admin.notes.update', 'ok', ['note_id' => $id]);
        $this->respondJson('success', 200, 'NOTA ACTUALIZADA.');
    }

    // ---------------------------------------------------------------
    // Corrección admin: elimina una nota ajena (POST/DELETE + CSRF).
    // ---------------------------------------------------------------

    public function delete(): void
    {
        $isHtmx = $this->isHtmx();
        $this->denyIfNotAdmin('admin.notes.delete', [], $isHtmx, 'api');
        $this->auth->requireRole('admin', '../login.php');

        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['POST', 'DELETE'], true)) {
            AuditLog::registrar('admin.notes.delete', 'error', ['motivo' => 'metodo_no_permitido']);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Allow: POST, DELETE');
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'MÉTODO NO PERMITIDO.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // El id SOLO se acepta en el cuerpo JSON (nunca por query string);
        // el CSRF en cuerpo o cabecera X-CSRF-Token.
        $payload = $this->readPayload();
        $id = trim((string) ($payload['id'] ?? ($payload['_id'] ?? ($payload['note_id'] ?? ''))));
        $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!$this->auth->validateCsrf(is_string($csrf) ? $csrf : null)) {
            AuditLog::registrar('admin.notes.delete', 'denegado', ['motivo' => 'csrf_invalido']);
            $this->respondJson('error', 403, 'TOKEN DE SEGURIDAD INVÁLIDO.');
        }
        if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
            $this->respondJson('error', 400, 'IDENTIFICADOR INVÁLIDO.');
        }

        if (NotesModel::getNoteAny($id) === null) {
            AuditLog::registrar('admin.notes.delete', 'error', ['note_id' => $id, 'motivo' => 'no_encontrada']);
            $this->respondJson('error', 404, 'NOTA NO ENCONTRADA.');
        }
        if (!NotesModel::deleteNoteAny($id)) {
            AuditLog::registrar('admin.notes.delete', 'error', ['note_id' => $id, 'motivo' => 'fallo_borrado']);
            $this->respondJson('error', 500, 'NO SE PUDO ELIMINAR LA NOTA.');
        }

        AuditLog::registrar('admin.notes.delete', 'ok', ['note_id' => $id]);
        $this->respondJson('success', 200, 'NOTA ELIMINADA DEL ARCHIVERO.');
    }

    // ---------------------------------------------------------------
    // Utilidades privadas (sin consultas salvo resolución email→ownerId).
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
     * Deny by default para el panel GM. Si no es admin: audita el denegado
     * best-effort y responde 403 (HTMX → 403 HTML; API/JSON → 403 JSON;
     * página → 403 plano como requireRole). Anónimo en página → redirect.
     */
    private function denyIfNotAdmin(string $evento, array $recurso, bool $isHtmx, string $context): void
    {
        if ($this->auth->isAdmin()) {
            return;
        }

        AuditLog::registrar($evento, 'denegado', $recurso);

        $loginHref = '../login.php';
        if ($context === 'page' && !$this->auth->isAuthenticated() && !$isHtmx && !$this->wantsJson()) {
            header('Location: ' . $loginHref);
            exit;
        }

        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            http_response_code(403);
            $htmxState = 'unauthorized';
            $htmxMessage = '';
            $count = 0;
            require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            exit;
        }

        if ($context !== 'page' || $this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'ACCESO DENEGADO.',
                'count' => 0,
                'notes' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(403);
        exit('ACCESO DENEGADO.');
    }

    /**
     * Saneado con fallbacks para el dashboard (nunca rompe la página).
     *
     * @return array{0:string,1:string,2:string,3:string,4:string,5:int}
     */
    private function sanitizeFiltersWithFallbacks(): array
    {
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
        $filterEmail = isset($_GET['email']) ? strtolower(trim((string) $_GET['email'])) : '';
        if (mb_strlen($filterEmail, 'UTF-8') > 254) {
            $filterEmail = mb_substr($filterEmail, 0, 254, 'UTF-8');
        }
        if ($filterEmail !== '' && filter_var($filterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $filterEmail = '';
        }
        $sort = trim((string) ($_GET['sort'] ?? 'asc'));
        if ($sort !== 'asc' && $sort !== 'desc') {
            $sort = 'asc';
        }
        $limit = 50;
        if (isset($_GET['limit']) && trim((string) $_GET['limit']) !== ''
            && ctype_digit((string) $_GET['limit'])
        ) {
            $n = (int) $_GET['limit'];
            if ($n >= 1 && $n <= 100) {
                $limit = $n;
            }
        }
        return [$level, $category, $q, $filterEmail, $sort, $limit];
    }

    /**
     * Lectura global GM. Email en 2 pasos: users.findOne(email) → ownerId;
     * si el correo no existe → [] (no revela nada más). listNotesAny() con
     * level null = todos los niveles y category null = todas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchGlobal(
        string $level,
        string $categoryRaw,
        string $q,
        string $filterEmail,
        int $limit,
        string $sort
    ): array {
        $ownerId = null;
        if ($filterEmail !== '') {
            try {
                $found = Database::getConnection()->selectCollection('users')->findOne(
                    ['email' => $filterEmail],
                    ['projection' => ['_id' => 1]]
                );
            } catch (\Throwable $e) {
                return [];
            }
            if ($found === null || !isset($found['_id'])) {
                return [];
            }
            $ownerId = (string) $found['_id'];
        }

        return NotesModel::listNotesAny(
            $level === 'todos' ? null : $level,
            $categoryRaw === 'todas' ? null : $categoryRaw,
            $q === '' ? null : $q,
            $ownerId,
            $limit,
            $sort
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $rawBody = file_get_contents('php://input');
        $payload = is_string($rawBody) ? json_decode($rawBody, true) : null;
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        return is_array($payload) ? $payload : [];
    }

    private function respondJson(string $status, int $httpCode, string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code($httpCode);
        echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function respondInvalid(bool $isHtmx, string $message, string $evento): void
    {
        AuditLog::registrar($evento, 'error', ['motivo' => $message]);
        if ($isHtmx) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Vary: HX-Request');
            http_response_code(200);
            $htmxState = 'error';
            $htmxMessage = $message;
            $count = 0;
            $loginHref = '../login.php';
            require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'count' => 0,
            'notes' => [],
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
            $loginHref = '../login.php';
            require __DIR__ . '/../../public/views/notes/_respuesta_htmx.php';
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Allow: GET');
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => 'MÉTODO NO PERMITIDO.',
            'count' => 0,
            'notes' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
