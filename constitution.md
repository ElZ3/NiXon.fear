# Constitución del Proyecto (Reglas Innegociables)

## 1. Arquitectura y Frontend Estricto
- **Patrón MVC:** Prohibido mezclar lógica de base de datos en Controladores o Vistas. Las consultas a MongoDB van solo en los Modelos.
- **Vistas y Motor Gráfico:** Uso exclusivo de HTML5 `<canvas>` y Vanilla JS. Prohibido inyectar PHP directamente en las Vistas.
- **Bloqueo de Arte:** El diseño UI, el lore y el arte del Avatar están bloqueados. No alterar sin orden explícita del usuario.

## 2. Bases de Datos y Seguridad
- **Exclusividad MongoDB Atlas:** Prohibido usar o sugerir MySQL, PostgreSQL o SQLite.
- **Seguridad Innegociable:** Todas las rutas deben estar protegidas y las entradas sanitizadas.

## 3. Protocolo Multiagente y Uso de Recursos
- **Uso de MCP (Fetch/GitHub):** Los agentes deben usar Fetch para buscar soluciones eficientes y gratuitas si carecen de contexto. GitHub debe usarse para respaldar el código funcional antes de refactorizaciones grandes.
- **Ejecución por Rúbricas:** `@coordinator` no puede delegar código al `@implementer` sin un plan previo que cumpla los requerimientos de la rúbrica activa.