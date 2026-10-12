# Especificación y Guía de Ejecución: Parcial II — NiXon.fear
### Arquitectura de Confianza, Panel Game Master y Controles de Seguridad

Este documento define la estrategia, arquitectura dual (In-Game vs Backoffice) y la especificación técnica para transformar el videojuego **NiXon.fear** en una aplicación capaz de demostrar la trazabilidad y seguridad en cada solicitud HTTP, integrando el trabajo de las 3 actividades previas y cumpliendo con los requisitos del **Parcial II**.

---

## 1. Objetivo de la Evaluación de Trabajo

Convertir el sistema de *NiXon.fear* (CRUD de notas, persistencia en MongoDB, arquitectura MVC e interfaz hipermedia con HTMX) en un ecosistema seguro y defendible. El objetivo no es alterar la experiencia de juego del usuario, sino implementar y demostrar las **7 puertas de confianza** en el servidor:

1. **Identidad:** Reconocer qué cuenta afirma ser el usuario (`_id`, correo).
2. **Credencial:** Demostrar el control del secreto mediante `password_verify()`.
3. **Sesión:** Mantener la continuidad temporal con cookies seguras y regeneración de ID.
4. **Autorización:** Decidir si la identidad puede ejecutar una acción sobre un recurso específico (Deny by Default).
5. **Integridad:** Validar originación legítima con tokens CSRF en solicitudes mutativas.
6. **Datos:** Sanitizar entradas y escapar salidas en contexto HTML.
7. **Evidencia:** Registrar eventos críticos en la bitácora de auditoría para reconstruir acciones.

---

## 2. Arquitectura Dual de NiXon.fear: Jugador vs Game Master

Para preservar la inmersión del juego y cumplir con los requisitos académicos de administración y auditoría, el sistema se divide en dos entornos independientes sostenidos por el mismo backend PHP/MongoDB:

### A. Vista del Jugador (In-Game Player View)
* **Propósito:** Experiencia de supervivencia e historia.
* **Interfaz:** Libreta de notas e inventario accesible dentro de los niveles del juego (Phaser/HTML).
* **Comportamiento:** El jugador solo interactúa con las notas que él mismo ha descubierto. 
* **Seguridad Activa:** Las peticiones HTMX y API están restringidas mediante el control de **Propiedad de Registros**. Si un usuario intenta acceder a notas ajenas mediante la consola del navegador (F12) o Postman, el servidor rechaza la acción con un estado HTTP 403.

### B. Panel Game Master / Dashboard de Soporte (Backoffice Admin)
* **Ruta de Acceso:** `public/views/admin/dashboard.php` (o `/admin/notes`).
* **Propósito:** Herramienta administrativa para los desarrolladores y el equipo de soporte técnico de *NiXon.fear*.
* **Interfaz:** Panel hipermedia desarrollado en HTMX y MVC para la gestión global del sistema.
* **Funcionalidades del Game Master:**
  * Visualizar la totalidad de notas guardadas en MongoDB por todos los usuarios.
  * Filtrar en tiempo real por correo de jugador, nivel o categoría con delay de 400ms.
  * Eliminar o corregir registros de notas corruptas que impidan el avance de un jugador.
  * Inspeccionar la bitácora de eventos y auditoría del servidor.
* **Seguridad Activa:** Exclusivo para usuarios autenticados con `rol: 'admin'`. Cualquier intento de acceso por parte de un usuario con rol `jugador` es denegado inmediatamente con error HTTP 403.

---

## 3. Integración de las 3 Actividades Base

* **Actividad 1 (CRUD Base & MongoDB):** Persistencia de las colecciones `notes` y `usuarios` en MongoDB (`nixon_fear_db`) mediante controladores centralizados[cite: 9, 10].
* **Actividad 2 (MVC + HTMX 2.0.11):** Búsqueda dinámica con delay de 400ms (`hx-trigger="keyup changed delay:400ms"`), filtros combinados por nivel y categoría, y parciales HTML (`_tabla_notas.php`, `<hx-partial>`)[cite: 9, 11]. Las solicitudes `HX-Request` están sujetas a las mismas reglas de seguridad que el tráfico estándar[cite: 9].
* **Actividad 3 (Seguridad Base):** Hashing criptográfico con `password_hash()`, gestión de sesiones en `app/Security/Sesion.php`[cite: 9], validación estricta de tokens CSRF en `app/Security/Csrf.php`[cite: 9] y script de creación de usuarios demo (`scripts/crear_usuarios_demo.php`)[cite: 9].

---

## 4. Implementación de los Nuevos Protocolos del Parcial II

1. **Prevención de Enumeración de Cuentas:** Los fallos de autenticación responden únicamente con *"Credenciales inválidas"*, impidiendo determinar si el correo existe o si la clave fue incorrecta[cite: 9].
2. **Denegar por Defecto (Deny by Default):** Si una ruta no cuenta con una regla explícita que autorice la acción para el rol autenticado, el servidor devuelve HTTP 403[cite: 9].
3. **Contrato de Métodos HTTP (405 Method Not Allowed):**
   * Peticiones `GET`: Exclusivas para consultar, listar y buscar[cite: 9].
   * Peticiones `POST`: Obligatorias para crear, editar, eliminar notas, cerrar sesión o mutar estado[cite: 9].
   * Los métodos no admitidos son rechazados con estado HTTP 405 y la cabecera `Allow`[cite: 9].
4. **Ciclo de Vida de Sesión Estricto:**
   * Cookies configuradas con `HttpOnly`, `SameSite Lax` y `Secure`[cite: 9].
   * Eliminación del ID anónimo previo mediante `session_regenerate_id(true)` inmediatamente tras el login exitoso[cite: 9].
   * Expiración automática por inactividad tras 20 minutos[cite: 9].

---

## 5. Matriz de Autorización por Roles (RBAC)

El servidor PHP es la única entidad autoritativa; ocultar elementos en la interfaz web no reemplaza las comprobaciones en la API[cite: 9].

| Acción / Endpoint | Rol `jugador` (Cliente In-Game) | Rol `admin` (Game Master) |
| :--- | :--- | :--- |
| **Inicio de Sesión** | Permitido[cite: 9] | Permitido[cite: 9] |
| **Ver Notas Propias** | Permitido (Filtrado por `propietario_id`)[cite: 9] | Permitido[cite: 9] |
| **Guardar Nueva Nota** | Permitido (Asignado a su ID de usuario)[cite: 9] | Permitido[cite: 9] |
| **Ver Notas de Otros Jugadores** | **DENEGADO (HTTP 403)**[cite: 9] | Permitido (En Panel GM)[cite: 9] |
| **Eliminar / Editar Notas Ajenas** | **DENEGADO (HTTP 403)**[cite: 9] | Permitido (Soporte Técnico)[cite: 9] |
| **Acceso al Dashboard GM (`/admin/`)** | **DENEGADO (HTTP 403)**[cite: 9] | Permitido[cite: 9] |
| **Ver Bitácora de Auditoría** | **DENEGADO (HTTP 403)**[cite: 9] | Permitido[cite: 9] |

---

## 6. Control Nuevo Elegido: Propiedad de Registros (Prevención de IDOR)

* **Línea Seleccionada:** **Control de Propiedad de Registros** (*Restringir lectura y escritura según dueño/asignación*)[cite: 9].
* **Justificación de Riesgo:** En un juego web con persistencia, un jugador malintencionado podría enviar solicitudes manipuladas con un `ObjectId` BSON ajeno (`/api/notes.php?id=65ff...`) para consultar o borrar el progreso y las notas de otros usuarios (Ataque de Referencia Directa a Objetos Insegura / IDOR)[cite: 9].
* **Regla de Control:** La localización por `_id` **no concede autorización**[cite: 9]. La consulta en la base de datos debe validar la relación entre el recurso y el usuario autenticado[cite: 9]:

```php
// Implementación en NotesModel.php
public function obtenerNotaSegura($idNota, $idUsuario, $rolUsuario) {
    $objectId = new MongoDB\BSON\ObjectId($idNota);
    
    // El Game Master (admin) puede acceder a cualquier registro para soporte
    if ($rolUsuario === 'admin') {
        return $this->collection->findOne(['_id' => $objectId]);
    }
    
    // El jugador solo puede consultar registros de su propiedad
    return $this->collection->findOne([
        '_id' => $objectId,
        'propietario_id' => new MongoDB\BSON\ObjectId($idUsuario)
    ]);
}