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

En el hosting de DonDominio, la carpeta `/public` del FTP es la raíz web del dominio, así que la estructura del proyecto encaja tal cual: todo se sube a la raíz del FTP y solo `public/` queda expuesta.

1. En el panel de DonDominio, seleccionar **PHP 8.1 o superior** y comprobar que `pdo_sqlite` está activo.
2. En local, instalar las dependencias sin las de desarrollo:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Subir por FTP (FileZilla) a la raíz del hosting: `public/`, `src/`, `templates/`, `database/`, `storage/`, `vendor/` y `.htaccess`.
   No subir `.git/`, `.env` ni ningún `.sqlite` local.
4. Crear en el servidor un `.env` a partir de `.env.example` con `APP_ENV=production` y `APP_DEBUG=false`.
5. Dar permisos de escritura a `storage/` (SQLite necesita escribir en la carpeta, no solo en el fichero).
6. Abrir `https://<dominio>/health`. En la primera petición se crea la base de datos.

Para actualizar, basta con volver a subir los ficheros cambiados (y `vendor/` si cambió `composer.lock`). No hay que sobrescribir `storage/`.

## Limitaciones conocidas

- SQLite admite una sola escritura simultánea; es suficiente para el volumen de un prototipo.
