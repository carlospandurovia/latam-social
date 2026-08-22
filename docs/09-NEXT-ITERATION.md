# 09 — Propuesta para la siguiente iteración

> Versión 0.1 — 2026-08-21.

---

## 1. Estado actual

**Fase 0 (Discovery) — entregada, pendiente de tu revisión.**

Según lo acordado en §101 de la especificación, **me detengo aquí**. No hay código, no hay esquema de base de datos y no hay wireframes hasta que revises y apruebes (o corrijas) lo anterior.

---

## 2. Lo que necesito de ti para desbloquear la Fase 2

### 2.1 Decisiones que puedes tomar hoy (5 minutos cada una)

| # | Decisión | Recomendación | ¿Aceptas? |
|---|---|---|---|
| `DEC-001` | Framework: **Laravel 12** | Sí, salvo restricción de hosting o preferencia por Symfony | ☐ |
| ~~`DEC-002`~~ | ✅ **Resuelta el 2026-08-21: sin multitenancy, sin `tenant_id`.** La plataforma la operan solo CTS y sus sociedades | — | ☑ |
| `DEC-003` | Frontend renderizado en servidor + PWA para el creador | Sí | ☐ |
| `DEC-007` | Culqi diferido a F12 | Sí, salvo que exista un cliente que quiera pagar con tarjeta | ☐ |
| `DEC-008` | Almacenamiento S3-compatible desde el inicio | Sí, sin excepción | ☐ |
| `DEC-013` | Portal de marca en el MVP = enlaces firmados, no portal completo | Sí | ☐ |
| `DEC-016` | Renombrar `Brand`→`ClientBrand` y prohibir `Organization` a secas, antes de la Fase 2 | Sí, es casi gratis ahora | ☐ |
| `DEC-017` | Cobertura de facturación N:M **con vigencia**, no con un booleano `activo` | Sí | ☐ |
| `DEC-019` | `legal_entity_id` persistido + snapshot del emisor en cada documento fiscal | Sí, sin excepción | ☐ |
| `DEC-020` | Modelar la separación facturación/liquidación pero **bloquearla** en el MVP | Sí, hasta que haya respaldo fiscal | ☐ |
| Roadmap | Adelantar motor de campaña, retrasar CRM y portal de marca | Sí | ☐ |
| MVP | Recorte a los 9 bloques de `04-ROADMAP.md §3` | Sí | ☐ |
| `DEC-026` | `purpose` de integración como enum cerrado en código, no catálogo editable | Sí | ☐ |
| `DEC-029` | Aislamiento de ambiente como **barrera** (excepción), no como filtro | Sí, sin excepción | ☐ |
| `DEC-031` | Una URL de webhook por conexión, con firma verificada de esa conexión | Sí | ☐ |
| `DEC-032` | `invoicing` y `tax_authority` nunca compartidos entre sociedades (validado, no recomendado) | Sí | ☐ |
| `DEC-036` | Premiar comportamiento verificado, no resultados ni volumen ni seguidores | Sí | ☐ |
| `DEC-038` | Ligas por cohorte con temporadas; sin tabla global de posiciones ni ranking por ingresos | Sí | ☐ |
| `DEC-039` | 🔴 Los retos internos llevan **siempre** recompensa tangible además del XP | Sí, sin excepción | ☐ |
| `DEC-041` | Gamificación y Creator Score separados: mismo origen de eventos, semántica opuesta | Sí | ☐ |
| Alcance | +1 a 1,5 semanas por el addendum multi-entidad y otro tanto por el de integraciones. Gamificación: **coste casi nulo en el MVP**, 6–8 semanas si se construye entera en F14–F15 | Sí | ☐ |

### 2.2 Acciones externas que conviene iniciar **ya** (no dependen de mí)

| Acción | Por qué urge | Responsable sugerido |
|---|---|---|
| 🔴 **Consulta a contador sobre pago a creadores sin RUC** (`DEC-005`) **y sobre pagos a creadores no domiciliados desde CTS Perú** (`Q-13`) | Bloquea toda la Fase 9 y condiciona el formulario de la Fase 5 | Administración |
| **Confirmar la cobertura de facturación inicial** (`Q-14`, `Q-15`): a qué países puede facturar hoy CTS Perú | Dato de configuración para F4.5b y los seeders | Administración |
| **Confirmar el registro de la marca "LATAM Social"** y a nombre de qué sociedad (`Q-18`) | Afecta contratos y licencias de contenido | Legal |
| **Encargar textos legales**: términos de uso, política de privacidad, cesión de derechos de imagen y contenido, consentimiento de datos | No se puede lanzar la landing de creadores sin ellos | Abogado |
| **Definir el dominio definitivo** de LATAM Social y su correo de remitente (`DEC-000`, `Q-12`) | La reputación de entregabilidad se construye con semanas de antelación | Negocio |
| **Contratar proveedor SMTP transaccional** y configurar SPF/DKIM/DMARC | La entregabilidad se construye con semanas de antelación | TI |
| **Abrir cuentas de SMTP, almacenamiento S3 y proveedor de tipo de cambio** (`Q-20`) | Son los tres adaptadores de integración que el MVP usa de verdad, en F4.6b y F4.7 | TI |
| **Reunir la base de creadores existente** en un archivo | Alimenta la importación masiva de F5.6 | Operaciones |

### 2.3 Preguntas cuya respuesta cambia el modelo de datos

Están las dieciocho en `05-DECISION-LOG.md`. Las seis que más me urgen para la Fase 2:

1. **Q-01/Q-02** — ¿Cómo se pagará legalmente a un creador sin RUC? ¿El operador factura la campaña completa o solo su servicio?
2. **Q-05** — ¿Cuál es el plazo de pago prometido al creador y desde qué evento se cuenta?
3. **Q-08/Q-09** — ¿Cuántas rondas de corrección incluye el precio? ¿Cuánto tiempo debe permanecer publicado un post?
4. **Q-10** — ¿Quién asume el costo del producto enviado y su logística?
5. **Q-11** — ¿Existe una base de creadores para importar y en qué formato está?
6. **Q-13/Q-14/Q-15** — ¿Cómo se paga a un creador no domiciliado desde CTS Perú, y a qué países puede facturar hoy la sociedad?

Si alguna no tiene respuesta todavía, no pasa nada: adoptaré la opción recomendada del Decision Log y quedará marcada como provisional. Pero **no la voy a esconder dentro del código**.

---

## 3. Propuesta concreta: Fase 2, Iteración 2.1

En cuanto des el visto bueno, la siguiente entrega será:

### Iteración 2.1 — Entidades y glosario de negocio

**Objetivo.** Identificar y definir todas las entidades del dominio, sin relaciones todavía, verificando que los nueve procesos P1–P9 se pueden recorrer completos sobre ese conjunto.

**Entregables.**
1. **Glosario de negocio** — cada término con una definición inequívoca. Sin esto, "campaña", "cliente", "marca" y "entregable" significan cosas distintas para cada persona y esa ambigüedad termina siempre en el código.
2. **Catálogo de entidades** por dominio (D1–D12), incluidas las del addendum multi-entidad: nombre, propósito, ciclo de vida, volumetría estimada a 1 y 3 años, nivel de criticidad y clasificación de datos.
3. **Entidades ausentes de la especificación original**, incorporadas y justificadas: `ProductShipment`, `PublicationEvidence`, `UsageRight`, `PurchaseOrder`, `MessageThread`, `TaxRegime`, `CancellationPolicy`, y las de los addenda: `PlatformBrand`, `LegalEntity`, `LegalEntityBillingCountry`, `LegalEntityTaxRegistration`, `LegalEntityFiscalConfiguration`, `LegalEntityDocumentSeries`, `LegalEntityBankAccount`, `IntegrationProvider`, `IntegrationConnection`, `IntegrationAssignment`, `IntegrationCredential`, `IntegrationWebhookEvent`.
4. **Distinción explícita** de los pares que suelen confundirse: `PlatformBrand` / `LegalEntity` / `ClientOrganization` / `ClientBrand` (los cuatro conceptos organizacionales, `DEC-016`) · `CreatorApplication` / `Creator` · `Campaign` / `CampaignCreator` · `Deliverable` / `DeliverableVersion` / `Publication` · `CreatorRate` (declarada) / `agreed_amount` (congelada) · país de constitución / países de cobertura / países con registro fiscal.
5. **Matriz entidad × proceso** para demostrar que no falta nada: si un paso de P1–P9 no encuentra dónde escribir, falta una entidad.
6. **Preguntas abiertas** que la iteración 2.2 debe resolver.

**Criterio de salida.** Los nueve procesos se pueden narrar de principio a fin señalando en qué entidad se guarda cada dato, sin decir "eso lo vemos después".

**Lo que NO haré en 2.1.** Nada de columnas, tipos, claves foráneas ni índices — eso es 2.2 y 2.3. Y por supuesto, nada de código.

---

## 4. Ritmo de trabajo propuesto

| Momento | Qué entrego | Qué necesito de ti |
|---|---|---|
| Cada iteración | Los 14 puntos del entregable de cierre (`08-DEFINITION-OF-DONE.md §3`) | Revisión y visto bueno, o correcciones |
| Cada fase | Phase Review Report desde el rol de arquitecto externo | Decisión: continuar / continuar con condiciones / detener |
| Cuando aparezca una decisión de negocio | Entrada nueva en el Decision Log con opciones, recomendación e impacto | Tu decisión, o el permiso para adoptar la recomendación provisionalmente |

**Compromiso explícito:** no avanzo a la siguiente iteración sin que confirmes la anterior, y no escondo decisiones dentro de commits.

---

## 5. Una recomendación de gestión, no técnica

Lo más valioso que puedes hacer en las próximas dos semanas **no es revisar estos documentos línea por línea**. Es:

1. **Sentarte una tarde con quien opera hoy las campañas** y anotar, con cronómetro, cuánto tarda cada tarea repetitiva. Esos números son el mejor criterio de priorización que vamos a tener, y valen más que cualquier opinión mía sobre qué módulo va primero.
2. **Hablar con 3 creadores actuales** y preguntarles qué es lo que más les molesta de trabajar con agencias. La respuesta más frecuente en este sector suele ser "que no me pagan cuando dijeron". Si eso se confirma, el módulo de pagos deja de ser un módulo de finanzas y pasa a ser el principal argumento de retención de la red.
3. **Iniciar la consulta con el contador.** Es lo único que puede bloquear el proyecto sin que ninguno de los dos pueda resolverlo por su cuenta.
