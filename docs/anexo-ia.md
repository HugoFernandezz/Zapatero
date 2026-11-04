# Anexo de uso de inteligencia artificial
## Zapatero · Tarea 2

Equipo: Natalia Castaño Lorente, Irene Almagro Andújar y Nikita Biriouk. Estado verificado: 4 de noviembre de 2026.

**Registro de uso de IA y alcance de la verificación.** Recoge lo declarado por Irene y lo observado en código, pruebas y publicación. Las sesiones anteriores no disponibles y las pruebas funcionales externas sin ejecutar se identifican expresamente.

### A. IA como apoyo al desarrollo

El equipo declara haber utilizado Codex con modelo GPT-6.1 y Claude Code. El modelo exacto de Claude Code, su versión y las fechas de sesiones anteriores no están registrados en la información facilitada. Se declara expresamente esa falta de trazabilidad y no se asigna un modelo por suposición: Claude Code es una herramienta y no identifica por sí sola un modelo.

| Herramienta | Modelo y versión | Uso y estado |
|---|---|---|
| Codex | GPT-6.1, según declaración del equipo | Apoyo en implementación, revisión, pruebas y documentación; historial anterior no aportado |
| Claude Code | Modelo y versión no registrados | Uso previo declarado por el equipo; no se reconstruyen sesiones sin evidencia |
| Agente funcional de WF2 | LLM_MODEL configurado al ejecutar | Modelo efectivo pendiente de verificar en n8n |

En esta intervención se estudió el ZIP y se adaptaron las mejoras sobre main fe1307d del repositorio de Hugo: políticas, pie y retirada de la vinculación antigua de Telegram. Se ejecutaron 122 comprobaciones PHP, se revisó la interfaz y se publicaron n8n y el back-office en Render y DonDominio. Una ejecución real de n8n consultó la tienda y su API desde Render y obtuvo HTTP 200. Esto no incluye llamadas a Telegram ni a un modelo de IA.

Se conserva el historial de los compañeros. El código y los textos asistidos por IA requieren revisión y comprensión del equipo; no se atribuye la base recibida como una creación propia de Irene. Los commits de configuración de Render y las fuentes de las mejoras permiten revisar su alcance.

### Prompts relevantes de apoyo, resumidos

- Añadir políticas visibles y explicar que no existen ventas ni envíos reales.
- Proteger o retirar el contrato antiguo de vinculación de Telegram sin romper los enlaces de un solo uso.
- Preparar la publicación de n8n y back-office con URLs públicas y secretos fuera de GitHub.
- Redactar memoria y anexo distinguiendo implementación, comprobación y tareas pendientes.

Estos son resúmenes de la petición actual, no transcripciones inventadas de sesiones anteriores. No se dispone de un registro de los prompts anteriores del equipo.

<!-- pagebreak -->

# B. IA incorporada al sistema

El sistema contiene un único rol agéntico funcional: asistente de soporte posventa. Está implementado en WF2 mediante un bucle de llamadas a un modelo compatible con chat/completions. n8n coordina el flujo y el back-office valida y guarda los resultados; no constituyen por sí mismos otros agentes de IA.

### Instrucción funcional resumida

Analizar un ticket de una tienda ficticia, consultar el pedido y el historial de su cliente, clasificar el ticket una vez y producir exclusivamente una propuesta JSON en español. El mensaje del cliente se trata como contenido no fiable. Se prohíben cancelaciones, reembolsos, solicitudes de contraseñas o datos de pago y consultas a otros clientes. Se limita el proceso a seis pasos y una herramienta por turno.

La instrucción completa se encuentra en el código del nodo «Agente de IA (bucle con herramientas)», dentro de n8n/workflows/wf2-incidencias-agente.json. No contiene una clave API.

| Herramienta conectada | Datos y permiso |
|---|---|
| consultar_pedido | Pedido del ticket: estado, líneas, importes, datos de cliente y tickets |
| consultar_cliente | Cliente conocido desde el pedido: historial y datos seleccionados |
| clasificar_ticket | Solo el ticket actual: categoría y prioridad; cambio reversible |

La segunda consulta reduce los datos enviados a nombre de pila e historial, pero la consulta de pedido puede contener más campos. No se debe declarar anonimización completa. El texto libre del ticket también puede contener datos introducidos por la persona que prueba el sistema. Solo se usan datos ficticios y se revisa lo que devuelve cada herramienta.

### Salida y fallos del modelo

La propuesta incluye case_type, consulted_data, action, justification, confidence, priority, risks y model. La API exige datos válidos y crea siempre pending_review. Las acciones admitidas son customer_message, status_change_proposal, internal_task y no_action.

Sin configuración de modelo, ante error de red o sin respuesta válida se crea una tarea de revisión manual con confianza cero y prioridad alta. Registrar ese modo seguro no demuestra una ejecución agéntica exitosa con herramientas; hay que conservar una ejecución real del modelo si se utiliza para la defensa.

<!-- pagebreak -->

# C. Acciones, prohibiciones y revisión humana

El agente puede consultar el caso, clasificar su ticket y proponer una respuesta, una tarea o un cambio de estado permitido. No tiene una herramienta para cobrar, reembolsar o enviar directamente a Telegram. La ejecución de una propuesta exige una decisión de la persona administradora en /bo/agente.

Una aprobación registra autor, fecha y comentario. WF3 consulta solo las acciones approved e informa del resultado; el back-office bloquea el informe de ejecución si no existe aprobación. Las propuestas rechazadas no se ejecutan por el camino previsto. El modo simulado conserva evidencia del control sin acreditar un mensaje real de Telegram.

Los controles combinan instrucciones, lista de herramientas, validación de argumentos, lista de acciones y revisión humana. Un prompt por sí solo no garantiza seguridad. El filtro que detecta alusiones a dinero o cancelaciones añade riesgo y reduce la confianza, pero no elimina toda respuesta incorrecta. Una persona debe leer el contenido antes de aprobar.

### Errores y controles documentados

| Hallazgo o caso | Cambio o tratamiento | Evidencia disponible |
|---|---|---|
| La API aceptaba order_code para vincular Telegram | Retirada de alternativa y método directo; token obligatorio | Test HTTP con 422 y chat sin cambios |
| Token repetido o caducado | Rechazo 410; hash y consumo de un solo uso | Suite del back-office |
| Tipos JSON incorrectos en vinculación | Validación sin convertir arrays en cadenas | Test HTTP sin error 500 |
| PHP no recibía variables cloud en $_ENV con GPCS | Lectura del entorno del proceso antes de .env | APIs autenticadas correctas tras publicar en DonDominio |
| Recursos SQLite seguían abiertos al limpiar tests en Windows | Liberación de referencias de servicios y closures | Reejecución local de suites |
| Descarga de la imagen de n8n rechazada por HTTP 429 | Cambio al Docker Hub oficial, misma versión | Log real de Render; corrección ce39b88 |
| Modelo sin configurar o respuesta inválida | Tarea interna de revisión en modo seguro | Modo funcional del proyecto; no se ejecutó WF2 en esta intervención |
| Ticket que intenta cambiar las instrucciones | Tratar entrada como no fiable y limitar herramientas | Payload 91 disponible; ejecución con modelo pendiente |

No se ha probado aquí la resistencia de un modelo real al mensaje malicioso. Su resultado, la revisión humana y las correcciones del grupo se documentarán cuando se ejecute.

<!-- pagebreak -->

# D. Trazabilidad, revisión del equipo y pendientes

### Material que permite revisar el uso de IA

El repositorio conserva el workflow exportado con instrucciones y herramientas, el contrato con el esquema de salida y los servicios que validan propuestas, decisiones y resultados. Los tests ejercitan el bloqueo de acciones no aprobadas y la protección de Telegram. El directorio docs/evidencias contiene las verificaciones locales disponibles; no sustituye el historial de ejecuciones de n8n.

La configuración con secretos se almacena en el servicio de alojamiento o en ficheros privados ignorados por Git. Las exportaciones y las capturas deben revisarse antes de publicarlas: una vista de Environment, una cabecera Authorization o un enlace t.me con token puede revelar credenciales incluso si el código está limpio.

### Información no registrada y pruebas por realizar

El modelo de Claude Code y las fechas o prompts de sus sesiones previas no se han recuperado. La memoria identifica las contribuciones visibles en Git; ese historial no permite atribuir cada línea a una herramienta de IA ni reconstruir todo el reparto de trabajo.

Los tres workflows funcionales están importados y sin publicar en n8n. No se ha configurado un proveedor de modelo ni un bot de Telegram en esta instancia. Por tanto, no hay modelo funcional efectivo, ejecución con herramientas, salida del modelo revisada ni aviso real al chat que declarar aquí. Antes de presentar esa demostración, el equipo debe registrar proveedor/modelo, ticket ficticio, ID de ejecución, herramientas llamadas, propuesta, decisión humana y resultado del envío.

La prueba externa existente es el workflow de lectura 7kUldPi4bl3J10JS, ejecución #1: el 04/11/2026 consultó salud y métricas autenticadas de DonDominio desde Render, con HTTP 200 en ambos casos. No contiene un agente de IA ni sustituye la prueba de resistencia al payload malicioso. Su salida, informe y captura figuran en docs/evidencias.

### Preguntas para la revisión y defensa

Cada integrante debe poder explicar por qué el Bearer y el token del pedido cumplen funciones distintas, qué información llega al modelo, cómo se limita una herramienta, quién autoriza el mensaje de Telegram y qué ocurre si el proveedor de IA no responde.

También debe distinguir el código preparado de las pruebas realmente ejecutadas: un JSON de ejemplo no acredita un envío; una propuesta simulada no acredita el uso de un modelo; un fichero Docker no acredita un servicio público. Las evidencias posteriores deben añadirse con su fecha, sin convertir los ejemplos en resultados ya obtenidos.

### Declaración final de revisión

Declaración propuesta para su aceptación por el equipo tras revisar el material: «Hemos revisado las aportaciones asistidas por IA y podemos explicar las partes que presentamos. Los resultados, herramientas, modelos y evidencias indicados corresponden a nuestro trabajo y a nuestras ejecuciones verificables».

Esta frase no se considera ya aceptada ni firmada por generar este documento. Debe validarse por el equipo antes de entregar.
