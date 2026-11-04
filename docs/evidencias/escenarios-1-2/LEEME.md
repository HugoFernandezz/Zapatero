# Evidencias de los escenarios 1 y 2

Ejecución real del 04/11/2026, entre las 12:01 y las 12:11 UTC. Todos los datos son ficticios.

## Entorno

- **Tienda y back-office:** este repositorio (rama `feature/escenarios-1-2`, sobre `main` con la PR #9) con `php -S 127.0.0.1:8000 -t public`. Cada uno usa su propia base SQLite.
- **n8n:** versión 2.46.4, la misma que el despliegue de Render, ejecutada con Node 24. Solo se importó y publicó `n8n/workflows/wf1-pedidos-sincronizacion.json`, sin modificarlo.
- **Configuración:** las variables de `n8n/n8n.env.example`, con tokens de prueba generados para esta ejecución. No hay tokens, contraseñas ni enlaces de Telegram en estos ficheros.

**Limitación:** esta ejecución es local, así que no sustituye a la demostración en el entorno publicado (n8n en Render y back-office en zapatero.onl), que exige el enunciado. El workflow, la API y el back-office son los mismos, de modo que el recorrido debe repetirse igual allí cuando WF1 esté publicado en Render.

## Escenario 1: pedido generado e integrado

1. **Antes:** el back-office está vacío (`01`, `02`).
2. **Compra en la tienda** como cliente invitado, con la tarjeta de prueba `4242 4242 4242 4242`. Se genera el pedido `ZAP-20261104-0001`: Pixel Pop EU 38, 78,90 €, pagado (`03`). El email del cliente se escribió con mayúsculas a propósito.
3. **Sincronización:** en el siguiente sondeo, la ejecución #1 de WF1 (12:02:42 UTC, 580 ms) lee `order.created` y `order.status_changed`, valida el contrato, consulta el pedido en la tienda, lo transforma y lo envía al back-office (`07`, `08`, `16`).
4. **Después** (`04`, `05`, `06`): el back-office tiene el pedido con:
   - su identificador;
   - el cliente, con el email normalizado a minúsculas;
   - la línea `ZAP-PIXELPOP-38` × 1;
   - el importe, el estado `paid_simulated` y la fecha.

   El registro de integración enlaza cada fila con el `event_id` y la ejecución de n8n.

## Escenario 2: cambio operativo sincronizado

1. **Antes:** el pedido está en `paid_simulated` en el back-office (`05`).
2. **Cambio en la tienda:** un administrador lo pasa a «Pendiente de preparación» en `/admin/pedidos/ZAP-20261104-0001` a las 12:04:49 UTC (`09`).
3. **Sincronización:** la ejecución #4 de WF1 (12:05:42 UTC) lee `order.status_changed` y llama a `POST /bo/api/v1/orders/{código}/status` (`12`, `16`).
4. **Después:** el back-office muestra `pending_preparation` con la fecha del cambio (`10`), y queda la fila `ok` en el registro (`11`).

## Controles del flujo (webhook de WF1)

Enviados con `n8n/probar.ps1` (`13`, `14`, `15`):

| Prueba | Resultado |
|---|---|
| Payload inválido (`90-error-payload-invalido.json`) | Rama de error: `rejected` / `invalid_payload`, con los 6 errores de validación. No se toca el back-office |
| Mismo `order.created` enviado dos veces | Primero `updated`; después `duplicate` (idempotencia). El estado no retrocede |
| Sin cabecera `X-Webhook-Secret` | `rejected` / `unauthorized` |

`13a` es un error real de esta ejecución. El script de arranque de n8n no cargaba `N8N_WEBHOOK_SECRET`, por un fallo en la lectura de las variables (era del script, no del proyecto). WF1 rechazó todas las llamadas como `unauthorized` en lugar de aceptarlas sin comprobar: falla de forma segura. Las filas 4 a 8 del registro de integración (`14`) son esos rechazos. Corregido el script, las pruebas de `13` dieron el resultado esperado.

## Pruebas automáticas

`17` es la salida de `php tests/integracion.php`. Recorre los escenarios 1 y 2 por las rutas HTTP reales de la tienda y del back-office, con el mismo contrato que aplica WF1:
- tokens y cursor;
- alta, idempotencia y rama de error;
- cambio de estado, eventos atrasados (`stale`);
- cambios que llegan antes que el pedido (`pending_sync`).

Las 34 comprobaciones son correctas.

## Ficheros

| Fichero | Contenido |
|---|---|
| `01`, `02` | Back-office antes: sin pedidos y sin registros |
| `03` | Confirmación del pedido en la tienda |
| `04`, `05`, `06` | Escenario 1 después: lista, detalle y registro de integración |
| `07`, `08` | n8n: historial y ejecución #1 de WF1 |
| `09` | Tienda: cambio de estado hecho por el administrador |
| `10`, `11` | Escenario 2 después: detalle y registro de integración |
| `12` | n8n: ejecución #4 de WF1 |
| `13`, `13a` | Respuestas del webhook en las pruebas de control |
| `14`, `15` | Registro de integración e historial de n8n al final |
| `16` | Recorrido de las ejecuciones #1 y #4: nodos y salidas, sin cabeceras ni tokens |
| `17` | Salida de `tests/integracion.php` |
