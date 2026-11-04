# Cambios de Irene sobre la base actualizada del equipo

Base de comparación: upstream/main fe1307d de https://github.com/HugoFernandezz/Zapatero. Rama de mejoras: codex/irene-politicas-telegram. El historial previo se conserva; no se importa el ZIP como una creación de Irene.

## Interfaz

- PoliciesController, ruta /politicas y nueva vista con funcionamiento, pagos y envíos simulados, cambios, datos, cookies y soporte.
- Pie común con enlaces y aviso académico; CSS adaptable al ancho de pantalla.
- La franja indica que no existen ventas, pagos ni envíos reales y pide datos ficticios.
- La URL del CSS incluye un hash del contenido: evita que la caché semanal del hosting oculte la actualización.
- No se copia AboutController ni «Quiénes somos» del ZIP: no están en la base actual publicada por el equipo.

## Seguridad y pruebas

- BoApiController retira la alternativa de Telegram con order_code y exige token/chat_id de tipo cadena.
- BoService elimina linkTelegram directo y registra rechazos del contrato por token sin guardar el secreto.
- La suite HTTP comprueba rutas, pie, destinos, autenticación, petición antigua rechazada sin modificar el chat, tipos JSON, enlace válido y token repetido.
- Se ajusta backoffice.php al contrato vigente y se liberan referencias SQLite antes de limpiar ficheros en Windows.
- Cuatro suites, 122 comprobaciones correctas. La prueba pública del contrato retirado devuelve 422.

## Publicación y documentación

- public/index.php carga variables del proceso antes de Dotenv; no se cambia la configuración privada del equipo en Git.
- Configuración de Render en rama separada: render.yaml, n8n/Dockerfile.render y n8n/render-start.sh, con commits 522833e, ce39b88, 97199dc y 9dbfdaf.
- Actualización de DonDominio sobre el código ya publicado por los compañeros, con respaldo privado y sin sustituir storage, vendor, la cuenta administradora ni SMTP.
- Workflow adicional verificar-publicacion.json, sin secretos, ejecutado manualmente desde Render: salud y API autenticada devuelven 200; no queda publicado como sondeo periódico.
- Scripts de comprobación y generación de PDF; memoria, anexo, guías y evidencias reales sin credenciales.

## Alcance pendiente del equipo

Los workflows WF1-WF3 están importados y sin publicar; faltan las credenciales de IA/Telegram y la ejecución conjunta de sus escenarios. Capturas complementarias y presentación son trabajo conjunto. Las mejoras futuras de la memoria no se presentan como implementadas.
