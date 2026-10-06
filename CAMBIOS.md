# Cambios: cuentas, carrito persistente, pagos, seguimiento y CRUD de productos

Estos requisitos tienen prioridad sobre lo que digan `README.md` o `PLANNING.md`.

## Login y cuentas
- `/login` es el login único (cliente o administrador) y está en el header. Si eres administrador entras al back-office (`/admin/pedidos`).
- `/registro` crea cuentas de cliente (contraseña de 8 caracteres o más). El administrador de prueba sigue saliendo de `.env`.
- Se puede comprar sin iniciar sesión.
- Cliente con sesión iniciada: **10 % de descuento automático** en la compra (`CartService::MEMBER_PERCENT`, código `CLIENTE10`). Si además introduce `BIENVENIDA10` o `ZAP5`, se aplica el que más descuente (no se suman).

## Carrito
- Con sesión iniciada el carrito se guarda también en la tabla `cart_items`. Al pulsar «Seguir comprando», al cerrar sesión o al volver otro día, los artículos siguen ahí.
- Al iniciar sesión se une el carrito que se tenía como invitado con el guardado, respetando el stock.
- Tras un pago aprobado el carrito guardado se vacía.
- La tabla se crea sola en la primera petición (`Database::migrate`), también en bases de datos ya existentes.

## Pago
- Cualquier tarjeta (13-19 cifras) es válida salvo `0000 0000 0000 0000`, que se deniega: el pedido queda cancelado, el stock no se toca y el carrito se conserva.
- La factura se envía al email indicado en el checkout. Los clientes con sesión ven su email precargado.
- Tras cada pago aprobado se descuenta el stock en la base de datos.

## Seguimiento del pedido (cliente)
- `/mis-pedidos`: lista de pedidos del cliente con su estado. Se actualiza sola cada 15 s.
- `/pedido/{código}`: detalle con historial de estados. Se actualiza solo cada 10 s.
- Solo ven un pedido su dueño, la sesión que lo hizo o un administrador.

## Back-office
- `/admin/pedidos`: filtro por estado, búsqueda y cambio de estado (validado por la máquina de estados).
- `/admin/productos`: alta, edición (datos, imagen y stock por talla) y borrado. Un producto que ya está en pedidos no se puede borrar: se retira poniendo su stock a 0.
- Las imágenes subidas se guardan en `public/assets/img/products/` (JPG, PNG o WebP, máximo 2 MB). En el hosting esa carpeta necesita permiso de escritura.

## Cierre de requisitos de la Tarea 1

- Base de datos nueva con catálogo y cliente de prueba incluidos (`CatalogSeeder`); antes `composer db:init` y el hosting la dejaban sin productos.
- Solicitud de soporte del cliente y evento `support.requested`.
- Pantalla `/admin/eventos` con filtros y exportación JSON/CSV.
- Envío real de correo por SMTP y aviso al cliente en cada cambio de estado.
- Datos de demostración ficticios (`composer db:demo`) y test de humo (`composer test`).
- README con usuarios de prueba, eventos, limitaciones y despliegue.

