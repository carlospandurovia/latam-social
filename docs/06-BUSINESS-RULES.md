# 06 — Business Rules (conjunto inicial)

> Versión 0.1 — 2026-08-21. Documento vivo y **fuente de verdad**: si el código y este documento discrepan, uno de los dos está mal y hay que resolverlo explícitamente.
> Convención de ID: `BR-<DOMINIO>-<NNN>`. Toda regla debe ser **testeable**; si no se puede escribir un test que la verifique, está mal redactada.
> Cada regla indica su **criticidad**: 🔴 crítica (viola la ley, el dinero o la confianza) · 🟠 alta · 🟡 media.

---

## Seguridad y acceso (`BR-SEC`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-SEC-001** | Existen tres audiencias de datos mutuamente excluyentes: interna, marca y creador. Ninguna respuesta servida a un portal externo puede contener campos de otra audiencia. En particular: la marca nunca ve el costo del creador ni el margen; el creador nunca ve el precio al cliente ni la tarifa de otros creadores. | 🔴 |
| **BR-SEC-002** | La autorización se evalúa siempre como `permiso ∧ ámbito externo ∧ estado del recurso`. Ningún acceso a un recurso por identificador puede omitir esta evaluación. | 🔴 |
| **BR-SEC-003** | Los registros de auditoría son inmutables. La aplicación no expone ninguna operación de modificación o borrado sobre ellos, y el usuario de base de datos de la aplicación carece de esos privilegios sobre la tabla. | 🔴 |
| **BR-SEC-004** | Nunca se transmite una contraseña en texto claro por ningún canal. La activación de cuenta usa un enlace de un solo uso con expiración ≤72 h. | 🔴 |
| **BR-SEC-005** | Los usuarios con permisos financieros (`payout.*`, `invoice.*`, `campaign.view_margin`) deben tener MFA activo. Sin MFA, esos permisos no son efectivos. | 🟠 |
| **BR-SEC-006** | Un intento de acceso a un recurso de otro ámbito devuelve 404, nunca 403: no se revela la existencia del recurso. | 🟠 |
| **BR-SEC-007** | Toda exportación de datos personales o financieros queda auditada con actor, filtro aplicado y número de filas. | 🟠 |
| **BR-SEC-008** | Los secretos de integraciones se almacenan cifrados y se muestran siempre enmascarados; una vez guardados no pueden volver a leerse desde la interfaz, solo reemplazarse. | 🟠 |

## Creadores (`BR-CREATOR`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-CREATOR-001** | Un creador en estado `rejected`, `suspended` o `blacklisted` no puede autenticarse en el portal ni recibir invitaciones. | 🔴 |
| **BR-CREATOR-002** | Solo un creador en estado `active` es elegible para aparecer en resultados de matching. | 🟠 |
| **BR-CREATOR-003** | No pueden coexistir dos creadores activos con el mismo email, el mismo documento de identidad (dentro del mismo país) o la misma cuenta social verificada. El sistema advierte antes de crear y exige resolución explícita. | 🟠 |
| **BR-CREATOR-004** | Toda métrica social declarada por el creador se marca como `self_declared` y se somete a chequeos de coherencia (engagement fuera de rango plausible, saltos de seguidores anómalos). Los resultados anómalos se marcan para revisión humana, nunca se rechazan automáticamente. *(Implementada en 3.7. Hasta entonces la columna existía y ningún código la escribía: toda métrica se declaraba no anómala sin comprobación — `DEC-063`.)* | 🟠 |
| **BR-CREATOR-005** | Los datos de audiencia y métricas se almacenan como snapshots con fecha y fuente. Un valor nuevo nunca sobrescribe al anterior. | 🟠 |
| **BR-CREATOR-006** | Un creador solo pasa a `active` cuando cumple la **completitud operativa mínima**: identidad verificada, al menos una red social validada, datos fiscales según su régimen, al menos un medio de pago verificado y **elegible**, y aceptación vigente de los términos. Si es menor, además tutela activa acreditada (`BR-CREATOR-010`). La evalúa `CompletitudOperativa` y se vuelve a comprobar en el servidor al activar; el botón deshabilitado no es la puerta. *(Implementada en 3.5.)* | 🔴 |
| **BR-CREATOR-007** | Los cambios en datos fiscales, medios de pago o documento de identidad requieren aprobación interna antes de surtir efecto, y notifican al canal de contacto anterior. **La aprobación interna está desde 3.6/3.8** (con dos personas distintas, `ck_ctp_segregation` y `ck_cpm_segregation`). **La notificación no**: `F4.9` (2026-08-26) construyó el aparato de correo; falta engancharlo (`T-10`). | 🔴 |
| **BR-CREATOR-008** | La tarifa declarada por el creador es una referencia, no un compromiso. El precio vinculante es el **monto acordado congelado** en la participación de campaña. **Implementada en 7.5** (`DEC-104`, `tg_ccr_compromiso`): se fija al invitar —no se invita a nadie sin decirle cuánto se le paga— y se congela al aceptar. | 🟠 |
| **BR-CREATOR-009** | Un creador puede solicitar la eliminación de sus datos personales. Se eliminan o anonimizan los datos personales; **se conservan los registros financieros y contractuales** por el plazo legal de retención, disociados en lo posible. | 🔴 |
| **BR-CREATOR-010** | Se admiten creadores menores de 18 años **solo con autorización firmada del padre o tutor**, acreditación de parentesco y documento de identidad del tutor. **El pago se emite a nombre del tutor**, y el documento tributario lo emite él. Se aplican restricciones de categoría de campaña (`Q-37`). Al cumplir la mayoría de edad, la tutela se cierra y el beneficiario pasa a ser el creador. | 🔴 |
| **BR-CREATOR-012** | Un creador con tutela activa no puede recibir invitaciones a campañas cuya `min_creator_age` supere su edad, ni a campañas de categorías con `min_age` superior. Valores concretos en `Q-37`. ⚠️ **`T-34`:** el texto dice «con tutela activa» y `min_creator_age` es una columna de `campaigns` que no menciona la tutela — aplicarla sólo a los menores dejaría pasar a un creador de 20 en una campaña de 21. **Implementada en 7.4 para todos**, con la edad efectiva = máximo(campaña, categorías de la marca). Hay que corregir el texto. | 🔴 |
| **BR-CREATOR-013** | **Todo creador debe tener datos fiscales legales y vigentes en su país.** No existe el pago informal. Sin un perfil tributario aprobado no se activa, no recibe invitaciones y no se le liquida. Si el creador no puede o no quiere regularizarse, la solicitud se rechaza. Para menores, el perfil tributario exigido es el **del tutor**, que es quien emite el documento (`BR-CREATOR-010`). | 🔴 |
| **BR-CREATOR-014** | Antes de rechazar por falta de datos fiscales, el creador permanece en `pending` durante el **periodo de gracia de 30 días** (confirmado por el negocio, configurable) con acompañamiento para regularizarse. El rechazo es la salida del embudo, no la puerta. | 🟠 |
| **BR-CREATOR-011** | Un creador incluido en la blacklist mantiene sus registros históricos y financieros intactos; la blacklist afecta elegibilidad futura, no el pasado. | 🟠 |
| **BR-CREATOR-015** | La verificación de identidad se registra con **tres datos inseparables**: cuándo, qué revisor la hizo y qué documento quedó archivado. No existe la marca sin revisor ni sin documento. Un creador `active` sin identidad verificada lo rechaza la propia base de datos, no la aplicación (`DEC-058`). | 🔴 |
| **BR-CREATOR-018** | Verificar la propiedad de una cuenta social deja constancia del **método** —de una lista cerrada— y de **quién** la comprobó; `oauth` es la única excepción a lo segundo, porque ahí verifica la plataforma. El histórico de métricas no se puede modificar ni borrar: lo impiden disparadores, no una convención (`DEC-063`). | 🔴 |
| **BR-CREATOR-017** | El perfil tributario dice **de quién** son los datos: del creador o de un tutor concreto. Para un menor tiene que ser el del **tutor activo**, el mismo al que pertenece el medio de pago. Capturarlo y aprobarlo son actos de **dos personas distintas**, y eso lo impone la base, no la aplicación (`DEC-062`). | 🔴 |
| **BR-CREATOR-016** | La aceptación de términos es de una **versión concreta** del documento y no se borra ni se revoca: se registra con canal, fecha, evidencia y quién la registró. Publicar una versión nueva cierra la anterior y, con ella, deja de estar vigente su aceptación (`DEC-059`). **Los creadores ya activos NO se desactivan al publicar una versión nueva**; la re-aceptación de los activos está abierta en `Q-46`. | 🔴 |

## Campañas (`BR-CAMPAIGN`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-CAMPAIGN-001** | Solo son válidas las transiciones de estado declaradas en la máquina de estados. Cualquier otra se rechaza y se registra como intento. | 🟠 |
| **BR-CAMPAIGN-002** | Solo un creador con invitación vigente y no expirada puede aceptar una campaña. | 🔴 |
| **BR-CAMPAIGN-003** | Al aceptar, el monto acordado, los entregables y las fechas quedan congelados en la participación. Cambios posteriores exigen una **enmienda** registrada y aceptada por ambas partes. **Parcial desde 7.2/7.3:** con la campaña confirmada, el brief no se toca y un mercado no se quita (`tg_cm_no_quitar_confirmada`, `DEC-096`) — **añadir un mercado sí se puede**, porque ampliar no rompe lo prometido. El circuito de enmienda como tal no existe todavía. | 🔴 |
| **BR-CAMPAIGN-004** | Una campaña no puede pasar a `approved` sin presupuesto, cliente, marca y brief definidos. **Implementada en 7.2.** «Brief definido» = al menos un requisito de formato (`DEC-092`); «presupuesto» = ingreso declarado, y cero vale si alguien dice que es a propósito (`DEC-093`, `ck_camp_revenue_declarado`); desde 7.3 también **al menos un mercado** (`DEC-095`). Cliente y marca los garantiza el esquema. | 🟠 |
| **BR-CAMPAIGN-005** | El costo comprometido con creadores no puede exceder el presupuesto de creadores de la campaña sin aprobación explícita de un rol autorizado, que queda auditada. **Implementada en 7.5** (`DEC-105`). El presupuesto **no existía como columna** hasta entonces: `campaigns.creator_budget_amount` es nueva. La autorización la firma finanzas con motivo obligatorio (`ck_camp_budget_override`). | 🔴 |
| **BR-CAMPAIGN-006** | Una invitación expira automáticamente tras el plazo configurado; una invitación expirada no puede aceptarse. | 🟠 |
| **BR-CAMPAIGN-007** | Un creador con conflicto de marca activo (competidor, exclusividad vigente, categoría prohibida) no puede ser invitado sin anulación explícita y justificada. **Parcial desde 7.4:** la *categoría prohibida* —lo que el creador declaró en `creator_restrictions`— ya excluye del buscador y veta el alta en la lista corta (`DEC-100`). Competidores y exclusividades vigentes, en 7.11. | 🔴 |
| **BR-CAMPAIGN-008** | Una campaña no puede cerrarse (`completed`) mientras existan participaciones sin resolver (ni completadas, ni descartadas). | 🟠 |
| **BR-CAMPAIGN-009** | Si la campaña incluye envío de producto, el creador no pasa a `producing` hasta confirmar la recepción. Los plazos de entrega se cuentan desde esa confirmación, no desde la aceptación. | 🟠 |
| **BR-CAMPAIGN-010** | Cancelar una campaña con creadores ya en producción genera obligaciones económicas según la política de cancelación; el sistema debe registrar el asiento correspondiente, no simplemente descartar la participación. | 🔴 |

## Contenido (`BR-CONTENT`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-CONTENT-001** | Las versiones de entregables son inmutables. Una corrección crea una versión nueva; nunca se reemplaza un archivo. | 🔴 |
| **BR-CONTENT-002** | Ningún contenido llega al cliente sin aprobación interna previa. | 🟠 |
| **BR-CONTENT-003** | El precio al cliente incluye **2 rondas de corrección**. Es el valor por defecto de la campaña y puede cambiarse por campaña. Superarlo genera cargo adicional o requiere anulación autorizada. | 🟠 |
| **BR-CONTENT-004** | Una publicación no se considera verificada hasta que existe evidencia archivada del post en vivo, con fecha y hora de captura. | 🔴 |
| **BR-CONTENT-005** | El uso del contenido por parte de la marca está limitado por la licencia registrada (alcance, territorio, canales, vigencia). El sistema alerta antes del vencimiento. | 🔴 |
| **BR-CONTENT-006** | Si el contrato establece permanencia mínima del post, el sistema verifica periódicamente su disponibilidad y alerta ante una despublicación anticipada. | 🟠 |
| **BR-CONTENT-007** | Todo comentario de revisión queda asociado a una versión concreta. Un comentario nunca "flota" sobre el entregable. | 🟡 |

## Finanzas (`BR-FIN`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-FIN-001** | El saldo de un creador **no es una columna almacenada**: es la suma de los asientos del ledger filtrados por estado. Cualquier caché de saldo es derivada y reconstruible. | 🔴 |
| **BR-FIN-002** | Los asientos del ledger son inmutables. Una corrección se realiza mediante un asiento de reversión que referencia al original. Nunca `UPDATE`, nunca `DELETE`. | 🔴 |
| **BR-FIN-003** | Un `earning` solo pasa a `payable` cuando: la participación está completada, el contenido está aprobado, la publicación está verificada (si aplica), el documento tributario requerido está presente y válido, y el medio de pago está verificado. *(La parte del medio de pago se impone desde 3.8 en el camino del dinero, no solo al activar: `H-09`.)* | 🔴 |
| **BR-FIN-004** | Todo importe se almacena junto a su moneda. Toda conversión registra monto origen, moneda origen, monto destino, moneda destino, tasa, fecha de la tasa y fuente. Está prohibido almacenar importes en punto flotante. | 🔴 |
| **BR-FIN-005** | Un lote de pago por encima del umbral configurado requiere doble aprobación por usuarios distintos. Quien crea el lote no puede aprobarlo. | 🔴 |
| **BR-FIN-006** | Un medio de pago modificado o añadido no es elegible para pagos hasta transcurrido el período de enfriamiento y completada la reverificación. La modificación notifica al canal de contacto anterior. *(Implementada en 3.8: el enfriamiento son 24 h configurables (`DEC-064`), «modificado» no existe porque la cuenta es inmutable (`DEC-066`), y el aviso al canal anterior sigue siendo manual (`T-10`).)* | 🔴 |
| **BR-FIN-007** | El margen de campaña (`Revenue − Creator Cost − Direct Cost − Descuentos − Otros gastos`) solo es visible para roles con el permiso `campaign.view_margin`. Nunca se serializa hacia portales externos. | 🔴 |
| **BR-FIN-008** | Ningún registro financiero se elimina físicamente. La anulación se representa mediante estado y asientos compensatorios. *(Extendida en 3.8 a `creator_payment_methods`, que admitía `DELETE`: `H-13`.)* | 🔴 |
| **BR-FIN-009** | El tipo de cambio aplicado a una operación es el vigente en la fecha de la operación, no el actual. Los históricos no se recalculan. | 🔴 |
| **BR-FIN-010** | Una factura emitida no se modifica. La corrección se hace con nota de crédito o débito. Además conserva snapshot de su emisor (`BR-LE-005`). | 🔴 |
| **BR-FIN-011** | El costo de productos enviados y la logística se registran como `Direct Cost` de la campaña e impactan el margen. | 🟠 |
| **BR-FIN-012** | El plazo de pago al creador es de **30 días por defecto, configurable por creador**, contados desde la verificación de la publicación. Es visible para el creador desde el momento en que acepta la campaña. | 🟠 |
| **BR-FIN-013** | Un `payout` genera **exactamente un** asiento de pago en el libro mayor. Impuesto por `UNIQUE (payout_id)`. | 🔴 |
| **BR-FIN-014** | El signo del importe está determinado por el tipo de asiento: `earning`, `bonus` y `payment_reversal` son positivos; `payment`, `penalty` y `withholding`, negativos. Solo `adjustment` admite ambos. | 🔴 |
| **BR-FIN-015** | Un devengo (`earning`) exige la participación en campaña que lo origina. No existe dinero devengado sin procedencia trazable. | 🔴 |
| **BR-FIN-016** | **Todo pago pertenece a un lote.** No existen pagos sueltos: un pago único es un lote de uno. Es lo que impide evitar la doble aprobación de `BR-FIN-005` simplemente no creando lote. | 🔴 |
| **BR-FIN-017** | El libro mayor es de **solo inserción**. La única columna mutable de un asiento es `status`. Impuesto por disparador en la base, no por la capa de aplicación. | 🔴 |
| **BR-FIN-018** | El régimen tributario se decide **por factura** y se congela junto al país del receptor. Una operación no gravada no lleva importe de impuesto, y no se exporta un servicio a un cliente domiciliado en Perú. | 🔴 |
| **BR-FIN-019** | Un perfil fiscal **no se aprueba** con la retención sin decidir. `pending_review` no es «no se retiene»: es «nadie lo ha mirado». Si se retiene, hacen falta tasa **y** la norma que la sustenta. | 🔴 |
| **BR-FIN-020** | Un asiento de retención congela la tasa aplicada y su norma. Cambiar la tasa mañana no reescribe las retenciones de ayer. | 🔴 |
| **BR-SEC-001** | Toda ruta de negocio declara el permiso que exige. El código pregunta por el permiso, nunca por el rol. | 🔴 |
| **BR-SEC-002** | El rol `admin` no tiene atajo en el código: sus permisos son filas de `permission_role`, igual que los de cualquier otro rol. | 🔴 |
| **BR-SEC-003** | Un rol de ámbito externo (`client_user`, `creator`) nunca recibe permisos internos. | 🔴 |
| **BR-SEC-004** | `audit_logs` es de **solo inserción**. Ni la aplicación ni nadie con la contraseña de la base puede editar o borrar una entrada. | 🔴 |
| **BR-SEC-005** | Ninguna acción de escritura usa `$request->all()`. Solo se persiste lo declarado en las reglas de validación. | 🔴 |
| **BR-SEC-006** | Los campos de identidad de un creador (nombre legal, nacimiento, documento, correo) y su estado no se editan desde una pantalla de datos de contacto. | 🔴 |
| **BR-SEC-007** | Ningún valor de un campo sensible se escribe en `audit_logs`. Se registra que cambió, con el valor como `[redactado]`. | 🔴 |
| **BR-SEC-008** | Una confirmación del usuario («ya lo revisé») nunca sustituye a una validación del servidor. | 🔴 |

## Marca de plataforma y entidades legales (`BR-LE`)

> Incorporadas por el addendum del 2026-08-21. Análisis en `docs/11-ADDENDUM-LEGAL-ENTITIES.md`.

| ID | Regla | Crit. |
|---|---|---|
| **BR-LE-001** | Todo documento comercial o fiscal (propuesta, contrato, campaña, factura, nota, pago, liquidación) almacena explícitamente su `legal_entity_id`. Nunca se deduce de la configuración vigente en el momento de la consulta. | 🔴 |
| **BR-LE-002** | La entidad legal de un documento es inmutable una vez emitido. Corregirla exige anular y reemitir, no editar. | 🔴 |
| **BR-LE-003** | Un cliente solo puede asociarse a entidades legales cuya cobertura de facturación incluya el país del cliente y esté vigente en la fecha de la operación. | 🔴 |
| **BR-LE-004** | Si ninguna entidad legal cubre el país del cliente, la operación se bloquea con un mensaje accionable. Nunca se asigna una entidad por defecto ni se continúa en silencio. | 🟠 |
| **BR-LE-005** | Todo documento fiscal conserva snapshot de los datos del emisor vigentes al emitirse: razón social, identificación fiscal, domicilio, serie, número y datos bancarios impresos. | 🔴 |
| **BR-LE-006** | Las instrucciones de pago que aparecen en una factura provienen exclusivamente de las cuentas de la entidad legal emisora, en la moneda del documento. | 🔴 |
| **BR-LE-007** | La numeración de documentos es correlativa por (entidad legal, país, tipo de documento, serie) y se asigna bajo bloqueo, sin huecos ni duplicados, incluso bajo concurrencia. | 🔴 |
| **BR-LE-008** | *(Reformulada por el addendum de integraciones.)* Las series de documentos pertenecen a la entidad legal. La configuración del proveedor de facturación electrónica vive en el registro de integraciones y **se asigna explícitamente** a una entidad legal: nunca se hereda de forma implícita entre entidades ni cuelga de la marca de plataforma. Para los propósitos fiscales, además, una conexión no puede compartirse entre sociedades (`BR-INT-011`). | 🔴 |
| **BR-LE-009** | En el MVP, la entidad que factura al cliente y la que liquida a los creadores de esa campaña deben ser la misma. Divergir requiere anulación explícita, autorizada y auditada. | 🟠 |
| **BR-LE-010** | Los reportes y comunicaciones operativas se presentan bajo la identidad de la marca de plataforma; los documentos fiscales y los contratos, bajo la identidad de la entidad legal. | 🟠 |
| **BR-LE-011** | Una entidad legal con documentos emitidos no se elimina jamás: se desactiva. Su cobertura de facturación se cierra con `valid_to`, no se borra. | 🔴 |
| **BR-LE-012** | El requisito documental y la retención aplicables a un pago a creador se determinan por la pareja (país de la entidad pagadora, país del creador), no solo por el país del creador. | 🔴 |

## Integraciones y proveedores externos (`BR-INT`)

> Incorporadas por el addendum del 2026-08-21. Análisis en `docs/12-ADDENDUM-INTEGRATIONS.md`.

| ID | Regla | Crit. |
|---|---|---|
| **BR-INT-001** | Ninguna parte del sistema instancia un cliente de proveedor externo directamente. Toda operación obtiene su conexión del resolver de integraciones. | 🔴 |
| **BR-INT-002** | La resolución es determinista: gana la asignación vigente de mayor especificidad. Dos asignaciones activas con la misma especificidad para la misma combinación son un error de configuración y se rechazan **al guardar**, no en ejecución. | 🔴 |
| **BR-INT-003** | El resolver devuelve la conexión y el motivo de la resolución, y ambos quedan registrados en la operación. | 🟠 |
| **BR-INT-004** | El resolver nunca devuelve una conexión de un ambiente distinto al de ejecución. La anulación es temporal, permisionada y auditada. | 🔴 |
| **BR-INT-005** | En ambientes no productivos el correo saliente pasa siempre por un capturador, con independencia de la configuración. | 🔴 |
| **BR-INT-006** | Una conexión no puede activarse sin una verificación exitosa. Se registra `last_verified_at`. | 🟠 |
| **BR-INT-007** | Una entidad legal no puede activarse para un país si no tiene cubiertos los propósitos declarados obligatorios para ese país. | 🔴 |
| **BR-INT-008** | Los secretos se cifran con clave maestra externa a la base de datos, se versionan al rotar, no se muestran completos tras guardarse y **nunca aparecen en logs, trazas de error ni exportaciones**. | 🔴 |
| **BR-INT-009** | Un webhook llega a la URL propia de su conexión, se verifica su firma con el secreto de esa conexión y se rechaza si la firma no es válida. Un webhook sin firma válida no se procesa jamás. | 🔴 |
| **BR-INT-010** | Los webhooks son idempotentes por `(conexión, identificador de evento del proveedor)`. Se acusan de inmediato y se procesan en cola. | 🔴 |
| **BR-INT-011** | Los propósitos no compartibles (`invoicing`, `tax_authority`, `payment_collection`, `creator_payment`) no admiten asignaciones que abarquen más de una entidad legal. | 🔴 |
| **BR-INT-012** | Todo documento emitido a través de un proveedor externo registra la conexión que lo emitió, y esa referencia es inmutable. | 🔴 |
| **BR-INT-013** | Una conexión con documentos o eventos asociados no se elimina: se desactiva, y sus asignaciones se cierran con `valid_to`. | 🔴 |
| **BR-INT-014** | Los límites de tasa y el cortocircuito ante fallos se aplican **por conexión**, no por proveedor. | 🟠 |
| **BR-INT-015** | Los payloads de webhook se almacenan con redacción de datos personales y financieros sensibles, y con plazo de retención definido. | 🟠 |

## CRM y clientes (`BR-CRM`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-CRM-001** | La conversión Lead → Cliente conserva la referencia al lead original. El lead no se elimina ni se duplica. | 🟠 |
| **BR-CRM-002** | Un cliente puede tener múltiples marcas (`ClientBrand`); **toda campaña se asocia a una marca del cliente**, no directamente al cliente. No confundir con `PlatformBrand` (LATAM Social). | 🟠 |
| **BR-CRM-003** | Cliente y usuario son entidades distintas. Un cliente puede tener cero, uno o muchos usuarios de plataforma. | 🟠 |
| **BR-CRM-004** | La identificación fiscal se valida según el catálogo del país del cliente. No existen reglas de un país concretas embebidas en el código. | 🟠 |
| **BR-CRM-005** | Los datos de consentimiento y origen (UTM, landing, fecha, IP si legalmente procede) se capturan en el momento del envío del formulario y son inmutables. | 🟠 |

## Comunicaciones (`BR-COMM`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-COMM-001** | Todo correo saliente queda registrado con destinatario, plantilla, versión, idioma, entidad relacionada, estado y respuesta del proveedor. | 🟠 |
| **BR-COMM-002** | Las plantillas solo pueden usar variables de su diccionario declarado. No se permite ejecución de código ni acceso arbitrario a objetos desde una plantilla. | 🔴 |
| **BR-COMM-003** | Si no existe la plantilla en el idioma del destinatario, se aplica la cadena de respaldo hasta el idioma por defecto de la plataforma; el envío nunca falla por falta de traducción. | 🟠 |
| **BR-COMM-004** | Los correos transaccionales (invitación, aprobación, pago) se envían siempre, independientemente de las preferencias de notificación. Solo los correos informativos y de marketing respetan la baja. | 🟠 |
| **BR-COMM-005** | El envío de correo nunca ocurre dentro de la transacción de negocio: se dispara por evento y se procesa en cola. Un fallo de correo no puede revertir una aprobación. | 🟠 |

## Gamificación (`BR-GAM`)

> Incorporadas por el addendum del 2026-08-21. Análisis en `docs/13-ADDENDUM-GAMIFICATION.md`.

| ID | Regla | Crit. |
|---|---|---|
| **BR-GAM-001** | El XP es append-only y nunca disminuye. No existe operación de resta ni de borrado. | 🔴 |
| **BR-GAM-002** | Todo asiento de XP referencia la regla que lo otorgó y el evento de dominio que lo disparó, y ambos son visibles para el creador. | 🔴 |
| **BR-GAM-003** | Solo generan XP eventos verificados por el sistema. Ninguna acción autodeclarada otorga puntos. | 🔴 |
| **BR-GAM-004** | Toda regla de XP declara un tope por periodo. Una regla sin tope no puede activarse. | 🟠 |
| **BR-GAM-005** | El nivel es una función del XP acumulado y de la curva vigente; no se almacena como verdad mutable. Recalibrar la curva no elimina XP a nadie. | 🟠 |
| **BR-GAM-006** | Las reglas de XP no se eliminan: se cierran con `valid_to`, para poder explicar puntos otorgados en el pasado. | 🟠 |
| **BR-GAM-007** | Los puntos de liga se reinician al cambiar de temporada; el XP acumulado nunca. | 🟠 |
| **BR-GAM-008** | No existe tabla pública de posiciones global ni ranking por ingresos. La comparación se limita a cohortes acotadas. | 🟠 |
| **BR-GAM-009** | Todo reto interno incluye una recompensa tangible además del XP, y su participación es opcional. No participar no afecta a la elegibilidad para campañas. | 🔴 |
| **BR-GAM-010** | El contenido producido en un reto interno requiere licencia de uso explícita con alcance y vigencia, igual que el de cualquier campaña. | 🔴 |
| **BR-GAM-011** | El XP por referido se consolida solo cuando el referido alcanza el hito definido, nunca al registrarse. | 🔴 |
| **BR-GAM-012** | Referente y referido no pueden compartir documento, teléfono ni medio de pago. La coincidencia bloquea la consolidación y marca para revisión. | 🔴 |
| **BR-GAM-013** | Toda recompensa con consecuencia económica queda auditada y es explicable al creador. | 🔴 |
| **BR-GAM-014** | Las bases del programa son un documento versionado con aceptación registrada. Un cambio de reglas no altera retroactivamente el XP ya otorgado. | 🔴 |
| **BR-GAM-015** | Si el motor de gamificación no está disponible, ninguna operación de campaña, contenido o pago se ve afectada. | 🟠 |

## Privacidad (`BR-PRIV`)

| ID | Regla | Crit. |
|---|---|---|
| **BR-PRIV-001** | Cada consentimiento se registra con su texto versionado, fecha, canal y evidencia. Cambiar los términos requiere una nueva aceptación; nunca se reinterpreta un consentimiento antiguo. *(Implementada en 3.5: `terms_versions` + `terms_acceptances`. Hasta entonces la regla existía sin ninguna tabla detrás.)* | 🔴 |
| **BR-PRIV-002** | Se aplica minimización: no se solicita un dato personal sin un uso definido y documentado. | 🟠 |
| **BR-PRIV-003** | Cada categoría de dato personal tiene un plazo de retención definido y un mecanismo de purga automatizado. | 🟠 |
| **BR-PRIV-004** | Los datos sensibles (documento de identidad, cuentas bancarias) se cifran en reposo a nivel de aplicación y toda lectura queda auditada. | 🔴 |
| **BR-PRIV-005** | Los ambientes que no son producción no contienen datos personales reales sin anonimizar. | 🔴 |
