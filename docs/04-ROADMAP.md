# 04 — Roadmap, iteraciones y dependencias

> Versión 0.1 — 2026-08-21.
> **Regla del proyecto:** una iteración a la vez, terminada según la Definition of Done, verificada, y recién entonces la siguiente.

---

## 0. Cambio propuesto al orden de fases (y por qué)

La especificación propone las fases en este orden: … F7 *Brand Acquisition* → F8 *Client Portal* → **F9 *Campaign Engine*** → F10 *Financial* …

**Recomiendo cambiarlo.** Motivo: el objetivo del MVP es *operar 3 campañas reales sin Excel*. Con 3 marcas, un CRM y un portal de cliente no resuelven ningún dolor —el equipo comercial puede vivir con una hoja de cálculo para 3 cuentas—, mientras que el motor de campaña y la liquidación de creadores son exactamente donde el Excel se rompe. Construir CRM y portal de marca antes que el motor de campaña retrasa el valor real entre 6 y 10 semanas.

Segundo cambio: **la importación masiva se adelanta a la Fase 5.** Los primeros 150 creadores no van a llegar por una landing; van a venir de contactos, bases previas y outreach directo. Sin importación, alguien va a teclear 150 fichas a mano y la landing va a estar muy bonita sin nadie que la use.

| Orden original | Orden propuesto | Cambio |
|---|---|---|
| F7 Brand Acquisition | → F11 | Se retrasa |
| F8 Client Portal | → F13 | Se retrasa (y se reduce, ver `DEC-013`) |
| F9 Campaign Engine | → **F7** | **Se adelanta** |
| F10 Financial | → F9 | Se adelanta |
| F11 Analytics | → F10 | Se adelanta parcialmente |
| (nuevo) Importación masiva | dentro de F5 | Añadido |
| (nuevo) Evidencia y verificación de publicación | dentro de F8 | Añadido |
| (nuevo) Logística de producto | dentro de F7 | Añadido |
| (addendum) Marcas de plataforma y entidades legales | dentro de F4 | Añadido |
| (addendum) Registro y resolver de integraciones | dentro de F4 | Añadido |
| (addendum) Gamificación: completitud con XP en F6, motor en F14, ligas y retos en F15 | F6 / F14 / F15 | Añadido |

---

## 1. Vista de olas (waves)

| Ola | Fases | Objetivo de negocio | Duración estimada* |
|---|---|---|---|
| **Ola 0 — Fundaciones** | F0–F4 | Que exista una base sobre la que se pueda construir rápido y seguro | 7–10 semanas |
| **Ola 1 — MVP: operar sin Excel** | F5–F10 | 150 creadores, 3 marcas, 3 campañas completas end-to-end | 10–14 semanas |
| **Ola 2 — Escalar comercialmente** | F11–F14 | 1.000 creadores, 15–20 marcas, autoservicio del cliente | 12–16 semanas |
| **Ola 3 — Internacionalizar y endurecer** | F15–F17 | LATAM, seguridad y operación de nivel producción sostenido | 8–12 semanas |

\* Estimación para **2 desarrolladores full-time** + participación del negocio para validación. Con 1 desarrollador, multiplicar por ~1,7. Estas cifras son de planificación, no compromisos: se recalibran al cierre de cada fase.

---

## OLA 0 — FUNDACIONES

### F0 — Discovery *(en curso — este documento)*
**Entregables:** definición de producto, actores, dominios, módulos, procesos, arquitectura, roadmap, decision log, business rules, DoD.
**Criterio de salida:** el negocio aprueba `DEC-001` a `DEC-033`, o los sustituye por decisiones propias.
**Bloqueantes:** `DEC-005` (régimen de pago a creadores) requiere consulta contable/legal. **Puede iniciarse en paralelo hoy mismo.**

### F1 — Arquitectura y esqueleto técnico
| It. | Contenido | Salida |
|---|---|---|
| 1.1 | Selección definitiva de stack, hosting y proveedores (SMTP, storage, error tracking) | Documento de infraestructura + costos mensuales estimados |
| 1.2 | Esqueleto del proyecto, estructura de módulos, Composer, `.env.example` | Repositorio inicializado, arranca en local |
| 1.3 | CI: lint, análisis estático, tests, Deptrac (fronteras de módulos) | Pipeline en verde |
| 1.4 | Convenciones: naming, commits, ramas, PR template, ADRs | `CONTRIBUTING.md`, `ARCHITECTURE.md` |

**Dependencias:** solo `DEC-001` confirmado. **No depende del modelo de datos** — puede arrancar en paralelo con la Fase 2. Ver `docs/15-ARRANQUE-MVP.md §5`.

### F2 — Modelo de datos *(la fase más importante del proyecto)*
Iterativa y exhaustiva, tal como pide la especificación. **Nada de frontend aquí.**

| It. | Contenido | Cuestionamiento obligatorio al cerrar |
|---|---|---|
| 2.1 | Entidades y glosario de negocio | ¿Falta alguna entidad de los procesos P1–P9? |
| 2.2 | Relaciones y cardinalidades | ¿Alguna relación N:M encubierta como 1:N? |
| 2.3 | Normalización y separación aplicación/creador, cliente/marca/contacto | ¿Hay duplicación de verdad? |
| 2.4 | Estados, transiciones e históricos | ¿Qué histórico se pierde con un `UPDATE`? |
| 2.5 | Finanzas: ledger, monedas, impuestos, retenciones, facturación | ¿El saldo es derivable? ¿Es append-only? |
| **2.6** | **Multi-entidad legal: marcas de plataforma, entidades legales, cobertura de facturación con vigencia, registros y configuración fiscal, series, cuentas bancarias** | ¿Existe alguna dependencia país → una sola entidad? ¿Algún documento sin `legal_entity_id`? |
| **2.7** | **Integraciones: proveedores, conexiones, asignaciones con alcance y vigencia, credenciales, webhooks, trazas** | ¿Puede una combinación (propósito, entidad, país, ambiente) resolver dos conexiones a la vez? ¿Algún documento emitido sin registrar su conexión? |
| 2.8 | Multipaís, multimoneda, multiidioma, zonas horarias | ¿Queda alguna regla de Perú incrustada? |
| 2.9 | Auditoría, permisos y ámbito de acceso externo (cliente / creador) | ¿Toda entidad alcanzable desde un portal externo tiene su ámbito definido y testeable? |
| ~~2.10~~ | ~~Índices, volumetría, plan de crecimiento, archivado~~ → **movida al final de F7** (`docs/15 §5`): los índices se diseñan contra consultas reales, no imaginadas | ¿Qué consulta muere con 100k creadores? |
| 2.11 | **Revisión adversarial** del propio modelo + ERD final | Rol: arquitecto externo. Incluye comprobación explícita de `DEC-019` y `DEC-033`: ¿se resuelve dinámicamente alguna entidad legal o conexión histórica? |

**Entregables:** ERD, diccionario de datos completo (tabla, columna, tipo, nulabilidad, default, descripción, índice, FK), `DATABASE.md`.
**Criterio de salida:** los 9 procesos P1–P9 se pueden recorrer sobre el modelo en papel, sin campos faltantes.

> **Replanificación (2026-08-22).** F2 **no** se termina antes de empezar a construir: corre en paralelo con el carril de construcción. Cada fase de construcción tiene su iteración de diseño bloqueante declarada en `docs/15-ARRANQUE-MVP.md §4`, y ninguna arranca sin ella. La **iteración 2.11 sigue siendo puerta obligatoria antes de F5**. Terminar las catorce iteraciones de diseño antes de escribir código sería cascada con nombre de iteración — justo lo que §2 de la especificación pide evitar. Efecto: la construcción empieza 3–4 semanas antes.

### F3 — Design System y UX
| It. | Contenido |
|---|---|
| 3.1 | Journeys de los 6 perfiles (Creator, Brand, Sales, Campaign Mgr, Finance, Superadmin) |
| 3.2 | ~~Tokens: color, tipografía, espaciado, radios, sombras, modo claro/oscuro~~ ✅ **Entregado el 2026-08-21** a partir del kit de marca: `design/tokens.css` + `docs/14`. Queda solo validar la propuesta tipográfica (`Q-29`) |
| 3.3 | Componentes base: botones, formularios, tablas, cards, badges, modales, tabs, alertas |
| 3.4 | Estados: vacío, carga, error, sin permisos, sin resultados |
| 3.5 | Wireframes de baja fidelidad de las 12 pantallas críticas |
| 3.6 | ~~Identidad diferenciada por portal~~ ✅ Resuelto con el token de densidad por portal en `design/tokens.css` |
| 3.7 | Sustituir los assets del kit por los corregidos y publicar la guía de marca interna (`docs/14 §2`) |

**Dependencia parcial de F2:** los wireframes de listados necesitan saber qué campos existen.
**Ahorro por el kit de marca:** 3 a 5 días. La fase ya no arranca en blanco: paleta, neutrales, semánticos, tema oscuro y tipografía están decididos.

### F4 — Core técnico
| It. | Contenido | Notas |
|---|---|---|
| 4.1 | ✅ **Cerrada (2026-08-26)**, junto con `5.9`: comparten la única pieza difícil. Recuperación por enlace de un solo uso, 1 h, con la **misma respuesta exista o no el correo** (`DEC-115`), la URL **sin el token dentro** (`DEC-117`) y límite doble —por IP en la ruta y por correo en el controlador—. Login, logout y rate limiting existían desde 3.1; la verificación de correo sigue pendiente. Ver `docs/fase-5/5.9-ENLACE-DE-CONTRASENA.md` |
| 4.2 | RBAC: roles, permisos, políticas de recurso, tests de autorización negativos | La base de toda la seguridad |
| 4.3 | Ámbito de acceso externo: `client_organization_id` y `creator_id` aplicados por política de recurso | Sin `tenant_id` (`DEC-002`) |
| 4.3b | MFA/TOTP obligatorio para roles con permisos financieros | `BR-SEC-005`. Barato ahora, caro de retrofitear |
| 4.4 | Auditoría append-only + activity log | Con `request_id` |
| 4.5 | Catálogos maestros + seeders (países, monedas, idiomas, redes, tipos de documento) | Datos reales de LATAM + España |
| 4.5b | **Marcas de plataforma y entidades legales**: CRUD, cobertura de facturación con vigencia y prioridad, monedas permitidas, cuentas bancarias, contactos, activación | Nuevo (addendum). MVP: LATAM Social + CTS Perú |
| 4.6 | Settings **jerárquico en cascada** (plataforma → marca → entidad → entidad×país) + indicador de herencia | Ampliado por `DEC-018` |
| 4.6b | **Registro de integraciones + resolver + bóveda de credenciales**: proveedores, conexiones, asignaciones con vigencia, validación de empates al guardar, aislamiento de ambiente, `test connection`, panel mínimo (listar, crear, editar, asignar, probar) | Nuevo (addendum). Adaptadores de arranque: SMTP, storage, tipo de cambio |
| 4.7 | File Manager sobre S3 + validación por contenido + URLs firmadas | |
| 4.8 | Colas, scheduler, workers, Horizon | |
| 4.9 | ✅ **Cerrada (2026-08-26), adelantada.** Plantillas versionadas con vigencia (una publicada no se edita), registro de envíos que guarda la **huella** del cuerpo y no el cuerpo (`DEC-106`), caída de idioma anotada (`DEC-107`) y tres reintentos con `failed` visible (`DEC-108`). Desbloquea 7.6, 5.9, 4.1 y `T-10` |
| 4.10 | Notification Center in-app + preferencias. **Nota (4.13):** el bus de eventos de dominio ya existe (`App\Shared\Eventos` + `domain_events`); el centro de notificaciones es otro oyente sobre el mismo bus | |
| 4.11 | Manejo de errores, logging estructurado, páginas de error, health check | |
| 4.12 | Layout de admin, navegación, búsqueda básica, tabla estándar con filtros/orden/paginación | Reutilizable en todos los módulos |
| 4.13 | **QA de fase + Phase Review Report** | |

**Dependencias:** F1, F2, F3.
**Criterio de salida:** se puede crear un usuario, asignarle un rol, restringir su acceso, subir un archivo, enviar un email con plantilla y verlo auditado.

---

## OLA 1 — MVP: OPERAR SIN EXCEL

### F5 — Adquisición de creadores
| It. | Contenido | MVP |
|---|---|---|
| 5.1 | Landing de creadores (responsive, SEO, consentimientos) | ✅ |
| 5.2 | Formulario de aplicación multi-paso con guardado parcial | ✅ |
| 5.3 | Cuentas sociales declaradas + métricas + evidencias | ✅ |
| 5.4 | Subida de archivos y capturas de insights | ✅ |
| 5.5 | Detección de duplicados y validaciones anti-basura (honeypot, rate limit, verificación de email) | ✅ |
| 5.6 | **Importación masiva CSV/Excel** con mapeo, previsualización, validación, resumen y reversión | ✅ **(adelantado)** |
| 5.7 | Bandeja de revisión + ficha 360 + notas internas | ✅ |
| 5.8 | Máquina de estados + aprobar/rechazar/solicitar información con motivos tipificados | ✅ |
| 5.9 | ✅ **Cerrada (2026-08-26).** Aprobar una solicitud crea la cuenta del creador —con un hash de 32 bytes aleatorios que nadie conoce: no se puede entrar hasta usar el enlace—, escribe `creators.user_id` y manda el enlace de 72 h (`DEC-113`). Destapó que `/panel` enseñaba los totales internos a cualquier autenticado (`DEC-118`) |
| 5.10 | Blacklist | ✅ |
| 5.11 | QA de fase + Phase Review Report | ✅ |

**Criterio de salida:** 150 creadores cargados y aprobados, con cuenta activa, sin tocar Excel.

### F6 — Portal del creador
| It. | Contenido |
|---|---|
| 6.1 | Shell del portal (PWA), navegación móvil, onboarding con barra de completitud |
| 6.2 | Mi perfil: personal, ubicación, idiomas, preferencias |
| 6.3 | Mis redes: alta, edición, actualización de estadísticas, evidencia |
| 6.4 | Perfil profesional: nichos, formatos, restricciones, portfolio |
| 6.5 | Mis tarifas por formato/moneda/vigencia |
| 6.6 | Datos fiscales y medios de pago (con cifrado, enmascarado y flujo de verificación de cambios) |
| 6.7 | Flujo de aprobación de cambios sensibles |
| 6.8 | Dashboard del creador (máx. 6 KPIs) + notificaciones + **barra de completitud con XP básico** (`G-01`, única gamificación del MVP) |
| 6.9 | QA de fase + Phase Review Report |

**Dependencias:** F5. **Bloqueo parcial:** 6.6 depende de `DEC-005`.

### F7 — Motor de campaña *(adelantado)*
| It. | Contenido |
|---|---|
| 7.0 | Clientes y marcas: alta manual de `ClientOrganization`, `ClientBrand`, `Contact`, datos fiscales por país, y **asignación de entidad legal según cobertura de facturación** (bloqueo si ningún país coincide, `BR-LE-003`/`BR-LE-004`) |
| 7.1 | ✅ **Cerrada (2026-08-25).** Entidad Campaign + estados + transiciones + auditoría + **`billing_legal_entity_id` congelado** |
| 7.2 | ✅ **Cerrada (2026-08-25).** Brief: requisitos y formatos por campaña, con el veto de `BR-CAMPAIGN-004` al salir de borrador, e **ingreso declarado** (`is_gratis`: cero vale, pero hay que decir que es a propósito). Hashtags, menciones, restricciones y *assets* **no** entran: sin mercados (7.3) un requisito no se puede partir por país, y los *assets* dependen del módulo de archivos |
| 7.3 | ✅ **Cerrada (2026-08-25).** Mercados de campaña (multipaís): al menos uno para salir de borrador, foráneas **compuestas** para que el mercado sea de su campaña, `N-03` implementada (el brief de mercado reemplaza al general) y **añadir sí, quitar no** con la campaña confirmada. Cierra `T-33` |
| 7.4 | ✅ **Cerrada (2026-08-26).** Buscador con los filtros derivados de la campaña (mercados, formatos del brief, edad mínima, categorías de la marca), interruptor de descartados con el motivo, coste estimado, y lista corta en `campaign_creators` con el mercado derivado del país. Media `BR-CAMPAIGN-007` implementada. Abre `T-34` |
| 7.5 | ✅ **Cerrada (2026-08-26).** Presupuesto de creadores (columna nueva: `BR-CAMPAIGN-005` nombraba un dato que no existía), veto de sobrecosto con autorización de finanzas y motivo auditado, y monto acordado que se fija al invitar y se congela al aceptar. «Cantidad» = por creador (`DEC-103`) |
| 7.6 | ✅ **Cerrada (2026-08-26).** Envío por enlace de un solo uso —el creador contesta él, no un operador (`DEC-119`)—, plazo fijo por campaña con comando de caducidad (`DEC-120`), rechazo con motivo de lista cerrada y reinvitación dejando constancia de las dos rondas (`DEC-121`). La invitación **copia el importe** y la base impide moverlo mientras viva (`DEC-122`). **Las preguntas del creador quedan fuera**, en `T-38`: son un mini módulo de mensajería |
| 7.7 | ✅ **Cerrada (2026-08-26).** Embudo por estado, cupo por mercado —donde **cubierto es aceptado, no invitado**—, dinero con el margen detrás de su permiso (`DEC-128`) y cuatro alertas ordenadas por lo que bloquean, no por gravedad (`DEC-127`). Pantalla aparte de la ficha: una contesta «qué es» y la otra «cómo va» |
| 7.8 | **Logística de producto:** dirección de envío, lista de despacho, tracking, confirmación de recepción |
| 7.9 | Campañas UGC vs distribución |
| 7.10 | Gestión de reemplazos: `dropped`/`replaced`, motivo, lista de espera, reasignación de presupuesto |
| 7.11 | Detección de conflictos de marca y exclusividades vigentes |
| 7.12 | Calendario de campaña |
| 7.13 | QA de fase + Phase Review Report |

**Dependencias:** F5, F6, F4.

### F8 — Contenido, revisión y evidencia
| It. | Contenido |
|---|---|
| 8.1 | ✅ **Cerrada (2026-08-26).** Los entregables se generan **solos** al aceptar, del brief **efectivo** del mercado (`DEC-129`, `DEC-130`). Se entrega un enlace `https://` y opcionalmente una imagen (`DEC-131`); si al caption le faltan los hashtags del brief, **no se envía y se le dice cuáles** (`DEC-132`). Primera pantalla del **portal del creador** —y primer permiso de ámbito EXTERNAL—, que `T-09` no bloquea: para llegar aquí ya hay que haber aceptado los términos. De paso, `T-43`: cuatro mensajes de la base que en **Percona no caben** y llevaban rotos desde 7.4 |
| 8.2 | ✅ **Cerrada (2026-08-26).** El puntero apunta a la versión **aprobada** —no a la última, que sale de un `MAX()`— con clave ajena **compuesta** para que la base garantice que es de **ese** entregable (`DEC-137`). Y un entregable aprobado deja de ser un callejón sin salida: se **reabre** con motivo de lista cerrada y firma, sin deshacer nada (`DEC-138`). De paso, `T-47`: «no se entrega sobre un entregable cerrado» vivía en el servicio desde 8.1 y la base no lo conocía |
| 8.3 | ✅ **Cerrada (2026-08-26).** Bandeja **global** de lo pendiente, ordenada por lo que lleva más esperando. Sólo las rondas del **cliente** cuentan contra el precio y son **por entregable** —el contador estaba en `campaign_creators`, o sea dos rondas por creador (`DEC-133`)—. Pasarse **bloquea** y exige decir si se cobra o se absorbe, firmado (`DEC-135`); el cargo **no** va a `campaign_costs`. Tres permisos: revisar, aprobar y autorizar el cargo (`DEC-136`). Y `tg_cvw_inmutable` cierra el «append-only» que 2.12 afirmaba sin impedir nada |
| 8.4 | ✅ **Cerrada (2026-08-27).** El límite ya existía entero desde `8.3` —lo dije mal en `docs/09` y queda anotado—; lo que faltaba era que **algo lo respaldara en la base**. Tres reglas vivían sólo en PHP: que sólo la corrección del **cliente** gaste ronda (`ck_cvw_round` no decía de quién era), que `over_included` diga la verdad —y esa columna es la que se factura— y que el contador no baje. `tg_cvw_techo` mira las dos direcciones y es cross-table. Los disparadores **siguen sin modificar filas**, a propósito (`DEC-150`). `tg_del_rondas` acabó monótono y no «+1» porque el daño no es simétrico (`T-53`). De paso, dos suites estaban verdes por una premisa que nunca escribieron (`T-54`). Abre `Q-60` |
| 8.5 | ✅ **Cerrada (2026-08-27).** La primera vez que entra alguien de la **marca**: sin portal, sin cuenta y sin contraseña. Su respuesta se **registra** y no mueve la pieza (`DEC-151`) — y no es burocracia: su corrección gasta ronda, y desde `8.4` una ronda de más exige firma, que él no puede poner contra sí mismo. El silencio no hace nada, y por eso esto **no estrena comando de caducidad** al revés que `7.6` (`DEC-152`). Una petición sin rondas queda pendiente de autorizar (`DEC-153`). `tg_apl_version_aprobada` cierra la otra mitad de `BR-CONTENT-002`: al cliente le llega lo aprobado **y esa versión**. Decimoséptima columna puerta: un enlace vivo por pieza |
| 8.6 | ✅ **Cerrada (2026-08-26).** El creador pega el enlace de su post desde su portal, y el equipo puede hacerlo por él (`DEC-139`). **Sólo se publica lo aprobado, y esa versión** (`DEC-140`), guardada como snapshot con clave ajena compuesta. La red se **deduce** del enlace con `platforms.url_pattern` y tiene que ser la del brief (`DEC-141`); la huella normaliza la URL para que `?utm_source=…` no esquive «el mismo post no se reclama dos veces». La **programación** queda fuera: hoy se registra lo ya publicado |
| 8.7 | ✅ **Cerrada (2026-08-26).** La evidencia es una **captura**, no una comprobación HTTP: Instagram y TikTok responden igual a un post vivo que a un bloqueo, así que un `http_status` no prueba nada y de `verified` cuelga el pago (`DEC-142`). Permiso propio `content.verify` (`DEC-143`). Si el post no está, el entregable vuelve al **creador** —el contenido estaba bien, falla el enlace— y se le avisa (`DEC-144`). `permanence_until` se calcula al verificar, que es lo que 8.8 necesita. De paso, `T-50`: una rechazada bloqueaba su propio enlace |
| 8.8 | ✅ **Cerrada (2026-08-27).** Retirar el post antes de tiempo **bloquea el pago** y el sistema no descuenta nada: la decisión de qué se le paga la toma una persona con el expediente montado (`DEC-145`). La sonda **marca** y una persona **confirma** —es `DEC-142` otra vez, pero aquí un falso negativo acusa a un creador de incumplir un contrato— y `tg_pub_permanencia` exige comprobación fallida **y** captura posterior a la verificación (`DEC-146`). Se avisa al creador y al equipo; al cliente no (`DEC-147`). El entregable caído estrena estado y `expired` pasa a llamarse `fulfilled`, que es lo que significaba (`DEC-148`). Decimosexta columna puerta: `uq_pc_sonda_dia`, para que un cron duplicado no mande dos correos. `permanence_checks` entra en la lista de `3.12`. Abre `Q-59` y resuelve `T-51` |
| 8.9 | Hilos de mensajes creador ↔ equipo por campaña |
| 8.10 | Derechos de uso (`UsageRight`): alcance, territorio, canales, exclusividad, vigencia |
| 8.11 | ✅ **Cerrada (2026-08-27).** No añade funcionalidad: añade la **séptima puerta**. De los trece defectos de la fase, **ocho eran comprobaciones que existían y no comprobaban lo que decían**. Los ayudantes de las suites estaban copiados treinta veces en seis variantes, y **nueve se habrían puesto verdes con el motor apagado** — medido: `3 correctas, 0 fallidas` contra una base que no existe (`T-55`). `cargosPendientes()` devolvía todas las rondas para siempre porque no existe la columna que decía filtrar (`T-56`, abre `Q-61`). Y el contador de columnas puerta iba por 17 cuando son 24 (`T-57`). `DEC-154` pone los ayudantes en un archivo y un trinquete sobre las 297 aserciones que todavía sólo afirman que algo falló |
| 8.12 | ✅ **Cerrada (2026-08-27).** Sin migración. La sociedad que factura **no se elige, se resuelve** —`uq_lec_country` + `tg_lec_sin_solape_*` dejan como mucho una por país y fecha— y lo que faltaba era decirlo: la ficha da la sociedad **y su motivo** (`DEC-155`). Al ir a enseñarlo salió `T-58`: el bloque imprimía la sociedad que tocaría HOY bajo el rótulo de la guardada, y el comentario de encima describía código que nunca se escribió. Y `DEC-156` fija que **la campaña decide quién paga a cada creador**, sea cual sea su país: `BR-LE-009` pierde el «en el MVP» y sube a 🔴. Eso cambia el eje de `Q-40`, que pasa a ser dos tablas —CTS Perú paga, CTS Colombia paga— |
| 8.13 | ✅ **Cerrada (2026-08-27).** Sin código. `T-59`: `DEC-156` decía que «`payouts` no tiene ninguna columna de sociedad» y era falso — `payout_batches.legal_entity_id` existe, y un pago no puede existir sin lote. Quien paga sí está dicho; lo que **no** está dicho es que sea quien debe, porque nada ata el lote a las campañas cuyos asientos liquida. `DEC-157` adelanta esa comprobación de `9.11` a la iteración que estrene `payout_batches`, y a la base. `DEC-020` deja de ser PROPUESTA |

**Dependencias:** F7.

### F9 — Finanzas
| It. | Contenido |
|---|---|
| 9.1 | ✅ **Cerrada (2026-08-27).** `exchange_rates` llevaba en el esquema desde la Fase 2 con cero filas y cero lecturas. Tres cosas que sólo se ven con datos: **(a)** dos fuentes podían tener tasa el mismo día y nada decía cuál se aplica —el `EMPATE` de `CoberturaFacturacion` otra vez—, y lo arregla `fx_official_sources` con periodos (`DEC-158`); **(b)** SUNAT publica compra y venta y en una columna `rate` sólo cabía una, así que nace `side` y **sin valor por defecto**, porque elegirlo es contable y no técnico (`DEC-159`, abre `Q-63`); **(c)** una tasa publicada se podía reescribir pese a `BR-FIN-009`, y ahora `tg_fx_inmutable` bloquea el `UPDATE` entero (abre `Q-62`). Un día sin tasa usa la anterior **y guarda la fecha de esa tasa**, con un corte de 10 días para que un cron parado no se disfrace de feriado (`DEC-160`). Vigesimoquinta columna puerta. De paso `T-60` —tres reglas que estaban puestas y no eran ellas las que contestaban— y `DEC-161`, que da a `porque()` la alternancia que faltaba para empezar a bajar las 297 |
| 9.2 | ✅ **Cerrada (2026-08-27).** El cron llama a Decolecta a las 05:30, trae el tipo de cambio de SUNAT y lo anota; hay pantalla de **Tipos de cambio**. La credencial se configura ahí pero **el entorno manda y en la base va cifrada**, no se reenseña nunca, y exige firma completa (`DEC-162`) — con permiso propio: la pantalla es `fx.manage`, la credencial `integration.manage`. Cada final de la traída tiene su nombre y un 404 no se pinta como avería (`DEC-163`). `fx_fetch_runs` enseña que el cron murió **antes** del día de la liquidación, y `ck_ffr_nuevas` impide que una corrida fallida diga que trajo algo (`DEC-164`). Decolecta sólo trae `USD → PEN` y el sistema lo dice en vez de disimularlo (`DEC-165`, abre `Q-64`). `T-61`: la traída podía escribir a medias y jurar que no. Y dos cosas las cazaron las puertas: `env()` fuera de `config/` y aritmética de fechas a mano |
| 9.3 | ✅ **Cerrada (2026-08-27).** `ledger_entries` llevaba desde la Fase 2 con doce `CHECK` y sus dos disparadores de inmutabilidad — **y cero filas**. Faltaban dos garantías: el estado podía **ir a cualquier sitio** —un `paid` volviendo a `accrued` es dinero pagado que reaparece como deuda— y un devengo se podía crear **dos veces** (`uq_ledger_devengo`, vigesimosexta columna puerta). Pasar a pagable lo hace el sistema en cuanto se cumplen las cinco de `BR-FIN-003`, porque las cinco ya las firmó alguien una por una (`DEC-166`). Un post caído **retiene**, no anula (`DEC-167`, es `DEC-145` aplicada al dinero). El asiento va en la **moneda pactada** y se convierte al pagar (`DEC-168`). Y toda transición exige decir por qué (`DEC-169`). `T-62`: el motivo de la transición anterior explicaba la siguiente — lo cazó la suite de `2.13`, que a su vez afirmaba el hueco como correcto desde la Fase 2 |
| 9.4 | ✅ **Cerrada (2026-08-27).** Aceptar una invitación anota el asiento solo. **Al aceptar y no al terminar**, porque es al aceptar cuando existe la deuda —`7.5` congela el importe y `BR-CREATOR-008` impide cambiarlo—; que aún no se pueda pagar vive en el estado (`DEC-170`). Por evento, como `8.1`, porque `deptrac` no deja que Campaign conozca a Finance — y si anotar falla, la aceptación sigue siendo cierta. Dos finales **no** son fallos: que ya hubiera devengo y que sea un canje. Y `ledger:revisar` rescata las aceptaciones que se quedaron sin asiento **avisando** de que hubo que rescatarlas: que la lista no salga en cero es la noticia (`DEC-171`) |
| 9.4 | Aprobación de ganancias, ajustes, bonos, retenciones |
| 9.5 | Requisitos documentales por país/régimen (recibo por honorarios, factura, etc.) |
| 9.6 | ✅ **Cerrada (2026-08-27).** Aquí sale el dinero — y aquí se **paga la deuda de `DEC-157`**: nace `payout_earnings` (qué devengos paga cada pago) y con ella `tg_pe_sociedad` impone que **la sociedad que paga sea la de la campaña** (`BR-LE-009`, `DEC-174`). Vigesimoséptima columna puerta. **Dos firmas siempre, sin umbral** (`DEC-175`): un umbral hay que mantenerlo, y el día que alguien quiera saltárselo parte el lote en dos. Sacar un pago del lote **no obliga a firmar otra vez** —el importe baja, nunca sube— y su liquidación se anula, no se borra (`DEC-176`). El CSV **no es el archivo del banco**, y la pantalla lo dice (`DEC-177`) |
| 9.7 | ✅ **Cerrada (2026-08-27).** `sent` significa «lo mandamos», no «llegó»: entre las dos cosas está el banco. Lo que faltaba no eran los estados —`payouts` los tiene desde la Fase 2— sino el **grafo**: sin él un pago saltaba de `pending` a `confirmed` sin haber salido nunca, y uno devuelto volvía a `sent` con un `UPDATE`. Confirmar exige **referencia, fecha valor y firma**; devolver, **motivo y firma**. Un pago devuelto **no deshace el devengo** (`DEC-178`): se escribe un `payment_reversal` que nace `accrued` y pasa a `payable` en la misma operación. Al creador se le avisa **al confirmar y al devolver, no al enviar** (`DEC-179`), y sin número de cuenta |
| 9.8 | ✅ **Cerrada (2026-08-27).** La primera vez que un creador ve su dinero. Sin migración: el libro mayor ya tenía todo. **Sin un solo botón** —el creador no mueve dinero, lo mira—. Saldo **por moneda** y no sumado, porque sumar dos monedas exige un tipo de cambio y el de hoy no es el del día del pago. El **motivo interno** de una retención no cruza: se escribe para el expediente y puede nombrar sospechas sin confirmar (`DEC-172`); la frontera vive en `Ledger::misIngresos()`, que enumera columnas. Y la **fecha de cobro sólo cuando ya es pagable** (`DEC-173`): antes se enseña qué falta, que es accionable |
| 9.9 | Facturación al cliente **emitida desde la entidad legal**: hitos, registro de OC, documento con snapshot del emisor, instrucciones de pago de sus cuentas, CxC, cobro, conciliación |
| 9.10a | ✅ **Cerrada (2026-08-30).** La mitad que le faltaba a `9.10`: **el gasto**. `campaign_costs` llevaba desde la Fase 2 con cinco restricciones y **cero filas**, así que un P&L sólo podía restar lo que se le paga a los creadores y llamar «margen» al resto. Cada moneda **se agrupa, no se suma** (`DEC-180`, `Q-63`). El costo del creador entra **al devengarse** y del libro mayor (`DEC-182`). Y **cargar gastos no es ver el margen**: `campaign_manager` pierde `campaign.view_margin` y gana `finance.cost.manage` (`DEC-181`). `T-63`: el `DELETE` estaba cerrado desde la Fase 2 y el `UPDATE` no — reescribir un importe borraba el margen de ayer igual que borrar la fila. `T-64`: la matriz del seeder concede y no revoca nunca |
| 9.10 | ✅ **Cerrada (2026-08-30).** Ingreso − costo de creadores − gasto operativo: tres cifras de tres iteraciones distintas que nunca se habían puesto juntas. **Sin migración** — un margen guardado en una columna es un margen que se queda viejo. `Compromiso::margen()` **se borra** (`DEC-183`): restaba sólo los creadores y desde `9.10a` habría empeorado con cada gasto; y `seguimiento` pasa a mandar un booleano y un enlace, no un importe. Cada moneda por su lado y **porcentaje sólo con una sola**, diciendo por qué cuando no (`DEC-185`). Los canjes salen marcados y fuera de todo total, igual que las campañas con gastos en otra moneda, y la cabecera dice cuántas quedaron fuera (`DEC-184`). **Por sociedad no**: el ingreso de hoy es el declarado, no el facturado |
| ~~9.11~~ | ✅ **Pagada en `9.6`.** Adelantada por `DEC-157` a la iteración que estrene `payout_batches`.** Era la validación de `billing_legal_entity ≠ settlement_legal_entity`, y una regla 🔴 en la posición 11 de 14 llega después de que diez iteraciones la den por buena. Va **a la base** y es cross-table, así que es un disparador. `DEC-020` ya no es «en el MVP»: es la regla (`DEC-156`) |
| 9.12 | ✅ **Cerrada (2026-08-31) — un número sale una sola vez, y lo que sale queda escrito.** Cierra `BR-LE-007` 🔴 y `DEC-021`. `document_series` existía desde la Fase 2 y **nunca la escribió nadie**: forma correcta, ninguna mecánica. El número se reserva **bajo bloqueo** de la fila de la serie, no con `MAX()+1` (`DEC-229`), y está **medido con dos conexiones de verdad**, con su contraejemplo. Cada número queda escrito con su estado: el hueco existe pero se explica, y `used`/`voided` son finales (`DEC-230`). Los tipos de comprobante pasan a ser **catálogo por país** —código oficial, forma de la serie, dígitos del correlativo— en vez de cinco palabras peruanas en un `CHECK` (`DEC-228`); una serie por defecto por tipo, y la forma la impone el país (`DEC-231`). Las series **no se siembran**: es la única configuración sin valor de fábrica, y sale en rojo (`DEC-232`). La suite cazó **dos reglas decorativas** el primer día —`REGEXP` no distingue mayúsculas y un `CHECK` con `NULL` no rechaza— y destapó `T-76` |
| 9.13 | **Matriz de propósitos obligatorios por país** + bloqueo de activación sin cobertura + `integration_connection_id` en documentos (`DEC-028`, `DEC-033`) |
| 9.14 | ✅ **Cerrada (2026-08-30) — la mitad de QA.** Nace `verificar-cobertura-sql.py` y mide lo que ningún verificador miraba: **de las 317 reglas del esquema, 167 no habían contestado nunca a nadie** (`DEC-186`). Veinte estaban en el camino del dinero —`ck_ledger_sign`, `ck_pbatch_approval_order`, las dos de retención…— y ahora se les pregunta: **las veinte contestaron bien**. Los tres defectos que aparecieron estaban en la suite que iba a comprobarlo, no en el esquema — incluido un falso verde hecho con la alternancia de `porque()`. Trinquete a 147. La **auditoría de seguridad** se cerró aparte como `9.14b` |
| 9.14b | ✅ **Cerrada (2026-08-30) — la auditoría de seguridad.** `MuroTest` recorre **las 145 rutas** como creador, como usuario de cliente y como autenticado **sin rol**, y exige que ninguna se abra: 371 aserciones, y el muro aguanta (`DEC-188`). Las 23 rutas sin permiso quedan escritas con su motivo en `RUTAS-ABIERTAS`, y `verificar-muro.py` obliga a que la lista, las rutas y la prueba digan lo mismo (`DEC-189`). Encontró **dos puertas que no eran nuestras** —`storage.local` y su `PUT`, que registra el framework sobre el disco privado— y las cerró: no eran una fuga, pero ninguna lectura del código las habría visto. Y `T-67`: los archivos se guardan y **no se pueden ver desde ninguna pantalla** |
| 9.15 | ✅ **Cerrada (2026-08-31).** Los archivos se guardaban desde la Fase 3 y **no los servía nadie**: «con comprobante» sin enlace. Ahora hay una sola puerta, `/archivos/{uuid}`, con **dos escalones** — `file.view` deja pedir uno y el `Vigilante` decide cuál (`DEC-197`). **Cada módulo declara la regla de sus archivos** en su ServiceProvider, porque un decisor central pondría a Shared a saber de `payouts` y `deptrac` no lo vería. Se niega por omisión. **El permiso se mira en cada petición**: nada de URLs firmadas que sobreviven a un permiso retirado (`DEC-198`). El creador ve lo suyo, incluida su identidad; rastro en bitácora sólo para los sensibles. Cierra `T-67` |
| 9.16 | ✅ **Cerrada (2026-08-30) — y corrige el criterio de `3.5`.** Los términos se editan desde el admin y el sistema **arranca con un texto base publicado que dice que no está revisado**. Borrador editable, publicada inmutable (`tg_terms_inmutable`), y **«cambio menor»** que no obliga a reaceptar. Trae el principio rector `DEC-190`: **la plataforma es white label y todo se configura desde el admin** — lo que el código aporta es la regla, el valor sale de la configuración. Los avisos con prioridad sustituyen a los bloqueos. `T-09` deja de bloquear la activación de creadores |
| 9.17 | ✅ **Cerrada (2026-08-31) — la marca es un dato.** «LATAM Social» estaba escrito en tres plantillas, el favicon era un archivo del repositorio y el pie legal con la razón social y el RUC estaba a mano al pie del formulario de entrada. `platform_brands` guardaba todo eso **desde 2.10 y nadie la leía**. Ahora se configuran nombre, lema, pie legal, logotipo, favicon, tres colores y la tipografía desde `/marca`, sin desplegar (`DEC-199`). **Una sola marca por defecto**, impuesto por la 28.ª columna puerta. Lo que falta **no bloquea**: valor de partida y badge 🔴/🟡/🟢 (`DEC-200`). El logotipo se sirve por una puerta pública **sin identificador**, porque la pantalla de acceso la ve quien no ha entrado (`DEC-201`). Salió que **las clases de color de la marca no existían desde la Fase 4** (`T-72`) y que la pantalla de «sin permiso» devolvía 500 en vez de 403. Cierra `Q-29`, `Q-30` y `Q-31` en código |
| 9.17b | ✅ **Cerrada (2026-08-31) — qué falta por configurar, en una sola pantalla.** `9.16` y `9.17` dejaron cada una su lista de avisos, y cada una **sólo se veía entrando en su pantalla**; el correo escribiéndose en un archivo en vez de enviarse no lo decía **ninguna**. `Preparacion` las junta y **cada módulo declara las comprobaciones de su área** en su ServiceProvider, para que Shared no acabe sabiendo de cinco módulos sin que `deptrac` lo vea (`DEC-205`). Cada área declara el permiso con el que se arregla: **si no puedes arreglarlo, no lo ves**, y ningún aviso lleva a un 403 (`DEC-206`). Una comprobación que revienta se convierte en un aviso ámbar y no en un 500 (`DEC-207`). El correo en `log` es lo primero, en rojo (`DEC-208`). Sin migración |
| 9.17c | ✅ **Cerrada (2026-08-31) — el domicilio fiscal, con la forma que exige cada país.** El comprobante peruano lleva el **ubigeo** y `legal_entities` no tenía ni el distrito ni el código; `9.9` habría chocado con ello el primer día. No se llama `ubigeo`: la forma la declara el **país** —etiqueta, patrón y si lo exige— y la imponen dos disparadores cruzados, así que el formulario pide «Ubigeo» a una peruana y «Código DANE» a una colombiana **sin una línea de código por país** (`DEC-209`). El establecimiento nace en «0000» (`DEC-210`). Que falte no bloquea: sale en rojo en el panel (`DEC-211`), igual que el domicilio sembrado que **todavía dice «Por completar»** y que no decía nadie. Salió un `1267` de colación que habría reventado cada alta con código —invisible mientras el campo viniera nulo— |
| 9.17d | ✅ **Cerrada (2026-08-31) — las credenciales de cada API, en un solo sitio.** Es el prerrequisito de `9.9`: sin un sitio donde poner la URL y las claves secundarias de SUNAT, la facturación electrónica no tiene de dónde leerlas, y `.env` habría exigido SSH para pasar de pruebas a producción (`DEC-223`). Tres tablas de las cinco de `docs/12`; **las asignaciones no se construyen** porque resuelven un problema que hoy no existe, y está escrito dónde (`DEC-224`). Rotar **versiona**, no pisa: quedan «cuál era la de antes» y «cuándo cambió» con respuesta (`DEC-225`). La bitácora anota **que** cambió, nunca el valor ni los cuatro últimos (`DEC-226`), y una conexión activa exige `https://` porque `http://` manda las claves en claro (`DEC-227`). MySQL 8 rechazó lo que MariaDB aceptó en silencio — hicieron falta los dos motores. **Y destapó `T-75`**: la pasada entera terminaba sin decir si era verde, y debajo `verificar-periodos.py` llevaba rojo desde `9.16` |
| 9.18 | ✅ **Cerrada (2026-08-31) — se pacta lo que el creador recibe.** Se teclea 100 y la campaña provisiona **141,8440**; el mensaje dice además que, para dejar el 20 %, el ingreso tendría que llegar a **170,21** — las tres cifras del ejemplo del negocio (`Q-40`). La política —retención, umbral y **sobre qué se calcula**— vive en `pricing_policies` con vigencia e historia (`DEC-212`). La pregunta «¿markup o margen?» **no había que hacerla**: es una columna configurable, sembrada en `cost` porque es lo que dice el ejemplo, con las dos cifras a la vista (`DEC-213`). El motor rehace la resta y los tres números se congelan en la participación; el ingreso mínimo se dice, no se impone (`DEC-214`). La aritmética la hace el motor, no `float` (`DEC-215`) |
| 9.19 | ✅ **Cerrada (2026-08-31) — los términos nuevos hay que volver a aceptarlos.** Los tres escalones de `Q-46`: dentro del plazo se pasa con una franja; pasado el plazo se **mira y no se toca** —filtrado por método HTTP, así que cubre toda pantalla que exista o se añada—; pasada la ventana, sólo la pantalla de aceptar. Los plazos van **en la versión** y son inmutables al publicar: «tienes 15 días» no puede convertirse en «tenías 10» (`DEC-216`). El reloj empieza cuando le toca a cada uno, o un creador activado ayer nacería bloqueado (`DEC-217`). La pantalla del muro **no lleva permiso** — es la única salida de quien está bloqueado (`DEC-218`). Franja fija y no popup (`DEC-219`). **Y arregla `T-73`, reportado en producción**: una cobertura cerrada sigue tapando su último día |
| 9.19b | ✅ **Cerrada (2026-08-31).** El aviso de `Q-46`: al publicar una versión **de fondo** sale un correo a cada creador activo, y el texto dice **qué pasa después** — «hay términos nuevos» a secas se pospone (`DEC-221`). Core levanta un evento y Creator escucha, así que un SMTP caído no puede tumbar la publicación (`DEC-220`); cada envío va en su propio `try` (`DEC-222`). **Y corrige `T-74`, que era mío**: `Terminos` leía `creators` desde Core — la frontera rota que `deptrac` no ve, y exactamente la trampa contra la que avisa la cabecera que escribí en `9.15`. Hay una prueba que lee el código fuente, porque no hay herramienta que lo cace |

**Dependencias:** F7, F8, `DEC-005` resuelto.

### F10 — Medición y reportes
| It. | Contenido |
|---|---|
| 10.1 | Snapshots de métricas por publicación con fuente y timestamp |
| 10.2 | Captura asistida (creador declara + sube captura; operador valida) |
| 10.3 | Consolidación por creador / plataforma / campaña |
| 10.4 | Reporte de campaña reproducible (fecha de corte fija) |
| 10.5 | Entrega del reporte al cliente por enlace firmado + exportación PDF |
| 10.6 | Dashboards por rol (Superadmin, Ops, Campaign Mgr, Finance, Creator) |
| 10.7 | Exportaciones CSV/XLSX como job en cola |
| 10.8 | Indicador de carga y capacidad del equipo (campañas y revisiones por persona) |
| 10.9 | QA de fase + Phase Review Report |

**🏁 HITO: fin del MVP.** Criterio de aceptación en §3.

---

## OLA 2 — ESCALAR COMERCIALMENTE

### F11 — Adquisición de marcas y CRM
11.1 Landing B2B · 11.2 Formulario de lead + UTM + consentimiento · 11.3 Lead, asignación y SLA · 11.4 Pipeline Kanban configurable · 11.5 Actividades, notas, tareas y recordatorios · 11.6 Conversión Lead→Cliente con trazabilidad · 11.7 Organizaciones cliente, marcas y contactos · 11.8 Tags y segmentación · 11.9 QA de fase.

### F12 — Integraciones
Con el registro de integraciones ya construido en F4, esta fase pasa de "configurar cada integración a su manera" a **"escribir adaptadores y dar de alta conexiones"**, que es bastante menos trabajo.

12.1 SMTP dedicado y monitoreo de entregabilidad (SPF/DKIM/DMARC), con **remitente por entidad legal para los correos fiscales** (`DEC-023`) · 12.2 Adaptador de facturación electrónica + primer PSE peruano, dado de alta como conexión con `purpose = invoicing` asignada a CTS Perú · 12.3 Notas de crédito/débito · 12.4 Adaptador de tipo de cambio SUNAT con override auditado · 12.5 **Infraestructura de webhooks: URL por conexión, verificación de firma, idempotencia, reintentos y reproceso** (`DEC-031`) · 12.6 Pasarela de pago (**solo si `DEC-007` lo justifica**) · 12.7 WhatsApp Business API para notificaciones · 12.8 **Panel de salud de integraciones y simulador de resolución** · 12.9 QA.

### F13 — Portal de la marca
13.1 Invitación y usuarios de cliente · 13.2 Dashboard de campaña · 13.3 Aprobación de brief y contenido dentro del portal · 13.4 Biblioteca de contenido aprobado con derechos y vigencia · 13.5 Reportes en vivo · 13.6 Facturas y estado de cuenta · 13.7 QA.

### F14 — Creator Intelligence
14.1 Evaluaciones post-campaña (interna y de cliente) · 14.2 Señales y métricas derivadas · 14.3 Creator Score **basado en reglas explícitas y auditables** · 14.4 Score por plataforma/categoría/tipo de campaña · 14.5 Matching asistido con ranking y detección de conflictos de marca · 14.6 Detección de anomalías de audiencia · **14.7 Gamificación: motor de reglas de XP con topes y vigencia, ledger append-only, niveles calculados, panel de administración y simulador de reglas** · **14.8 Insignias y Academia del creador** — ✅ aprobada (`DEC-196`, 2026-08-30): módulos cortos con evaluación e insignia de «creador certificado», **cada módulo configurable desde el admin** (orden, formato, intentos, XP, insignia). El contenido —guiones y vídeo— es trabajo aparte y se puede arrancar sólo con texto · 14.9 QA.

> El Score solo tiene sentido **después** de F10, porque necesita histórico real. Calcularlo antes produce un número inventado que la gente usará como si fuera verdad.

---

## OLA 3 — INTERNACIONALIZAR Y ENDURECER

### F15 — Internacionalización real
**15.0 Gamificación avanzada:** ligas por cohorte con temporadas, retos internos con recompensa tangible, referidos con consolidación diferida, recompensas y canjes auditados (`DEC-038`–`DEC-040`; requiere validación legal previa, `Q-24`) · 15.1 Traducción de UI y correos (en-US, pt-BR) · 15.2 Esquemas fiscales por país (tipos de documento, retenciones, comprobantes) · 15.3 Proveedores de facturación por país · 15.4 Medios de pago por país · 15.5 Zonas horarias en operación multipaís · 15.6 Cumplimiento de privacidad por jurisdicción, incluida la **identificación del responsable del tratamiento cuando cambia la sociedad** · 15.7 QA · **15.8 Alta de una nueva entidad legal**: lista de verificación operativa (constitución, registro fiscal, proveedor de facturación, series, bancos, textos legales, migración de cobertura con `valid_to`) para que incorporar una segunda sociedad sea un procedimiento y no un proyecto.

### F16 — Hardening
16.1 Revisión OWASP completa · 16.2 Pruebas de penetración (externas si el presupuesto lo permite) · 16.3 Pruebas de carga y perfilado de consultas · 16.4 Índices y archivado bajo volumen real · 16.5 Auditoría de accesibilidad · 16.6 Revisión de deuda técnica acumulada · 16.7 Plan de respuesta a incidentes.

### F17 — Producción
17.1 Infraestructura definitiva, SSL, dominios · 17.2 Monitoreo, alertas, uptime · 17.3 Backups y **restauración probada** · 17.4 Runbooks operativos · 17.5 Documentación final · 17.6 Plan de soporte y de despliegue continuo.

---

## 2. Grafo de dependencias

```
F0 ──> F1 ──> F2 ──┬──> F4 ──┬──> F5 ──> F6 ──> F7 ──> F8 ──> F9 ──> F10 ──> [MVP]
              F3 ──┘         │                    │        │        │
                             │                    │        │        └──> F14
                             │                    │        └──> F12 (facturación)
                             │                    └──> F13 (portal marca)
                             └──> F11 (CRM, independiente del motor de campaña)

F15, F16, F17 dependen de que exista operación real (post-MVP).
```

**Rutas críticas:** `F2 → F4 → F5 → F7 → F8 → F9`. Cualquier retraso ahí retrasa el MVP día por día.
**Trabajo paralelizable:** F3 (diseño) con F2; F11 (CRM) con F7–F9 si hay un segundo desarrollador; contenido de landings y textos legales durante toda la Ola 0.

**Bloqueos externos (no técnicos) que hay que destrabar YA:**
| Bloqueo | Bloquea | Responsable |
|---|---|---|
| `DEC-005` régimen de pago a creadores | F9 completa | Contador / abogado |
| Textos legales (términos, privacidad, cesión de derechos) | F5 (no se puede lanzar la landing sin ellos) | Abogado |
| Cuenta de proveedor SMTP + dominio con SPF/DKIM/DMARC | F4.9 | Operador |
| Cuenta S3-compatible | F4.7 | Operador |
| Definición del PSE de facturación | F12 | Operador |

---

## 3. MVP exacto

### 3.1 Está dentro
1. Landing de creadores + aplicación + revisión + aprobación + activación.
2. **Importación masiva de creadores.**
3. Portal del creador: perfil, redes, tarifas, datos de pago, campañas, entregables, ingresos.
4. Motor de campaña: brief, filtros, shortlist, invitaciones, seguimiento, logística de producto.
5. Contenido: entregables versionados, revisión interna, aprobación del cliente por enlace firmado, verificación de publicación con evidencia archivada.
6. Finanzas: ledger, aprobación de ganancias, lotes de pago, exportación bancaria, facturación al cliente (registro), rentabilidad interna por campaña.
7. Métricas manuales + reporte de campaña reproducible + dashboards por rol.
8. Núcleo: RBAC, auditoría, settings, catálogos, archivos, emails con plantillas, notificaciones, colas.
9. Solo **es-PE**, solo **PEN + USD**, solo **Perú** operativo (modelo listo para más).
10. **Una marca de plataforma (LATAM Social) y una entidad legal (CTS Perú)** con varios países configurados en su cobertura de facturación. La capacidad multi-entidad se construye como configuración; simplemente no se usa todavía con una segunda sociedad.

11. **Una conexión por propósito**, dada de alta en el registro de integraciones. El resolver existe y se usa desde el primer día; simplemente todavía no tiene nada que desempatar.

> **Nota de alcance (addenda del 2026-08-21):** el addendum multi-entidad añade **1 a 1,5 semanas** (fases 2 y 4) y el de integraciones otras **1 a 1,5 semanas** (fase 4), parcialmente compensadas por la simplificación de la Fase 12. Ninguno reordena ni recorta nada. Ambos llegan en el momento correcto: incorporarlos después de la Fase 9 costaría una migración sobre las tablas financieras y reescribir la lógica de facturación.

### 3.2 Está fuera del MVP (y es una decisión, no un olvido)
CRM completo · portal de marca con cuentas · propuestas comerciales · facturación electrónica automática · pasarela de pago · Creator Score · matching automático · referidos · custom fields · filtros guardados · blog · multiidioma · integración con APIs de redes sociales · WhatsApp automatizado · firma electrónica · app móvil nativa · MFA para roles no financieros.

### 3.3 Criterio de aceptación del MVP (verificable, no opinable)
Se declara MVP terminado cuando el equipo ejecute **3 campañas reales completas** cumpliendo simultáneamente:
- ≥50 creadores invitados por campaña desde el sistema, sin listas externas.
- 100% de entregables recibidos, versionados y aprobados dentro del sistema.
- 100% de publicaciones verificadas con evidencia archivada.
- Reporte de campaña generado desde el sistema y entregado al cliente.
- Liquidación de todos los creadores generada desde el sistema, con archivo de pago bancario.
- Margen real de cada campaña visible en el sistema y **coincidente con la contabilidad** (±0).
- **Cero hojas de cálculo** usadas para coordinar, revisar o liquidar.

Si alguno falla, el MVP no está terminado, por muy bonito que se vea.

---

## 4. Post-MVP: orden de prioridad recomendado

1. **WhatsApp para notificaciones al creador** (F12.7) — probablemente el mayor salto de eficiencia operativa por unidad de esfuerzo de todo el backlog.
2. **Facturación electrónica** (F12.2) — elimina doble digitación en Finance.
3. **Portal de marca: aprobación y biblioteca de contenido** (F13.3–13.4) — es lo que hace que la marca renueve.
4. **CRM** (F11) — cuando haya >10 leads/mes.
5. **Métricas por API oficial** — cuando el volumen de captura manual duela.
6. **Creator Score y matching asistido** (F14) — cuando haya ≥500 creadores y ≥15 campañas de histórico.
7. **Multipaís fiscal** (F15) — cuando haya un cliente real fuera de Perú.
