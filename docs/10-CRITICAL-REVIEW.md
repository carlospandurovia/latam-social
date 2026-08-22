# 10 — Revisión crítica de la especificación

> Versión 0.1 — 2026-08-21.
> Escrito desde el rol de **arquitecto externo independiente**. El encargo explícito fue: *"Si detectas que algo está mal diseñado, no lo ejecutes ciegamente."* Esto es esa respuesta.
>
> **Lo primero, y en serio:** la especificación es notablemente buena. Está por encima del 95% de los briefs que recibe un equipo de desarrollo. Acierta en cosas que casi nadie acierta: prohibir el scraping, exigir ledger en vez de un campo de saldo, exigir snapshots históricos, separar cliente de usuario, prohibir el borrado físico de información financiera, exigir capa de abstracción para proveedores de pago y facturación, y exigir iteraciones pequeñas. Las críticas que siguen son de **priorización y de completitud**, no de criterio.

---

## Parte A — Problemas de diseño y priorización

### C-01 · El alcance no guarda relación con el objetivo declarado 🔴
**El problema.** El documento pide ~40 módulos y a la vez fija como primer objetivo operativo 150 creadores, 3 marcas y 3 campañas. Ambas cosas no caben en el mismo proyecto. La sección §106 lo dice perfectamente ("5 funciones críticas extraordinariamente bien") y luego las 100 secciones anteriores piden 20 funciones.

**Por qué importa.** Un MVP de 40 módulos no llega tarde: llega **muerto**. Cuando por fin sale, el negocio ya montó sus procesos en Excel y WhatsApp, y el sistema pasa a ser un lugar donde *además* hay que copiar datos.

**Recomendación.** Aceptar el recorte del MVP en `04-ROADMAP.md §3`: 9 bloques, criterio de aceptación medible, todo lo demás con fase asignada. Nada se cancela; todo se ordena.

---

### C-02 · El orden de fases retrasa el valor entre 6 y 10 semanas 🔴
**El problema.** La spec pone *Brand Acquisition* (F7) y *Client Portal* (F8) **antes** del *Campaign Engine* (F9).

**Por qué importa.** Con 3 marcas, un CRM no resuelve nada: el equipo comercial puede llevar 3 cuentas en una hoja. El motor de campaña sí resuelve el dolor real (coordinar 60 creadores). Construir primero lo que no duele es la forma más común de gastar un presupuesto de desarrollo sin cambiar la operación.

**Recomendación.** Motor de campaña adelantado a F7; CRM a F11; portal de marca a F13 y reducido (ver C-04).

---

### C-03 · Culqi probablemente no pertenece a este producto (todavía) 🟠
**El problema.** §36 pide integrar una pasarela de tarjetas. Pero el modelo es B2B con tickets de miles de soles. Una campaña de S/ 30.000 cobrada con tarjeta regala ~S/ 1.050 en comisión, y ningún departamento de finanzas de una marca mediana paga a un proveedor con tarjeta corporativa: paga por transferencia contra factura, a 30 o 60 días.

**Recomendación.** Construir `PaymentGatewayInterface` (barato, 1 día) y **no** la integración. Activarla solo si aparece un producto de ticket bajo y autoservicio. Ver `DEC-007`. Ahorro: 2–3 semanas.

---

### C-04 · El portal de la marca está sobredimensionado para el MVP 🟠
**El problema.** §23 pide 14 módulos de portal de cliente (perfil, empresa, contactos, usuarios, campañas, briefs, creators, aprobaciones, entregables, reportes, facturas, pagos, documentos, notificaciones) para 3 clientes.

**Por qué importa.** El cliente entra a ese portal 3 o 4 veces por campaña. De esos 14 módulos usará dos: **aprobar contenido** y **ver el reporte**.

**Recomendación.** MVP con **enlaces firmados por campaña** (expirables, revocables, auditados): el cliente aprueba y ve su reporte sin crear cuenta, sin recordar contraseña, sin fricción. Portal completo en F13, cuando haya 15 marcas que lo justifiquen. Ver `DEC-013`. Ahorro: ~4 semanas.

---

### C-05 · El Creator Score, calculado hoy, sería un número inventado 🟠
**El problema.** §15 pide un sistema de scoring con ~17 variables. En el momento del lanzamiento no existe ninguna de las variables de comportamiento (cumplimiento, puntualidad, número de correcciones, conversión, campañas completadas, cancelaciones).

**Por qué importa.** Un score sin datos es una opinión disfrazada de número — y la gente confía en los números. Peor: los Campaign Managers tomarían decisiones reales basadas en él, y los creadores se sentirían juzgados por un algoritmo que no mide nada.

**Recomendación.** Diferir el Score a F14, **pero capturar desde el día 1 todos los eventos que lo alimentan** (invitación enviada/aceptada/rechazada, entregable a tiempo/tarde, rondas de corrección, incidencias). El score es una **proyección sobre un registro de eventos**; si el registro existe desde el principio, el score se puede calcular retroactivamente el día que se decida. Si no existe, no hay score posible aunque se programe.

Y cuando llegue: reglas explícitas, ponderaciones visibles, y **explicable al creador**. Un score opaco que afecta ingresos genera resentimiento y fuga.

---

### C-06 · La verificación por captura de pantalla es falsificable, y hay que decirlo 🟠
**El problema.** §8 propone como MVP la subida de capturas de Analytics/Insights con aprobación manual. Una captura se edita en 30 segundos con el inspector del navegador.

**Por qué importa.** No es que esté mal como punto de partida —es lo único viable al inicio—. Está mal **presentarlo como verificación**. Si el reporte al cliente dice "verificado" sobre un dato autodeclarado, el problema deja de ser técnico y pasa a ser de confianza comercial.

**Recomendación.**
- Etiquetar el nivel de verificación **en el propio dato y en todo reporte**: `autodeclarado`, `con evidencia`, `verificado por API`.
- Chequeos automáticos de coherencia (relación engagement/seguidores fuera de rango, saltos de crecimiento anómalos, engagement idéntico entre publicaciones).
- Pedir, en lugar de una captura, una **grabación corta de pantalla** navegando por los insights: mucho más difícil de falsificar y casi igual de fácil de pedir.
- El histórico es el mejor detector de fraude: mentir de forma consistente durante 6 meses es difícil.

---

### C-07 · Falta el mecanismo por el que realmente llegan los primeros 150 creadores 🟠
**El problema.** La especificación pone la importación masiva en §66, tratada como funcionalidad secundaria, mientras la landing de captación es F5.

**Por qué importa.** Nadie consigue 150 creadores de calidad con una landing recién lanzada y sin marca. Los primeros 150 salen de contactos, bases previas, agencias y outreach directo — es decir, de un archivo.

**Recomendación.** Importación masiva **dentro de la Fase 5** (iteración 5.6), con mapeo de columnas, validación, previsualización, informe de errores y reversión. Sin ella, alguien va a teclear 150 fichas a mano y esa persona va a odiar el sistema desde el primer día.

---

### C-08 · El formulario de aplicación del creador es demasiado largo 🟡
**El problema.** §6, §7, §9, §10 y §11 suman, entre datos personales, redes, demografía, perfil profesional y tarifas, más de 60 campos.

**Por qué importa.** Un micro-influencer con 8.000 seguidores lo abandona en el campo 15. Y la mayoría de esos datos **no hacen falta para decidir si aprobarlo**.

**Recomendación.** Dividir en dos momentos:
- **Aplicación (6–10 campos):** nombre, email, teléfono/WhatsApp, país/ciudad, red principal + usuario, categoría, aceptación de términos. Suficiente para revisar y decidir.
- **Onboarding post-aprobación (progresivo):** el resto, con barra de completitud y bloqueo solo donde es imprescindible (no se puede cobrar sin datos de pago). El creador ya aceptado tiene mucha más motivación para completar que el desconocido que está evaluando si vale la pena.

Efecto esperado: aumento sustancial de la tasa de conversión de la landing sin perder un solo dato relevante.

---

### C-09 · "Snapshots históricos de todo" es una regla demasiado amplia 🟡
**El problema.** §54 pide no perder nunca información relevante y usar tablas de historia, snapshots, versiones y auditoría. Aplicado literalmente, multiplica las tablas por dos y ralentiza cada escritura.

**Recomendación.** Distinguir tres niveles:
1. **Append-only real** (el histórico *es* el producto): ledger, métricas, snapshots de audiencia, versiones de entregables, tarifas, aceptaciones de términos, transiciones de estado.
2. **Auditoría de cambios** (basta con saber quién cambió qué): perfiles, catálogos, configuración. Un `audit_log` genérico con `before`/`after` cubre esto sin tablas espejo.
3. **Sin histórico**: preferencias de UI, borradores, datos derivados.

---

### C-10 · Custom fields y filtros guardados son prematuros 🟡
**El problema.** §68 pide un sistema de campos personalizados para Creator, Client, Lead y Campaign.

**Por qué importa.** Los campos personalizados son la solución cuando **muchos clientes distintos** necesitan campos distintos. Aquí hay un solo operador. Si durante el desarrollo hace falta un campo nuevo, se añade con una migración de cinco minutos. Un motor de campos dinámicos, en cambio, cuesta 2–3 semanas y contamina para siempre las consultas, los índices, los formularios, la validación, la exportación y los reportes.

**Recomendación.** Diferir. La necesidad temprana de campos personalizados casi siempre significa que el modelo de datos no capturó bien el negocio: ese es el problema a resolver, no el síntoma.

---

### C-11 · Doce roles de partida es demasiada granularidad 🟡
**El problema.** §46 define 13 roles para un equipo que hoy tiene entre 2 y 6 personas.

**Por qué importa.** No es que la implementación cueste (con RBAC granular, un rol es una fila). Es que **13 roles mal diferenciados generan errores de asignación** y nadie sabe cuál dar a la persona nueva.

**Recomendación.** Mantener el modelo `Usuario → Roles → Permisos` con permisos granulares —eso está muy bien planteado— pero **arrancar con 5 roles reales** (Superadmin, Operaciones, Comercial, Finanzas, Revisor) más los externos (Creator, Client). Los demás se crean cuando exista una persona que los ocupe.

---

### C-12 · Los dashboards propuestos tienen demasiados KPIs 🟡
**El problema.** §14 lista 13 KPIs para el creador; §38 lista 18 para el superadmin.

**Por qué importa.** La propia spec lo dice bien en §38 ("no llenar el dashboard con métricas decorativas") y luego se contradice. Un panel con 18 números no se lee: se ignora.

**Recomendación.** Máximo 6 indicadores por dashboard, elegidos por una regla: **si el número cambia, ¿alguien hace algo distinto hoy?** Si no, no va en el dashboard, va en un reporte.
- Creator: campañas activas · entregables pendientes con fecha · invitaciones sin responder · saldo por cobrar · próximo pago · % de cumplimiento.
- Superadmin: campañas en riesgo · contenido pendiente de revisar · CxC vencida · CxP pendiente a creadores · margen del mes · creadores activos.

---

### C-13 · Los 3 idiomas iniciales no tienen mercado que los justifique 🟡
**El problema.** §48 pide traducir UI, correos, notificaciones, landings y catálogos a español, inglés y portugués.

**Recomendación.** Toda la **infraestructura** de i18n desde el día 1 (obligatorio, retrofitearla es carísimo). **Cero traducciones** hasta que exista un mercado. Traducir tres idiomas de una interfaz que va a cambiar cada semana durante seis meses es tirar dinero y, peor, deja textos desactualizados que dan sensación de producto abandonado.

---

### C-14 · Programa de referidos: bien planteado, mal momentado 🟡
§16 está pensado con cuidado (incluso la advertencia sobre no convertir la promoción en trabajo gratuito, que es un detalle ético que casi nadie considera). Pero un programa de referidos con 150 creadores y sin campañas que ofrecerles produce frustración: se invita gente a una fiesta que aún no empezó. Activarlo cuando exista flujo constante de campañas.

---

## Parte B — Lo que falta en la especificación

Esta es la parte más importante del documento. Cada elemento aquí, si no se modela ahora, obliga a un retrofit doloroso o a volver a Excel.

### F-01 · Régimen tributario del creador 🔴 (bloqueante)
No aparece en toda la especificación cómo se le paga legalmente a una persona natural sin RUC. Es **el mayor riesgo operativo del negocio**, no un detalle contable. Determina el formulario de aplicación, el onboarding, el ledger, el lote de pago y qué creadores se pueden aceptar. Ver `DEC-005`. **Consultar con contador esta semana.**

### F-02 · Logística de producto (product seeding) 🔴
La inmensa mayoría de campañas de producto exigen **enviarle el producto al creador**. Falta por completo: dirección de envío (distinta de la fiscal y de la dirección personal), lista de despacho, número de seguimiento, confirmación de recepción, y costo del producto como Direct Cost de la campaña.

Consecuencias de no modelarlo: los plazos de entrega se cuentan mal (el creador no puede producir sin producto y aparece como incumplidor), el margen de la campaña queda incompleto, y el equipo mantiene una hoja de cálculo paralela desde la primera campaña. Ver proceso P9.

### F-03 · Archivado de evidencia de publicación 🔴
La spec registra la URL publicada, pero **no la evidencia**. Los posts se borran, las Stories duran 24 horas, las cuentas se cierran, los creadores se enfadan. Cuando el cliente pida el respaldo tres meses después, o cuando haya una disputa sobre si se cumplió, la URL no vale nada.

Necesario: captura del post en vivo (imagen y/o archivo) en el momento de la verificación, almacenada de forma inmutable con fecha, hora y hash. Especialmente crítico para **Stories**, cuya única prueba posible es la captura.

### F-04 · Orden de compra y documento de compromiso del cliente 🟠
En LATAM, las marcas medianas y grandes exigen **Orden de Compra** antes de que el proveedor facture. Sin registrar la OC (número, monto, vigencia, centro de costo), Finance no puede cobrar y la campaña se ejecuta sin respaldo. También hace falta representar contratos marco vs. contratación por campaña (Q-06).

### F-05 · Canal de comunicación estructurado creador ↔ equipo 🟠
La spec menciona que el creador "puede preguntar", pero no hay módulo de mensajería. Sin un hilo por campaña, toda la comunicación vuelve a WhatsApp — y con ella la mitad de las decisiones operativas, que dejan de estar en el sistema. **El vacío que más rápido devuelve la operación al caos.**

Mínimo viable: hilos por campaña y por participación, con notificación por email (y por WhatsApp saliente en F12), y con el histórico consultable desde la ficha.

### F-06 · Antifraude en el cambio de datos bancarios 🔴
§34 pide guardar historial de modificaciones de cuentas bancarias, pero no controles. El ataque es trivial y clásico: se compromete la cuenta de un creador (o se suplanta por email), se cambia la cuenta bancaria justo antes del lote de pago, y el dinero se va. Controles necesarios: período de enfriamiento, reverificación, notificación al canal de contacto **anterior**, y doble aprobación en lotes sobre umbral. Ver `BR-FIN-006`.

### F-07 · Modelo de derechos de uso con vigencia 🟠
§28 menciona cesión de derechos y licencias, pero como documento, no como **dato explotable**. Lo que la marca compra es una licencia con alcance, territorio, canales, exclusividad y vigencia. Modelarlo como entidad permite: alertar antes del vencimiento, vender renovaciones (línea de ingreso recurrente real y muy rentable), y evitar que el cliente use contenido con licencia expirada — infracción con consecuencias legales para él y reputacionales para el operador. Ver `DEC-014`.

### F-08 · Política de cancelación y sus consecuencias económicas 🟠
¿Qué pasa si el cliente cancela cuando 40 creadores ya están produciendo? ¿Qué se les paga? ¿Qué se le factura al cliente? Sin modelarlo, cada cancelación se negocia a mano y el ledger queda inconsistente. Ver `BR-CAMPAIGN-010`.

### F-09 · Capacidad y carga del equipo 🟡
No hay forma de saber cuántas campañas simultáneas puede llevar un Campaign Manager, ni cuánto contenido tiene pendiente el equipo de revisión. Es el dato que dice si se puede vender otra campaña este mes. Un indicador simple de carga por persona resuelve el 80%.

### F-10 · Gestión de reemplazos de creadores 🟡
Un creador acepta y luego desaparece, se enferma o incumple. En una campaña de 60 creadores esto pasa **siempre**. Hace falta: estado `dropped`/`replaced`, motivo, lista de espera desde la shortlist, reasignación del presupuesto y trazabilidad de quién sustituyó a quién.

### F-11 · Contenido en distintos idiomas y variantes por mercado 🟡
Una campaña en México y Perú necesita variantes de caption y de hashtags. Está implícito en `CampaignMarkets` pero no explícito en los entregables.

### F-12 · Onboarding y ayuda dentro del producto 🟡
150 creadores nuevos van a preguntar lo mismo 150 veces. Un centro de ayuda mínimo (FAQ, guía de primeros pasos, ejemplos de buen contenido) reduce drásticamente la carga de soporte y es barato.

### F-13 · Gestión de impuestos sobre la venta al cliente 🟡
IGV en Perú, IVA en otros países, retenciones, detracciones, percepciones. La spec habla de facturación electrónica pero no del **cálculo** de impuestos, que varía por país y por tipo de servicio.

### F-14 · Registro de banco de datos personales 🟡
Si aplica la Ley 29733, tratar datos personales de cientos de creadores puede requerir inscripción ante la autoridad de protección de datos. Es un trámite, no desarrollo, pero **debe estar en la lista de tareas de lanzamiento**. Requiere confirmación legal.

---

## Parte C — Lo que la especificación acierta y hay que proteger

Lo digo explícitamente porque durante el desarrollo habrá tentación de recortar justo aquí, y sería un error:

| Acierto | Por qué protegerlo |
|---|---|
| **Prohibir el scraping** (§8) | Evita un riesgo legal y una dependencia frágil que hunde a muchas plataformas del sector |
| **Ledger en lugar de campo de saldo** (§33) | Es la diferencia entre finanzas auditables y finanzas creíbles |
| **Nunca borrar información financiera** (§55) | Requisito legal y de auditoría, no preferencia |
| **Snapshots históricos de métricas** (§9, §30) | El histórico propio es la ventaja competitiva a 3 años, y no se puede reconstruir después |
| **Capas de abstracción para pagos y facturación** (§35, §36) | Los proveedores cambian; el core no debe |
| **Separar cliente de usuario** (§22) | Error clásico evitado |
| **Marca dentro de organización cliente** (§77) | Modelado correcto desde el inicio; retrofitearlo es carísimo |
| **Estados centralizados, no strings dispersos** (§25) | Base de la mantenibilidad |
| **Multimoneda con moneda por transacción** (§49) | Imposible de retrofitear |
| **RBAC con permisos, no solo roles** (§46) | Correcto |
| **Auditoría no modificable desde la aplicación** (§45) | Correcto y poco común |
| **Fases → iteraciones → validación** (§2, §92) | La decisión de gestión más importante del documento |
| **No implementar supuestos legales sin revisión jurídica** (§56) | Madurez poco frecuente |
| **Advertencia ética sobre el programa de embajadores** (§16) | Habla bien de cómo se quiere tratar a los creadores, y eso es retención |

---

## Resumen ejecutivo de la crítica

**Tres cosas que hay que cambiar antes de escribir una línea de código:**
1. Recortar el MVP a los 9 bloques de `04-ROADMAP.md §3`.
2. Adelantar el motor de campaña y retrasar CRM y portal de marca.
3. Resolver `DEC-005` (régimen de pago al creador) con un contador.

**Cuatro cosas que hay que añadir al modelo antes de la Fase 2:**
1. Logística de producto (F-02).
2. Evidencia archivada de publicación (F-03).
3. Derechos de uso como entidad con vigencia (F-07).
4. Marca de plataforma y entidades legales con cobertura de facturación (`docs/11`), incluido el renombrado `Brand`→`ClientBrand` que evita la colisión conceptual.
5. Registro y resolver de integraciones con alcance por propósito, entidad, país y ambiente (`docs/12`), que sustituye a la tabla de configuración de facturación que yo mismo había propuesto.

**Tres cosas que hay que diferir sin culpa:**
1. Creator Score (pero capturar sus eventos desde el día 1).
2. Portal de marca completo y CRM.
3. Custom fields, filtros guardados, referidos, multiidioma y pasarela de pago.
