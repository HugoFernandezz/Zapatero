# M3 · Carrito y checkout

Este directorio es una copia del proyecto incluido en `Zapatero-main.zip`, con la responsabilidad M3 implementada sobre la arquitectura Slim 4 existente.

## Qué incluye

- `CartController`, `CheckoutController`: acciones HTTP, redirects y renderizado.
- `CartService`, `CheckoutService`: reglas de stock, importes, descuentos y validación de entrega.
- `CartRepository`: consulta PDO de variantes y precios actuales.
- `EventService`: adaptador pequeño a la tabla `events` ya declarada en `schema.sql`.
- `src/routes.php`: rutas `/carrito`, `/carrito/agregar`, `/carrito/actualizar`, `/carrito/descuento` y `/checkout`.
- `templates/cart.php` y `templates/checkout.php`: vistas PHP escapadas y controles HTML5.

## Reglas calculadas

Los precios de catálogo ya incluyen IVA. Se desglosa como `importe_total × 21 / 121`, redondeado al céntimo. El código `BIENVENIDA10` descuenta el 10 % y `ZAP5` descuenta 500 céntimos, con descuento máximo igual al subtotal. El envío cuesta 495 céntimos bajo 6000 céntimos de subtotal y es gratuito desde ese umbral. El IVA del resumen se calcula sobre el importe final con envío, dado que el envío también forma parte del total gravado en este modelo.

## Encaje con M4 y M5

El POST válido de checkout guarda `shipping_data` y `checkout_quote` en sesión para que M4 los consuma al crear el pedido y sus líneas. Esta entrega no crea pedidos ni procesa pagos. Los eventos `cart.item_added` y `checkout.started` se almacenan en `events`. Si al integrar el trabajo de M5 ya existe su propia clase `EventService`, conserva esa implementación y adapta el callback de `routes.php` a su firma.

Para integrar en la rama del grupo, copia los ficheros nuevos y aplica los cambios señalados en `src/routes.php`, `src/Controllers/ProductController.php`, `templates/product.php`, `templates/layout.php`, `public/index.php` y `public/assets/css/styles.css`. No hace falta cambiar el esquema SQLite.

Las rutas POST incluyen un token CSRF de sesión. Para probar el flujo se deben usar solo datos ficticios, como recuerda el aviso global del proyecto.
