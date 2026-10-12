# NiXon.fear — Parcial II: Arquitectura de Confianza (7 puertas) — ENTREGA FINAL
Consolida Act.1 (CRUD), Act.2 (MVC+HTMX), Act.3 (RBAC/sesiones/CSRF) + dashboard GM + bitácora. Mapeo nominal: `docs/mapeo_tecnico_parcial2.md`.

## 1. Arranque seguro
`MONGODB_URI` por entorno/`.env` (gitignorado) o phpdotenv; ausente → `RuntimeException`. Cero credenciales en código. **Operador: rotar `admin_NiXon` en Atlas + purgar historial + re-verificar.**

## 2. Vista dual
Jugador: libreta in-game + `notes.php` (solo propias). Game Master (`admin`): `admin/notes.php` → `views/admin/dashboard.php` (deny-first, jugador→403): todas las notas, filtro correo/nivel/categoría 400ms, corregir/eliminar (`*Any`+CSRF), bitácora `audit_log` (fecha desc, best-effort, sin secretos).

## 3. Controles
IDOR `obtenerNotaSegura` (ajena→403, inexistente→404; `notes_get.php` nuevo). Login/registro genérico `Credenciales inválidas.` 405+`Allow` exacto en todos los endpoints (HTMX-search 200-parcial auditado). Gates complete (victoria) / gameover (flag one-time); win/lose→`levels.php`. Rate-limit, headers, `no-store` global, CSRF cuerpo/header+rotate, HEX en inyección CSRF a JS. DocumentRoot `public/` + `.htaccess`.

## 4. T01–T10: 10/10 PASS estático (runtime con Atlas+PHP pendiente de operador)
T01 login 302 · T02 genérico · T03 403/200/404 · T04 403 · T05 admin 200 · T06 302/401/403 · T07 405+Allow · T08 403/200 · T09 genérico invisible · T10 redirects+gates. Comandos y evidencias en auditoría Parcial II.


