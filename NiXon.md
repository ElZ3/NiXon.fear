# Contexto Global del Proyecto: NiXon.fear

## 1. Naturaleza y Finalidad del Proyecto
NiXon.fear es un videojuego web enfocado en una experiencia interactiva fluida y una presentación visual inmersiva. El objetivo principal es ofrecer un juego estable, escalable y con un alto rendimiento.

## 2. Entorno y Arquitectura (Stack Tecnológico)
- **Infraestructura:** Entorno aislado mediante Docker (`php:8.2-apache`).
- **Backend:** PHP estructurado bajo un estricto patrón **MVC**.
- **Frontend:** Renderizado exclusivo mediante HTML5 `<canvas>` y JavaScript.
- **Diseño de Niveles:** Tiled (archivos JSON/XML).

## 3. Persistencia de Datos y Gestión de Usuarios
- **Base de Datos:** MongoDB Atlas (Cloud).
- **Casos de Uso:** Cuentas, progreso, logs, notas y configuraciones.

## 4. Herramientas MCP y Capacidades Extendidas
- **GitHub MCP:** El equipo tiene acceso para leer, crear ramas, realizar commits y gestionar PRs del repositorio directamente.
- **Fetch MCP:** Los agentes están autorizados a utilizar Fetch para consumir APIs públicas, buscar recursos Open Source gratuitos, consultar documentación técnica oficial o extraer snippets de código que agilicen el desarrollo, siempre y cuando no violen los protocolos de seguridad.