# M4 · Pago simulado, pedidos y back-office

Se apoya en el checkout de M3 y en el esquema que ya existía (`orders`, `order_items`, `payments`, `events`, `users`). **No hay cambios de esquema**: funciona con una base de datos ya creada.

## Flujo

`/carrito` → `/checkout` (datos de envío) → `/pago` (tarjeta de prueba) → `/pedido/{código}` (confirmación) → factura por email.

- El pedido se crea **al pagar**, dentro de una transacción (`BEGIN IMMEDIATE`): numeración, líneas, pago, stock y eventos son todo-o-nada.
- Importes y precios se **recalculan en servidor** al pagar; nunca se confía en lo que envía el navegador.
- Tarjetas: cualquier número de tarjeta aprueba, salvo `0000 0000 0000 0000`, que se deniega. Solo se guardan los 4 últimos dígitos.
- Pago aprobado → estado `paid_simulated`, se descuenta stock y se envía la factura.
- Pago rechazado → el pedido queda `cancelled`, el stock no se toca y el carrito se conserva para reintentar.

## Identificador único

`ZAP-AAAAMMDD-XXXX` (día en UTC + secuencia diaria de 4 cifras). Se calcula dentro de la transacción y además `orders.code` es `UNIQUE`.

## Estados y transiciones (`src/Support/OrderStatus.php`)

| Desde | Hacia |
|---|---|
| created | paid_simulated (solo por el flujo de pago), cancelled |
| paid_simulated | pending_preparation, incident, cancelled* |
| pending_preparation | shipped, incident, cancelled |
| shipped | incident |
| incident | pending_preparation, cancelled* |
| cancelled | — |

\* Estas dos transiciones **no estaban en el diagrama de PLANNING.md §6**; se añadieron para que un pedido pagado o con incidencia pueda cancelarse. Para quitarlas, basta con editar `TRANSITIONS`.

Cancelar un pedido que ya había descontado stock lo devuelve al inventario.

## Factura por email

`InvoiceService` genera una factura HTML (estilos en línea) + versión texto, y `MailService` la envía como `multipart/alternative`. La factura va **en el cuerpo del correo** (no como PDF adjunto). También se puede ver/imprimir en `/pedido/{código}/factura`.

- `MAIL_DRIVER=log` (por defecto): guarda el correo en `storage/mail/*.eml`, sin enviar nada.
- `MAIL_DRIVER=smtp`: envío real por SMTP (Gmail, buzón del hosting…) con `SMTP_HOST/PORT/SECURE/USER/PASSWORD`. Sin dependencias.
- `MAIL_DRIVER=mail`: usa `mail()` de PHP. Usa un `MAIL_FROM` de tu propio dominio.
- Un fallo de correo **no** deshace el pedido; queda como evento `invoice.failed` y el admin puede reenviar la factura.

## Back-office

- `/login`: login único. El administrador de prueba se define con `ADMIN_EMAIL` y `ADMIN_PASSWORD` en `.env` (se crea en el primer acceso; no se guardan credenciales en el código). `/admin/login` redirige a `/login`.
- `/admin/pedidos`: listado con filtro por estado, búsqueda (código, nombre, email) y paginación.
- `/admin/pedidos/{código}`: líneas, cliente, pago, historial de eventos, cambio de estado (validado por la máquina de estados, con nota opcional) y reenvío de factura.
- Todos los POST llevan token CSRF; las rutas `/admin/*` están tras `AdminAuth` y no se cachean.
- La página de confirmación del cliente solo se muestra a la sesión que hizo el pedido (o a un admin), porque los códigos son predecibles.

## Eventos nuevos (tabla `events`)

`order.created`, `payment.simulated`, `order.status_changed`, `incident.created`, `invoice.sent`, `invoice.failed`. Todos llevan `order_code` en el payload. `EventService::record()` admite ahora un 4.º parámetro opcional `$userId`.

## Ficheros

Nuevos: `src/Support/*`, `src/Middleware/AdminAuth.php`, `src/Repositories/OrderRepository.php`, `src/Services/{Order,Payment,Invoice,Mail,AdminAuth}Service.php`, `src/Controllers/{Payment,Order,Admin}Controller.php`, `templates/{payment,order,invoice,invoice-page}.php`, `templates/admin/*`.
Modificados: `src/routes.php`, `src/Controllers/CheckoutController.php`, `src/Services/EventService.php`, `templates/checkout.php`, `templates/layout.php`, `public/assets/css/styles.css`, `.env.example`.
