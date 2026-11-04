# Pruebas realizadas y guion de demostración conjunta

Estado: 04/11/2026. La base actual es fe1307d con las mejoras de Irene. Utilizar solo datos ficticios, mantener secretos ocultos y no mostrar enlaces de vinculación de Telegram.

## Evidencias ya disponibles

| Archivo en docs/evidencias | Qué demuestra |
|---|---|
| pruebas-base-actualizada.txt | Cuatro suites PHP, 122 comprobaciones correctas |
| publicacion-2026-11-04.json | Siete comprobaciones HTTP públicas correctas |
| telegram-contrato-retirado-publico.json | HTTP 422 para el contrato antiguo con Bearer válido |
| n8n-red-externa.json y n8n-verificacion-red-externa.jpg | Ejecución #1 desde Render: salud y API del back-office con 200 |
| politicas-publicadas-2026-11-04.jpg y pie-publicado-2026-11-04.jpg | Políticas y pie publicados |
| backoffice-publicado-2026-11-04.jpg | Acceso real con la cuenta existente |

Las evidencias locales del ZIP inicial son anteriores y pueden mostrar 123 comprobaciones o «Quiénes somos». La referencia para la entrega actual es pruebas-base-actualizada.txt con 122. Ninguna de esas capturas acredita un mensaje de Telegram o una respuesta real del modelo.

## Demostración de los tres escenarios

Antes de esta sesión conjunta, configurar el modelo y el bot en el entorno privado de n8n, revisar los destinos y publicar los workflows cuando estén listos. Actualmente están importados y sin publicar.

1. Crear un pedido ficticio en la tienda, completar el pago simulado y guardar su código. Ejecutar WF1 y mostrar el pedido correspondiente en /bo/pedidos y el registro de integración con execution_id. Repetir el evento para demostrar idempotencia sin duplicar el pedido.
2. Cambiar un estado permitido desde la administración de la tienda. Ejecutar WF1 y mostrar el antes/después en el back-office y el log del evento. Probar un evento antiguo para comprobar que no retrocede el estado.
3. Crear un ticket asociado al pedido. Ejecutar WF2 con el modelo y mostrar herramientas consultadas, propuesta JSON y pending_review. Aprobar o rechazar en /bo/agente. Vincular un chat de prueba con un token de un solo uso y ejecutar WF3; documentar el resultado real. No confundir executed_simulated con un mensaje recibido.

Para el intento malicioso, usar el payload de prueba 91, registrar el modelo efectivo y sus herramientas, revisar la salida y describir un error realmente observado. No inventar un fallo ni una corrección.

## Capturas y presentación, entre las integrantes

Preparar conjuntamente cuatro a seis diapositivas: proceso y arquitectura; contrato y errores; agente y límites; supervisión humana y Telegram; métricas y elección; publicación y aportaciones. Cada captura debe tener URL o pantalla, fecha, identificadores ficticios y resultado visible. Una configuración o un payload no sustituyen una ejecución.

Irene puede explicar la diferencia entre Bearer y token de pedido, el 422 del contrato antiguo, la página de políticas, los secretos fuera de Git y la prueba desde otra red. Las demás aportaciones se explican conforme al historial y al trabajo real del grupo.

La presentación y las capturas completas de los tres escenarios no se han elaborado en esta intervención: requieren la ejecución y revisión conjunta que solicitó el equipo. La publicación y conectividad ya están acreditadas con las evidencias anteriores.
