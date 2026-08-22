# 11 — Addendum: marca de plataforma y multi-entidad legal

> Versión 0.1 — 2026-08-21. **Este addendum es parte integral del Master Prompt desde ahora.**
> No sustituye ni reduce nada de lo anterior: añade una capa organizacional que atraviesa el modelo de datos, la configuración, la facturación y los pagos.
>
> **Veredicto en una línea:** es un requerimiento correcto, llega en el momento adecuado (antes de la Fase 2, que es exactamente cuando debía llegar), y su costo es bajo **ahora** y brutal después. Pero introduce una colisión conceptual seria con el modelo previo que hay que resolver antes de dibujar una sola tabla.

---

## 1. El conflicto que hay que resolver primero

El addendum introduce el término **"marca"** para la marca de la plataforma (LATAM Social). El modelo anterior ya usaba **`Brand`** para otra cosa completamente distinta: la marca del cliente sobre la que se hace la campaña (`docs/00 §D`, `BR-CRM-002`, §77 de la spec original).

Y la palabra **"organización"** ya estaba ocupada por `ClientOrganization`, el grupo empresarial cliente.

Si no se corrige ahora, el modelo tendrá cuatro conceptos organizacionales con nombres que se pisan, y en seis meses nadie —ni el equipo, ni tú, ni yo— sabrá si `brand_id` apunta a LATAM Social o a Shampoo ABC. **Esto no es un problema estético: es la fuente número uno de bugs de datos en sistemas multi-entidad.**

### 1.1 Desambiguación obligatoria

| Concepto | Qué es | Ejemplo | Eje | Nombre anterior |
|---|---|---|---|---|
| **PlatformBrand** | La marca comercial con la que se ofrece el servicio al mercado | **LATAM Social** | Identidad de marca | — (nuevo) |
| **LegalEntity** | La sociedad que contrata, factura, cobra y paga | **Soluciones Tecnológicas a Medida S.A.C.**, RUC 20603203896, Perú | Legal y fiscal | — (nuevo) |
| **ClientOrganization** | El grupo empresarial cliente | Grupo ABC | Cliente | igual |
| **ClientBrand** | La marca del cliente sobre la que se hace la campaña | Shampoo ABC | Cliente | `Brand` ⚠️ |

**Renombrados propuestos** (ver `DEC-016`):
- `Brand` / `brand_id` → **`ClientBrand`** / `client_brand_id`
- Prohibido el término `Organization` a secas: siempre `ClientOrganization`

Coste hoy: buscar y reemplazar en documentos que aún no tienen código detrás. Coste en la Fase 9: una migración sobre decenas de tablas más la reeducación de todo el que ya interiorizó el término equivocado.

### 1.2 Los tres ejes son independientes

Este es el punto que hay que tener clarísimo antes de la Fase 2:

```
Eje 1 — IDENTIDAD         platform_brand_id                      "¿bajo qué marca se presenta?"
Eje 2 — RESPONSABILIDAD   legal_entity_id                        "¿qué sociedad responde legalmente?"
Eje 3 — ÁMBITO EXTERNO    client_organization_id / creator_id    "¿qué puede ver este usuario?"
```

Añadir una `LegalEntity` **no** cambia ninguno de los otros dos ejes, y sobre todo **no crea un inquilino**. Confundir el eje 2 con aislamiento llevaría a construir multitenancy real donde solo hace falta configuración — y eso multiplicaría el costo del proyecto sin resolver nada.

> **Actualización del 2026-08-21.** `DEC-002` quedó resuelta: la plataforma la operan solo CTS y sus sociedades, para su propia agencia, así que **no hay inquilinos ni columna `tenant_id`**. El razonamiento de esta sección se mantiene intacto — de hecho se refuerza: sin esa columna, la tentación de modelar CTS Colombia como un inquilino desaparece por completo.

---

## 2. Decisiones anteriores que este addendum modifica

Como pediste, las señalo explícitamente antes de tocarlas:

| Decisión anterior | Qué cambia | Gravedad |
|---|---|---|
| **`DEC-000`** — nombre de trabajo "CTS Creators" | **Queda resuelto y sustituido.** La marca de plataforma es **LATAM Social**. El identificador técnico del proyecto pasa a `latam-social`. La documentación se actualiza en consecuencia. | Resuelta |
| **`DEC-002`** — aislamiento por inquilino | **Resuelta el 2026-08-21 sin multitenancy.** El negocio confirma que no habrá terceros operando sobre esta instalación, así que no existe `tenant_id`. El aislamiento real es el ámbito del usuario externo (`client_organization_id` / `creator_id`). | Resuelta |
| **`DEC-008`** — storage S3-compatible | Sin cambios, pero se añade una consideración: los documentos fiscales de cada entidad legal pueden tener requisitos de retención y residencia de datos distintos por país. Se anota, no se implementa. | Nota |
| **`DEC-014`** — derechos de uso | Sin cambios en el modelo, pero el **licenciante** del contenido es la `LegalEntity` que contrató al creador, no "LATAM Social". Los contratos deben nombrar a la sociedad. | Nota |
| **F4.6 Settings** | Deja de ser una configuración global plana y pasa a ser una **configuración jerárquica en tres niveles**. Es el cambio de alcance más significativo del addendum. Ver §5. | **Alcance** |
| **F9.9 Facturación al cliente** | Toda factura nace de una `LegalEntity`, con su serie, su numeración, sus datos bancarios y su configuración fiscal. | **Alcance** |
| **`BR-FIN-010`** — factura emitida no se modifica | Se refuerza: además de no modificarse, debe conservar **snapshot del emisor**. | Reforzada |
| **`BR-COMM-*`** — comunicaciones | Se añade la distinción entre identidad de marca (operativo) e identidad legal (fiscal). Ver `DEC-023`. | Ampliada |

**Ninguna decisión anterior queda invalidada.** Ninguna tecnología cambia. El roadmap se amplía en aproximadamente **una semana y media**, concentrada en las fases 2 y 4.

---

## 3. Modelo conceptual propuesto

> Esto es un **borrador conceptual** para la Fase 2, no el modelo definitivo. Los nombres finales, tipos, claves e índices se deciden en las iteraciones 2.1–2.10. Como pediste, no creo tablas solo porque aparecen mencionadas: cada una de abajo tiene una responsabilidad distinta y justificada.

### 3.1 Identidad y cobertura

**`platform_brands`** — la cara comercial.
Nombre, slug, logotipo, favicon, dominio, paleta, idioma por defecto, identidad de remitente (nombre y dirección de correo), textos legales asociados, estado.
*MVP: una fila — LATAM Social.*

**`legal_entities`** — la sociedad.
Razón social, nombre comercial, país de constitución (`incorporation_country_id`), tipo y número de identificación fiscal, domicilio fiscal, moneda por defecto, zona horaria, idioma por defecto, representante legal, estado, vigencia.
*MVP: una fila — Soluciones Tecnológicas a Medida S.A.C., Perú, RUC 20603203896.*

**`platform_brand_legal_entities`** — N:M.
Una marca puede estar respaldada por varias sociedades (es exactamente el caso de la evolución que describes), y una sociedad podría respaldar más de una marca si el grupo lanza un segundo producto. Modelarlo como N:M cuesta una tabla; modelarlo como 1:N obliga a migrar el día que aparezca el segundo caso.

**`legal_entity_billing_countries`** — la cobertura. **El corazón del addendum.**
`legal_entity_id`, `country_id`, `priority`, `valid_from`, `valid_to`, `is_default_for_country`, notas.

Dos detalles que no están en el addendum y recomiendo añadir:

1. **Vigencia (`valid_from` / `valid_to`) en lugar de un simple `active`.** Es lo que permite representar con honestidad "CTS Perú facturó Ecuador hasta el 30-06-2028, y desde el 01-07-2028 lo hace CTS Ecuador" sin destruir la historia de la configuración. Con un booleano, al desactivar la fila se pierde la información de cuándo dejó de aplicar, y las auditorías se vuelven imposibles de reconstruir.
2. **`priority`**, para el caso —que el addendum contempla explícitamente— de que dos sociedades cubran el mismo país. Sin prioridad, el sistema tendría que preguntar siempre; con prioridad, propone y deja cambiar.

### 3.2 Configuración fiscal y de facturación

**`legal_entity_tax_registrations`** — registros tributarios.
`legal_entity_id`, `country_id`, tipo de registro (doméstico / no residente / registro de IVA), número, régimen, vigencia.

Necesaria porque **estar registrado fiscalmente en un país no es lo mismo que estar constituido en él ni que poder facturar allí**. Una sociedad peruana puede terminar con un registro de IVA en España sin tener sociedad española. Son tres conceptos distintos que el addendum tiende a juntar y conviene separar desde el modelo.

**`legal_entity_fiscal_configurations`** — reglas de cálculo.
`legal_entity_id`, `country_id` (nulo = configuración por defecto de la entidad), tipo de operación, tratamiento de impuestos, redondeo, leyendas legales obligatorias, vigencia.
*MVP: una configuración —Perú, servicios, IGV— y nada de motor fiscal. Pero la clave compuesta `(entidad, país, tipo de operación)` existe desde el principio, que es lo que permite crecer sin rediseñar.*

**~~`legal_entity_invoice_configurations`~~** — ❌ **descartada.**
Propuse esta tabla y el addendum de integraciones del mismo día la deja obsoleta, con razón: si la configuración de facturación cuelga de la entidad legal, la de la pasarela colgará de otro sitio y la de SMTP de otro, y en un año habrá seis mecanismos para el mismo problema. La configuración del proveedor pasa al **registro de integraciones**, como una conexión con `purpose = invoicing` asignada explícitamente a esta sociedad. Ver `docs/12-ADDENDUM-INTEGRATIONS.md §1`.

**`legal_entity_document_series`** — series y correlativos.
`legal_entity_id`, `country_id`, tipo de documento, serie, número actual, formato, estado.

Tabla propia y no columnas dentro de la configuración, por una razón operativa concreta: **en Perú la numeración por serie debe ser correlativa y sin huecos**, lo que obliga a incrementarla bajo bloqueo y con su propia semántica transaccional. Mezclarla con la configuración general provocaría bloqueos sobre filas que se leen constantemente. Ver `DEC-021`.

**`legal_entity_bank_accounts`** — cobros.
`legal_entity_id`, `currency_id`, banco, número, CCI/IBAN/SWIFT, titular, texto de instrucciones de pago, orden de presentación, `visible_on_invoice`, estado.

**`legal_entity_currencies`** — monedas permitidas y moneda por defecto.

**`legal_entity_contacts`** — contacto de facturación, representante legal, correo de cobranzas.

### 3.3 Dónde aparece `legal_entity_id`

**Obligatorio, no nulo, e inmutable una vez creado el documento:**

`proposals` · `contracts` · `campaigns` (como `billing_legal_entity_id`) · `invoices` · `credit_notes` · `debit_notes` · `payments` · `accounts_receivable` · `creator_earnings` (como `paying_legal_entity_id`) · `payouts` · `payout_batches`

**Opcional / configuración (mutable):**

`client_organizations.default_legal_entity_id` — la sugerencia, no la verdad.

**Snapshot obligatorio en documentos fiscales:**
Además de la clave foránea, todo documento con valor fiscal conserva copia de los datos del emisor vigentes en el momento de la emisión: razón social, identificación fiscal, domicilio, serie, número y datos bancarios impresos. Si mañana la sociedad cambia de domicilio, la factura de ayer debe seguir mostrando el domicilio de ayer. Ver `BR-LE-005`.

### 3.4 Relaciones que el modelo debe permitir — y las que debe prohibir

✅ **Debe permitir**
- Un país atendido por **varias** sociedades.
- Una sociedad que factura **varios** países.
- Una sociedad registrada fiscalmente en un país donde no está constituida.
- Una campaña facturada en una moneda distinta a la moneda por defecto de la sociedad.
- Que la sociedad que factura al cliente **no sea** la que paga al creador (modelado, aunque bloqueado en el MVP — ver `DEC-020`).

❌ **No debe existir jamás**
- `countries.legal_entity_id` — un país no "pertenece" a una sociedad.
- Resolución dinámica de la entidad histórica desde la configuración actual.
- Configuración de facturación electrónica colgando de la marca de plataforma.
- Una factura sin `legal_entity_id`.

---

## 4. Regla de selección de entidad legal

### 4.1 Función (MVP: simple y determinista)

```
entidadesDisponibles(paísCliente, fecha, moneda?) =
    legal_entities
      donde estado = activa
        y vigente en la fecha
        y existe legal_entity_billing_countries fila para paísCliente
            con valid_from <= fecha <= (valid_to o infinito)
        y (moneda es nula o está en legal_entity_currencies)
    ordenadas por priority, luego por is_default_for_country
```

- **Cero resultados** → la conversión de lead a cliente se **bloquea** con un mensaje claro: *"No hay ninguna sociedad del grupo habilitada para facturar clientes de Ecuador. Configúralo en Ajustes → Entidades legales → Cobertura de facturación."* Nunca un fallo silencioso ni un valor por defecto adivinado.
- **Un resultado** → se propone preseleccionado, y se puede cambiar.
- **Varios resultados** → se presentan ordenados por prioridad y **la persona elige**. El sistema no decide solo algo con consecuencias fiscales.

### 4.2 Momentos en que se resuelve

| Momento | Qué pasa |
|---|---|
| Conversión Lead → Cliente | Se calcula el conjunto disponible y se fija `default_legal_entity_id` |
| Creación de campaña | Se propone la del cliente; se puede cambiar; queda **congelada** en la campaña |
| Emisión de factura | Se **hereda de la campaña**, nunca se recalcula; se congela con snapshot del emisor |
| Liquidación al creador | Se propone `paying_legal_entity_id` = entidad de facturación de la campaña |

**El MVP no construye un motor de reglas fiscales.** Es una consulta con filtros y una pantalla de selección. Lo que sí construye es la **forma de los datos** que permitirá que un motor exista más adelante sin rediseñar nada.

---

## 5. Configuración jerárquica: el cambio de alcance real

El módulo de Settings dejaba de ser una lista plana de pares clave-valor. Ahora es una resolución en cascada de tres niveles:

```
Nivel 1 — Plataforma        (global: idiomas soportados, feature flags, storage, límites)
Nivel 2 — Marca             (identidad visual, dominio, remitente, textos legales)
Nivel 3 — Entidad legal     (fiscal, series, bancos, monedas, proveedor de facturación)
Nivel 3b — Entidad × País   (impuestos, leyendas, tipos de documento)

Resolución: el valor más específico que exista gana; si no existe, se hereda del nivel superior.
```

Reglas que evitan que esto se convierta en un laberinto:

1. **Cada ajuste declara en qué nivel vive.** No hay ajustes que puedan definirse en dos niveles a capricho.
2. **La UI muestra siempre de dónde viene el valor efectivo** ("heredado de Plataforma" / "definido en esta entidad"). Sin esto, nadie entiende por qué una factura salió con el dato equivocado.
3. **Los secretos se cifran por entidad** y nunca se heredan: las credenciales del proveedor de facturación de CTS Perú no pueden filtrarse hacia otra sociedad.

Impacto en el roadmap: la iteración F4.6 crece, y aparece una iteración nueva F4.5b. Total estimado: **+1 a 1,5 semanas**, todo en la Ola 0.

---

## 6. Impacto por dominio

| Dominio | Impacto | Gravedad |
|---|---|---|
| **D1 Identity** | Nuevo permiso `legal_entity.manage`, restringido a Superadmin. Permiso de lectura para Finanzas. | Bajo |
| **D2 Platform Core** | Aloja `PlatformBrand`, `LegalEntity` y `legal_entity_billing_countries`. Settings pasa a jerárquico. **Es el dominio más afectado.** | **Alto** |
| **D3 Creator** | El creador se relaciona con una sociedad que le paga, no con "la plataforma". Los términos que acepta nombran a la sociedad. | Medio |
| **D4 CRM** | La conversión Lead → Cliente incorpora la selección de entidad legal, con bloqueo si no hay cobertura. | Medio |
| **D5 Client** | `default_legal_entity_id` en la organización cliente. | Bajo |
| **D6 Campaign** | `billing_legal_entity_id` congelado en la campaña. | Medio |
| **D7 Matching** | Sin impacto. | — |
| **D8 Content** | El licenciante de los derechos de uso es la sociedad, no la marca. Los contratos la nombran. | Bajo |
| **D9 Measurement** | Sin impacto en métricas; los reportes al cliente muestran identidad de **marca**, no de sociedad. | Bajo |
| **D10 Finance** | Facturación, series, impuestos, bancos, cobros, liquidaciones y **rentabilidad por sociedad**. | **Muy alto** |
| **D11 Communication** | Identidad de remitente dual: marca para lo operativo, sociedad para lo fiscal. | Medio |
| **D12 Intelligence** | Sin impacto. | — |

### 6.1 Rentabilidad: una consecuencia que conviene ver ahora

El P&L de campaña (`BR-FIN-007`) deja de ser un único número. Con varias sociedades hay **dos vistas legítimas y distintas**:

- **Margen de campaña** — la operación completa, mire quien mire. Es la que usa Operaciones.
- **Margen por sociedad** — lo que efectivamente queda en cada empresa del grupo. Es la que usa Contabilidad.

Mientras una sola sociedad facture y pague, ambas coinciden. En cuanto no coincidan, divergen — y ahí aparece el problema del punto siguiente.

---

## 7. Advertencia seria: operaciones intercompañía

El §12 del addendum pide separar *quién factura al cliente* de *qué sociedad paga al creador*. **Modelarlo: sí, sin duda. Habilitarlo: todavía no.**

Si CTS Perú factura una campaña y CTS Colombia paga a los creadores colombianos, eso no es un detalle de configuración: es una **operación intercompañía entre dos sociedades de un grupo, en dos jurisdicciones**. Trae consigo, como mínimo:

- Un servicio prestado de una sociedad a otra, que debe documentarse y facturarse entre ellas.
- **Precios de transferencia**: las operaciones entre partes vinculadas deben valorarse a precio de mercado y, según los umbrales de cada país, documentarse formalmente ante la autoridad tributaria.
- Consolidación contable y eliminación de operaciones internas.
- Posible retención en la fuente sobre el pago transfronterizo entre las sociedades.

Nada de esto lo resuelve el software. Lo resuelve un contador, y el software lo refleja.

**Recomendación (`DEC-020`):** el modelo permite que `billing_legal_entity_id ≠ settlement_legal_entity_id`, y **una validación de negocio lo bloquea en el MVP**. Se desbloquea con una decisión explícita del negocio, respaldada por asesoría fiscal, cuando exista la segunda sociedad. Es el equilibrio exacto entre no cerrar la puerta y no dejar que alguien la abra sin darse cuenta un martes por la tarde.

---

## 8. Impacto sobre `DEC-005` (pago a creadores)

El addendum agrava una decisión que ya era bloqueante, y conviene decirlo con claridad:

Hasta ahora la pregunta era *"¿cómo se le paga legalmente a un creador peruano sin RUC?"*. Ahora se añade una segunda: **"¿cómo se le paga a un creador colombiano, mexicano o español desde una sociedad peruana?"**.

Un pago desde Perú a una persona natural no domiciliada por un servicio suele activar **retención sobre renta de fuente peruana pagada a no domiciliados**, con tasas y convenios de doble imposición que varían por país. La consecuencia práctica es que el creador extranjero recibe menos de lo que espera, y eso genera exactamente el tipo de conflicto que destruye la relación con la red.

**Consecuencia para el modelo:** el régimen tributario y los requisitos documentales no dependen solo del país del creador, sino de la **pareja** (país de la sociedad pagadora, país del creador). La tabla de requisitos documentales debe tener esa clave compuesta desde el diseño, aunque en el MVP solo tenga filas para (Perú → Perú).

Nueva pregunta abierta: **Q-13**.

---

## 9. Reglas de negocio nuevas

Se incorporan a `docs/06-BUSINESS-RULES.md` con el prefijo `BR-LE`.

| ID | Regla | Crit. |
|---|---|---|
| **BR-LE-001** | Todo documento comercial o fiscal (propuesta, contrato, campaña, factura, nota, pago, liquidación) almacena explícitamente su `legal_entity_id`. Nunca se deduce de la configuración vigente en el momento de la consulta. | 🔴 |
| **BR-LE-002** | La entidad legal de un documento es inmutable una vez emitido. Corregirla exige anular y reemitir, no editar. | 🔴 |
| **BR-LE-003** | Un cliente solo puede asociarse a entidades legales cuya cobertura de facturación incluya el país del cliente y esté vigente en la fecha de la operación. | 🔴 |
| **BR-LE-004** | Si ninguna entidad legal cubre el país del cliente, la operación se bloquea con un mensaje accionable. Nunca se asigna una entidad por defecto ni se continúa en silencio. | 🟠 |
| **BR-LE-005** | Todo documento fiscal conserva un snapshot de los datos del emisor vigentes al emitirse: razón social, identificación fiscal, domicilio, serie, número y datos bancarios impresos. | 🔴 |
| **BR-LE-006** | Las instrucciones de pago que aparecen en una factura provienen exclusivamente de las cuentas de la entidad legal emisora, en la moneda del documento. | 🔴 |
| **BR-LE-007** | La numeración de documentos es correlativa por (entidad legal, país, tipo de documento, serie) y se asigna bajo bloqueo, sin huecos ni duplicados, incluso bajo concurrencia. | 🔴 |
| **BR-LE-008** | La configuración de facturación electrónica, las credenciales del proveedor y las series pertenecen a la entidad legal. Ninguna se hereda entre entidades ni cuelga de la marca. | 🔴 |
| **BR-LE-009** | En el MVP, la entidad que factura al cliente y la que liquida a los creadores de esa campaña deben ser la misma. Divergir requiere anulación explícita, autorizada y auditada. | 🟠 |
| **BR-LE-010** | Los reportes y comunicaciones operativas se presentan bajo la identidad de la marca de plataforma; los documentos fiscales y los contratos, bajo la identidad de la entidad legal. | 🟠 |
| **BR-LE-011** | Una entidad legal con documentos emitidos no se elimina jamás: se desactiva. Su cobertura de facturación se cierra con `valid_to`, no se borra. | 🔴 |
| **BR-LE-012** | El requisito documental y la retención aplicables a un pago a creador se determinan por la pareja (país de la entidad pagadora, país del creador), no solo por el país del creador. | 🔴 |

---

## 10. Impacto en el roadmap

Cambios mínimos y localizados. **No se reordena nada, no se recorta nada.**

### Fase 2 — Modelo de datos
Iteración nueva **2.6 — Multi-entidad legal, cobertura de facturación y configuración fiscal**, con renumeración de las siguientes. La iteración 2.10 (revisión adversarial) incorpora una comprobación específica: *"¿existe alguna dependencia del tipo país → exactamente una entidad legal? ¿Algún documento sin `legal_entity_id`? ¿Alguna resolución dinámica de la entidad histórica?"*

### Fase 4 — Core técnico
Iteración nueva **4.5b — Marcas de plataforma y entidades legales**: CRUD, cobertura de facturación con vigencia, monedas, cuentas bancarias, contactos, activación. Y **4.6 Settings** se amplía a resolución jerárquica con indicador de herencia.

### Fase 7 — Motor de campaña
**7.0** incorpora la asignación de entidad legal al cliente y el bloqueo por falta de cobertura. **7.1** congela `billing_legal_entity_id` en la campaña.

### Fase 9 — Finanzas
**9.9** pasa a emitir desde la entidad legal, con snapshot del emisor. Iteración nueva **9.12 — Series y numeración correlativa por entidad**. **9.10** añade la vista de margen por sociedad.

### Fase 12 — Integraciones
**12.2** configura el proveedor de facturación **por entidad legal y país**, no globalmente.

### Fase 15 — Internacionalización
Iteración nueva **15.8 — Alta de una nueva entidad legal**: lista de verificación operativa (constitución, registro fiscal, proveedor de facturación, series, bancos, textos legales, migración de cobertura) para que incorporar CTS Colombia sea un procedimiento y no un proyecto.

**Coste total estimado: +1 a 1,5 semanas**, concentradas en la Ola 0. El MVP sigue siendo el mismo: **una marca, una entidad legal (CTS Perú) con varios países en su cobertura de facturación.** La capacidad multi-entidad se construye como configuración; simplemente no se usa todavía con una segunda sociedad.

---

## 11. Lo que este addendum acierta

Vale la pena señalarlo, porque es un requerimiento inusualmente bien planteado:

- **Separar marca de sociedad** es correcto y casi nadie lo hace a tiempo. Se descubre el día que se abre la segunda empresa, y ese día ya es tarde.
- **N:M entre entidad legal y países** en lugar de 1:1 es exactamente la relación correcta, y el addendum lo argumenta mejor de lo que suele argumentarse.
- **Insistir en el histórico inmutable** (§14) es el punto más importante de todo el texto. Resolver la entidad histórica desde la configuración actual es un error silencioso: no rompe nada, no da error, y falsifica todas las facturas antiguas el día que cambia la configuración.
- **Distinguir país de constitución de países atendidos** evita el error más común de los modelos multi-entidad.
- **"No construyas todavía un motor fiscal complejo, pero permite evolucionar hacia él"** es la dosis correcta de anticipación. Es justo la línea que separa preparar el terreno de sobrearquitectar.

---

## 12. Preguntas nuevas para el negocio

| # | Pregunta | Bloquea |
|---|---|---|
| **Q-13** | ¿Cómo se paga a un creador **no domiciliado** desde CTS Perú? ¿Qué retención aplica y qué documento se le exige? | F9 completa |
| **Q-14** | ¿CTS Perú puede facturar hoy legalmente a clientes de todos los países que se contemplan, o hay restricciones por país? | F4.5b (datos de configuración) |
| **Q-15** | ¿Qué países debe cubrir CTS Perú al arrancar? Es un dato de configuración, no de desarrollo, pero hace falta para los seeders. | F4.5b |
| **Q-16** | ¿Existe ya un proveedor de facturación electrónica contratado, y soporta facturar a clientes del exterior (exportación de servicios)? | F12.2 |
| **Q-17** | Si en el futuro se constituye una segunda sociedad, ¿se migran los clientes existentes a ella o se quedan con la sociedad original? | Diseño de la migración de cobertura |
| **Q-18** | ¿La marca LATAM Social es marca registrada, y a nombre de qué sociedad? Afecta a los contratos y a quién licencia los derechos de uso. | Textos legales |
