# Cierre de la integración en Render — 4 de noviembre de 2026

Los tres workflows están publicados y se han probado en el servicio público [zapatero-n8n](https://zapatero-n8n.onrender.com), conectado con la [tienda y el back-office de DonDominio](https://www.zapatero.onl). Este documento actualiza las referencias a workflows importados pero todavía no publicados que aparecen en los borradores anteriores.

| Flujo | ID en n8n | Resultado comprobado |
| --- | --- | --- |
| WF1: pedidos y estados | `daHf5k4YLRDCcJXi` | Pedido ficticio creado y sincronizado automáticamente; cambio a `pending_preparation` recibido en el back-office. |
| WF2: incidencias y agente | `FqMUQoEDYuF5u68m` | Consulta del pedido, del cliente y clasificación del ticket mediante herramientas; propuesta corregida y revisada por Irene. |
| WF3: Telegram y acciones aprobadas | `3DWMFRO834z5RXMk` | Chat vinculado con enlace de un solo uso; mensaje aprobado enviado y recepción confirmada por Irene. |

## Pruebas realizadas

- Pedido de demostración `ZAP-20261104-0001`: alta sincronizada en la ejecución WF1 #15 y cambio de estado en la #101.
- Webhook de WF1: petición válida, repetición idempotente, ausencia de secreto y contrato inválido. Los dos últimos casos quedan rechazados sin aplicar cambios. Evidencia: [wf1-webhook-produccion.json](evidencias/wf1-webhook-produccion.json).
- WF2 #18 generó una primera propuesta que pedía una foto y prometía una gestión física. Irene rechazó la propuesta #1. Se corrigió la instrucción del agente y se añadió un filtro para impedir esas promesas en la respuesta al cliente.
- WF2 #74 generó la propuesta #2, que describe un cambio simulado, sin productos ni envíos reales. Irene la aprobó personalmente a las 14:03:00 UTC. El proveedor utilizado fue Groq y el modelo `openai/gpt-oss-120b`.
- WF3 #183 envió la propuesta aprobada por Telegram, con `message_id` 35. El back-office registró la acción como `executed` a las 14:03:42 UTC e Irene confirmó la recepción en el móvil. Los tickets de prueba siguen abiertos; la acción aprobada sí está ejecutada.
- `composer test`: cinco suites, 155 comprobaciones correctas, salida 0. Registro: [pruebas-render-final.txt](evidencias/pruebas-render-final.txt).

Las capturas de [rechazo](evidencias/agente-propuesta-inicial-rechazada.jpg), [nueva propuesta](evidencias/agente-propuesta-corregida-pendiente.jpg) y [envío aprobado](evidencias/agente-propuesta-aprobada-enviada.jpg) documentan la revisión humana. El [estado final](evidencias/caso-render-estado.json) excluye identificadores personales del chat.

## Configuración y cambios de esta entrega

WF1 y WF2 tienen sondeo periódico y webhooks de producción de tipo **POST** (`/webhook/zapatero-event` y `/webhook/zapatero-incidencia`). Una petición GET no comprueba estos webhooks. WF3 utiliza sondeo de Telegram mediante `getUpdates`; no necesita un webhook de Telegram. El cursor inicial de WF2 se situó después de los casos históricos para probar el modelo únicamente con los nuevos casos ficticios.

Las URLs de tienda y back-office apuntan a `https://www.zapatero.onl`. Se verificó la coincidencia de los secretos de integración con el hosting y se configuraron las credenciales de Telegram y Groq en Render. Los valores privados, el archivo `.env`, los enlaces personales de vinculación y las copias del hosting quedan fuera de Git.

Los cambios nuevos de código son la corrección del agente en `wf2-incidencias-agente.json` y la apertura del enlace de Telegram en la misma pestaña en `templates/order.php`, ya actualizada en DonDominio. Las políticas, la retirada del endpoint antiguo y el resto de la entrega anterior ya estaban integrados en `main` y no se vuelven a incorporar en este cambio.

La comprobación se hizo a través de las URLs públicas, sin depender de un servidor local. El despliegue utiliza recursos gratuitos: Render puede suspender el servicio tras un periodo de inactividad y la base de datos gratuita tiene caducidad. El resultado acredita el funcionamiento durante las pruebas, sin prometer disponibilidad permanente. No se realizan ventas, cobros ni envíos físicos reales.
