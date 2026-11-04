# Actualización realizada en DonDominio

Fecha: 04/11/2026. URLs: https://www.zapatero.onl y https://www.zapatero.onl/bo. Alojamiento PHP del equipo, accedido con la sesión de DonDominio autorizada por Irene mediante WebFTP.

## Procedimiento ejecutado

1. Se descargó y validó un respaldo privado del hosting: 395 entradas y 2.135.049 bytes sin comprimir, incluyendo código, configuración y storage. Queda en tmp/backup-dondominio-2026-11-04, fuera de Git y del paquete de cambios.
2. Se comparó la versión desplegada con fe1307d y las mejoras. composer.lock era idéntico, por lo que vendor se mantuvo.
3. Se comprobó la migración en una copia local de la base del hosting. Se conservaron sus registros y se incorporaron los identificadores de eventos correctamente. No se ejecutó db:init en el servidor.
4. Se subieron 34 archivos de ejecución y configuración necesarios. Después se actualizó layout.php para versionar el CSS por contenido ante la caché de siete días observada.
5. Se conservó el .env existente y se completaron API_TOKEN, BO_API_TOKEN y BO_DB_PATH; APP_ENV queda en production y APP_DEBUG en false. Los secretos coinciden con Render. Se conservaron la base de tienda, el administrador y los ajustes de correo existentes.
6. La primera petición autenticada creó la base separada del back-office en storage/backoffice.sqlite. No se subió ninguna SQLite local.

## Resultados

| Verificación | Resultado |
|---|---|
| /health y /politicas | HTTP 200 y contenido esperado |
| /bo sin sesión | HTTP 303 al login |
| Acceso con cuenta administradora existente | Resumen del back-office correcto |
| /bo/api/v1/metrics sin / con token | HTTP 401 / 200 |
| /api/v1/events con token | HTTP 200 |
| /bo/api/v1/telegram/link con contrato antiguo | HTTP 422 |
| n8n en Render consulta salud y métricas | Ejecución #1 correcta; HTTP 200 |

Las capturas e informes sin secretos están en docs/evidencias. No se borraron archivos remotos ni se cambiaron credenciales administrativas. No se hicieron pedidos ni envíos reales en estas verificaciones.

## Recuperación y futuras actualizaciones

El respaldo privado permite restaurar los archivos anteriores. Antes de cualquier recuperación, guardar también el estado nuevo de storage, incluida la base del back-office; no sustituir bases activas sin acordarlo con el equipo. La configuración contiene secretos y debe mantenerse fuera de public, GitHub y los ZIP de entrega.

Para actualizar de nuevo, obtener la versión acordada del equipo, revisar el diff y repetir copia y comprobaciones. La raíz pública sigue siendo public; src, templates, database y vendor son directorios hermanos. No subir .git, tmp, tests ni documentos al hosting web.

Los workflows funcionales necesitan configurar el proveedor de IA y Telegram y completar la demostración antes de publicarlos. El informe de red externa solo acredita conectividad HTTP y autenticación.
