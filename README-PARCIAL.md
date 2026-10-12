# Parcial II — NiXon.fear explicado en simple

> Este archivo explica el parcial sin palabras técnicas difíciles.
> No cambia el juego. Solo explica cómo el servidor cuida tus notas.

---

## 1. ¿Qué es el parcial? En palabras simples

Imagina que tus notas del juego son cartas secretas guardadas en una caja fuerte.

El parcial demuestra que el servidor revisa **7 puertas de confianza** antes de entregar o guardar cualquier carta:

1. **¿Quién eres? (Identidad)**
   Es como decir tu nombre al entrar. El servidor mira tu cuenta y tu correo.
   Historia: "Hola, soy el Jugador 2".

2. **¿Cuál es tu secreto? (Credencial)**
   Es como decir tu contraseña secreta. El servidor comprueba que de verdad eres tú.
   Si te equivocas, solo dice "credenciales inválidas" para no dar pistas a extraños.

3. **¿Tienes tu pulsera? (Sesión)**
   Cuando entras, te dan una pulsera (una cookie segura).
   Esa pulsera dura 20 minutos sin moverte. Si entras de nuevo, te cambian la pulsera vieja por una nueva para que nadie la copie.

4. **¿Tienes permiso? (Autorización)**
   No todo el que entra puede hacer todo. La regla es: **si no tienes permiso claro, no pasas**.
   Cada puerta dice quién sí puede pasar: jugador o Game Master.

5. **¿Traes tu sello? (CSRF / Integridad)**
   Para crear, cambiar o borrar notas necesitas un sello especial.
   Si alguien intenta engañarte desde otra página para borrar tus notas, como no tiene tu sello, el servidor lo rechaza.

6. **¿Viene limpio lo que traes? (Datos)**
   Todo lo que escribes en una nota se limpia antes de guardarse y antes de mostrarse.
   Así nadie puede meter trampas o mensajes malos dentro de una nota.

7. **¿Quedó anotado en la bitácora? (Evidencia)**
   Todo lo importante se escribe en un cuaderno: quién entró, qué hizo, a qué hora.
   Si algo raro pasa, se puede revisar ese cuaderno.

Eso es todo el parcial: demostrar que estas 7 puertas funcionan en cada pedido al servidor.

---

## 2. Diagramas: cómo viaja una nota

### A. Tú pides tu nota y vuelve

```text
+----------+        +----------+        +-----------+
| Jugador  | -----> | Servidor | -----> | MongoDB   |
| (tú en   | pedido | (PHP que  | busca  | (caja     |
| el juego)|        | revisa    |        | fuerte de |
|          | <----- | las 7     | <----- | notas)    |
+----------+ vuelve | puertas)  | trae   +-----------+
  tu nota            +----------+
  aparece
  en tu libreta
```

En simple:

1. Tú abres tu libreta en el juego.
2. El servidor revisa: ¿quién eres?, ¿tienes pulsera?, ¿tienes permiso?, ¿es tu nota?
3. Solo si todo está bien, va a la caja fuerte (MongoDB) y te trae tu nota.

Si algo falla, no va a la caja fuerte. Te devuelve un "no" (error 403).

### B. Jugador vs Game Master: ¿quién ve qué?

```text
  JUGADOR (tú)                    GAME MASTER (soporte)
  ============                    =====================

  +---------------+               +--------------------+
  | Ve SOLO       |               | Ve TODO            |
  | sus notas     |               | las notas de todos |
  +---------------+               +--------------------+
        |                                   |
  +---------------+               +--------------------+
  | Puede crear   |               | Puede buscar por   |
  | y borrar      |               | correo, nivel o    |
  | SUS notas     |               | categoría          |
  +---------------+               +--------------------+
        |                                   |
  +---------------+               +--------------------+
  | NO puede ver  |               | Puede arreglar o   |
  | notas ajenas  |               | borrar notas rotas |
  | NO puede entrar|              | Puede leer la      |
  | al panel admin|               | bitácora           |
  +---------------+               +--------------------+
```

Regla de oro: **esconder un botón en la pantalla no es seguridad.**
La seguridad real está en el servidor: aunque intentes entrar por la consola (F12) o con Postman, el servidor te dice que no.

---

## 3. IDOR: el truco de cambiar el id (muy importante)

### ¿Qué es IDOR? Con ejemplo del juego

IDOR significa: entrar a algo ajeno solo cambiando un número o código.

Ejemplo en NiXon.fear:

1. El Jugador 1 tiene su nota secreta con código `id=AAAA`.
2. El Jugador 2 tiene su propia nota con código `id=BBBB`.
3. El Jugador 2 abre la consola del navegador (F12) y cambia el pedido a `id=AAAA`.
4. Sin protección, el servidor le entregaría la nota secreta del Jugador 1. O peor: la podría borrar.

Eso sería trampa: robarías el progreso de otro jugador solo cambiando el `id`.

### ¿Cómo lo evitamos? La regla dueño + id

El servidor nunca confía solo en el `id`. Siempre pregunta: **¿este id es tuyo?**

En la caja fuerte (MongoDB) cada nota guarda quién es su dueño:

```text
nota = buscar donde:
  id de la nota  = el que pides
  Y
  dueño de la nota (propietario_id) = tu id de usuario
```

- Si dueño + id coinciden → te entrega la nota.
- Si pides una nota ajena → dueño no coincide → **error 403 (prohibido)**.

Así se protege la progresión de cada usuario: nadie avanza robando ni rompe el juego de otro borrando sus notas.

### ¿Qué puede hacer el admin (Game Master)?

El Game Master es como el profesor o el soporte del juego:

- Sí puede ver todas las notas (para ayudar).
- Sí puede borrar o corregir una nota rota que no deja avanzar a un jugador.
- Sí puede leer la bitácora para ver qué pasó.
- Solo entra al panel `/admin/` si su cuenta tiene rol `admin`. Un jugador normal que intente entrar recibe error 403.

Resumen en una frase: **el jugador solo toca lo suyo, el admin puede tocar todo para ayudar.**

---

## 4. Cómo probar en 5 minutos

Necesitas: el juego corriendo y estas 2 cuentas demo.

| Cuenta | Usuario | Para qué sirve |
|---|---|---|
| Admin | `admin_nixon` | Entra al panel Game Master |
| Jugador | `player_nixon` | Juega normal, ve solo sus notas |

### Paso 1 (1 min): entra como jugador

1. Inicia sesión con `player_nixon`.
2. Abre tus notas en el juego.
3. Crea una nota nueva. Debe guardarse y aparecer en tu lista.

### Paso 2 (1 min): entra como admin

1. Cierra sesión e inicia sesión con `admin_nixon`.
2. Entra al panel Game Master (`/admin/` o `dashboard.php`).
3. Debes ver las notas de todos. Prueba buscar por correo con el filtro (espera medio segundo, tiene delay).

### Paso 3 (1 min): prueba que el jugador NO entra al panel

1. Entra con `player_nixon`.
2. Intenta abrir la ruta `/admin/` a mano.
3. Debes recibir **error 403**. Eso está bien: significa que la puerta 4 (permiso) funciona.

### Paso 4 (2 min): prueba 1 ejemplo IDOR bloqueado

1. Entra con `player_nixon` y abre una de tus notas. Copia su `id`.
2. Cierra sesión y entra con otra cuenta de jugador (o pide el `id` de la nota del Jugador 1).
3. Intenta pedir la nota ajena cambiando el `id` en la URL o consola:
   - Ejemplo: `/api/notes.php?id=ID_DE_OTRO_JUGADOR`
4. Debes recibir **error 403** y no ver la nota.
5. Repite lo mismo con `admin_nixon`: el admin SÍ la ve. Eso demuestra que la regla dueño + id funciona y el admin conserva su poder de ayuda.

Si todo sale así, el parcial está demostrado: tus notas viajan seguras, nadie roba progreso ajeno y el Game Master puede ayudar cuando hace falta.
