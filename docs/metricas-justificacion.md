# Justificación de las métricas del cuadro de mando (borrador)

> **Estado: borrador para completar y verificar por el grupo.** Las referencias indicadas son obras y normas reales de uso habitual, pero **cada integrante debe comprobar edición, capítulo/sección, URL y fecha de consulta antes de citarlas en la memoria**, y reescribir la justificación con sus palabras (el profesorado analiza la similitud del texto).

Todas las métricas se calculan en el momento a partir de datos generados por el sistema (ver `src/BackOffice/MetricsService.php`). No hay cifras manuales ni CSV estático.

| # | Métrica (enunciado §7) | Pregunta operativa que responde | Cálculo | Fuente de datos |
|---|---|---|---|---|
| a | Número de pedidos | ¿Cuánto volumen llega al back-office? ¿Está sincronizado con la tienda? | `COUNT(bo_orders)`; cobertura = pedidos del back-office / pedidos de la tienda | `bo_orders`, `orders` (tienda) |
| b | Importe total simulado | ¿Cuánto valor mueve el proceso? | `SUM(total_cents)` sin cancelados | `bo_orders` |
| c | Productos más solicitados | ¿Qué se vende más? (reposición, catálogo) | `SUM(quantity)` por producto, top 5, sin cancelados | `bo_order_lines` |
| d | Pedidos por estado | ¿Dónde se acumula el trabajo en el ciclo del pedido? | `COUNT` por `status` | `bo_orders` |
| e | Incidencias abiertas | ¿Qué carga de postventa está pendiente? | tickets `open` + `in_progress` | `bo_tickets` |
| f | Tiempo medio simulado de resolución | ¿Cuánto tarda en cerrarse una incidencia? | media de `resolved_at − created_at` (min) en tickets resueltos | `bo_tickets` |
| g | Eventos de integración correctos y fallidos | ¿Funciona la integración tienda → back-office? | fallidos = `rejected` + `error`; correctos = el resto; tasa = correctos/total | `bo_integration_log` |
| h | Tasa de conversión del flujo | ¿Qué parte de las visitas con producto termina en pedido? | sesiones distintas con `order.created` / sesiones con `product.viewed`; embudo de 4 pasos | `events` (tienda) |
| i | Errores por tipo | ¿Qué falla y con qué frecuencia? | `COUNT` por `error_code` para `rejected`/`error` | `bo_integration_log` |
| j | Acciones propuestas por el agente y aprobadas | ¿Cuánto confía el personal en el agente? ¿Se supervisa realmente? | aprobadas / (aprobadas + rechazadas); confianza media sin modo seguro | `bo_agent_actions` |

## Por qué estas métricas: referencias de partida

- **Observabilidad de sistemas distribuidos (g, i).** El libro de SRE de Google propone vigilar un servicio con las «cuatro señales doradas»: latencia, tráfico, errores y saturación. Los eventos procesados y los errores por tipo son las señales de *tráfico* y *errores* de la integración.
  Beyer, B., Jones, C., Petoff, J. y Murphy, N. R. (eds.) (2016). *Site Reliability Engineering: How Google Runs Production Systems*. O'Reilly. (cap. 6, «Monitoring Distributed Systems»).
- **Integración fiable (g, i).** Los patrones *Idempotent Receiver*, *Dead Letter Channel* y *Message Store* justifican contar duplicados, rechazos y errores aparte, y conservar el registro de cada mensaje.
  Hohpe, G. y Woolf, B. (2003). *Enterprise Integration Patterns*. Addison-Wesley.
- **Gestión de incidencias y tiempo de resolución (e, f).** ITIL 4 incluye la práctica de gestión de incidentes, y el tiempo medio de resolución es el indicador clásico asociado. ISO/IEC 20000-1 recoge la gestión de incidentes dentro de la gestión de servicios.
  AXELOS (2019). *ITIL Foundation: ITIL 4 Edition*. TSO. · ISO/IEC 20000-1:2018, *Information technology — Service management*.
- **Comercio electrónico: volumen, mix de producto y embudo (a, b, c, d, h).** La conversión y el análisis de embudos por pasos son indicadores estándar en analítica web.
  Kaushik, A. (2009). *Web Analytics 2.0*. Sybex. · Kohavi, R., Tang, D. y Xu, Y. (2020). *Trustworthy Online Controlled Experiments*. Cambridge University Press. · Documentación de analítica de comercio electrónico de Google Analytics (consultar la versión vigente).
- **Supervisión humana de la IA (j).** Medir cuántas propuestas se aprueban o rechazan y con qué confianza permite evidenciar la supervisión humana y vigilar el comportamiento del sistema.
  NIST (2023). *Artificial Intelligence Risk Management Framework (AI RMF 1.0)*, NIST AI 100-1. · Reglamento (UE) 2024/1689 (Ley de IA), art. 14 «Supervisión humana».
- **Trazabilidad de ejecuciones.** El historial de ejecuciones de n8n y el registro del back-office permiten auditar cada resultado. Documentación de n8n (executions, error handling), `docs.n8n.io`.

## Decisiones de diseño que conviene explicar en la defensa

1. **Las razones sin datos son «sin datos», no 0.** Una tasa con denominador 0 se muestra como `sin datos`, para no aparentar un resultado inexistente.
2. **El modo seguro del agente no distorsiona la confianza media:** las propuestas con confianza 0 se cuentan aparte.
3. **Los pedidos cancelados cuentan como pedidos pero no suman importe ni unidades.**
4. **Tiempo de resolución «simulado»:** se mide entre la creación del ticket y la acción de «Resolver» en `/bo/tickets`; en producción sería el tiempo real de atención.
5. **Limitaciones:** el embudo usa sesiones anónimas (una sesión por navegador); los datos son de prueba; con pocos datos las medias son poco representativas.

## Cómo comprobar que son datos reales (para la defensa)

1. Abre `/bo/metricas` y anota el número de pedidos y de incidencias abiertas.
2. Genera una acción (nuevo pedido en la tienda, o resolver un ticket en `/bo/tickets`).
3. Recarga `/bo/metricas`: las cifras cambian. Compara con `/bo/metricas/datos` (JSON) y con las tablas `bo_*` de la base de datos.
