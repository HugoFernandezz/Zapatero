# Alcance de las evidencias

Estado verificado: 04/11/2026. Las capturas son imágenes reales de navegador sin alterar su contenido. Los informes omiten cuerpos privados, cabeceras y credenciales.

## Publicación actual

- publicacion-2026-11-04.json: siete comprobaciones correctas por HTTPS, incluidas ambas APIs con sus tokens privados y el rechazo de acceso sin autorización.
- telegram-contrato-retirado-publico.json: el contrato antiguo con order_code devuelve 422 con Bearer válido y código ficticio inexistente. La prueba de chat sin cambios sobre un pedido existente está en la suite HTTP local.
- n8n-red-externa.json: salida visible de la ejecución real #1 del workflow 7kUldPi4bl3J10JS. Render consulta salud y métricas autenticadas de DonDominio, con HTTP 200. Acredita otra red; no demuestra los tres escenarios funcionales.
- n8n-verificacion-red-externa.jpg: historial con estado Succeeded y duración de 4,127 s.
- politicas-publicadas-2026-11-04.jpg y pie-publicado-2026-11-04.jpg: interfaz actual en zapatero.onl, con CSS versionado para evitar la caché anterior.
- backoffice-publicado-2026-11-04.jpg: resumen tras acceder con la cuenta existente. No se modificó su contraseña.
- n8n-workflows-importados.jpg: importación de los workflows del equipo. Siguen sin publicar; después se añadió el cuarto workflow de comprobación manual.

## Pruebas locales

pruebas-base-actualizada.txt recoge las cuatro suites sobre la base actual fe1307d: 122 comprobaciones correctas. Se repitieron correctamente después del ajuste de caché del CSS, el 04/11/2026. Se utiliza SQLite temporal y datos ficticios. También se comprobó la migración sobre una copia privada de la base desplegada.

Los PDF finales tienen 10 y 4 páginas y se renderizaron completos para revisar márgenes, tablas y saltos de página.

## Evidencias históricas del 03/11/2026

pruebas-automatizadas.txt y las capturas locales de políticas, pie y móvil pertenecen a la primera versión derivada del ZIP: muestran 123 comprobaciones y pueden incluir «Quiénes somos». No sustituyen a la suite de la base actualizada.

backoffice-publico-antes.jpg muestra el 404 previo. render-configuracion-previa.jpg es anterior a introducir secretos; render-incidencia-registro.md y render-estado-antes-del-ajuste.json describen fallos reales 429/502 durante el despliegue, resueltos posteriormente con los ajustes documentados. No representan el estado final.

No se ha ejecutado aquí un modelo de IA ni enviado mensajes reales a Telegram. No hay evidencia de los tres escenarios completos ni de una prueba móvil por datos; la comprobación de otra red procede de Render. El equipo debe añadir las ejecuciones funcionales y su presentación conjunta cuando disponga de las credenciales y las haya realizado.
