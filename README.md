# Zapatero

Prototipo académico de eCommerce (SIE, Tarea 1). **Sin actividad comercial real**: los pagos son simulados y todos los datos son ficticios. La propia web lo indica en una franja visible en todas las páginas.

- **URL pública:** _(completar con la URL del hosting antes de entregar)_
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
| Administrador | `ADMIN_EMAIL` / `ADMIN_PASSWORD` del `.env` (en `.env.example`: `admin@zapatero.test` / `admin1234`). Se crea solo al abrir `/login` la primera vez. Entra por `/login` y te lleva a `/admin/pedidos`. |
| Tarjeta aprobada | Cualquier número de 13 a 19 cifras (p. ej. `4242424242424242`), caducidad futura `MM/AA` y CVC de 3 cifras. |
| Tarjeta rechazada | `0000000000000000` → el pedido nace «cancelado» y no descuenta stock. |
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

Todos se guardan en la tabla `events` (`type`, `occurred_at` UTC, `session_id`, `user_id`, `payload` JSON validado con `json_valid`). Se registran con `EventService::record()` desde la capa de servicios/controladores. Detalle y ejemplos en [INTEGRACION-M5.md](INTEGRACION-M5.md).

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

**Consulta y exportación:** `/admin/eventos` lista los eventos con filtros (tipo, fecha, texto) y recuento por tipo. Desde ahí se exportan a **JSON** (`/admin/eventos/exportar/json`) o **CSV** (`/admin/eventos/exportar/csv`), respetando los filtros activos. Esa exportación es el punto de integración previsto para la Tarea 2 (un sistema de análisis o automatización puede leerla periódicamente o leer directamente la tabla `events`).

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
5. Crear en la raíz del FTP un `.env` a partir de `.env.example` con `APP_ENV=production`, `APP_DEBUG=false`, un `ADMIN_PASSWORD` propio (no dejes `admin1234` en un servidor público) y el correo: `MAIL_DRIVER=mail` con un `MAIL_FROM` de tu dominio, o `MAIL_DRIVER=smtp` con los datos SMTP de un buzón (ver [Correo](#correo)).
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

## Tarea 2: integración y automatización

La configuración incluida arranca la tienda, el back-office y n8n **en local para desarrollo**. Esto todavía no satisface el requisito de entrega: el enunciado original (§3 y §9.a) pide que n8n y el back-office sean accesibles fuera de la red local del grupo. Antes de entregar, desplegad ambos servicios y sustituid esta nota por sus URLs públicas. La guía local paso a paso para Windows está en [`n8n/README.md`](n8n/README.md).

### Arranque rápido en Windows

1. Instala PHP 8.1 o superior con `pdo_sqlite`, Composer y Docker Desktop.
2. En la raíz del proyecto, instala dependencias y crea la configuración local:
   ```powershell
   composer install
   Copy-Item .env.example .env
   ```
3. Edita `.env` localmente: genera valores distintos para `API_TOKEN` y `BO_API_TOKEN`, y configura `MAIL_DRIVER=log`. No compartas ese fichero. Mantén las variables SMTP y de cuenta de correo según la configuración proporcionada al equipo.
4. Inicializa la tienda y arráncala en una terminal:
   ```powershell
   composer db:init
   composer start:lan
   ```
   `start:lan` permite que el contenedor de n8n acceda al servidor PHP. Abre `http://localhost:8000`.
5. En otra terminal, crea `n8n/n8n.env` desde `n8n/n8n.env.example`, completa los tokens para que coincidan con `.env` y sigue [`n8n/README.md`](n8n/README.md) para arrancar n8n, importar y activar los tres workflows.
6. Abre `http://localhost:5678`. Las variables de Telegram y del modelo de IA son opcionales para arrancar; sin ellas, las funciones correspondientes no podrán completar sus acciones externas.

Los valores de cada equipo son locales. No incluyas `.env`, `n8n/n8n.env`, `storage/`, bases SQLite, tokens ni claves en Git, capturas o el ZIP de entrega. Los `.example` contienen solo valores de plantilla.

### Workflows y pruebas

Los workflows exportados están en `n8n/workflows/`: sincronización de pedidos, gestión de incidencias con agente supervisado y ejecución/notificación por Telegram. Los payloads de escenario están en `n8n/payloads/`. En `n8n/README.md` se describen la importación, activación, escenarios de prueba y resultados esperados.

Para ejecutar las suites del proyecto, usa `composer test` con PHP y las dependencias instaladas. Las capturas de n8n, back-office, Telegram y métricas deben obtenerse con una ejecución real en el equipo que prepare la defensa; no se sustituyen por los payloads de ejemplo.

### Back-office y métricas

Entra como administrador en `/login`; las credenciales locales se configuran en `.env`. El back-office de la integración está bajo `/bo`, incluido el registro en `/bo/integracion`, tickets en `/bo/tickets`, revisión humana en `/bo/agente` y cuadro de mando en `/bo/metricas`. La matriz comparativa de implantación se documenta en [`docs/matriz-decision.md`](docs/matriz-decision.md). La justificación de las métricas está en [`docs/metricas-justificacion.md`](docs/metricas-justificacion.md) con referencias comprobadas y límites documentados.

### Limitaciones conocidas de la integración

- Es un prototipo académico con datos ficticios; el pago es simulado y no debe usarse para actividad comercial.
- La sincronización periódica puede tardar hasta un minuto. SQLite admite una sola escritura simultánea.
- n8n y el agente requieren configuración local adicional; Telegram y el modelo dependen de sus servicios externos.
- Los tokens Bearer y el acceso a variables de entorno están pensados para la demostración local. Una implantación real requiere gestión de secretos, rotación, controles de acceso y evaluación de privacidad.
- Las métricas y evidencias de demostración dependen de datos generados durante las pruebas; con pocos pedidos sus valores no son representativos de un negocio real.

### Página de presentación

La ruta /quienes-somos presenta el concepto de la tienda, explica que es un prototipo académico y resume el recorrido de compra de prueba.


### Despliegue con Docker en un servidor

Para preparar la aplicación PHP y el back-office en un servidor externo con dominio, volumen persistente para SQLite y comprobación automática de salud, consulta [`docs/despliegue-docker.md`](docs/despliegue-docker.md). Incluye `Dockerfile`, `compose.deploy.yml` y `.dockerignore`. Hace falta configurar aparte el servidor, el dominio y HTTPS; la configuración no despliega por sí sola una URL pública.
