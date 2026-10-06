# Zapatero

Prototipo académico de eCommerce (SIE, Tarea 1). **Sin actividad comercial real**: los pagos son simulados y todos los datos son ficticios. La propia web lo indica en una franja visible en todas las páginas.

- **URL pública:** https://www.zapatero.onl
- **Stack:** PHP 8.1+ · Slim 4 · SQLite (PDO) · plantillas PHP. Justificación y alternativas en [PLANNING.md](PLANNING.md).

## Requisitos

- PHP 8.1 o superior con las extensiones `pdo_sqlite` y `mbstring`
- [Composer](https://getcomposer.org/)

## Instalación y ejecución en local

```bash
composer install
cp .env.example .env     # en Windows (CMD): copy .env.example .env
composer db:init         # crea storage/zapatero.sqlite con el catálogo y el cliente de prueba
composer db:demo         # (opcional) pedidos y eventos de demostración, todo ficticio
composer start           # http://localhost:8000
composer test            # test de humo del flujo de compra, estados, eventos y soporte
```

Si el fichero de la base de datos no existe, la aplicación lo crea sola en la primera petición (esquema + catálogo + cliente de prueba), así que `composer db:init` solo es necesario para **borrarla y recrearla**. `GET /health` devuelve la versión de PHP y SQLite y el número de tablas.

## Usuarios y datos de prueba

| Para qué | Dato |
|---|---|
| Cliente | `cliente@zapatero.test` / `Cliente1234` (existe desde el primer arranque). Recibe un 10 % de descuento automático (`CLIENTE10`). |
| Administrador | `ADMIN_EMAIL` / `ADMIN_PASSWORD` del `.env` (en `.env.example`: `admin@zapatero.test` / `cambia-esta-clave`). Se crea solo al abrir `/login` la primera vez. Entra por `/login` y te lleva a `/admin/pedidos`. |
| Tarjeta aprobada | Cualquier número de 13 a 19 cifras (p. ej. `4242424242424242`), caducidad futura `MM/AA` y CVC de 3 cifras. |
| Tarjeta rechazada | `4000 0000 0000 0002` o `0000 0000 0000 0000` → el pedido nace «cancelado» y no descuenta stock. |
| Códigos de descuento | `ZAP5` (−5 €) y `BIENVENIDA10` (−10 %). |
| Envío e impuestos | 4,95 € de envío, gratis desde 60 €. IVA del 21 % desglosado del total (precios con IVA incluido). |

`composer db:demo` añade 5 pedidos ficticios que cubren los estados (enviado, pendiente de preparación, con incidencia, pago rechazado, cancelado), una solicitud de soporte y ~60 eventos. Usa un usuario interno `sistema@zapatero.test` que **no puede iniciar sesión** (contraseña aleatoria).

## Flujo funcional

`/` → `/catalogo` (colecciones y estilos, filtros por talla) → `/producto/{slug}` → `/carrito` → `/checkout` → `/pago` → `/pedido/{código}` → factura por email.

Estados del pedido y transiciones permitidas (`src/Support/OrderStatus.php`):

| Desde | Hacia |
|---|---|
| created | paid_simulated (solo por el flujo de pago), cancelled |
| paid_simulated | pending_preparation, incident, cancelled |
| pending_preparation | shipped, incident, cancelled |
| shipped | incident |
| incident | pending_preparation, cancelled |
| cancelled | — |

Por eso, para marcar un pedido como **Enviado** hay que pasar antes por «Pendiente de preparación». Cada cambio de estado hecho en el back-office **avisa al cliente por email** (preparación, envío, incidencia, resolución y cancelación). Las notas internas del administrador nunca se incluyen en el correo.

Back-office (`/admin`, solo rol `admin`): pedidos con filtros y detalle, cambio de estado, reenvío de factura, gestión de productos y **registro de eventos** (`/admin/eventos`).

## Eventos de negocio

Todos se guardan en la tabla `events` (`type`, `occurred_at` UTC, `session_id`, `user_id`, `payload` JSON validado con `json_valid`). Se registran con `EventService::record()` desde la capa de servicios/controladores.

| Evento | Cuándo se genera |
|---|---|
| `product.viewed` | Al abrir la ficha de un producto |
| `cart.item_added` | Al añadir una talla al carrito |
| `checkout.started` | Al entrar en el checkout con un carrito válido |
| `order.created` | Al crear el pedido (dentro de la transacción de pago) |
| `payment.simulated` | Resultado del pago de prueba (`approved` / `rejected`) |
| `support.requested` | Cuando el cliente envía una solicitud de soporte desde su pedido |
| `incident.created` | Cuando el administrador marca un pedido «Con incidencia» |
| `order.status_changed` | En cada cambio de estado (incluye el estado inicial) |
| `invoice.sent` / `invoice.failed`, `status_email.sent` / `status_email.failed` | Resultado de cada correo enviado al cliente |

**Consulta y exportación:** `/admin/eventos` lista los eventos con filtros (tipo, fecha, texto) y recuento por tipo. Desde ahí se exportan a **JSON** (`/admin/eventos/exportar/json`) o **CSV** (`/admin/eventos/exportar/csv`), respetando los filtros activos.

**API para la Tarea 2:** `GET /api/events` (JSON) y `GET /api/events.csv`, con `?type=` y `?since=` (fecha UTC ISO 8601, p. ej. `2026-10-06T10:00:00Z`) para el sondeo incremental. Si `EVENTS_API_TOKEN` está definido en el `.env`, hay que enviarlo en la cabecera `X-Api-Token`. El `session_id` guardado en `events` es un identificador derivado (hash), nunca el id de sesión real.

## Arquitectura y modelo de datos

```
public/      document root (index.php, .htaccess, assets)
src/
  Controllers/   interfaz HTTP (Slim): recibe la petición, delega y elige la vista
  Services/      reglas de negocio (carrito, IVA y descuentos, pedidos, pago simulado, soporte, correo, eventos)
  Repositories/  acceso a datos con PDO y consultas preparadas
  Support/       utilidades (estados, CSRF, formato)
templates/   vistas PHP (tienda y back-office)
database/    schema.sql, init.php, seed.php, demo.php, test-mail.php
tests/       smoke.php (sin dependencias)
storage/     base de datos SQLite y correos de desarrollo (no versionados)
```

Entidades: `collections`, `styles`, `products`, `product_variants` (talla + stock) · `users` · `cart_items` · `discount_codes` · `orders`, `order_items`, `payments` · `support_tickets` · `events`. El esquema completo está en `database/schema.sql`.

## Correo

`MAIL_DRIVER` en el `.env`:

- `log` (por defecto): no envía nada; guarda el correo como `.eml` en `storage/mail/`.
- `smtp`: envío real por SMTP, también desde tu ordenador. Con Gmail: activa la verificación en 2 pasos, crea una *contraseña de aplicación* (https://myaccount.google.com/apppasswords) y rellena `SMTP_*` y `MAIL_FROM` (misma cuenta que `SMTP_USER`).
- `mail`: `mail()` de PHP; solo funciona en un hosting con correo configurado.

Prueba rápida sin hacer pedidos: `php database/test-mail.php destino@ejemplo.com`. Un fallo de correo nunca deshace un pedido ni un cambio de estado: queda como evento `*.failed`.

## Seguridad y privacidad

- Sin credenciales en el código ni en el repositorio: `.env` y `storage/` están en `.gitignore`; `.env.example` solo lleva valores de ejemplo.
- Contraseñas con `password_hash`, sesiones `HttpOnly`/`SameSite`, token CSRF en todos los formularios que modifican datos, consultas preparadas y salida HTML escapada.
- Importes y stock se recalculan en el servidor al pagar. De la tarjeta solo se guardan los 4 últimos dígitos.
- Todos los datos son ficticios. La exportación de eventos neutraliza fórmulas de hoja de cálculo (`=`, `+`, `-`, `@`).

**Antes de entregar o subir a GitHub:** no incluir el `.env`; si has usado una contraseña de aplicación de Google, revócala; y regenera la base de datos con `composer db:init && composer db:demo` para que no queden pedidos con datos reales.

## Despliegue en DonDominio

El hosting usa **PHP 8.5 con `pdo_sqlite`** (comprobado con `phpinfo()`). Solo hay acceso por FTP, así que las dependencias se instalan en local y se sube `vendor/`.

El dominio apunta a la carpeta `/public/` del FTP ([ayuda de DonDominio](https://www.dondominio.com/es/help/121/para-que-sirven-las-distintas-carpetas-mi-dominio/)), que coincide con nuestra `public/`. El resto del proyecto va en la raíz del FTP, al mismo nivel que `/public/`.

1. Comprobar la versión en el panel del hosting: **Sitios web → Versión PHP** (debe ser 8.1 o superior).
2. En local, instalar las dependencias sin las de desarrollo:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Conectarse por FTP (FileZilla). Los datos de acceso están en la sección **FTP** del panel ([ayuda](https://www.dondominio.com/es/help/120/como-subo-web-mediante-ftp/)).
   Activar *Servidor → Forzar mostrar archivos ocultos* para ver los `.htaccess`.
4. Subir a la raíz del FTP: `src/`, `templates/`, `database/`, `storage/`, `vendor/`, `.htaccess`, y el contenido de nuestra `public/` dentro de `/public/`.
   Ojo: hay dos `.htaccess` con el mismo nombre. Si el de la raíz acaba en `/public/`, toda la web da 403.
   No subir `.git/`, `.env` ni ningún `.sqlite` local.
5. Crear en la raíz del FTP un `.env` a partir de `.env.example` con `APP_ENV=production`, `APP_DEBUG=false`, un `ADMIN_PASSWORD` propio (no dejes `cambia-esta-clave` en un servidor público) y el correo: `MAIL_DRIVER=mail` con un `MAIL_FROM` de tu dominio, o `MAIL_DRIVER=smtp` con los datos SMTP de un buzón (ver [Correo](#correo)).
6. Dar permisos de escritura a `storage/` con `chmod` desde el cliente FTP (SQLite necesita escribir en la carpeta, no solo en el fichero).
7. Abrir `http://<dominio>/health`. En la primera petición se crea la base de datos **con el catálogo (12 productos) y el cliente de prueba**, sin ejecutar ningún comando.
8. *(Opcional)* Para tener pedidos y eventos de demostración en el servidor, sube el `storage/zapatero.sqlite` generado en local con `composer db:init && composer db:demo` (solo contiene datos ficticios; ver más abajo). Hazlo antes de la primera visita, o borra antes el fichero del servidor.

Si algo falla, los errores se ven en el panel: **Sitios web → Ver logs → "Servidor web - Últimos errores"** ([ayuda](https://www.dondominio.com/es/help/278/como-visualizar-logs-errores/)).

Para actualizar, basta con volver a subir los ficheros cambiados (y `vendor/` si cambió `composer.lock`). No hay que sobrescribir `storage/`.

## Limitaciones conocidas

- SQLite admite una sola escritura simultánea: suficiente para un prototipo, no para tráfico real.
- El pago es una simulación; no hay pasarela, 3-D Secure ni conciliación.
- Las solicitudes de soporte se guardan y se muestran al administrador en el detalle del pedido, pero no hay bandeja de tickets ni respuesta desde la aplicación (el estado queda en «abierta»).
- La exportación de eventos exige sesión de administrador (no hay API con token para sistemas externos).
- El stock se descuenta al pagar, no al añadir al carrito: dos clientes pueden tener la misma última unidad en el carrito y el segundo en pagar verá el aviso.
- No hay recuperación de contraseña ni límite de intentos de inicio de sesión.
- El administrador se crea desde el `.env` solo la primera vez; cambiar `ADMIN_PASSWORD` después no actualiza la contraseña ya guardada.
- El envío de correo depende de un servidor SMTP externo; sin él se usa el modo `log`.
- Los tests son un test de humo del flujo principal, no una batería exhaustiva.
