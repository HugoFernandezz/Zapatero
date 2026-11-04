# Publicación y operación

Estado verificado el 04/11/2026: n8n y back-office publicados y con conectividad HTTPS desde una red externa.

| Componente | URL y alojamiento |
|---|---|
| Tienda | https://www.zapatero.onl, PHP en DonDominio |
| Políticas | https://www.zapatero.onl/politicas |
| Back-office | https://www.zapatero.onl/bo, sesión administradora requerida |
| n8n 2.46.4 | https://zapatero-n8n.onrender.com, servicio Free zapatero-n8n |
| Estado de n8n | https://zapatero-n8n.onrender.com/healthz |
| Persistencia de n8n | PostgreSQL Free zapatero-n8n-db, región Frankfurt |

## Configuración efectiva

Render utiliza codex/despliegue-n8n-irene del fork laireeelokoo/Zapatero. Sus tres archivos propios están en los commits 522833e, ce39b88, 97199dc y 9dbfdaf. Se usa la imagen oficial docker.io/n8nio/n8n:2.46.4, HTTPS, cookie segura, N8N_PROXY_HOPS=1 y URLs derivadas de RENDER_EXTERNAL_HOSTNAME.

ZAPATERO_BASE_URL y BACKOFFICE_BASE_URL apuntan a https://www.zapatero.onl. ZAPATERO_API_TOKEN coincide con API_TOKEN del hosting; BO_API_TOKEN coincide en ambos. Los valores están en Environment de Render y en el .env privado del hosting, nunca en Git. No cambiar ni perder N8N_ENCRYPTION_KEY, que protege las credenciales persistidas en PostgreSQL.

La instancia permite Code y lectura de variables de entorno por los workflows del equipo; solo deben editarla personas de confianza. Se mantienen runner interno, heap principal de 256 MB, runner de 64 MB, concurrencia 1, TTL 600, Python y paquetes comunitarios deshabilitados, y solo los siete tipos de nodo usados en el proyecto. Estos ajustes resolvieron los fallos de arranque observados en Free; no garantizan rendimiento de producción.

## Workflows y prueba externa

WF1, WF2 y WF3 están importados y guardados, sin publicar. No se han configurado credenciales del proveedor de IA ni del bot Telegram en esta instancia. Antes de activarlos, configurar las variables que describe n8n/README.md, revisar los destinos y ejecutar los tres escenarios con datos ficticios. Un envío de Telegram puede llegar a un chat real; la propuesta del agente requiere aprobación humana.

Se ha añadido un cuarto workflow de verificación manual, n8n/workflows/verificar-publicacion.json. La ejecución #1 del workflow 7kUldPi4bl3J10JS obtuvo 200 de /health y de /bo/api/v1/metrics con el secreto del entorno desde Render. Su duración fue 4,127 s y su salida final no contiene datos privados. Sigue sin publicar para evitar un sondeo periódico.

## DonDominio

La base publicada corresponde a main fe1307d de Hugo con las mejoras de Irene. Se mantuvieron datos, dependencias, cuenta administradora y configuración de correo. El procedimiento y respaldo privado se describen en actualizacion-dondominio.md. La base del back-office es storage/backoffice.sqlite, separada de la SQLite de la tienda. Nunca ejecutar db:init sobre los datos del hosting.

## Reproducir la comprobación

```powershell
.\scripts\comprobar-publicacion.ps1 -TiendaUrl https://www.zapatero.onl -N8nUrl https://zapatero-n8n.onrender.com
```

Para incluir las APIs autenticadas, proporcionar ZAPATERO_API_TOKEN y BO_API_TOKEN solo como variables privadas del proceso. El script no imprime cuerpos ni cabeceras. El informe del 04/11/2026 tiene siete comprobaciones correctas; la prueba desde Render y las capturas están en docs/evidencias. No acreditan por sí solas la ejecución completa de WF1-WF3.

## Límites de los recursos gratuitos

Render Free suspende el servicio tras 15 minutos sin actividad; el primer acceso puede tardar y los sondeos no funcionan mientras duerme. PostgreSQL Free caduca a los 30 días: revisar la fecha del panel, guardar exportaciones y planificar antes de la defensa. No se ha contratado un servicio de pago ni configurado tráfico artificial para evitar la suspensión.

Fuentes contrastadas el 3 y 4 de noviembre de 2026: https://render.com/docs/deploy-n8n, https://render.com/docs/free, https://render.com/docs/blueprint-spec y la documentación de configuración de nodos y task runners de n8n.
