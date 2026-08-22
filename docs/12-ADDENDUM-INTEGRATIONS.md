# 12 — Addendum: integraciones y APIs por entidad legal

> Versión 0.1 — 2026-08-21. **Parte integral del Master Prompt desde ahora.**
> Continúa y completa `docs/11-ADDENDUM-LEGAL-ENTITIES.md`. No sustituye nada anterior.
>
> **Veredicto en una línea:** el principio de diseño que cierra el addendum — *"¿qué integración corresponde a esta operación?"* en lugar de *"¿cuál es la API configurada?"* — es correcto y hay que adoptarlo tal cual. Pero el modelo, tal como está descrito, **resuelve bien el caso feliz y deja abiertos cuatro agujeros** que solo se descubren en producción: ambientes cruzados, enrutado de webhooks, empates de resolución y conexiones faltantes detectadas demasiado tarde. Este documento los cierra.

---

## 1. Corrección de algo que yo mismo propuse mal

En `docs/11 §3.2` propuse una tabla `legal_entity_invoice_configurations` colgando de la entidad legal. **Ese diseño queda superado por este addendum y hay que descartarlo.**

El motivo es que era un caso particular resuelto de forma particular: si la configuración de facturación electrónica cuelga de la entidad legal, la de la pasarela de pago colgará de otro sitio, la de SMTP de otro, y en un año habrá seis mecanismos distintos para el mismo problema. El addendum propone la generalización correcta: **un único registro de integraciones con alcance configurable**.

Lo que sobrevive de `docs/11`: `legal_entity_tax_registrations`, `legal_entity_fiscal_configurations`, `legal_entity_document_series` y `legal_entity_bank_accounts` **siguen siendo tablas propias**, porque son datos fiscales y financieros de la sociedad, no configuraciones de un proveedor externo. La serie de facturación es un hecho legal de la empresa; el proveedor que la transmite a SUNAT es una integración. No son lo mismo y no deben vivir juntos.

| En `docs/11` | Estado |
|---|---|
| `legal_entity_invoice_configurations` | ❌ **Descartada.** Pasa a ser una conexión con `purpose = invoicing` + asignación |
| `legal_entity_tax_registrations` | ✅ Se mantiene (dato fiscal de la sociedad) |
| `legal_entity_fiscal_configurations` | ✅ Se mantiene (reglas de cálculo) |
| `legal_entity_document_series` | ✅ Se mantiene (correlativo legal) |
| `legal_entity_bank_accounts` | ✅ Se mantiene (dato bancario, no integración) |
| `BR-LE-008` | 🔄 Reformulada — ver §8 |

---

## 2. Integraciones no son Settings

Antes de modelar: `DEC-018` ya estableció una configuración jerárquica en cascada (plataforma → marca → entidad → entidad×país). La tentación evidente es meter las integraciones ahí dentro. **Sería un error.**

| | Settings | Integraciones |
|---|---|---|
| Qué resuelve | Un **valor** (`timezone`, `payment_terms`) | Una **conexión viva** a un sistema externo |
| Tiene estado | No | Sí: activa, verificada, caída, credencial expirada |
| Tiene ciclo de vida | No | Sí: crear, verificar, rotar, desactivar, reemplazar |
| Falla | No puede fallar | Falla constantemente, y hay que saberlo |
| Ejes de alcance | 4 | 6 (añade `purpose` y `environment`) |

Son dos subsistemas distintos con dos resolvers distintos. Lo que **sí** comparten, y hay que unificar deliberadamente, es el **vocabulario de alcance**: los mismos nombres de nivel (`platform`, `brand`, `legal_entity`, `country`) significan lo mismo en ambos. Si en uno se llama "global" y en otro "platform", alguien se equivocará. Ver `DEC-024`.

---

## 3. Modelo conceptual

> Borrador para la Fase 2. Nombres definitivos en las iteraciones 2.1–2.11.

### 3.1 Las tres entidades del núcleo

**`integration_providers`** — *qué sabe hacer el sistema.*
Código del proveedor (`culqi`, `stripe`, `nubefact`, `ses`, `s3`, `sunat_exchange`, `whatsapp_cloud`), nombre, propósitos que soporta, esquema de las credenciales que necesita, si admite sandbox, si es **compartible entre entidades**, adaptador que lo implementa, estado.

Punto importante: esta tabla es **catálogo respaldado por código**, no datos que el usuario crea libremente. Un proveedor solo existe si hay un adaptador que lo implementa. Añadir una fila sin adaptador produce una conexión que nadie sabe ejecutar.

**`integration_connections`** — *una configuración concreta y viva.*
`provider_id`, nombre legible (*"Culqi Producción CTS Perú"*), `environment` (`sandbox` | `production`), endpoints, referencia a credenciales, `webhook_secret`, estado, `last_verified_at`, `last_success_at`, `last_error_at`, `last_error_message`, metadatos.

**`integration_assignments`** — *dónde aplica.*
`connection_id`, `purpose`, y los ejes opcionales `platform_brand_id`, `legal_entity_id`, `country_id`, más `priority`, `valid_from`, `valid_to`, estado.

Un `NULL` en un eje significa **"cualquiera"**, y es exactamente lo que hace que una misma conexión pueda servir a una entidad, a varias o a todas sin tablas distintas. `SMTP_LATAMSOCIAL` es una fila con `legal_entity_id = NULL` y `country_id = NULL`.

> **Vigencia, igual que en la cobertura de facturación (`DEC-017`).** Cuando CTS Perú cambie de proveedor de facturación, la asignación antigua se cierra con `valid_to`; no se borra. Sin eso es imposible responder "¿qué proveedor emitió la factura F001-00347?" dos años después.

### 3.2 Credenciales

**`integration_credentials`** — separada de la conexión, a propósito.

- **Cifrado sobre (envelope encryption):** cada credencial tiene su propia clave de datos, cifrada a su vez con una clave maestra que **vive fuera de la base de datos** (variable de entorno o gestor de secretos). Rotar la clave maestra recifra las claves de datos, no las miles de filas. Ver `DEC-030`.
- **Versionado:** una rotación crea una versión nueva y desactiva la anterior; no la sobrescribe. Permite volver atrás si la credencial nueva es incorrecta y auditar cuándo cambió.
- **Solo escritura desde la interfaz:** una vez guardado, un secreto no se puede volver a leer, solo reemplazar. En pantalla se muestran los últimos 4 caracteres y nada más.
- **Toda lectura queda auditada** (`BR-PRIV-004`).

### 3.3 Webhooks

**`integration_webhook_events`** — `connection_id`, `provider_event_id`, tipo, payload crudo, cabeceras relevantes, resultado de la verificación de firma, estado de procesamiento, intentos, error, timestamps.

Índice único sobre `(connection_id, provider_event_id)`: esa es la idempotencia real. No basta con `provider_event_id`, porque dos conexiones del mismo proveedor pueden emitir identificadores que colisionen.

### 3.4 Trazabilidad

**`integration_call_logs`** — llamadas salientes: conexión, operación, duración, código de respuesta, éxito/fallo, `request_id`. **Sin cuerpos completos por defecto** y **jamás con secretos**. Retención corta y agregados largos.

---

## 4. El resolver

### 4.1 La pregunta

```
resolver.for(purpose, legalEntity?, country?, brand?, environment) → IntegrationConnection
```

Ningún controlador, job o servicio instancia un cliente de proveedor directamente. Todos preguntan al resolver. Esto es lo que hace realidad el principio del addendum y lo que evita el acoplamiento a proveedores concretos.

### 4.2 Resolución por especificidad, no por escalera fija

El addendum propone una escalera de prioridad de cuatro peldaños. Funciona, pero se rompe al añadir el eje de marca y no dice qué hacer ante un empate. Propongo generalizarla (`DEC-027`):

Cada asignación candidata puntúa por los ejes que **coinciden explícitamente** (un `NULL` no puntúa):

| Eje coincidente | Peso |
|---|---|
| `legal_entity_id` | 8 |
| `country_id` | 4 |
| `platform_brand_id` | 2 |
| `priority` manual | desempate secundario |

Gana la puntuación más alta. La escalera del addendum sale sola como caso particular:

```
Entidad + País + Propósito   → 12   (la más específica)
Entidad + Propósito          →  8
País + Propósito             →  4
Global + Propósito           →  0
```

**La parte que importa: los empates no se resuelven en tiempo de ejecución, se prohíben al guardar.** Si dos asignaciones activas producen la misma puntuación para la misma combinación, la interfaz **rechaza el guardado** y explica cuál es el conflicto. Así el resolver es siempre determinista y nadie descubre un empate el día que emite una factura.

### 4.3 El resolver explica su decisión

Devuelve la conexión **y el motivo**: *"resuelta por entidad legal + país + propósito, asignación #47"*. Ese motivo se registra en el log de la operación y se muestra en la interfaz.

Sin esto, la pregunta "¿por qué esta factura salió por el proveedor equivocado?" solo se puede responder haciendo arqueología sobre la configuración actual — que ya no es la de entonces. Es el mismo razonamiento de `DEC-019`, aplicado a integraciones.

### 4.4 Cero resultados: fallar pronto, no tarde

Que no exista conexión para (CTS Perú, Ecuador, `invoicing`) es un problema de configuración. Descubrirlo **al pulsar "emitir factura"**, con el cliente esperando, es un problema de diseño.

**Propuesta (`DEC-028`): matriz de propósitos obligatorios.** Cada país declara qué propósitos son imprescindibles para operar en él (en Perú: `invoicing` y `exchange_rate`). Entonces:

- Una entidad legal **no puede activarse** para un país si le falta algún propósito obligatorio.
- El panel de entidades muestra el estado de cobertura de integraciones por país, en verde o rojo, **antes** de que haga falta.
- Una comprobación programada revisa a diario que todas las conexiones activas siguen verificándose, y avisa antes de que una credencial expirada rompa una operación.

### 4.5 Aislamiento de ambiente: la protección más importante de todo el documento

El fallo más caro que este diseño puede producir no es una integración mal configurada: es **una conexión de producción resolviéndose en un ambiente que no es producción**. Consecuencias reales: emitir comprobantes fiscales de verdad desde QA, cobrar tarjetas de verdad en una demo, o enviar correos de verdad a 150 creadores desde staging.

Reglas duras (`DEC-029`):

1. El resolver **rechaza** devolver una conexión de `production` cuando la aplicación no corre en producción, y rechaza una de `sandbox` cuando sí corre en producción. No es un filtro: es una excepción.
2. La anulación existe para pruebas de humo, requiere permiso explícito, es temporal y queda auditada.
3. En ambientes no productivos, el correo saliente pasa siempre por un capturador, **independientemente de lo que diga la configuración**. La defensa no puede depender de que alguien haya configurado bien.
4. Las conexiones de producción se marcan visualmente en la interfaz y su edición pide confirmación adicional.

---

## 5. Enrutado de webhooks: el agujero que el addendum no ve

El addendum enumera correctamente qué registrar de un webhook, pero omite la pregunta previa: **cuando llega un webhook, ¿de qué conexión es?**

Con una sola conexión por proveedor es trivial. Con `Culqi_PE_Producción`, `Culqi_PE_Sandbox` y `Stripe_CO_Producción` conviviendo, el payload entrante muchas veces **no identifica la entidad legal**, y adivinarlo probando firmas contra todos los secretos es lento e inseguro.

**Solución (`DEC-031`): una URL de webhook por conexión.**

```
POST /webhooks/{connection_uuid}
```

- El enrutado se resuelve por URL, no por inspección del contenido.
- La firma se verifica con el secreto **de esa conexión**, una sola vez.
- Si la firma falla → 401, se registra y **no se procesa**. Nunca se procesa un webhook sin firma válida.
- El identificador es un ULID no adivinable, y rotarlo es cambiar una URL en el panel del proveedor.
- Cada conexión muestra su URL para copiar y pegar en el proveedor.

Además: el webhook se **acusa de inmediato** (2xx) y se procesa en cola. Un proveedor que no recibe respuesta en segundos reintenta y multiplica los eventos.

---

## 6. Compartir conexiones: sí, pero no en todo

El addendum plantea que una conexión pueda servir a varias entidades legales. Correcto para unas cosas y peligroso para otras:

| Propósito | ¿Compartible? | Por qué |
|---|---|---|
| `file_storage` | ✅ Sí | Un bucket sirve a todo el grupo |
| `email_transactional` / `email_marketing` | ✅ Sí | El remitente es la marca, no la sociedad |
| `exchange_rate` | ✅ Sí | El tipo de cambio no depende de quién pregunte |
| `analytics` | ✅ Sí | — |
| `social_verification` | ✅ Sí | La cuenta del creador no depende de la sociedad |
| `whatsapp` | ⚠️ Según el caso | El número emisor puede ser de marca o de sociedad |
| `payment_collection` | ❌ **No** | El dinero cae en la cuenta del titular del comercio |
| `creator_payment` | ❌ **No** | Ídem, en sentido contrario |
| `invoicing` | ❌ **Nunca** | Las credenciales pertenecen al contribuyente; una sociedad no puede emitir con el certificado de otra |
| `tax_authority` | ❌ **Nunca** | Ídem |

**Propuesta:** el proveedor declara `sharable` a nivel de **propósito**, y el sistema **impide** guardar una asignación que comparta un propósito no compartible entre entidades legales. No es una recomendación en un manual que nadie lee: es una validación. Ver `DEC-032`.

Esto reformula `BR-LE-008` sin contradecirlo: lo que aquella regla prohibía era la **herencia implícita** entre entidades. Compartir por **asignación explícita** es distinto — y aun así, para facturación y autoridad tributaria, sigue prohibido.

---

## 7. Qué conexión emitió cada documento

Paralelo exacto de `DEC-019`: los documentos guardan `legal_entity_id` porque la configuración cambia. Por la misma razón, los documentos emitidos a través de un proveedor externo deben guardar **`integration_connection_id`**.

Aplica a: facturas y notas emitidas electrónicamente, cobros procesados por pasarela, pagos a creadores ejecutados por un proveedor, y correos enviados (que ya se registran en el Email Log, al que se añade la conexión).

Sin esto, cuando dentro de un año haya que consultar el estado de un comprobante ante el proveedor antiguo, no habrá forma de saber a cuál preguntarle. Ver `DEC-033`.

---

## 8. Reglas de negocio nuevas

| ID | Regla | Crit. |
|---|---|---|
| **BR-INT-001** | Ninguna parte del sistema instancia un cliente de proveedor externo directamente. Toda operación obtiene su conexión del resolver. | 🔴 |
| **BR-INT-002** | La resolución es determinista: gana la asignación vigente de mayor especificidad. Dos asignaciones activas con la misma especificidad para la misma combinación son un error de configuración y se rechazan **al guardar**, no en ejecución. | 🔴 |
| **BR-INT-003** | El resolver devuelve la conexión y el motivo de la resolución, y ambos se registran en la operación. | 🟠 |
| **BR-INT-004** | El resolver nunca devuelve una conexión de un ambiente distinto al de ejecución. La anulación es temporal, permisionada y auditada. | 🔴 |
| **BR-INT-005** | En ambientes no productivos el correo saliente pasa siempre por un capturador, con independencia de la configuración. | 🔴 |
| **BR-INT-006** | Una conexión no puede activarse sin una verificación exitosa (`test connection`). Se registra `last_verified_at`. | 🟠 |
| **BR-INT-007** | Una entidad legal no puede activarse para un país si no tiene cubiertos los propósitos declarados obligatorios para ese país. | 🔴 |
| **BR-INT-008** | Los secretos se cifran con clave maestra externa a la base de datos, se versionan al rotar, no se muestran completos tras guardarse y **nunca aparecen en logs, trazas de error ni exportaciones**. | 🔴 |
| **BR-INT-009** | Un webhook llega a la URL propia de su conexión, se verifica su firma con el secreto de esa conexión y se rechaza si la firma no es válida. Un webhook sin firma válida no se procesa jamás. | 🔴 |
| **BR-INT-010** | Los webhooks son idempotentes por `(conexión, identificador de evento del proveedor)`. Se acusan de inmediato y se procesan en cola. | 🔴 |
| **BR-INT-011** | Los propósitos marcados como no compartibles (`invoicing`, `tax_authority`, `payment_collection`, `creator_payment`) no admiten asignaciones que abarquen más de una entidad legal. | 🔴 |
| **BR-INT-012** | Todo documento emitido a través de un proveedor externo registra la conexión que lo emitió, y esa referencia es inmutable. | 🔴 |
| **BR-INT-013** | Una conexión con documentos o eventos asociados no se elimina: se desactiva, y sus asignaciones se cierran con `valid_to`. | 🔴 |
| **BR-INT-014** | Los límites de tasa y el cortocircuito ante fallos se aplican **por conexión**, no por proveedor: la cuota pertenece a la cuenta, y la caída de una entidad no puede afectar a otra. | 🟠 |
| **BR-INT-015** | Los payloads de webhook se almacenan con redacción de datos personales y financieros sensibles, y con plazo de retención definido. | 🟠 |

---

## 9. Propósitos: enum cerrado, no catálogo

El addendum lista los propósitos como si fueran datos. **Deben ser un enum cerrado en código** (`DEC-026`), porque el código se ramifica según ellos: `invoicing` implica una interfaz con `emit()` y `getStatus()`; `email_transactional` implica otra completamente distinta.

Si un propósito fuera una fila que alguien puede crear desde el panel, el resultado sería una conexión perfectamente configurada que ningún código sabe ejecutar. Los catálogos editables son para datos de negocio (países, categorías, motivos de rechazo); los propósitos son **contratos de código**.

Conjunto inicial: `invoicing` · `tax_authority` · `payment_collection` · `creator_payment` · `email_transactional` · `email_marketing` · `exchange_rate` · `file_storage` · `social_verification` · `whatsapp` · `sms` · `analytics` · `error_tracking`.

---

## 10. Alcance: qué entra al MVP y qué no

Aquí está la crítica principal. Construido en su totalidad —registro, resolver, bóveda de credenciales, webhooks, comprobaciones de salud, simulador, panel completo con duplicado y pruebas— esto son **3 a 4 semanas**. Para un MVP que necesita exactamente una conexión por propósito, sería desproporcionado.

Pero hay una compensación real que conviene ver: **este addendum no solo añade trabajo, también lo sustituye.** La Fase 12 estaba planteada como "integrar SMTP, facturación electrónica, tipo de cambio, webhooks", cada una con su propia configuración. Con el registro construido, la Fase 12 pasa a ser "escribir adaptadores y dar de alta conexiones", que es bastante menos.

| Iteración | Qué | Fase | ¿MVP? |
|---|---|---|---|
| Modelo: providers, connections, assignments, credenciales | La forma de los datos | F4 | ✅ |
| Resolver con especificidad, validación de empates y aislamiento de ambiente | El núcleo | F4 | ✅ |
| Bóveda de credenciales con cifrado sobre y rotación | Seguridad | F4 | ✅ |
| Panel mínimo: listar, crear, editar, probar conexión, asignar | Lo justo para operar | F4 | ✅ |
| Adaptadores de arranque: SMTP, S3, tipo de cambio | Los tres que el MVP usa de verdad | F4 | ✅ |
| Matriz de propósitos obligatorios + bloqueo de activación | Fallar pronto | F9 | ✅ |
| `integration_connection_id` en documentos | Trazabilidad | F9 | ✅ |
| Infraestructura de webhooks con URL por conexión e idempotencia | — | F12 | 🟡 |
| Adaptadores de facturación electrónica y pasarela | — | F12 | 🟡 |
| Comprobación de salud programada y panel de estado | — | F12 | 🟡 |
| Simulador de resolución ("¿qué conexión ganaría para…?") | — | F12 | 🟡 |
| Duplicar conexión, rotación asistida, historial de credenciales | — | F12 | ⬜ |
| Cortocircuito y límites de tasa por conexión | — | F16 | ⬜ |

**Coste neto estimado: +1 a 1,5 semanas en la Fase 4**, parcialmente compensado por la simplificación de la Fase 12.

---

## 11. Lo que este addendum acierta

- **El principio de cierre** —preguntar qué integración corresponde a la operación, no cuál es la API global— es exactamente la formulación correcta, y es lo que impide que el sistema quede casado con Culqi o con un proveedor de facturación concreto.
- **Separar proveedor, conexión y asignación** en tres conceptos es la descomposición correcta. La mayoría de los sistemas mete los tres en una tabla y descubre el error cuando llega el segundo país.
- **Insistir en sandbox vs producción como dato de primera clase** es acertado; solo faltaba convertirlo en una barrera y no en un filtro.
- **N:M mediante asignaciones** en lugar de `LegalEntity 1:N Integration` evita exactamente la rigidez que el addendum teme.
- **Reconocer que las credenciales pueden requerir almacenamiento distinto** es una madurez poco frecuente.

---

## 12. Preguntas nuevas para el negocio

| # | Pregunta | Bloquea |
|---|---|---|
| **Q-19** | ¿Qué propósitos son obligatorios para operar en cada país objetivo? Para Perú asumo `invoicing` y `exchange_rate`. | F9 (matriz de obligatorios) |
| **Q-20** | ¿Hay ya cuentas contratadas de SMTP, almacenamiento y proveedor de tipo de cambio, o hay que abrirlas? Son los tres adaptadores del MVP. | F4 |
| **Q-21** | ¿El proveedor de facturación electrónica que se elija permite una cuenta por sociedad, o exige contrato separado por RUC? Afecta a cuántas conexiones habrá. | F12 |
| **Q-22** | ¿Quién es responsable operativo de rotar credenciales y atender una integración caída? Es un rol, no una función del software. | F17 (runbooks) |
