# Plan de Acción y Tareas de Agentes: NiXon.fear
### Documento operativo para OpenCode — 3 Actividades Académicas

Este documento instruye al equipo de agentes sobre la **secuencia de tareas a ejecutar** para transformar *NiXon.fear* según las 3 fases académicas, garantizando en todo momento la jugabilidad y produciendo los entregables `.zip` totalmente limpios.

> **Regla de oro:** cada actividad produce una copia funcional y limpia de *NiXon.fear* — el juego nunca debe dejar de ser jugable.

---

## 1. Reparto de Responsabilidades en el Equipo

| Agente | Rol en la Ejecución de Entregables |
| :--- | :--- |
| **`@coordinator`** | Dirige el flujo por actividad, verifica la creación de ramas/copias limpias de *NiXon.fear* y ejecuta el empaquetado final sin archivos del sistema de agentes. |
| **`@analyst`** | Diseña los esquemas BSON para MongoDB (`notes` y `usuarios`), la arquitectura de rutas para HTMX y la matriz de permisos por roles (RBAC). |
| **`@implementer`** | Desarrolla la lógica PHP en `NotesModel.php`, `NotesController.php`, los endpoints de búsqueda, `Sesion.php` y `Csrf.php`. |
| **`@ui-designer`** | Adapta la interfaz de notas a HTMX 2.0.11, crea las vistas parciales (`_tabla_notas.php`), los contadores `<hx-partial>` y los estilos de carga. |
| **`@reviewer`** | Ejecuta las pruebas de comportamiento en la búsqueda e interfaz, verifica que el juego no se rompa y revisa la estructura del `.zip`. |
| **`@security`** | Audita el hashing de claves, la expiración de sesiones, tokens CSRF y restricción de roles antes de autorizar el cierre. |

---

## 2. Secuencia de Actividades y Tareas

### 🟢 Actividad 1 — CRUD Base de Notas en MongoDB

**Objetivo:** Asegurar que *NiXon.fear* administre correctamente el CRUD de notas del juego persistiéndolas en MongoDB.

**Flujo de trabajo:**
1. `@analyst` — Define la estructura del documento en la colección `notes` de MongoDB.
2. `@implementer` — Refactoriza/implementa `NotesModel.php` y los scripts PHP de operaciones (Crear, Leer, Editar, Eliminar notas).
3. `@reviewer` — Comprueba que las operaciones CRUD funcionen desde la interfaz sin afectar el motor Phaser ni los niveles.
4. `@coordinator` — Redacta el `README.md` con instrucciones de MongoDB y genera `Actividad_1.zip` excluyendo `vendor/` y archivos `.md` de agentes.

**Criterio de cierre:** el jugador puede crear, consultar, editar y borrar sus notas y éstas persisten en MongoDB.

---

### 🟢 Actividad 2 — Panel de Notas MVC + HTMX (Búsqueda e Hipermedia)

**Objetivo:** Evolucionar el panel de notas de *NiXon.fear* a arquitectura MVC y HTMX sin recargas de página.

**Flujo de trabajo:**
1. `@analyst` — Estructura las rutas MVC (`app/Models`, `app/Controllers`, `app/Views/notes/`).
2. `@ui-designer` — Integra HTMX 2.0.11 en el panel de notas. Agrega la barra de búsqueda con delay de 400 ms (`hx-trigger="keyup changed delay:400ms"`), los dropdowns de filtrado por nivel/categoría y la actualización dinámica con `_tabla_notas.php`.
3. `@implementer` — Desarrolla `NotesController.php` para detectar la cabecera `HX-Request` y retornar respuestas fragmentadas o parciales `<hx-partial>`.
4. `@reviewer` — Realiza las 10 pruebas de comportamiento y toma las 5 evidencias exigidas para el `README.md`.
5. `@coordinator` — Empaqueta `Actividad_2.zip` con la versión MVC+HTMX de *NiXon.fear* limpia de agentes.

**Criterio de cierre:** la búsqueda y filtros actualizan la tabla sin recargar la página y la URL refleja el estado.

---

### 🟢 Actividad 3 — Seguridad Total, Autenticación, RBAC y CSRF

**Objetivo:** Blindar *NiXon.fear* implementando gestión de usuarios, roles, protección de sesiones y tokens CSRF.

**Flujo de trabajo:**
1. `@analyst` — Diseña la colección `usuarios` y la matriz de permisos (Rol `admin`: borrado/edición total; Rol `jugador`/`consulta`: vista e interacción normal).
2. `@implementer` — Crea `app/Security/Sesion.php` (cookies `HttpOnly`, `SameSite`, timeout 20 min) y `app/Security/Csrf.php`. Implementa `scripts/crear_usuarios_demo.php` con `password_hash()`.
3. `@security` — Revisa que la API de notas, el login/logout y el cambio de nivel exijan verificación de token CSRF y validación de sesión.
4. `@reviewer` — Intenta vulnerar rutas directas sin autenticación para confirmar la redirección al login.
5. `@coordinator` — Empaqueta `Actividad_3.zip` con la versión final y protegida de *NiXon.fear*.

**Criterio de cierre:** cualquier acceso sin sesión válida redirige al login y toda operación sensible exige CSRF.

---

## 3. Protocolo de Empaquetado Limpio (`.zip`)

Para cada actividad, el `@coordinator` ejecutará este comando en consola para garantizar la exclusión de artefactos ajenos al juego:

```bash
# Ejemplo de empaquetado para Actividad 1
zip -r Actividad_1.zip . \
  -x "vendor/*" ".*" "*.md" "auditors/*" "docs/*" \
     ".opencode/*" "constitution.md" "NiXon.md" \
     "instrucciones_entregables.md" "actividades_entregables.md" \
     "release_criteria.md" "auditoria_*.md" "secuencia_nivel1.txt" \
     ".env" "debug_login.log"

# Agregar únicamente el README.md específico de la entrega
zip -g Actividad_1.zip README.md
```

### Lista de Chequeo Final de Entrega
- [ ] El juego *NiXon.fear* arranca y es jugable.
- [ ] No existe la carpeta `vendor/` dentro del `.zip`.
- [ ] Los archivos `.opencode/`, `constitution.md`, `NiXon.md`, `instrucciones_entregables.md` y `actividades_entregables.md` **NO** están dentro del `.zip`.
- [ ] El archivo `README.md` de la actividad está presente en la raíz del `.zip`.
- [ ] `composer.json` y `composer.lock` están incluidos.

---

## 4. Prompt Inicial para OpenCode

Una vez guardados ambos archivos en la raíz del proyecto, envía este prompt al agente coordinador:

> `@coordinator` He creado los archivos `instrucciones_entregables.md` y `actividades_entregables.md` ajustados a nuestro proyecto **NiXon.fear** usando el módulo de **Notas** y **Progreso** como entidad central.
>
> Por favor, lee ambos archivos y presenta el plan de trabajo detallado para comenzar inmediatamente con la **Actividad 1** (CRUD de Notas en MongoDB + copia limpia de NiXon.fear). Asigna las primeras tareas al `@analyst` y al `@implementer`.