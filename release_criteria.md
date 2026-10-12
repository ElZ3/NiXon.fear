# Criterios de Lanzamiento (Beta Remota)

El proyecto se considerará listo para acceso público remoto únicamente cuando se cumplan y validen los siguientes hitos de desarrollo:

## 1. Contenido Jugable
- [ ] **Niveles:** Al menos 3 niveles totalmente funcionales integrados mediante Tiled y renderizados en Canvas.
- [ ] **Gameplay:** Entorno fluido, físicas responsivas y lógica de juego libre de caídas de frames o bloqueos.

## 2. Interfaz y Experiencia de Usuario
- [ ] **Vistas Funcionales:** Las ventanas de Configuración, Personalización de Avatar y Notas in-game deben operar sin errores.
- [ ] **Lore y Diseño:** La interfaz debe ser amigable para el jugador, pero respetando estrictamente la temática y lore oscuro/tecnológico de NiXon.fear.

## 3. Backend y Persistencia
- [ ] **Progreso:** El sistema debe permitir consultar, guardar y cargar el progreso de los jugadores de forma precisa desde MongoDB Atlas.
- [ ] **Rúbricas:** Todos los requerimientos de las rúbricas de evaluación deben estar implementados en el código.

## 4. Seguridad
- [ ] **Auditoría:** Protocolos de seguridad OWASP básicos aplicados (protección de rutas, encriptación de datos sensibles y prevención de inyecciones) validados y funcionales.