# 07 — Registro de riesgos

> Versión 0.1 — 2026-08-21.
> Escala: Probabilidad (P) y Severidad (S) de 1 a 5. **Exposición = P × S.** Se revisa al cierre de cada fase.

---

## 1. Riesgos que pueden matar el proyecto (exposición ≥ 16)

### R-01 · Alcance desproporcionado respecto al objetivo · P5 S5 = **25**
La especificación describe ~40 módulos para soportar 150 creadores, 3 marcas y 3 campañas. Construida literalmente, la primera versión utilizable llegaría en 12–18 meses; el negocio necesita operar en 3–4.
**Es el riesgo número uno del proyecto.** No es técnico: es de gestión de producto.
**Mitigación:** el MVP de `04-ROADMAP.md §3` recorta explícitamente a 9 bloques con criterio de aceptación verificable. Cada solicitud nueva durante la Ola 1 se responde con "sí, en la fase X", no con "sí, ahora".
**Indicador de alerta:** si al final de la semana 8 no existe todavía un creador aprobado en el sistema, el alcance se está desbordando.

### R-02 · Bloqueo legal-tributario del pago a creadores · P4 S5 = **20**
Sin una vía legal para pagar a personas naturales sin RUC, la operación no escala: o se rechazan creadores, o se acumula contingencia tributaria. Ver `DEC-005`.
**Mitigación:** consulta contable **iniciada esta semana**, antes de escribir código de finanzas. Modelar `tax_regime` configurable para no quedar atado a una única respuesta.

### R-03 · El equipo sigue operando por WhatsApp y Excel · P4 S5 = **20**
El riesgo más subestimado de todos. Si el sistema es más lento que WhatsApp para una tarea frecuente, el equipo lo saltará; y a partir de ahí los datos del sistema serán incompletos, y todo lo construido encima (métricas, score, márgenes, KPIs) será ficción.
**Mitigación:** medir el tiempo real de las 5 tareas más frecuentes (invitar 50 creadores, revisar un entregable, aprobar un pago) y exigir que en el sistema sean **más rápidas** que hacerlo a mano. Notificaciones por WhatsApp saliente (F12.7) en lugar de pretender que el creador entre al portal.
**Indicador:** proporción de entregables recibidos por el portal frente a los recibidos por WhatsApp.

### R-04 · Fraude de audiencia y pérdida de credibilidad · P4 S4 = **16**
Datos autodeclarados + capturas editables = cifras infladas. Cuando una marca compara el reporte con su propia analítica y no cuadra, se pierde la cuenta y la reputación.
**Mitigación:** chequeos de coherencia automáticos, evidencia de insights, histórico difícil de falsificar de forma consistente, y **transparencia sobre la fuente del dato en todo reporte** (marcar explícitamente lo autodeclarado). Migrar a API oficial o proveedor externo en cuanto el volumen lo justifique.

### R-05 · Fuga de información de margen entre audiencias · P3 S5 = **15**
Un solo endpoint que serialice el costo del creador hacia el portal de la marca puede costar una cuenta y la confianza de toda la red de creadores.
**Mitigación:** DTOs por audiencia (nunca serializar el modelo directamente) + **tests automáticos que verifican que las respuestas de portales externos no contienen campos prohibidos**. Este test se escribe en F4 y se ejecuta siempre.

---

## 2. Riesgos altos (exposición 9–15)

| ID | Riesgo | P | S | Exp. | Mitigación |
|---|---|---|---|---|---|
| **R-06** | Modelo de datos insuficiente descubierto en la Fase 7–9, obligando a migraciones dolorosas | 3 | 5 | 15 | Fase 2 exhaustiva con revisión adversarial; recorrer P1–P9 sobre el modelo antes de codificar |
| **R-07** | Fraude en pagos (suplantación / cambio de cuenta bancaria) | 3 | 5 | 15 | `BR-FIN-005`, `BR-FIN-006`: enfriamiento, reverificación, doble aprobación, notificación al canal anterior |
| **R-08** | Baja entregabilidad de correo → creadores no reciben invitaciones | 4 | 3 | 12 | Proveedor SMTP dedicado, SPF/DKIM/DMARC, dominio calentado, email log con rebotes, canal alternativo (WhatsApp) |
| **R-09** | Costos de almacenamiento de video fuera de control | 3 | 4 | 12 | S3 con ciclo de vida, límites por campaña, transcodificación/compresión, política de retención de archivos raw |
| **R-10** | Baja adopción del portal por parte de los creadores | 3 | 4 | 12 | Portal móvil-first, onboarding guiado, notificaciones por el canal que ya usan, el módulo de ingresos como incentivo de entrada |
| **R-11** | Cambios en las APIs de las plataformas sociales rompen integraciones | 4 | 3 | 12 | No depender de ellas en el MVP; adaptadores aislados con degradación a captura manual |
| **R-12** | Rotación o indisponibilidad del equipo de desarrollo (bus factor) | 3 | 4 | 12 | Documentación viva, convenciones estrictas, tests, commits pequeños, ADRs |
| **R-13** | Incumplimiento de protección de datos (Ley 29733 / GDPR) | 3 | 4 | 12 | `BR-PRIV-*`, validación legal, minimización, registro de banco de datos si procede |
| **R-14** | Deuda técnica por presión de entrega | 4 | 3 | 12 | Definition of Done no negociable; Phase Review Report obligatorio |
| **R-15** | Dependencia excesiva de un único cliente inicial | 3 | 4 | 12 | Riesgo de negocio; el sistema debe facilitar la captación paralela (F11 no demasiado tarde) |
| **R-16** | Disputas por derechos de uso de contenido | 3 | 3 | 9 | `DEC-014`: licencias explícitas con alcance, territorio y vigencia + alertas de vencimiento |
| **R-17** | El cliente cancela una campaña con producción en curso | 3 | 3 | 9 | `BR-CAMPAIGN-010`: política de cancelación modelada, no improvisada |
| **R-18** | Sobrecarga del equipo de revisión de contenido en campañas grandes | 3 | 3 | 9 | Cola priorizada, revisión por lotes, plantillas de comentarios, métricas de tiempo de revisión |

## 2b. Riesgos introducidos por el addendum multi-entidad (2026-08-21)

| ID | Riesgo | P | S | Exp. | Mitigación |
|---|---|---|---|---|---|
| **R-26** | **Colisión conceptual entre los cuatro conceptos organizacionales** (marca de plataforma, entidad legal, grupo cliente, marca del cliente). Nadie sabe a qué apunta `brand_id`; se escriben datos en el eje equivocado. | 3 | 4 | 12 | `DEC-016`: renombrar `Brand`→`ClientBrand` y prohibir `Organization` a secas, **antes** de la Fase 2. Glosario fijado en la iteración 2.1. Bajó de 16 a 12 al resolverse `DEC-002` sin inquilinos: un concepto menos que confundir. |
| **R-27** | **Operación intercompañía sin diseño fiscal.** Si una sociedad factura y otra paga a los creadores, se genera una operación entre partes vinculadas con implicaciones de precios de transferencia y consolidación. | 3 | 5 | **15** | `DEC-020`: modelar la separación pero **bloquearla por validación** en el MVP. Habilitarla exige decisión del negocio con respaldo fiscal. |
| **R-28** | **Numeración fiscal con huecos o duplicados** bajo concurrencia, con varias sociedades y varias series. | 3 | 4 | 12 | `DEC-021`: tabla propia de series con asignación bajo bloqueo; tests de concurrencia obligatorios en F9.12. |
| **R-29** | **Resolución dinámica de la entidad legal histórica.** Cambiar la cobertura de facturación reescribe retroactivamente el emisor de documentos antiguos. Error silencioso: no falla, solo falsifica. | 3 | 5 | **15** | `DEC-019` y `BR-LE-001`/`BR-LE-005`: `legal_entity_id` persistido + snapshot del emisor. Comprobación específica en la revisión adversarial de la iteración 2.10. |
| **R-30** | **Pagos transfronterizos a creadores no domiciliados** con retención inesperada: el creador recibe menos de lo prometido y se rompe la relación. | 3 | 4 | 12 | `Q-13` con el contador antes de F9; requisitos documentales con clave (país entidad pagadora, país creador) desde el diseño. |
| **R-31** | **Configuración jerárquica opaca**: alguien no entiende de qué nivel viene un valor y una factura sale con datos equivocados. | 3 | 3 | 9 | `DEC-018`: la interfaz muestra siempre el origen del valor efectivo ("heredado de Plataforma" / "definido aquí"). |

## 2c. Riesgos introducidos por el addendum de integraciones (2026-08-21)

| ID | Riesgo | P | S | Exp. | Mitigación |
|---|---|---|---|---|---|
| **R-32** | **Credencial de producción resolviéndose fuera de producción.** Emitir comprobantes fiscales reales desde QA, cobrar tarjetas en una demo, o enviar correos reales a 150 creadores desde staging. | 4 | 5 | **20** | `DEC-029`: el resolver **lanza excepción** ante ambiente cruzado, no filtra. Capturador de correo obligatorio fuera de producción, con independencia de la configuración. |
| **R-33** | **Resolución ambigua**: dos asignaciones igualmente específicas y una operación que elige "la que salga primero". | 3 | 4 | 12 | `DEC-027`: puntuación por especificidad y **empates rechazados al guardar**, no resueltos en ejecución. El resolver registra el motivo de su decisión. |
| **R-34** | **Conexión faltante detectada en el momento de operar**, con el cliente esperando la factura. | 4 | 3 | 12 | `DEC-028`: matriz de propósitos obligatorios por país; una entidad legal no se activa para un país sin cobertura completa. Comprobación de salud programada. |
| **R-35** | **Secretos en logs, trazas de error o exportaciones.** | 3 | 5 | **15** | `DEC-030`: cifrado sobre, escritura sin lectura, y filtro de redacción **en el logger** — no depender de que alguien se acuerde. |
| **R-36** | **Webhook mal enrutado o procesado sin firma válida** con varias conexiones del mismo proveedor conviviendo. | 3 | 4 | 12 | `DEC-031`: una URL por conexión con identificador no adivinable; firma verificada con el secreto de esa conexión; sin firma válida no se procesa. |
| **R-37** | **Sobreconstrucción del subsistema de integraciones**: 3–4 semanas para un MVP que necesita una conexión por propósito. | 3 | 3 | 9 | Dosificación de `docs/12 §10`: modelo, resolver, bóveda y panel mínimo en F4; webhooks, salud y simulador en F12. Parcialmente compensado por la simplificación de F12. |

## 2d. Riesgos introducidos por el addendum de gamificación (2026-08-21)

| ID | Riesgo | P | S | Exp. | Mitigación |
|---|---|---|---|---|---|
| **R-38** | **El XP se percibe como sustituto del pago.** Creadores profesionales a los que se les pide contenido de marketing para la plataforma a cambio de puntos. En una red pequeña, el daño reputacional se propaga en un grupo de WhatsApp. | 3 | 5 | **15** | `DEC-039`: todo reto interno lleva recompensa tangible además del XP; participación opcional declarada en la interfaz; cesión de derechos como en cualquier campaña. |
| **R-39** | **El ranking desmotiva a la mayoría.** Una tabla global con 1.000 creadores significa 990 personas viendo que pierden, en un negocio donde la retención lo es todo. | 4 | 3 | 12 | `DEC-038`: progreso personal siempre visible, comparación solo en cohortes acotadas con temporadas, y se premia el ascenso, no la posición absoluta. |
| **R-40** | **Fraude de referidos**: autorreferidos, cuentas falsas, granjas. | 4 | 3 | 12 | `DEC-040`: consolidación diferida al hito real, topes por periodo y verificación de documento, teléfono y medio de pago compartidos. |
| **R-41** | **Gamificar la métrica equivocada.** Premiar aceptaciones produce creadores que aceptan lo que no pueden cumplir; premiar seguidores desmotiva al micro-creador y refuerza `R-04`. | 3 | 4 | 12 | `DEC-036`: se premia comportamiento verificado y controlable, nunca resultados, volumen ni audiencia. |
| **R-42** | **Premios y sorteos con implicaciones regulatorias**, y XP convertible en bienes con implicaciones tributarias. | 3 | 3 | 9 | `docs/13 §11`: validación legal antes de lanzar recompensas materiales; `Q-24` decide si el XP será convertible. |
| **R-43** | **El programa se apaga por falta de dueño.** Una gamificación sin nadie que la cuide muere en tres meses y deja una sección muerta en el portal. | 3 | 2 | 6 | `Q-28`: asignar responsable operativo antes de lanzar. Si no lo hay, no se lanza. |

## 3. Riesgos medios y bajos

| ID | Riesgo | Exp. | Nota |
|---|---|---|---|
| R-19 | Elección incorrecta del proveedor de facturación electrónica | 8 | Mitigado por la capa de abstracción |
| R-20 | Rendimiento degradado con volumen | 8 | Prevención en el diseño de índices; medir antes de optimizar |
| R-21 | Complejidad excesiva de la i18n sin mercado que la use | 8 | Infraestructura sí, traducciones no, hasta que haya demanda |
| R-22 | Contenido inapropiado publicado por un creador | 6 | Revisión humana obligatoria + términos claros |
| R-23 | Pérdida de datos por fallo de backup | 6 | Restauración probada mensualmente y documentada |
| R-24 | Creator Score percibido como injusto por los creadores | 6 | Reglas auditables y explicables; nunca caja negra |
| R-25 | Spam y bots en formularios públicos | 6 | Honeypot, rate limit, verificación de email, moderación |

## 4. Riesgos que la especificación introduce por sí misma

Estos no son riesgos del negocio, sino de decisiones tomadas en el propio documento de requisitos:

| Riesgo | Origen | Recomendación |
|---|---|---|
| Construir un MVC propio | §3 "MVC o equivalente" interpretado literalmente | `DEC-001`: usar un framework maduro |
| Integrar Culqi sin caso de uso validado | §36 | `DEC-007`: diferir |
| Portal de marca completo con 3 marcas | §23 | `DEC-013`: enlaces firmados |
| Creator Score sin datos históricos | §15 | Diferir a F14; capturar los eventos desde el día 1 |
| Custom fields y filtros guardados temprano | §68, §65 | Diferir; son síntomas de un modelo incompleto si se necesitan pronto |
| Guardar snapshots de absolutamente todo | §54 | Selectivo: histórico donde el histórico es el producto, auditoría en el resto |
| 3 idiomas desde el inicio | §48 | Infraestructura sí; traducciones cuando haya mercado |
| Formulario de lead con 16 campos | §18 | `DEC-012`: reducir a 6–8 |
