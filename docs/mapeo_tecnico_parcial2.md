# Mapeo técnico Parcial II — Equivalencias código real vs documentos académicos
Fecha: 2026-10-05. Propósito: justificar ante evaluación las diferencias nominales (sin renombres que rompan el juego).

## 1. Rutas de seguridad: `app/Security/` ≡ `src/Security/`
- Docs (`evaluacion_parcial_nixon.md` §3, `actividades_entregables.md` Act.3) exigen `app/Security/Sesion.php` y `app/Security/Csrf.php`.
- Real: `src/Security/Sesion.php` (`App\Security`) y `src/Security/Csrf.php`. Motivo: no existe `app/` ni autoload PSR-4 en `composer.json`; todo el proyecto resuelve con `require_once __DIR__.'/../../src/...'`. Crear `app/` duplicaría clases y rompería el dual-write Act.1-2 (dictamen @analyst Act.2, confirmado en auditoría).
- Contenido idéntico al exigido: Sesion (1200s, HttpOnly, SameSite Strict, Secure auto, regenerate_id) y Csrf (ensure/get/validate hash_equals/rotate).

## 2. Campo propietario: `propietario_id` ≡ `user_id`
- Parcial II §6 exige filtro `{ _id: id_nota, propietario_id: usuario.id }` y helper `obtenerNotaSegura($idNota, $idUsuario, $rolUsuario)`.
- Real: campo `user_id` (ObjectId, ref `users._id`) con ownership `{_id, user_id}` en get/update/delete + variantes `*Any` solo admin. Misma garantía IDOR con otro nombre (el esquema Act.1 usa `user_id` desde el inicio; renombrar rompería colección `notes`, índices y dual-write).
- `obtenerNotaSegura($idNota, $idUsuario, $rolUsuario)` existe literal en `src/Models/NotesModel.php` (Parcial II §6): valida ObjectIds; admin → `findOne({_id})`, jugador → `findOne({_id, user_id})`. El campo exigido como `propietario_id` es el `user_id` real (NO renombrado). Lectura por id: `GET public/api/notes_get.php?id=` (nuevo; antes solo listado/búsqueda + gates de escritura). Denegación jugador-ajeno → HTTP 403 `ACCESO DENEGADO.` (se distingue de 404 inexistente vía `getNoteAny()`).

## 3. Colección de cuentas: `usuarios` ≡ `users`
- Docs exigen `usuarios`; el juego persiste en `users` (con `progress` embebido que lee Phaser). Se añade solo `rol` (`admin`/`jugador`). Renombrar rompería AuthController, syncProgressArray y panel.

## 4. Modelos/enchedpoints
- `app/Models/NotesModel.php` ≡ `src/Models/NotesModel.php` (namespace `App\Models`). `Controllers/NotesController.php` ≡ `src/Controllers/NotesController.php`. Endpoints `GET` lectura / `POST` mutación con 405 + `Allow` donde aplica.

## 5. Credenciales (PASO 0)
- URI Atlas fuera del código: `MONGODB_URI` por entorno/`.env` (gitignorado) o `vlucas/phpdotenv`; sin ella `RuntimeException`. `.env.example` plantilla. Pendiente operador: rotar `admin_NiXon` en Atlas y purgar historial (el secreto viejo sigue válido).
