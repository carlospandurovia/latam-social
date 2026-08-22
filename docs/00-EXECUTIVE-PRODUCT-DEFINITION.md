# 00 — Executive Product Definition (FASE 0 / Discovery)

> Documento vivo. Versión 0.1 — 2026-08-21. Estado: **PROPUESTA, pendiente de revisión del negocio.**
> Marca de plataforma: **LATAM Social** (`DEC-000`, resuelta en el addendum del 2026-08-21).
> Sociedad operadora actual: **Soluciones Tecnológicas a Medida S.A.C.** — RUC 20603203896, Perú. **La marca y la sociedad no son lo mismo**: ver `docs/11-ADDENDUM-LEGAL-ENTITIES.md`.

---

## A. Resumen ejecutivo

### A.1 Qué es realmente este producto

No es "una plataforma de influencers". Es un **sistema operativo para una agencia de Creator Marketing**, con tres superficies de autoservicio conectadas a un único motor operativo.

La definición que usaremos durante todo el proyecto:

> **LATAM Social es el sistema de operación de campañas de una empresa que compra contenido a cientos de creadores y lo vende empaquetado a marcas, asumiendo el riesgo comercial, el margen y la coordinación.**

Esta definición no es cosmética; determina decisiones estructurales que si se toman mal obligan a reescribir el sistema:

| Consecuencia | Por qué |
|---|---|
| El operador es **principal**, no intermediario | La marca le compra al operador; el operador le compra al creador. Hay dos flujos financieros independientes (CxC y CxP), no una comisión sobre una transacción única. Ver `DEC-004`. |
| El **margen** es un dato interno de primer nivel | Revenue − Creator Cost − Direct Cost. Nunca visible para marca ni creador. Obliga a segregación de datos por audiencia desde el modelo, no desde la vista. |
| La unidad de valor es la **campaña**, no el perfil | Un directorio de creadores no es un producto vendible; una campaña ejecutada y reportada sí. El motor de campaña es el núcleo, no el buscador de creadores. |
| El sistema debe **reemplazar Excel y WhatsApp**, no complementarlos | Si el equipo sigue coordinando por WhatsApp, el dato nunca será confiable y todo lo demás (score, métricas, KPIs) será ficción. |

### A.2 Los tres problemas reales que resuelve

Un proyecto de este tamaño falla cuando construye funcionalidades en vez de resolver dolores. Estos son los dolores reales de una operación de creator marketing con 150+ creadores:

1. **Coordinación 1-a-N.** Una campaña con 60 creadores son ~600 interacciones (invitar, confirmar, briefear, recordar, revisar, corregir, aprobar, verificar publicación, pagar). Hecho por WhatsApp, esto satura al equipo alrededor de la campaña n.º 4 simultánea. **Este es el problema #1 y el MVP debe resolverlo antes que ningún otro.**
2. **Prueba de ejecución.** La marca paga por alcance y contenido. Sin evidencia estructurada (post publicado, captura de insights, métricas por pieza), la renovación depende de la confianza personal y no del dato. Los posts además se borran: sin archivado, la evidencia desaparece.
3. **Liquidación de pagos a muchos proveedores pequeños.** 150 creadores × pagos pequeños × reglas tributarias distintas = el proceso administrativo más caro y más propenso a error de todo el negocio. Es también el que más rápido destruye la confianza del creador cuando falla.

Todo lo demás (CRM, scoring, matching por IA, portal de marca, facturación electrónica, referidos, custom fields) es **secundario respecto a estos tres**, y buena parte debe salir del MVP. Ver `docs/10-CRITICAL-REVIEW.md`.

### A.3 Propuesta de valor por audiencia

**Para la marca:** un solo proveedor, un solo contrato, una sola factura, decenas de voces reales hablando del producto, con reporte verificable y derechos de uso resueltos.

**Para el creador:** acceso recurrente a campañas pagadas sin necesidad de tener cientos de miles de seguidores, con reglas claras, plazos claros y **pagos que llegan cuando se prometió**. La retención del creador se gana casi por completo en el módulo de pagos.

**Para el operador:** capacidad de operar 3 campañas hoy y 36+/año mañana con el mismo equipo, con trazabilidad, margen medible y activo de datos propietario (histórico de performance por creador) que a mediano plazo es la ventaja competitiva real.

### A.4 Objetivo operativo y horizonte

| Horizonte | Escala | Criterio de éxito |
|---|---|---|
| **MVP (T0 + ~4 meses)** | 150 creadores, 3 marcas, 3 campañas reales | Operar campañas completas **sin Excel** para invitación, entregables, aprobación, evidencia y liquidación. |
| **Año 1** | 1.000 creadores, 15–20 marcas, 36+ campañas | Portal de marca, reporting automático, facturación integrada, márgenes por campaña confiables. |
| **Año 2–3** | 10.000+ creadores, LATAM multipaís | Multipaís fiscal real, matching asistido, Creator Score con datos propios, agencias externas como clientes intermediarios. |

### A.5 Qué NO es este producto (delimitación explícita)

- No es una red social ni un feed. Los creadores no interactúan entre sí.
- No es un marketplace de autoservicio donde la marca contrata sola. Hay curaduría humana. (Puede evolucionar; hoy no lo es.)
- No es una herramienta de social listening ni de analítica de terceros. No competimos con Modash/HypeAuditor: **los integramos** si hace falta (ver `DEC-009`).
- No es un gestor de publicación (no publica por el creador en sus redes). Ver `DEC-011`.
- No es un ERP ni un contable. Se integra con facturación electrónica; no la reimplementa.

---

## B. Supuestos interpretados

Estos supuestos han sido **inferidos**, no confirmados. Cada uno que resulte falso cambia diseño. Los que tienen impacto estructural están escalados al Decision Log.

### B.1 De negocio

| # | Supuesto | Impacto si es falso |
|---|---|---|
| S-01 | El operador contrata a la marca y subcontrata al creador (modelo principal / reventa), no comisión de marketplace. | **Alto.** Cambia modelo financiero, fiscal y contable completo. → `DEC-004` |
| S-02 | El precio a la marca se negocia por campaña (no hay tarifario público self-service). | Medio. Afecta módulo de propuestas. |
| S-03 | El cobro a la marca es por transferencia contra factura con crédito (0–30–60 días), no tarjeta. | **Alto.** Si es falso, Culqi sube de prioridad; si es cierto, Culqi sale del MVP. → `DEC-007` |
| S-04 | La mayoría de creadores en Perú son personas naturales, muchos sin RUC. | **Muy alto.** Determina cómo se les paga legalmente. → `DEC-005` (bloqueante) |
| S-05 | Hay un equipo interno pequeño (2–6 personas) operando; no cientos de usuarios internos. | Medio. Justifica monolito modular y no microservicios. |
| ~~S-06~~ | ~~El operador es una sola empresa (un tenant). Agencias/white-label es visión futura.~~ **Confirmado y cerrado el 2026-08-21:** la plataforma la operan solo CTS y sus sociedades, para su propia agencia; no se venderá a terceros. → `DEC-002` resuelta sin multitenancy. | — |
| S-07 | Perú es el mercado de lanzamiento; el resto de LATAM es expansión ≥12 meses. | Medio. Permite diferir esquemas fiscales por país sin cerrarlos. |
| S-08 | Existe capacidad de captar creadores fuera de la plataforma (bases previas, redes, referidos). | Medio. Justifica priorizar **importación masiva** antes que la landing de captación. |

### B.2 Técnicos y de equipo

| # | Supuesto | Impacto si es falso |
|---|---|---|
| S-09 | El equipo de desarrollo es pequeño (1–3 devs) y necesita productividad, no ceremonia. | **Alto.** Refuerza la recomendación de framework maduro sobre MVC propio. → `DEC-001` |
| S-10 | Hosting inicial en VPS/Cloud Linux estándar con MySQL gestionado o local; no Kubernetes. | Bajo-medio. |
| S-11 | Hay presupuesto para servicios SaaS de bajo costo (SMTP transaccional, storage S3, PSE de facturación). | Medio. Si es falso, sube el costo de construir. |
| S-12 | No hay app móvil nativa en el horizonte de 12 meses; el portal del creador será web móvil (PWA). | Bajo. La arquitectura de servicios lo permite igual. |
| S-13 | El volumen de archivos (video UGC) es significativo: ~0,5–3 GB por campaña mediana. | **Alto** en costos e infraestructura de storage. Obliga a S3-compatible desde temprano, no filesystem local. |

### B.3 Legales (requieren validación de abogado — no las damos por ciertas)

| # | Supuesto | Nota |
|---|---|---|
| S-14 | Se aplica la Ley 29733 (Protección de Datos Personales, Perú) y su reglamento; el tratamiento de datos de creadores requiere consentimiento informado y probablemente inscripción de banco de datos ante la ANPD. | **Debe validarse con asesoría legal.** No implementaremos supuestos jurídicos como si fueran hechos. |
| S-15 | Trabajar con creadores menores de 18 años exige consentimiento de padre/tutor y controles adicionales. | Riesgo reputacional y legal alto. → `BR-CREATOR-010` |
| S-16 | La cesión de derechos de uso de contenido debe ser explícita, con alcance, territorio y vigencia. | Es el activo que la marca compra. → `DEC-014` |
| S-17 | La expansión a UE activa GDPR (base legal, DPA, transferencias internacionales, derecho de supresión). | No implementar hoy; **no bloquearlo** en el modelo de datos. |

---

## C. Mapa de actores y responsabilidades

### C.1 Actores externos al sistema

| Actor | Descripción | Interacción |
|---|---|---|
| **Prospecto (marca)** | Empresa que aún no es cliente. | Landing B2B, formulario de lead, WhatsApp. |
| **Postulante (creador)** | Creador que aún no fue aprobado. | Landing creador, formulario de aplicación, correo. |
| **Plataformas sociales** | Instagram/Meta, TikTok, YouTube, etc. | Verificación y métricas, vía API oficial o evidencia manual. |
| **Proveedor de facturación electrónica (PSE/OSE)** | Emisión de comprobantes en Perú y equivalentes por país. | API, detrás de una interfaz propia. |
| **Pasarela de pago** | Culqi / Stripe / Mercado Pago. | Solo si aplica (ver `DEC-007`). |
| **Banco / medio de pago masivo** | Ejecución de pagos a creadores. | Archivo de pago masivo / transferencias. |
| **Fuente de tipo de cambio** | SUNAT u otra. | Servicio con historial. |
| **Operador logístico** | Envío de producto al creador (product seeding). | **Ausente en la especificación original — ver `docs/10-CRITICAL-REVIEW.md` §Faltantes.** |

### C.2 Actores internos (usuarios del sistema)

| Rol | Responsabilidad principal | Necesita ver margen | Dashboard propio |
|---|---|---|---|
| **Superadmin** | Configuración, seguridad, usuarios, integraciones, auditoría. | Sí | Negocio global |
| **Administrator** | Gestión operativa general sin acceso a seguridad crítica. | Sí | Negocio global |
| **Operations Manager** | Salud de la operación: campañas atrasadas, cuellos de botella, capacidad. | Sí | Operación |
| **Campaign Manager** | Dueño de la campaña end-to-end: brief, selección, invitaciones, revisión, publicación. | Parcial (costo sí, margen no por defecto) | Mis campañas y tareas |
| **Creator Manager / Talent** | Reclutamiento, aprobación, relación y retención de creadores. | No | Pipeline de creadores |
| **Sales** | Leads, pipeline, propuestas, cierre, cuenta. | Sí (precio, no costo detallado) | Pipeline y metas |
| **Finance** | CxC, CxP, facturación, liquidaciones, retenciones, conciliación. | Sí | Finanzas |
| **Content Reviewer** | Control de calidad del contenido contra el brief. | No | Cola de revisión |
| **Support** | Soporte a creadores y marcas. | No | Cola de tickets |

### C.3 Actores externos con cuenta

| Rol | Ámbito | Restricción crítica |
|---|---|---|
| **Creator** | Su propio perfil, sus campañas, sus entregables, su dinero. | **Jamás** ve tarifa de otros creadores, precio al cliente ni margen. |
| **Client Admin** | Administra la cuenta de la marca, sus usuarios y contactos. | Ve inversión propia; **jamás** ve costo del creador ni margen. |
| **Client Campaign Manager** | Aprueba briefs y contenido de sus campañas. | Ídem. |
| **Client Finance** | Facturas, pagos, estado de cuenta. | Ídem. |
| **Client Viewer** | Solo lectura de reportes. | Ídem. |

> **Regla de oro de segregación (`BR-SEC-001`):** existen tres audiencias de datos mutuamente excluyentes — *interna*, *marca*, *creador*. Ninguna consulta que sirva a una audiencia debe poder devolver campos de otra. Esto se implementa con **DTOs/Resources por audiencia**, no con `if` en las vistas. Un solo escape de esta regla (mostrarle a la marca lo que se le paga al creador) puede costar la cuenta.

### C.4 Matriz RACI de los procesos críticos

| Proceso | Sales | Campaign Mgr | Creator Mgr | Reviewer | Finance | Cliente | Creador |
|---|---|---|---|---|---|---|---|
| Captación de lead | **A/R** | C | – | – | – | I | – |
| Cierre comercial | **A/R** | C | – | – | C | **C** | – |
| Diseño de campaña / brief | C | **A/R** | C | – | – | **A (aprueba)** | I |
| Aprobación del creador | – | C | **A/R** | – | – | – | I |
| Selección e invitación | – | **A/R** | C | – | – | C | **R (acepta)** |
| Producción de contenido | – | I | – | – | – | – | **A/R** |
| Revisión interna | – | C | – | **A/R** | – | – | I |
| Aprobación de cliente | – | R | – | – | – | **A** | I |
| Verificación de publicación | – | **A/R** | – | C | – | I | R |
| Liquidación al creador | – | C | – | – | **A/R** | – | I |
| Facturación al cliente | C | C | – | – | **A/R** | I | – |

---

## D. Dominios funcionales (bounded contexts)

La plataforma se descompone en **12 dominios**. Esta descomposición es la base de la estructura de código, de los permisos y del orden del roadmap. Un módulo pertenece a exactamente un dominio; los dominios se comunican por **servicios de aplicación y eventos**, nunca por consultas SQL cruzadas arbitrarias.

| # | Dominio | Responsabilidad | Entidades núcleo |
|---|---|---|---|
| **D1** | **Identity & Access** | Usuarios, autenticación, sesiones, roles, permisos, organizaciones. | User, Role, Permission, Session, Organization |
| **D2** | **Platform Core** | Configuración jerárquica, **marcas de plataforma**, **entidades legales y su cobertura de facturación**, **registro y resolver de integraciones**, catálogos maestros, países, monedas, idiomas, feature flags, archivos, auditoría, logs. | Setting, **PlatformBrand**, **LegalEntity**, **LegalEntityBillingCountry**, **IntegrationProvider**, **IntegrationConnection**, **IntegrationAssignment**, Country, Currency, Language, File, AuditLog |
| **D3** | **Creator** | Ciclo de vida del creador: aplicación, perfil, redes, audiencia, tarifas, verificación, estados. | CreatorApplication, Creator, SocialAccount, AudienceSnapshot, CreatorRate |
| **D4** | **CRM & Sales** | Leads, pipeline, actividades, propuestas, conversión a cliente. | Lead, Activity, Deal, Proposal |
| **D5** | **Client** | Organizaciones cliente, marcas, contactos, usuarios de cliente, datos fiscales. | ClientOrganization, Brand, Contact, ClientUser |
| **D6** | **Campaign** | Campaña, mercados, brief, requisitos, estados, participantes. | Campaign, CampaignMarket, Brief, CampaignCreator |
| **D7** | **Matching** | Búsqueda, filtros, shortlist, compatibilidad, conflictos de marca. | Shortlist, Candidate, ConflictRule |
| **D8** | **Content** | Entregables, versiones, revisión, aprobación, publicación, evidencia, derechos. | Deliverable, DeliverableVersion, Review, Publication, UsageRight |
| **D9** | **Measurement** | Métricas por publicación/creador/campaña, snapshots, tracking links, reportes. | MetricSnapshot, TrackingLink, PromoCode, Report |
| **D10** | **Finance** | Ledger de creadores, liquidaciones, pagos, facturación al cliente, **registros y configuración fiscal por entidad legal**, series y numeración, monedas, tipo de cambio, rentabilidad global y por sociedad. | LedgerEntry, Payout, Invoice, Payment, ExchangeRate, **LegalEntityTaxRegistration**, **LegalEntityFiscalConfiguration**, **LegalEntityDocumentSeries**, **LegalEntityBankAccount** |
| **D11** | **Communication** | Plantillas de email, envíos y su log, notificaciones in-app, preferencias, hilos de conversación. | EmailTemplate, EmailLog, Notification, Thread, Message |
| **D12** | **Intelligence** | Creator Score, evaluaciones, señales, recomendaciones. **Consumidor de datos, nunca fuente.** | Evaluation, ScoreSnapshot, ScoreRule |
| **D13** | **Engagement & Gamification** | XP, niveles, insignias, ligas, retos internos, referidos y recompensas del creador. **Consumidor de eventos, nunca fuente ni dependencia.** | XpRule, XpEntry, LevelTrack, Badge, League, Season, Challenge, Referral, Reward |

### D.0 Los cuatro conceptos organizacionales — desambiguación obligatoria

Cuatro cosas que suenan parecido y no lo son. Confundirlas es el error de datos más caro que puede cometer este proyecto (ver `DEC-016`, `R-26`):

| Concepto | Qué es | Ejemplo | Eje |
|---|---|---|---|
| **PlatformBrand** | La marca comercial con la que se ofrece el servicio | **LATAM Social** | Identidad de marca |
| **LegalEntity** | La sociedad que contrata, factura, cobra y paga | **Soluciones Tecnológicas a Medida S.A.C.**, RUC 20603203896 | Legal y fiscal |
| **ClientOrganization** | El grupo empresarial cliente | Grupo ABC | Cliente |
| **ClientBrand** (antes `Brand`) | La marca del cliente sobre la que se hace la campaña | Shampoo ABC | Cliente |

Los ejes son independientes: `platform_brand_id` responde "¿bajo qué marca se presenta?", `legal_entity_id` responde "¿qué sociedad responde legalmente?", y `client_organization_id` / `creator_id` responden "¿qué puede ver este usuario externo?". **Añadir una sociedad no cambia ninguno de los otros dos.**

> **No hay inquilinos.** `DEC-002` quedó resuelta el 2026-08-21: la plataforma la operan solo CTS y sus sociedades para su propia agencia, así que **no existe `tenant_id`**. Si algún día un tercero operase su propia red sobre este software, ver el disparador de revisión en el Decision Log. Detalle completo en `docs/11-ADDENDUM-LEGAL-ENTITIES.md`.

### D.1 Reglas de dependencia entre dominios

Las dependencias deben ser **acíclicas**. Esta es la regla que mantiene el monolito modular y no una bola de barro:

```
D1 Identity ──┐
D2 Core ──────┴──> (todos pueden depender de estos dos)

D3 Creator ──┐
D5 Client ───┼──> D6 Campaign ──> D7 Matching
D4 CRM ──────┘         │
                       ├──> D8 Content ──> D9 Measurement
                       └──> D10 Finance

D11 Communication  <── (todos publican eventos; Communication reacciona)
D12 Intelligence   <── (lee eventos; no escribe en nadie)
D13 Gamification   <── (lee los mismos eventos; no escribe en nadie)
```

Reglas duras:
- **D12 (Intelligence) y D13 (Gamification) no pueden ser dependencia de nadie.** Si el Creator Score deja de calcularse o el motor de XP se cae, la operación debe seguir funcionando igual. Esto los mantiene desechables y reemplazables. Ambos leen el mismo `DomainEvent` y **no se fusionan**: el score puede bajar y es interno; el XP nunca baja y es transparente (`DEC-041`).
- **D11 (Communication) se activa por eventos de dominio**, no por llamadas directas desde controladores. `CreatorApproved` → listener → email. Así el envío de correo nunca bloquea ni rompe una transacción de negocio.
- **D10 (Finance) es append-only.** Ningún otro dominio puede modificar un asiento del ledger; solo puede solicitar la creación de uno nuevo.
- **D4 (CRM) no puede escribir en D5 (Client).** La conversión Lead→Cliente es un caso de uso explícito y auditado, no un update encadenado.
