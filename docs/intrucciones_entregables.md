# Mapeo de Adaptación Técnica: NiXon.fear
### Guía de adaptación de las 3 actividades académicas al proyecto real

Este documento especifica cómo adaptar las exigencias académicas de las **3 actividades** al contexto real de **NiXon.fear**, utilizando como entidad central el módulo de **Notas del Jugador** (`NotesModel`) y la persistencia en **MongoDB**.

La estrategia no es crear un proyecto sintético, sino **evolucionar el videojuego NiXon.fear** a través de las 3 fases (CRUD base → HTMX + MVC → Seguridad/RBAC/CSRF), generando en cada etapa una **copia limpia y funcional del juego**.

---

## 1. Entidad Central del Sistema: `Notas`

Toda la interacción de las 3 actividades se basa en la gestión de notas descubiertas, guardadas y consultadas dentro del universo de *NiXon.fear*.

### Esquema BSON/JSON de la Colección `notes` en MongoDB

```json
{
  "_id": { "$oid": "6700112233445566778899aa" },
  "user_id": "fabio_dev",
  "level": "level1",
  "category": "pistas_safe",
  "note_text": "Combinación del archivero: 010-99991-712",
  "impact": "critico",
  "created_at": { "$date": "2026-10-04T05:57:15Z" }
}
```

### Colecciones implicadas
| Colección | Propósito | Actividad |
| :--- | :--- | :--- |
| `notes` | Notas/pistas descubiertas por el jugador | 1, 2, 3 |
| `usuarios` | Cuentas, roles y credenciales | 3 |
| `progress` | Estado del jugador por nivel | 1, 3 |

---

## 2. Adaptación por Actividad a NiXon.fear

### 🎯 Actividad 1: Fundamentos y Operaciones Básicas de Notas (MongoDB + PHP)

**Adaptación al juego:** Implementar el ciclo de vida completo (Crear, Leer, Editar, Eliminar) de las notas recogidas en el nivel 1.

**Módulos involucrados:**
- `config/database.php` — Conexión directa a MongoDB Atlas / Local (`nixon_fear_db`).
- `api/save_notes.php` y `Models/NotesModel.php` — Funciones de backend para insertar, modificar, listar y borrar notas del expediente del jugador.
- `public/levels/welcome.php` o panel de gestión — Interfaz limpia donde el jugador o administrador pueda gestionar el expediente de notas.

**Entregable limpio:** Una copia funcional de *NiXon.fear* donde el CRUD de notas en MongoDB funciona de forma transparente.

---

### 🎯 Actividad 2: Panel Hipermedia de Notas con HTMX y MVC

**Adaptación al juego:** Transformar la consulta de notas del jugador en un panel dinámico hipermedia usando **HTMX 2.0.11** sin recargas de página.

**Módulos involucrados (Patrón MVC):**
- `app/Models/NotesModel.php` (o `Models/NotesModel.php`)
- `Controllers/NotesController.php`
- `public/views/notes/` (`index.php`, `_tabla_notas.php`, `_respuesta_htmx.php`)

**Requisitos HTMX aplicados a NiXon.fear:**
- **Búsqueda por texto con delay de 400 ms:** búsqueda en vivo dentro del contenido de las notas.
  ```html
  <input hx-get="/api/notes_search.php"
         hx-trigger="keyup changed delay:400ms"
         hx-target="#tabla-notas" />
  ```
- **Filtros combinables:** por Nivel (`level1`, `level2`) y por Categoría (`pistas_safe`, `terminal`, `documentos`).
- **Parciales:** `_tabla_notas.php` para la grilla y `<hx-partial>` para actualizar el contador de notas encontradas en la UI.
- **Sincronización de URL:** `hx-push-url="true"` para reflejar los filtros en la barra del navegador.
- **Indicador de carga:** estilo CSS en `estilos.css` para el feedback visual al buscar notas.

**Entregable limpio:** Una copia de *NiXon.fear* con la arquitectura MVC implementada y el buscador hipermedia de notas dinámico.

---

### 🎯 Actividad 3: Autenticación, Sesiones, Roles y CSRF en NiXon.fear

**Adaptación al juego:** Proteger todo el ecosistema de *NiXon.fear* (inicio de juego, guardado de notas, progreso y panel de administración).

**Módulos involucrados:**
- **Colección `usuarios`:** roles `admin` (acceso total / borrado de notas) y `consulta`/`jugador` (solo lectura y sus propias notas).
- **Seguridad criptográfica:** uso obligatorio de `password_hash()` y `password_verify()` en `Controllers/AuthController.php`.
- **Gestión de sesiones (`app/Security/Sesion.php`):** cookies `HttpOnly`, `SameSite Strict/Lax`, `Secure`, expiración por inactividad a los 20 min y `session_regenerate_id(true)`.
- **Protección CSRF (`app/Security/Csrf.php`):** tokens en todas las operaciones de guardado/edición/borrado de notas y en el logout.
- **Seeder:** `scripts/crear_usuarios_demo.php` para generar cuentas demo (`admin_nixon` y `player_nixon`).

**Entregable limpio:** La versión completa y blindada de *NiXon.fear* protegida contra los ataques OWASP principales.

---

## 3. Criterios de Limpieza y Exoneración para los Paquetes `.zip`

Cada entregable (`Actividad_1.zip`, `Actividad_2.zip`, `Actividad_3.zip`) es un **snapshot auto-contenido** de *NiXon.fear* que debe cumplir:

### 3.1 Archivos a excluir obligatoriamente
- Documentación de agentes y entorno: `.opencode/`, `constitution.md`, `NiXon.md`, `release_criteria.md`, `auditoria_*.md`, `secuencia_nivel1.txt`, `instrucciones_entregables.md`, `actividades_entregables.md`.
- Dependencias instaladas: carpeta `vendor/` (se mantienen `composer.json` y `composer.lock`).
- Archivos temporales o sensibles: `.env`, `debug_login.log`, `.git/`, `.gitignore`.

### 3.2 Archivos exclusivos a incluir
- Todo el código fuente del juego (`public/`, `app/` o `Controllers/`, `Models/`, `config/`).
- El archivo `README.md` redactado específicamente para la actividad evaluada.

### 3.3 Checklist final de entrega
- [ ] El juego *NiXon.fear* arranca y es jugable.
- [ ] No existe la carpeta `vendor/` dentro del `.zip`.
- [ ] Los archivos `.opencode/`, `constitution.md`, `NiXon.md`, `instrucciones_entregables.md` y `actividades_entregables.md` **NO** están dentro del `.zip`.
- [ ] El archivo `README.md` de la actividad está presente en la raíz del `.zip`.
- [ ] `composer.json` y `composer.lock` están incluidos.