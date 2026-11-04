# Tu parte de Zapatero: estado del 4 de noviembre de 2026

La copia actual para continuar es **Zapatero-Equipo**, basada en main fe1307d de Hugo. Conserva el historial y los cambios publicados por los compañeros. No subas la carpeta completa ni el ZIP inicial como aportación tuya.

- Políticas y pie publicados: https://www.zapatero.onl/politicas
- Back-office publicado: https://www.zapatero.onl/bo, con la cuenta administradora existente.
- n8n publicado: https://zapatero-n8n.onrender.com, con PostgreSQL y los tres workflows importados.
- Siete comprobaciones públicas correctas y acceso desde Render a DonDominio verificado en la ejecución n8n #1.
- Contrato antiguo de Telegram rechazado con 422 también en el servidor público.
- Cuatro suites PHP con 122 comprobaciones correctas sobre la base actualizada.

La memoria y el anexo están cerrados conforme al estado comprobado, en docs y output/pdf. El modelo de Claude Code se declara no registrado. No se inventan sesiones anteriores ni pruebas con modelo o Telegram reales.

Los tres workflows funcionales siguen sin publicar. Configurar el proveedor de IA y el bot, activar los flujos y obtener las capturas completas de los tres escenarios corresponde a la demostración conjunta del equipo. No es necesario contratar un plan de pago para el despliegue realizado, aunque Render Free tiene suspensión y caducidad de su base de datos.

Los cambios propios de interfaz, seguridad, pruebas y documentos están en codex/irene-politicas-telegram; la configuración de Render está separada en codex/despliegue-n8n-irene del fork de Irene. La guía docs/guia-github.md explica cómo revisar ambas sin incluir commits ajenos como propios.

La copia privada del hosting, .env y paquetes de publicación están en tmp fuera del repositorio. No los compartas. No ejecutes db:init en el hosting.

Consulta docs/despliegue-publico.md para operación y limitaciones; docs/pruebas-y-defensa.md para la demostración; docs/cambios-irene.md para el alcance exacto de tu aportación.
