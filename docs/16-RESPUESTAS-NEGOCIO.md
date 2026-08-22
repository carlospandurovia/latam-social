# 16 — Respuestas del negocio y sus consecuencias

> Versión 0.1 — 2026-08-22. Registro de las quince respuestas recibidas, qué desbloquea cada una y **qué queda sin resolver**.
> Las decisiones se reflejan en `docs/05-DECISION-LOG.md`; aquí queda la trazabilidad y el análisis.

---

## 1. Lo que queda resuelto

| Ref. | Respuesta | Consecuencia |
|---|---|---|
| `DEC-001` | **Laravel** | ✅ F1 desbloqueada. Proyecto creado |
| `DEC-005` | **Opción C**: modelo mixto por umbral, con asesoría al creador para sacar su RUC | ✅ Desbloquea F6.6 y buena parte de F9. Ver §2.1 para lo que **no** cubre |
| `Q-05` | Plazo de pago **30 días por defecto, configurable por creador** | `BR-FIN-012` cerrada. Nuevo campo en `Creator`; se muestra al aceptar la campaña |
| `Q-06` | **Contrato por campaña**, sin contratos marco | Simplifica F9.9: no hace falta modelar acuerdos marco. `Agreement` cuelga siempre de la participación |
| `Q-07` | **Menores admitidos** con autorización firmada del padre o tutor; se paga a nombre del tutor | `BR-CREATOR-010` cerrada, pero **abre modelado nuevo**. Ver §3.1 |
| `Q-08` | **2 rondas de corrección** incluidas | `BR-CONTENT-003` cerrada. Valor por defecto de campaña, anulable con autorización |
| `Q-09` | Permanencia **definida por campaña y por red**; el creador adjunta el enlace y el sistema lo valida | `BR-CONTENT-006` cerrada. Ver §3.2 sobre qué se puede validar de verdad |
| `Q-11` | No hay base previa; se armará en CSV | ✅ Plantilla entregada: `tools/plantilla-importacion-creadores.csv` |
| `Q-14` | CTS Perú **sí puede facturar exportación de servicios** | ✅ Desbloquea F4.5b |
| `Q-15` | Cobertura: **CTS Perú → PE, EC, CL, MX, US** · **CTS Colombia → CO** | ✅ Datos para los seeders. **Dos sociedades desde el día uno**: ver §2.2 |
| `Q-16` | **SUNAT directo** en Perú, **DIAN directo** en Colombia | Decisión de peso. Ver §2.3 |
| `Q-19` | Perú: `invoicing` y `exchange_rate` obligatorios. Colombia: opcionales | ✅ Matriz de propósitos obligatorios lista |
| `Q-20` | SMTP **Google Workspace** · almacenamiento **sin contratar** · tipo de cambio **Decolecta** | Decolecta ✅. Los otros dos: ver §3.3 y §3.4 |
| `Q-22` | Operación de credenciales e integraciones: **100% interna** | ✅ Cerrada. Queda como responsabilidad en los runbooks de F17 |
| `Q-23`–`Q-28` | Gamificación: se adopta la recomendación de que no sea obligatoria | ✅ Confirmado. Sigue en F14–F15 con `DEC-039` intacta |

---

## 2. Lo que cambia de forma significativa

### 2.1 🔴 `DEC-005` resuelve el caso peruano, no el transfronterizo

La opción C con asesoría para sacar RUC es una buena respuesta **para un creador peruano**. Cierra el problema doméstico y además construye red: ayudar a alguien a formalizarse es un argumento de retención.

**Pero no responde `Q-13`.** Un creador residente en México o Chile es *no domiciliado* en Perú, y sacarle un RUC peruano no es viable ni tiene sentido. La pregunta real para CTS Perú es otra:

> ¿El pago a un creador extranjero que produce contenido **fuera del Perú** constituye renta de fuente peruana? Y si no lo constituye, ¿qué documento se le exige para que el gasto sea deducible?

Es una cuestión tributaria con matices —dónde se presta el servicio, y el tratamiento particular de servicios digitales y asistencia técnica— que **no voy a resolver yo**, porque una respuesta equivocada aquí sale cara.

**Qué hago mientras tanto:** el modelo ya contempla `DocumentRequirement` con clave compuesta *(país de la entidad pagadora, país del creador, régimen)* — `BR-LE-012`. Así que cuando el contador responda, es configuración y no rediseño.

**Qué necesito de ti:** llevar esa pregunta concreta al contador. Bloquea F9 solo para creadores extranjeros; el MVP con creadores peruanos avanza igual.

### 2.2 Dos entidades legales desde el primer día

Que **CTS Colombia ya esté constituida** cambia el panorama. La Fase 4.5b deja de ser "una fila de ejemplo" y pasa a ser multi-entidad real desde el arranque:

```
CTS Perú      → PE · EC · CL · MX · US
CTS Colombia  → CO
```

El addendum de `docs/11` no era previsión: era necesidad. Y trae una consecuencia inmediata que conviene ver ahora:

> **Una campaña colombiana facturada por CTS Colombia con creadores peruanos sería una operación intercompañía.** `DEC-020` la bloquea por validación en el MVP, y hace bien: eso lleva precios de transferencia y consolidación detrás.

Cuando eso pase —y va a pasar—, la decisión se toma con el contador, no cambiando una casilla.

**Una nota sobre Estados Unidos:** exportar servicios a clientes estadounidenses suele venir con que el cliente pida un formulario **W-8BEN-E** para justificar la retención. No es un problema, pero conviene tenerlo listo antes de la primera factura y no durante.

### 2.3 SUNAT y DIAN directos: dos casos muy distintos

Aquí quiero ser claro porque la decisión suena simétrica y no lo es.

**Perú — SUNAT directo: razonable.** Existe `greenter/greenter`, la librería PHP estándar del mercado peruano, madura y mantenida. Genera UBL 2.1, firma con el certificado digital y habla con los servicios de SUNAT. Hace falta certificado digital, usuario SOL y las extensiones `soap` y `openssl`. Coste estimado frente a usar un PSE: **+2 a 3 semanas**, y a cambio te ahorras la cuota mensual y la dependencia. Lo veo bien.

**Colombia — DIAN directo: lo reconsideraría.** Emitir directo ante la DIAN implica habilitarse como proveedor tecnológico propio: proceso de habilitación con set de pruebas, `software_id` y PIN, cálculo del CUFE, firma con políticas específicas y reglas propias del documento soporte. No hay una librería PHP con la madurez de Greenter. Coste estimado: **+4 a 6 semanas** más riesgo regulatorio real.

**Mi recomendación:** SUNAT directo sí; **Colombia por proveedor tecnológico**, al menos hasta que el volumen colombiano lo justifique. Y en cualquier caso, **nada de esto entra al MVP**: las tres campañas iniciales son peruanas, así que la facturación colombiana puede esperar a F12.

Lo bueno es que la decisión no compromete arquitectura: el registro de integraciones de `docs/12` trata a SUNAT, a la DIAN y a un PSE exactamente igual — son conexiones con `purpose = invoicing` asignadas a una sociedad. Cambiar de una a otra es configuración.

---

## 3. Lo que hay que ajustar

### 3.1 Menores de edad: el pago no va al creador

Tu respuesta abre una consecuencia que no estaba modelada: **si se paga al tutor, el beneficiario del pago no es el creador.**

Eso significa:
- Entidad nueva `CreatorGuardian`: datos del tutor, documento, parentesco acreditado, y la autorización firmada como archivo con vigencia.
- `Payout` necesita un **beneficiario** que puede ser el creador o su tutor.
- El documento tributario lo emite el tutor, no el menor — encaja con `DocumentRequirement`, pero por el tutor.
- Al cumplir 18, el creador deja de necesitar tutor: **hay una transición de estado que hay que modelar**, no es un dato fijo.
- Y una regla que recomiendo añadir: **restricción de categorías** para menores (alcohol, apuestas, y lo que el negocio decida). Va en `BR-CREATOR-010`.

Lo incorporo en la iteración 2.3.

### 3.2 Validar el enlace: qué se puede y qué no

"El aplicativo debe ser capaz de validar que el enlace sea válido" — se puede, con límites que conviene fijar ahora para no prometer de más:

| Se puede comprobar | No se puede |
|---|---|
| Que la URL tiene la forma de esa red y apunta al usuario declarado | Leer el contenido de perfiles privados |
| Que responde y no da 404 | Confirmar que la pieza cumple el brief sin verla |
| Que sigue viva días después (la comprobación de permanencia) | **Nada sobre una Story: expira en 24 h** |

Por eso la evidencia se archiva **en el momento de verificar** (`docs/10 §F-03`). Para Stories es la única prueba posible, y la permanencia como concepto no aplica: se configura por red, tal como dijiste.

### 3.3 ⚠️ Google Workspace no sirve como correo transaccional

Workspace tiene un límite del orden de **2.000 destinatarios al día** y está pensado para correo humano. Una sola campaña con 60 creadores genera invitaciones, recordatorios, correcciones y avisos de pago: varios cientos de correos en pocas horas. Además, enviar correo transaccional masivo desde el dominio corporativo **daña la reputación del dominio con el que también escribes a tus clientes**.

**Recomendación:** un proveedor transaccional dedicado (Postmark, SES o Resend) para todo lo automático, y Workspace solo para el correo que escriben personas. Cuestan del orden de decenas de dólares al mes y traen registro de rebotes, que es lo que hace útil el Email Log de `docs/01`.

El registro de integraciones lo trata como dos conexiones con propósitos distintos —`email_transactional` y el humano fuera del sistema—, así que no cambia nada de arquitectura.

### 3.4 Almacenamiento: recomendación concreta

Sin contratar todavía. Para este caso —clientes descargando vídeo— el coste que duele no es guardar, es **sacar**. Recomiendo **Cloudflare R2**, que no cobra egreso, o Backblaze B2. Evitaría S3 de AWS precisamente por el egreso.

### 3.5 Pregunta que quedó sin responder

`Q-10` iba por otro lado. Respondiste que **el creador asume el costo de creación**, y eso queda claro y va a los términos y condiciones. Pero falta:

> **¿Quién paga el envío del producto al creador, y el creador se queda con el producto o lo devuelve?**

Importa porque el costo del producto y su logística son `CampaignCost` y **entran en el margen de la campaña** (`BR-FIN-011`). Si el cliente los asume, se le facturan; si los asumes tú, te comen margen.

---

## 4. Preguntas nuevas

| # | Pregunta | Bloquea |
|---|---|---|
| **Q-33** | 🔴 ¿El pago a un creador extranjero por trabajo hecho fuera del Perú es renta de fuente peruana? ¿Qué documento se le exige? | F9 para creadores no peruanos |
| **Q-34** | ¿Se confirma DIAN directo, o se usa proveedor tecnológico en Colombia? | F12, no el MVP |
| **Q-35** | ¿Quién asume el envío del producto y qué pasa con él después? (`Q-10` sin cerrar) | F7.8, margen |
| **Q-36** | ¿Se acepta un proveedor transaccional aparte de Workspace? | F4.6b |
| **Q-37** | ¿Categorías vetadas para creadores menores de edad? | 2.3 |
| **Q-38** | ¿Cuántos desarrolladores? Las estimaciones cambian ×1,7 con uno solo | Todo el plan |
