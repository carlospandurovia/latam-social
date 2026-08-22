# 02 — Procesos end-to-end y máquinas de estado

> Versión 0.1 — 2026-08-21.
> **Principio rector:** los estados no son strings sueltos. Cada máquina de estados vive en un solo lugar (enum + tabla de transiciones permitidas + guardas de permiso), y toda transición produce un registro de auditoría inmutable. Ver `BR-SEC-003`.

---

## P1 — Adquisición y activación del creador

```
Landing → Aplicación → Revisión → Aprobación → Cuenta → Onboarding → Disponible
```

**Flujo detallado**

1. El postulante llega a la landing (orgánico, referido, campaña paga, importación, contacto directo).
2. Completa el formulario multi-paso. Se guarda como `draft` desde el paso 1 (**no perder aplicaciones a medio llenar es dinero real**).
3. Al enviar: `submitted`. Se dispara evento `CreatorApplicationSubmitted` → email de acuse + tarea en la cola de revisión.
4. El sistema ejecuta chequeos automáticos: duplicados (email/documento/@usuario), blacklist, edad mínima, campos mínimos, coherencia básica de métricas (ver `BR-CREATOR-004`).
5. Creator Manager revisa: perfil, redes, evidencias, señales de alerta.
6. Decide: **aprobar** / **rechazar** (con motivo tipificado) / **solicitar información** (con mensaje).
7. Al aprobar: se crea `Creator` + `User` + se emite **enlace de establecimiento de contraseña de un solo uso con expiración** (nunca contraseña en claro por correo — `BR-SEC-004`).
8. Email de bienvenida; el creador completa datos faltantes (fiscal, bancario, formatos, tarifas).
9. El creador pasa a `active` solo cuando alcanza el **mínimo de completitud operativa** (ver `BR-CREATOR-006`). Antes de eso no debe aparecer en el matching: invitar a alguien a quien no se le puede pagar es un fallo de proceso, no de dato.

**Máquina de estados — Creator**

| Estado | Puede pasar a | Acceso al portal | Elegible para campaña |
|---|---|---|---|
| `draft` | `submitted`, `abandoned` | No | No |
| `submitted` | `under_review`, `rejected` | No | No |
| `under_review` | `info_requested`, `approved`, `rejected` | No | No |
| `info_requested` | `under_review`, `rejected`, `abandoned` | No | No |
| `approved` | `active`, `suspended` | Sí | No (falta completitud) |
| `active` | `paused`, `suspended`, `inactive`, `blacklisted` | Sí | **Sí** |
| `paused` (a pedido del creador) | `active`, `inactive` | Sí | No |
| `suspended` (decisión interna) | `active`, `blacklisted`, `inactive` | Solo lectura | No |
| `inactive` (sin actividad prolongada) | `active` | Sí | No |
| `rejected` | `submitted` (nueva aplicación tras N días) | No | No |
| `blacklisted` | — (solo Superadmin revierte) | No | No |

> **Crítica:** la spec original mezcla `Draft/Submitted/...` de la *aplicación* con estados del *creador*. Son dos ciclos de vida distintos: una `CreatorApplication` puede ser rechazada y volver a presentarse; el `Creator` es la entidad duradera. Separarlos evita corromper el histórico. Ver `DEC-006`.

---

## P2 — Adquisición comercial (lead → cliente)

```
Lead → Contacto → Calificación → Discovery → Propuesta → Negociación → Ganado → Cliente
```

1. Formulario B2B / WhatsApp / referido / outbound crea `Lead` con origen, UTM, landing, consentimiento y timestamp.
2. Asignación automática a un `Sales` (round-robin o por país). SLA de primer contacto: **objetivo < 4 h hábiles** (medible desde el día 1: es un KPI, no un adorno).
3. Calificación con criterios explícitos (presupuesto, autoridad, necesidad, plazo). Los descalificados se marcan con motivo tipificado — esa taxonomía es la que después dice qué landing arreglar.
4. Discovery → propuesta (fuera de la plataforma en el MVP; módulo de propuestas en F10).
5. Al ganar: **conversión explícita** `Lead → ClientOrganization (+ Brand + Contact primario)`, conservando `lead_id` de origen. No se borra el lead ni se duplica su historia.
6. Se completan datos fiscales según el país (catálogo `country_tax_id_types`, nunca campos hardcodeados).
7. **Se determina la entidad legal responsable**: el sistema consulta qué sociedades del grupo tienen cobertura de facturación vigente para el país del cliente. Cero resultados → la conversión se bloquea con un mensaje accionable; una → se propone; varias → la persona elige. Nunca se asigna por defecto ni se adivina. Ver `BR-LE-003`, `BR-LE-004`.
8. Opcional: invitación al portal por enlace seguro.

---

## P3 — Ciclo de vida de la campaña (proceso núcleo)

```
Draft → Revisión interna → Aprobación cliente → Reclutamiento → Selección → Invitaciones →
Confirmado → Producción → Revisión → Aprobado → Programado → Publicado → Medición → Cerrado
```

**Máquina de estados — Campaign**

| Estado | Significado | Transiciones válidas | Quién puede |
|---|---|---|---|
| `draft` | En construcción interna | `internal_review`, `cancelled` | Campaign Mgr |
| `internal_review` | Revisión de brief, presupuesto y margen | `draft`, `client_review`, `cancelled` | Ops Mgr |
| `client_review` | Cliente aprueba brief y presupuesto | `draft`, `approved`, `cancelled` | Cliente |
| `approved` | Aprobada y presupuestada; se puede comprometer costo | `recruiting`, `cancelled` | Ops Mgr |
| `recruiting` | Buscando y preseleccionando creadores | `creator_selection`, `on_hold`, `cancelled` | Campaign Mgr |
| `creator_selection` | Shortlist definida | `invitations_sent`, `recruiting` | Campaign Mgr |
| `invitations_sent` | Invitaciones enviadas, esperando respuestas | `confirmed`, `recruiting` (si faltan cupos) | Sistema |
| `confirmed` | Cupos cubiertos, creadores confirmados | `in_production`, `cancelled` | Campaign Mgr |
| `in_production` | Producción de contenido en curso | `content_review` | Sistema |
| `content_review` | Revisión interna y/o de cliente | `in_production` (correcciones), `content_approved` | Reviewer |
| `content_approved` | Todo el contenido aprobado | `scheduled`, `live` | Campaign Mgr |
| `scheduled` | Con fechas de publicación fijadas | `live` | Sistema |
| `live` | Publicaciones activas | `measuring` | Sistema |
| `measuring` | Ventana de captura de métricas | `completed` | Sistema |
| `completed` | Reporte entregado, liquidaciones generadas | `archived` | Finance |
| `on_hold` | Pausada por el cliente o internamente | estado anterior, `cancelled` | Ops Mgr |
| `cancelled` | Cancelada | `archived` | Ops Mgr |
| `archived` | Solo lectura | — | — |

> **Crítica importante:** la campaña y **la participación de cada creador en ella** tienen ciclos de vida distintos y paralelos. Una campaña puede estar `live` mientras el creador #23 sigue en `content_review`. Modelar un solo estado global es el error más común y más caro en este tipo de plataformas. Por eso `CampaignCreator` tiene su propia máquina de estados. Ver `DEC-010`.

**Máquina de estados — CampaignCreator (participación)**

`candidate → shortlisted → invited → accepted | declined | expired → contracted → briefed → [shipping] → producing → submitted → in_review → changes_requested → approved → scheduled → published → verified → completed | dropped | replaced`

Cada transición registra actor, timestamp, motivo y comentario.

---

## P4 — Workflow de contenido (con evidencia)

```
Upload V1 → Revisión interna → [Correcciones → V2 …] → Aprobación interna →
Revisión cliente → [Correcciones → Vn] → Aprobado → Programado → Publicado →
Verificación de publicación → Archivado de evidencia → Métricas
```

Reglas duras:
- **Nunca se sobrescribe una versión.** `deliverable_versions` es append-only; la versión "actual" es un puntero.
- Cada revisión registra: revisor, rol, fecha, decisión, comentarios, y a qué versión aplica.
- Se distingue **corrección interna** (no visible para el cliente) de **corrección del cliente** (cuenta contra el límite contractual de rondas — ver `BR-CONTENT-003`).
- **Verificación de publicación (añadido respecto a la spec):** al declararse publicado, el sistema debe conservar **evidencia inmutable** del post en vivo (captura de pantalla y/o copia del archivo). Los posts se borran, las cuentas se cierran, y la evidencia que se le entregó al cliente debe sobrevivir. Ver crítica §F-03.
- **Ventana de permanencia:** si el contrato exige que el post permanezca N días, debe existir un chequeo programado y una alerta al despublicarse. Ver `BR-CONTENT-006`.

---

## P5 — Producción UGC (sin publicación)

Distinto de P4 en puntos que afectan el modelo de datos:

| Aspecto | Campaña de distribución | Campaña UGC |
|---|---|---|
| ¿Publica en su red? | Sí | No (o solo opcionalmente) |
| Entregable | Post publicado + insights | Archivos maestros (raw + editado) |
| Métrica de éxito | Reach/engagement | Piezas entregadas y aceptadas |
| Derechos | Licencia de uso del post | **Cesión amplia**, a menudo perpetua, para paid media |
| Precio | Refleja audiencia | Refleja producción, no audiencia |
| Almacenamiento | Ligero | **Pesado** (video 4K, raws) → impacta costos de storage |

> Consecuencia arquitectónica: `Campaign.type ∈ {distribution, ugc, hybrid}` desde el inicio, y el modelo de derechos (`UsageRight`) debe ser una entidad propia con alcance, territorio, canales y vigencia — no un booleano.

---

## P6 — Liquidación y pago al creador

```
Devengo (earning estimado) → Confirmación de cumplimiento → Aprobación → Payable →
Lote de pago → Ejecución → Comprobante → Pagado
```

1. Al confirmarse la participación se crea un asiento `earning` en estado `estimated` con el monto negociado. **No es un pasivo aún.**
2. Al verificarse la publicación / aceptarse el entregable, pasa a `pending`.
3. Finance aprueba → `approved`. Se aplican ajustes, retenciones y bonos como **asientos separados**, nunca modificando el asiento original.
4. Se verifica requisito documental según el país y régimen del creador (`DEC-005`): recibo por honorarios, factura, o comprobante equivalente.
5. `payable` → se incluye en un **lote de pago** (`payout_batch`) → `scheduled`.
6. Ejecución (archivo de pago masivo del banco / transferencias / billetera) → `paid`, con referencia bancaria y fecha valor.
7. Notificación al creador con detalle y comprobante descargable.

Reglas duras:
- El "saldo" del creador **no es una columna**, es `SUM(ledger)` filtrado por estado. Ver `BR-FIN-001`.
- Un asiento nunca se edita ni se borra: se contrarresta con un `reversal`. Ver `BR-FIN-002`.
- Cambio de cuenta bancaria → **período de enfriamiento + verificación** antes de habilitarla para un pago. Es el vector de fraude más obvio del sistema. Ver `BR-FIN-006` y crítica §F-06.

---

## P7 — Facturación y cobro al cliente

```
Campaña aprobada → Documento de compromiso (OC/contrato) → Hito de facturación →
Factura (registro) → Emisión electrónica (PSE) → Envío → Seguimiento CxC → Cobro → Conciliación
```

- **La entidad legal emisora se hereda de la campaña, nunca se recalcula.** El documento persiste `legal_entity_id` y un snapshot de los datos del emisor (razón social, identificación fiscal, domicilio, serie, número, datos bancarios impresos). Si mañana la configuración de cobertura cambia, la factura de ayer sigue diciendo lo que decía ayer. Ver `BR-LE-001`, `BR-LE-005`.
- El número sale de la serie de esa entidad legal, asignado bajo bloqueo para garantizar correlatividad sin huecos (`BR-LE-007`).
- Las instrucciones de pago impresas provienen exclusivamente de las cuentas de la entidad emisora, en la moneda del documento (`BR-LE-006`).
- Los hitos son configurables por campaña: 100% anticipado / 50-50 / contra entrega / mensual.
- **Añadido respecto a la spec:** muchas marcas medianas y grandes en LATAM exigen **Orden de Compra (OC)** antes de facturar. Sin registrarla, Finance no puede cobrar. Ver crítica §F-04.
- La emisión electrónica se delega a un PSE detrás de `ElectronicInvoiceProviderInterface`. El core registra el documento; el proveedor lo emite y devuelve CDR/estado. El fallo del PSE **no debe** impedir registrar la operación.

---

## P8 — Medición y reporte

```
Publicación verificada → Captura de métricas (T+24h, T+7d, T+30d) → Consolidación →
Reporte de campaña → Entrega al cliente → Cierre
```

- Las métricas se guardan como **snapshots con timestamp y fuente** (`manual`, `screenshot_ocr`, `api_meta`, `api_tiktok`, `provider_x`), nunca como valor único mutable.
- La captura en el MVP es **manual/asistida**: el creador sube la captura de insights y declara los números; el operador valida. Es imperfecto y hay que asumirlo explícitamente.
- El reporte al cliente debe ser **reproducible**: dos generaciones del mismo reporte con la misma fecha de corte deben dar el mismo resultado. Esto exige guardar el snapshot usado, no recalcular contra "lo último".

---

## P9 — Logística de producto (proceso ausente en la especificación original)

Casi toda campaña de producto requiere **enviar producto físico al creador**. Sin esto en el sistema, vuelve a Excel de inmediato.

```
Campaña con producto → Confirmación del creador → Recolección de dirección de envío →
Generación de lista de envíos → Despacho (tracking) → Recepción confirmada por el creador →
Habilitación de producción
```

Implicaciones:
- La **dirección de envío** es un dato distinto de la dirección fiscal y es **dato personal sensible** con retención limitada.
- Un creador no puede pasar a `producing` si el producto no fue recibido → evita reclamos de incumplimiento injustos.
- Se necesita registrar costo del producto enviado: **es Direct Cost** y afecta el margen de la campaña.

---

## Eventos de dominio (contrato de integración interna)

Estos eventos son el pegamento entre módulos. Definirlos ahora evita acoplamiento después.

| Evento | Emisor | Consumidores típicos |
|---|---|---|
| `CreatorApplicationSubmitted` | D3 | D11 (email), D2 (auditoría) |
| `CreatorApproved` | D3 | D1 (crear usuario), D11 (bienvenida), D12 |
| `CreatorProfileCompleted` | D3 | D7 (elegibilidad) |
| `LeadCreated` | D4 | D11, asignación |
| `LeadConverted` | D4 | D5 |
| `CampaignApproved` | D6 | D7, D10 (presupuesto) |
| `CreatorInvited` / `InvitationAccepted` / `InvitationDeclined` | D6 | D11, D10 (earning estimado) |
| `DeliverableSubmitted` | D8 | D11, cola de revisión |
| `ContentApproved` | D8 | D6, D11 |
| `PublicationVerified` | D8 | D9 (métricas), D10 (earning → pending) |
| `MetricsCaptured` | D9 | D12 |
| `EarningApproved` / `PayoutPaid` | D10 | D11, D12 |
| `CampaignCompleted` | D6 | D9 (reporte), D10 (cierre), D12 (evaluación) |
