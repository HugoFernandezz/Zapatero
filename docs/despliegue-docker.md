# Despliegue Docker del back-office y la tienda

Este despliegue ejecuta la aplicación PHP en Apache dentro de Docker, conserva las bases SQLite en un volumen y ofrece `/health` para comprobar que responde. Está pensado para un servidor del grupo con Docker y un dominio. **El compose no publica n8n ni configura por sí solo un dominio o HTTPS**; hace falta un proxy inverso en el servidor (por ejemplo, Caddy o nginx) con certificado TLS.

## Preparación en el servidor

1. Copia el proyecto al servidor y crea la configuración privada:
   ```sh
   cp .env.example .env
   ```
2. Edita `.env` en el servidor: `APP_ENV=production`, `APP_DEBUG=false`, una contraseña nueva para `ADMIN_PASSWORD` y valores aleatorios para `API_TOKEN` y `BO_API_TOKEN` (distintos, de al menos 24 caracteres). Conserva la configuración del correo que el equipo ya utiliza; no publiques el `.env` ni lo añadas a Git.
3. Construye y arranca:
   ```sh
   docker compose -f compose.deploy.yml up -d --build
   ```
4. Comprueba el contenedor:
   ```sh
   docker compose -f compose.deploy.yml ps
   curl http://127.0.0.1:8080/health
   ```
   Debe aparecer `healthy` y el endpoint debe responder JSON con `"status":"ok"`.
5. Configura el proxy inverso HTTPS del servidor para enviar el dominio a `http://127.0.0.1:8080` y establecer `X-Forwarded-Proto: https`. El compose activa `TRUST_PROXY` para que las cookies de sesión usen `Secure` al terminar TLS en ese proxy. Este ajuste solo es seguro si el proxy de confianza reemplaza esa cabecera y el puerto 8080 permanece limitado a localhost. No abras el puerto 8080 a Internet. Abre `/health` en el dominio y después `/login`.
6. En el `n8n.env` del servidor n8n, usa el dominio HTTPS en `ZAPATERO_BASE_URL` y `BACKOFFICE_BASE_URL`, y los mismos tokens configurados en el `.env` de la aplicación. No guardes secretos en el workflow ni en Git.

El contenedor escucha Apache solo en `127.0.0.1:8080` del servidor. El volumen Docker `zapatero_storage` conserva tanto la base de la tienda como la del back-office aunque se recree el contenedor. Para ver incidencias:

```sh
docker compose -f compose.deploy.yml logs --tail=100 zapatero
```

Para actualizar la aplicación, sube los cambios al servidor y ejecuta de nuevo `docker compose -f compose.deploy.yml up -d --build`. **No borres el volumen** `zapatero_storage`: contiene los datos persistentes. La imagen incluye solo dependencias de producción y el contexto de construcción excluye `.env`, `storage/`, bases de datos y `n8n/n8n.env`.

## Alcance

Este fichero deja preparado el despliegue, pero no crea el servidor, el dominio ni el certificado TLS. La entrega solo cumple el acceso desde fuera de la red local cuando el grupo lo arranca en un servidor público, conecta el dominio y comprueba la URL desde una red externa.

