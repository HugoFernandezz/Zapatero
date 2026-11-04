# Incidencia real durante el despliegue de n8n

Fecha: 3 de noviembre de 2026. Servicio: zapatero-n8n, plan Free de Render.

La primera construcción del commit 522833e se detuvo al consultar la imagen n8n:2.46.4 en docker.n8n.io. El registro devolvió HTTP 429 Too Many Requests antes de construir el contenedor. PostgreSQL sí quedó disponible.

Se cambia el origen de la misma versión a docker.io/n8nio/n8n:2.46.4, distribución oficial utilizada por el ejemplo de Render. Se conserva el plan gratuito, los secretos del servicio y la base de datos. La corrección modifica únicamente n8n/Dockerfile.render y se vuelve a desplegar.

La incidencia demuestra un fallo real de descarga de dependencias; no es un error del modelo de IA ni una prueba de su comportamiento. El commit ce39b88 completó la construcción desde Docker Hub. Posteriormente se obtuvo 502 al comprobar la URL pública y se observó un nuevo arranque del proceso. El commit 97199dc limita memoria y concurrencia para probar su estabilidad en Free; no se atribuye el reinicio a falta de memoria como causa demostrada.
