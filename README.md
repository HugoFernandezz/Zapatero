# Zapatero

Prototipo académico de eCommerce (SIE, Tarea 1). **Sin actividad comercial real**: los pagos son simulados y todos los datos son ficticios.

Stack: PHP 8.1+ · Slim 4 · SQLite (PDO). Ver [PLANNING.md](PLANNING.md).

## Requisitos

- PHP 8.1 o superior con las extensiones `pdo_sqlite` y `mbstring`
- [Composer](https://getcomposer.org/)

## Instalación en local

```bash
composer install
cp .env.example .env
composer db:init      # crea storage/zapatero.sqlite desde database/schema.sql
composer start        # http://localhost:8000
```

`GET /health` devuelve la versión de PHP y de SQLite y el número de tablas, para comprobar que todo funciona.

Si el fichero de la base de datos no existe, la aplicación lo crea automáticamente en la primera petición. `composer db:init` sirve para **borrarla y recrearla**.

## Estructura

```
public/      document root (index.php, .htaccess, assets)
src/         código de la aplicación (namespace App\)
templates/   vistas PHP
database/    schema.sql e init.php
storage/     base de datos SQLite (no versionada)
```

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
5. Crear en la raíz del FTP un `.env` a partir de `.env.example` con `APP_ENV=production` y `APP_DEBUG=false`.
6. Dar permisos de escritura a `storage/` con `chmod` desde el cliente FTP (SQLite necesita escribir en la carpeta, no solo en el fichero).
7. Abrir `http://<dominio>/health`. En la primera petición se crea la base de datos.

Si algo falla, los errores se ven en el panel: **Sitios web → Ver logs → "Servidor web - Últimos errores"** ([ayuda](https://www.dondominio.com/es/help/278/como-visualizar-logs-errores/)).

Para actualizar, basta con volver a subir los ficheros cambiados (y `vendor/` si cambió `composer.lock`). No hay que sobrescribir `storage/`.

## Limitaciones conocidas

- SQLite admite una sola escritura simultánea; es suficiente para el volumen de un prototipo.
