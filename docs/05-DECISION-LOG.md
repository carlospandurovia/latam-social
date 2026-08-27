# 05 — Decision Log

> Versión 0.1 — 2026-08-21.
> Estados: **PROPUESTA** (adoptada provisionalmente para no bloquear) · **APROBADA** · **RECHAZADA** · **BLOQUEANTE** (no se puede avanzar sin respuesta del negocio).
> Regla: toda decisión con impacto estructural vive aquí. Ninguna se toma en silencio dentro de un commit.

---

### DEC-000 — Nombre del producto y dominio ✅ RESUELTA
| | |
|---|---|
| **Contexto** | El repositorio se llama `ManageCampaingInfluencer` (con error ortográfico). El nombre afecta marca, dominio, correos y branding. |
| **Resolución** | El addendum del 2026-08-21 lo define: la **marca de plataforma es LATAM Social**. El identificador técnico del proyecto pasa a `latam-social`. El nombre de trabajo provisional "CTS Creators" queda descartado. |
| **Consecuencia** | LATAM Social es una **marca**, no la sociedad que factura. La sociedad actual es Soluciones Tecnológicas a Medida S.A.C. (RUC 20603203896, Perú). Esta separación es estructural, no cosmética: ver `docs/11-ADDENDUM-LEGAL-ENTITIES.md`. |
| **Pendiente** | Dominio definitivo y correo del remitente, que deben empezar a calentarse antes de F5. Confirmar si la marca está registrada y a nombre de qué sociedad (`Q-18`). |
| **Estado** | ✅ APROBADA (addendum 2026-08-21) |

---

### DEC-001 — Framework PHP
| | |
|---|---|
| **Contexto** | La spec exige PHP 8.3 y "MVC o equivalente bien estructurado", y a la vez pide migraciones, seeders, colas, scheduler, cache, logs, SMTP, webhooks, RBAC, i18n y tests. Todo eso ya existe, probado en producción, en los frameworks maduros. |
| **Opciones** | **A) Laravel 12.** Máxima velocidad, ecosistema enorme, todo lo pedido incluido. **B) Symfony 7.** Más explícito y desacoplado, mejor para DDD estricto, curva más lenta. **C) MVC propio.** Control total. |
| **Recomendación** | **A — Laravel 12.** C queda descartada: construir a mano autenticación, colas, migraciones y abstracción de storage añadiría 3–5 meses de trabajo indiferenciado y una superficie de seguridad propia que nadie audita. B es defendible si el equipo ya domina Symfony. |
| **Impacto** | **Muy alto.** Es la decisión más costosa de revertir del proyecto. |
| **Riesgo** | Acoplamiento al framework. Se mitiga manteniendo `Domain/` y `Application/` libres de dependencias del framework donde sea razonable. |
| **Estado** | ✅ **APROBADA** (2026-08-22). Proyecto creado: ver `tools/bootstrap-laravel.ps1` y `CONTRIBUTING.md` |

---

### DEC-002 — Multitenancy ✅ RESUELTA
| | |
|---|---|
| **Contexto** | Hoy hay un solo operador. La spec contempla agencias y white-label a futuro (§78). |
| **Opciones** | **A)** Sin columna de inquilino. **B)** `tenant_id` presente desde el día 1 con una sola fila, como seguro barato. **C)** Multitenant completo desde el inicio. |
| **Recomendación original** | **B**, por el escenario de agencias y white-label que la especificación contempla en §78 (supuesto `S-06`). |
| **Resolución (2026-08-21)** | El negocio confirma que **la plataforma la operarán únicamente CTS y sus sociedades, para su propia agencia, y no se venderá ni cederá a terceros.** Eso invalida el supuesto que justificaba el seguro. Se adopta **A: sin `tenant_id`.** |
| **Por qué A y no B** | 1) El peaje no se paga una vez: es una columna en cada tabla, un prefijo en cada índice compuesto, un filtro que recordar en cada consulta cruda y un campo en cada factory y cada test, sobre ~135 entidades y durante años. 2) Las sociedades de otros países no necesitan aislamiento: comparten creadores, campañas y equipo, y lo único que cambia es quién factura — eso ya lo resuelve `legal_entity_id`. 3) **Una columna que no hace nada es una invitación al error `R-26`**: tarde o temprano alguien pensaría que CTS Colombia debe ser un inquilino. Quitarla elimina la tentación. |
| **Lo que sí se conserva** | El escenario de una **agencia externa que trae marcas** como intermediaria sigue abierto y no necesita inquilinos: se modela como un `ClientOrganization` de tipo agencia con marcas hijas, dentro del dominio de clientes (D5). |
| **Coste de revertir** | Real y conocido: si algún día un tercero operase su propia red de creadores sobre este software, habría que añadir la columna a más de 50 tablas con datos en producción **y auditar cada consulta del sistema**. Semanas de trabajo, no días, y con riesgo de fuga entre inquilinos si alguna consulta se olvida. |
| **Disparador de revisión** | Que se plantee dejar que un tercero opere su propia red de creadores sobre esta instalación. Nada más lo activa: ni nuevas sociedades, ni nuevos países, ni agencias como clientes. |
| **Impacto** | Alto en el modelo (simplifica todas las tablas), nulo en funcionalidad. |
| **Estado** | ✅ **APROBADA** (decisión del negocio, 2026-08-21) |

---

### DEC-003 — Estrategia de frontend
| | |
|---|---|
| **Contexto** | Cuatro portales con necesidades distintas. Equipo pequeño. |
| **Opciones** | A) Server-side (Blade) + Tailwind + Alpine/Livewire. B) SPA (React/Vue) + API. C) Híbrido: admin server-side, portal creador SPA. |
| **Recomendación** | **A.** B duplica el trabajo y, sobre todo, **duplica la autorización** —el punto donde este sistema más se puede romper—. El portal del creador se entrega como **PWA instalable** con A. |
| **Impacto** | Alto en velocidad de desarrollo, medio en experiencia. |
| **Reversibilidad** | Alta, si la lógica vive en casos de uso y no en controladores. |
| **Estado** | PROPUESTA |

---

### DEC-004 — Modelo económico: principal vs. comisión
| | |
|---|---|
| **Contexto** | ¿El operador vende la campaña y compra el contenido (reventa), o cobra una comisión sobre una transacción entre marca y creador? |
| **Opciones** | A) Principal/reventa. B) Marketplace con comisión. C) Ambos según el caso. |
| **Recomendación** | **A**, coherente con §53 de la especificación (Revenue − Creator Cost = Margen). Determina el modelo fiscal, contable y financiero completo. |
| **Impacto** | **Muy alto.** Si en realidad es B, cambian facturación, impuestos, ledger y contratos. |
| **Estado** | PROPUESTA — **confirmar con el contador** |

---

### DEC-005 — Régimen legal y tributario del pago al creador ✅ RESUELTA (parcial)
| | |
|---|---|
| **Contexto** | En Perú, pagar a una persona natural por un servicio exige normalmente comprobante (recibo por honorarios electrónico) y puede implicar retención de renta de 4.ª categoría, con umbrales y suspensión de retención. Muchos micro-creadores no tienen RUC ni emiten comprobantes. Sin resolver esto, **no se puede pagar legalmente a escala**. |
| **Opciones** | **A)** Exigir RUC y recibo por honorarios a todo creador. Limpio fiscalmente, **reduce fuertemente el pool de creadores** y añade fricción. **B)** Pagar sin comprobante. **No es viable**: el gasto no es deducible y expone a contingencia tributaria. **C)** Modelo mixto por umbral: bajo cierto monto acumulado anual, una vía simplificada; sobre ese umbral, RUC obligatorio. **D)** Intermediar con un tercero que agregue y liquide pagos a personas naturales. |
| **Recomendación** | **C**, con la vía simplificada definida por un contador. Y en cualquier caso: **el sistema debe modelar `tax_regime` por creador y por país**, con requisitos documentales configurables por régimen, y bloquear el pago si falta el documento requerido. |
| **Impacto** | **Bloqueante para F9.** También afecta el formulario de aplicación (F5) y el onboarding (F6). |
| **Resolución (2026-08-22)** | **Opción C aprobada**: modelo mixto por umbral, y además **asesoría al creador para obtener su RUC y formalizarse**. Buena decisión: cierra el caso doméstico y además retiene, porque ayudar a alguien a formalizarse construye relación. |
| **Lo que NO cubre** | El caso transfronterizo. Un creador residente en México o Chile es no domiciliado y un RUC peruano no le aplica. Sigue abierto en `Q-33`. El modelo ya lo contempla con `DocumentRequirement` por *(país pagador, país creador, régimen)* — `BR-LE-012` —, así que la respuesta será configuración, no rediseño. |
| **Estado** | ✅ **APROBADA** para creadores peruanos · 🔴 `Q-33` abierta para extranjeros (bloquea F9 solo en ese caso) |

---

### DEC-006 — Separación Aplicación vs. Creador
| | |
|---|---|
| **Contexto** | La spec mezcla el ciclo de vida de la solicitud con el del creador. |
| **Opciones** | A) Una sola entidad con estados. B) `CreatorApplication` (efímera, reintentable) + `Creator` (duradero). |
| **Recomendación** | **B.** Permite reaplicaciones, conserva el histórico de rechazos y evita que un rechazo contamine la entidad permanente. |
| **Impacto** | Medio en modelo de datos, alto en calidad del histórico. |
| **Estado** | PROPUESTA |

---

### DEC-007 — Pasarela de pago (Culqi) en el MVP
| | |
|---|---|
| **Contexto** | La spec pide integrar Culqi. Pero en B2B LATAM, campañas de miles de soles se pagan por **transferencia contra factura**, no con tarjeta. Una campaña de S/ 30.000 no se cobra con tarjeta (comisión ~3,5% = S/ 1.050 regalados). |
| **Opciones** | A) Culqi en el MVP. B) Diferir a F12 y solo si aparece un caso real. C) Nunca. |
| **Recomendación** | **B.** Se construye la **abstracción** (`PaymentGatewayInterface`) pero no la integración. Si aparece un segmento de ticket bajo (p. ej. paquetes autoservicio para pymes), se activa. |
| **Impacto** | Ahorra 2–3 semanas del MVP. |
| **Pregunta al negocio** | ¿Existe hoy algún cliente que quiera pagar con tarjeta? |
| **Estado** | PROPUESTA |

---

### DEC-008 — Almacenamiento de archivos
| | |
|---|---|
| **Contexto** | Campañas UGC generan video pesado (0,5–3 GB por campaña). |
| **Opciones** | A) Disco local. B) S3-compatible desde el día 1. |
| **Recomendación** | **B, sin excepción.** A rompe el despliegue en más de un servidor, complica los backups, no escala y migrar después implica reescribir rutas de miles de archivos. Cloudflare R2 o Backblaze B2 tienen costo de egreso muy bajo, relevante porque los clientes descargarán contenido. |
| **Impacto** | Alto en costos e infraestructura. |
| **Estado** | PROPUESTA |

---

### DEC-009 — Origen de los datos de audiencia
| | |
|---|---|
| **Contexto** | Los datos autodeclarados son el principal vector de fraude. Las APIs oficiales exigen que el creador conecte su cuenta (Instagram/Facebook requieren cuenta Business/Creator y autorización explícita; TikTok y YouTube tienen sus propios programas). |
| **Opciones** | **A)** Solo manual con evidencia. **B)** Manual + API oficial opt-in. **C)** Proveedor de datos de terceros (Modash, HypeAuditor, Favikon…) por suscripción. **D)** Scraping — **descartado**: ilegal/frágil, y la propia spec lo prohíbe correctamente. |
| **Recomendación** | **A en el MVP** (manual + evidencia + chequeos de coherencia), **B en F12–F14**, **C evaluado por costo** cuando el volumen lo justifique: comprar datos verificados suele ser más barato que construir y mantener integraciones con 6 plataformas. |
| **Impacto** | Alto en credibilidad ante la marca. |
| **Estado** | PROPUESTA |

---

### DEC-010 — Estado de campaña vs. estado de participación
| | |
|---|---|
| **Contexto** | Una campaña `live` puede tener creadores aún en revisión. |
| **Opciones** | A) Un solo estado global. B) Dos máquinas de estado paralelas (Campaign y CampaignCreator). |
| **Recomendación** | **B, obligatoriamente.** A es el error de diseño más frecuente y más caro en plataformas de este tipo: obliga a hacks inmediatos y hace imposible el reporte de avance. |
| **Impacto** | Alto en modelo de datos y en UI. |
| **Estado** | PROPUESTA |

---

### DEC-011 — ¿La plataforma publica en las redes del creador?
| | |
|---|---|
| **Contexto** | Publicar automáticamente requiere permisos amplios, es frágil y las plataformas lo restringen. |
| **Opciones** | A) Publicar automáticamente. B) El creador publica y registra la URL; la plataforma verifica. |
| **Recomendación** | **B.** A añade riesgo de cuenta, complejidad enorme y las APIs cambian sin aviso. La verificación posterior aporta casi todo el valor con una fracción del costo. |
| **Estado** | PROPUESTA |

---

### DEC-012 — Longitud del formulario de lead B2B
| | |
|---|---|
| **Contexto** | La spec lista 16 campos. Cada campo adicional reduce la conversión. |
| **Opciones** | A) Los 16 campos. B) 6–8 campos visibles + enriquecimiento en la llamada de discovery. |
| **Recomendación** | **B:** empresa, nombre, email corporativo, teléfono/WhatsApp, país, objetivo, mensaje. El resto (presupuesto, cantidad de creadores, países objetivo, industria) se captura en la conversación comercial y se guarda en el CRM. UTM, landing y origen se capturan **ocultos**, siempre. |
| **Impacto** | Directo sobre el costo de adquisición. |
| **Estado** | PROPUESTA |

---

### DEC-013 — Alcance del portal de la marca en el MVP
| | |
|---|---|
| **Contexto** | Con 3 marcas, construir un portal con cuentas, roles, facturación y documentos es esfuerzo desproporcionado. |
| **Opciones** | A) Portal completo en el MVP. B) **Enlaces firmados por campaña** (magic links con expiración) para aprobar contenido y ver el reporte, sin cuentas. C) Nada, todo por email. |
| **Recomendación** | **B.** Entrega el 90% del valor percibido con el 15% del esfuerzo, y es el camino natural hacia A en F13. Requiere cuidado en seguridad: enlaces de un solo destinatario, con expiración, revocables y auditados. |
| **Impacto** | Ahorra ~4 semanas del MVP. |
| **Estado** | PROPUESTA |

---

### DEC-014 — Modelo de derechos de uso del contenido
| | |
|---|---|
| **Contexto** | Lo que la marca realmente compra es una **licencia**. Modelarlo como un booleano "cede derechos: sí/no" es insuficiente y genera disputas. |
| **Opciones** | A) Booleano. B) Entidad `UsageRight` con: alcance (orgánico / paid media / web / punto de venta / OOH), territorio, canales, exclusividad, vigencia (inicio-fin o perpetua), y posibilidad de renovación con costo. |
| **Recomendación** | **B.** Además, alertas de vencimiento: usar contenido después de que expiró la licencia es una infracción con consecuencias legales para el cliente y reputacionales para el operador. |
| **Impacto** | Medio en modelo, alto en valor comercial (la renovación de licencias es una línea de ingreso recurrente real). |
| **Estado** | PROPUESTA |

---

### DEC-015 — Retención y minimización de datos personales
| | |
|---|---|
| **Contexto** | Se recopilan documento de identidad, fecha de nacimiento, dirección, datos bancarios y dirección de envío de cientos de personas. |
| **Opciones** | A) Guardar todo indefinidamente. B) Política de retención por tipo de dato, con purgado automatizado y consentimientos versionados. |
| **Recomendación** | **B.** Además: la dirección de envío se purga N meses después de la campaña; las copias de documentos de identidad, si se recogen, se cifran y tienen la retención más corta legalmente posible. **Menos datos = menos riesgo.** |
| **Impacto** | Medio técnico, **alto legal y reputacional**. |
| **Estado** | PROPUESTA — requiere validación legal |

---

## Decisiones introducidas por el addendum multi-entidad (2026-08-21)

> Análisis completo en `docs/11-ADDENDUM-LEGAL-ENTITIES.md`.

### DEC-016 — Desambiguación de los conceptos organizacionales
| | |
|---|---|
| **Contexto** | El addendum introduce "marca de plataforma" (LATAM Social) y "entidad legal" (la sociedad). El modelo anterior ya usaba `Brand` para la marca **del cliente**. Cuatro conceptos organizacionales con nombres que se pisan. |
| **Opciones** | A) Mantener los nombres actuales y añadir los nuevos. B) Renombrar `Brand`→`ClientBrand`, introducir `PlatformBrand` y `LegalEntity`, y **prohibir el término `Organization` a secas** — siempre `ClientOrganization`. |
| **Recomendación** | **B.** A garantiza que en seis meses nadie sepa si `brand_id` apunta a LATAM Social o a Shampoo ABC. Es la fuente número uno de errores de datos en sistemas multi-entidad. |
| **Nota (2026-08-21)** | Con `DEC-002` resuelta en A, el concepto de inquilino desaparece y quedan **cuatro** conceptos, no cinco. El renombrado `Organization`→`Tenant` ya no aplica. |
| **Impacto** | Alto conceptual, **coste casi nulo hoy** (aún no hay código); migración sobre decenas de tablas si se hace después. |
| **Estado** | PROPUESTA |

### DEC-017 — Cobertura de facturación como relación N:M con vigencia
| | |
|---|---|
| **Contexto** | Una sociedad puede facturar clientes de varios países, y un país puede ser atendido por más de una sociedad. |
| **Opciones** | A) `country.legal_entity_id` (1:1). B) Tabla puente con `active`. C) Tabla puente con `valid_from` / `valid_to` y `priority`. |
| **Recomendación** | **C.** A es directamente incorrecta. B pierde la información de *cuándo* dejó de aplicar una cobertura, y eso hace imposible auditar por qué una factura de 2027 salió de una sociedad y una de 2029 de otra. La vigencia es lo que permite la evolución del §15 del addendum sin destruir historia. |
| **Impacto** | Alto en el modelo, bajo en esfuerzo. |
| **Estado** | PROPUESTA |

### DEC-018 — Configuración jerárquica en cascada
| | |
|---|---|
| **Contexto** | La configuración deja de ser global: hay ajustes de plataforma, de marca, de entidad legal y de (entidad × país). |
| **Opciones** | A) Todo global con excepciones puntuales. B) Cuatro niveles con resolución en cascada, donde cada ajuste declara en qué nivel vive. |
| **Recomendación** | **B**, con dos condiciones no negociables: la interfaz debe mostrar siempre **de dónde viene el valor efectivo**, y los secretos se cifran por entidad y **nunca se heredan**. |
| **Impacto** | Es el mayor cambio de alcance del addendum: F4.6 crece y aparece F4.5b. ~+1 a 1,5 semanas. |
| **Estado** | PROPUESTA |

### DEC-019 — Entidad legal explícita e inmutable en cada documento
| | |
|---|---|
| **Contexto** | §14 del addendum. Si mañana Ecuador pasa a facturarse desde otra sociedad, las facturas de ayer deben seguir diciendo lo que decían ayer. |
| **Opciones** | A) Resolver la entidad dinámicamente desde la configuración vigente. B) Persistir `legal_entity_id` en el documento. C) B + snapshot de los datos del emisor. |
| **Recomendación** | **C.** A es un error silencioso: no da error, no rompe nada, y falsifica retroactivamente todos los documentos históricos. La clave foránea sola tampoco basta: si la sociedad cambia de domicilio, la factura antigua debe seguir mostrando el domicilio antiguo. |
| **Impacto** | **Muy alto.** Es el punto más importante del addendum. |
| **Estado** | PROPUESTA |

### DEC-020 — Entidad que factura vs. entidad que liquida al creador
| | |
|---|---|
| **Contexto** | §12 del addendum pide separar ambos conceptos. Pero si divergen, aparece una **operación intercompañía** entre dos sociedades del grupo, con precios de transferencia, documentación ante la autoridad tributaria, consolidación contable y posible retención en la fuente. |
| **Opciones** | A) Una sola entidad para todo, sin modelar la separación. B) Modelar la separación y permitirla. C) Modelar la separación y **bloquearla por validación de negocio** en el MVP. |
| **Recomendación** | **C.** A cierra la puerta; B la deja abierta para que alguien la cruce sin darse cuenta un martes por la tarde y genere una contingencia fiscal. C conserva la flexibilidad del modelo y exige una decisión consciente, respaldada por asesoría fiscal, para habilitarla. |
| **Impacto** | Alto legal, bajo técnico. |
| **Estado** | PROPUESTA |

### DEC-021 — Series y numeración correlativa por entidad legal
| | |
|---|---|
| **Contexto** | En Perú la numeración por serie debe ser correlativa y sin huecos. Con varias sociedades hay varios contadores independientes, y bajo concurrencia dos facturas simultáneas pueden tomar el mismo número o saltarse uno. |
| **Opciones** | A) Autoincremento de base de datos. B) Contador en la tabla de configuración. C) Tabla propia `legal_entity_document_series` con asignación bajo bloqueo explícito. |
| **Recomendación** | **C.** A no permite series por entidad y deja huecos ante transacciones revertidas. B bloquea una fila que se lee constantemente. C aísla el punto de contención. |
| **Impacto** | Medio técnico, **alto en cumplimiento fiscal**. |
| **Estado** | PROPUESTA |

### DEC-022 — Monedas: no derivar del país de la entidad
| | |
|---|---|
| **Contexto** | §11 del addendum. CTS Perú puede facturar en PEN a un cliente peruano y en USD a uno ecuatoriano. |
| **Opciones** | A) Moneda = moneda del país de la entidad. B) Moneda por defecto de la entidad + monedas permitidas + preferida del cliente + moneda de campaña, con la del documento congelada al emitir. |
| **Recomendación** | **B.** Coherente con `BR-FIN-004` y `BR-FIN-009`, que ya exigían no asumir una sola moneda. |
| **Impacto** | Bajo (el modelo ya era multimoneda); solo añade la moneda por defecto de la entidad. |
| **Estado** | PROPUESTA |

### DEC-023 — Identidad dual en comunicaciones
| | |
|---|---|
| **Contexto** | ¿Los correos salen de "LATAM Social" o de "Soluciones Tecnológicas a Medida S.A.C."? |
| **Opciones** | A) Todo bajo la marca. B) Todo bajo la sociedad. C) Marca para lo operativo y de relación; sociedad para lo fiscal y contractual. |
| **Recomendación** | **C.** El creador y la marca cliente se relacionan con LATAM Social; las facturas, contratos y comunicaciones de cobranza deben identificar a la sociedad que responde legalmente. Implica remitente configurable por entidad para los correos de facturación. |
| **Impacto** | Medio en el módulo de plantillas de correo. |
| **Estado** | PROPUESTA |

---

## Decisiones introducidas por el addendum de integraciones (2026-08-21)

> Análisis completo en `docs/12-ADDENDUM-INTEGRATIONS.md`.

### DEC-024 — Las integraciones son un subsistema propio, no Settings
| | |
|---|---|
| **Contexto** | `DEC-018` ya definió una configuración jerárquica. La tentación es meter las integraciones dentro. |
| **Opciones** | A) Integraciones como ajustes de la cascada. B) Subsistema propio con su propio resolver, compartiendo el **vocabulario** de alcance. |
| **Recomendación** | **B.** Un ajuste resuelve un valor; una integración resuelve una conexión viva que tiene estado, ciclo de vida y que falla. Además necesita dos ejes más (`purpose`, `environment`). Lo que sí se unifica son los nombres de los niveles, para que nadie confunda "global" con "plataforma". |
| **Impacto** | Alto estructural. |
| **Estado** | PROPUESTA |

### DEC-025 — Proveedor / Conexión / Asignación como tres entidades
| | |
|---|---|
| **Contexto** | Un proveedor puede tener varias conexiones (producción, sandbox, por sociedad) y cada conexión puede aplicar a varios ámbitos. |
| **Opciones** | A) Una sola tabla de "integraciones". B) Tres entidades separadas. |
| **Recomendación** | **B**, tal como propone el addendum. `integration_providers` es catálogo respaldado por código (un proveedor solo existe si hay adaptador); `integration_connections` es la configuración viva; `integration_assignments` es el alcance, con `NULL` significando "cualquiera" y con vigencia (`valid_from`/`valid_to`) igual que la cobertura de facturación. |
| **Impacto** | Alto en el modelo. |
| **Estado** | PROPUESTA |

### DEC-026 — `purpose` es un enum cerrado en código, no un catálogo editable
| | |
|---|---|
| **Contexto** | El addendum lista los propósitos como si fueran datos administrables. |
| **Opciones** | A) Catálogo en base de datos. B) Enum cerrado en código. |
| **Recomendación** | **B.** El código se ramifica por propósito: `invoicing` implica una interfaz con `emit()`/`getStatus()`; `email_transactional` implica otra distinta. Un propósito creado desde el panel produciría una conexión perfectamente configurada que ningún código sabe ejecutar. Los catálogos editables son para datos de negocio; los propósitos son contratos de código. |
| **Impacto** | Medio. Evita una clase entera de fallos en tiempo de ejecución. |
| **Estado** | PROPUESTA |

### DEC-027 — Resolución por especificidad, con empates rechazados al guardar
| | |
|---|---|
| **Contexto** | El addendum propone una escalera de cuatro peldaños. Funciona, pero no contempla el eje de marca ni dice qué hacer ante un empate. |
| **Opciones** | A) Escalera fija codificada. B) Puntuación por especificidad (entidad 8, país 4, marca 2, `priority` como desempate), con la escalera del addendum saliendo como caso particular. |
| **Recomendación** | **B**, y sobre todo: **los empates no se resuelven en ejecución, se prohíben al guardar.** Si dos asignaciones activas puntúan igual para la misma combinación, la interfaz rechaza el guardado y explica el conflicto. Así el resolver es siempre determinista y nadie descubre un empate emitiendo una factura. |
| **Impacto** | Alto en corrección. |
| **Estado** | PROPUESTA |

### DEC-028 — Matriz de propósitos obligatorios por país
| | |
|---|---|
| **Contexto** | Descubrir que no hay conexión de facturación para Ecuador **al pulsar "emitir"**, con el cliente esperando, es un problema de diseño, no de configuración. |
| **Opciones** | A) Fallar en el momento de la operación. B) Declarar por país qué propósitos son imprescindibles y **bloquear la activación** de una entidad legal para ese país si falta alguno. |
| **Recomendación** | **B**, más un panel de cobertura en verde/rojo y una comprobación programada que avisa antes de que una credencial expirada rompa una operación. |
| **Impacto** | Medio técnico, alto operativo. |
| **Estado** | PROPUESTA |

### DEC-029 — Aislamiento de ambiente como barrera, no como filtro
| | |
|---|---|
| **Contexto** | El fallo más caro posible de este diseño: una conexión de producción resolviéndose fuera de producción. Consecuencias reales: emitir comprobantes fiscales de verdad desde QA, cobrar tarjetas en una demo, o enviar correos reales a 150 creadores desde staging. |
| **Opciones** | A) `environment` como un filtro más de la consulta. B) El resolver **lanza excepción** si el ambiente no coincide, con anulación temporal, permisionada y auditada para pruebas de humo; y en ambientes no productivos el correo pasa siempre por un capturador con independencia de la configuración. |
| **Recomendación** | **B.** La defensa no puede depender de que alguien haya configurado bien. |
| **Impacto** | **Muy alto.** Es la protección más importante del addendum. |
| **Estado** | PROPUESTA |

### DEC-030 — Cifrado sobre para las credenciales
| | |
|---|---|
| **Contexto** | El addendum señala que las credenciales pueden requerir almacenamiento distinto, sin concretar. |
| **Opciones** | A) Cifrado directo con la clave de la aplicación. B) Cifrado sobre: clave de datos por credencial, cifrada con una clave maestra que vive fuera de la base de datos. |
| **Recomendación** | **B**, con versionado en la rotación (no sobrescribir), escritura sin lectura desde la interfaz, y un filtro de redacción **a nivel del logger** — no "acordarse de no loguear". |
| **Impacto** | Medio técnico, alto en seguridad. |
| **Estado** | PROPUESTA |

### DEC-031 — Una URL de webhook por conexión
| | |
|---|---|
| **Contexto** | Con varias conexiones del mismo proveedor conviviendo, el payload entrante a menudo no identifica la sociedad, y probar firmas contra todos los secretos es lento e inseguro. El addendum enumera qué registrar de un webhook pero no resuelve de quién es. |
| **Opciones** | A) Un endpoint por proveedor, deduciendo la conexión del contenido. B) `POST /webhooks/{connection_uuid}` con identificador no adivinable. |
| **Recomendación** | **B.** El enrutado por URL hace la verificación de firma determinista y de un solo intento. Se acusa de inmediato (2xx) y se procesa en cola: un proveedor que no recibe respuesta en segundos reintenta y multiplica los eventos. |
| **Impacto** | Medio técnico, alto en fiabilidad. |
| **Estado** | PROPUESTA |

### DEC-032 — Compartibilidad declarada por propósito
| | |
|---|---|
| **Contexto** | El addendum permite que una conexión sirva a varias entidades legales. Correcto para almacenamiento o correo; peligroso para facturación. |
| **Opciones** | A) Permitirlo siempre y documentar la recomendación. B) Declarar `sharable` por propósito y **validar**: `invoicing`, `tax_authority`, `payment_collection` y `creator_payment` no admiten asignaciones que abarquen más de una sociedad. |
| **Recomendación** | **B.** Las credenciales de facturación pertenecen al contribuyente: una sociedad no puede emitir con el certificado de otra. Una validación se cumple; una recomendación en un manual, no. Reformula `BR-LE-008` sin contradecirla: lo prohibido era la herencia implícita; compartir por asignación explícita es otra cosa, y aun así sigue vedado para lo fiscal. |
| **Impacto** | Alto en cumplimiento. |
| **Estado** | PROPUESTA |

### DEC-033 — Registrar la conexión emisora en cada documento
| | |
|---|---|
| **Contexto** | Paralelo exacto de `DEC-019`. Si dentro de un año hay que consultar ante el proveedor el estado del comprobante F001-00347, hay que saber por cuál se emitió. |
| **Opciones** | A) Deducirlo de la configuración vigente. B) Persistir `integration_connection_id` en el documento, inmutable. |
| **Recomendación** | **B**, aplicado a comprobantes electrónicos, cobros por pasarela, pagos a creadores ejecutados por proveedor y correos enviados. |
| **Impacto** | Bajo esfuerzo, alto valor de trazabilidad. |
| **Estado** | PROPUESTA |

---

## Decisiones introducidas por el addendum de gamificación (2026-08-21)

> Análisis completo en `docs/13-ADDENDUM-GAMIFICATION.md`.

### DEC-034 — Gamificación como dominio propio (D13)
| | |
|---|---|
| **Contexto** | XP, niveles, insignias, ligas, retos y referidos para el creador. |
| **Opciones** | A) Repartir la lógica dentro de D3 Creator. B) Fusionarla con D12 Intelligence. C) Dominio propio **D13**, consumidor de `DomainEvent`, sin ser dependencia de nadie. |
| **Recomendación** | **C.** Igual que D12: si el motor se cae, la operación sigue igual. Y como se alimenta de eventos, el XP es **recalculable**: cambiar la tabla de puntos permite reprocesar el histórico. Si el XP se escribiera a mano en el momento, eso sería imposible. |
| **Coste** | Bajo: `DomainEvent` ya existe desde la iteración 2.1, introducido para el Creator Score. La gamificación es un segundo consumidor del mismo flujo. |
| **Estado** | PROPUESTA |

### DEC-035 — El XP es append-only y nunca decrece
| | |
|---|---|
| **Contexto** | ¿Se puede restar XP por un incumplimiento? |
| **Opciones** | A) Sí, penalizar restando. B) No: el XP solo sube, y las consecuencias negativas viven en el Creator Score. |
| **Recomendación** | **B.** Quitar puntos se percibe como robo y destruye la confianza de golpe. Además sería castigar dos veces con el mecanismo equivocado. Mismo patrón que el ledger financiero: se corrige con asientos nuevos, no editando. |
| **Estado** | PROPUESTA |

### DEC-036 — Se premia comportamiento verificado, no resultados
| | |
|---|---|
| **Contexto** | ¿Sobre qué se otorga XP? |
| **Opciones** | A) Resultados (engagement, alcance). B) Volumen (campañas aceptadas, seguidores). C) **Comportamiento que el creador controla y que el sistema verificó**: responder rápido, entregar a tiempo, subir evidencia, mantener datos al día. |
| **Recomendación** | **C.** A premia al algoritmo de Instagram, no al creador. B produce sobrecompromiso —creadores que aceptan lo que no pueden cumplir— y, en el caso de seguidores, desmotiva al micro-creador y premia el vector de fraude de `R-04`. Detalle en `docs/13 §3`. |
| **Estado** | PROPUESTA |

### DEC-037 — Los niveles se calculan, no se almacenan
| | |
|---|---|
| **Contexto** | Las curvas de nivel se recalibran; el XP ya otorgado no debe verse afectado. |
| **Opciones** | A) Guardar el nivel alcanzado. B) Nivel = función(XP acumulado, curva vigente). |
| **Recomendación** | **B.** Permite ajustar umbrales sin quitarle puntos a nadie, que es justamente lo que `DEC-035` prohíbe. |
| **Estado** | PROPUESTA |

### DEC-038 — Ranking por cohortes, sin tabla global
| | |
|---|---|
| **Contexto** | Una clasificación global con 1.000 creadores significa que 990 abren la app y ven que están perdiendo. Y con el tiempo se estanca: quien lleva más tiempo acumula más. |
| **Opciones** | A) Ranking global visible. B) Solo progreso personal. C) Progreso personal siempre + **ligas por cohorte acotada (~30) con temporadas**, premiando el ascenso y no la posición absoluta. |
| **Recomendación** | **C.** Los puntos de liga se reinician por temporada; el XP nunca. Sin ranking público por ingresos, jamás. Un ranking global solo tiene sentido en una vista interna, y ahí es un reporte, no gamificación. |
| **Impacto** | Alto en retención, que es el objetivo del sistema. |
| **Estado** | PROPUESTA |

### DEC-039 — Los retos internos llevan siempre recompensa tangible 🔴
| | |
|---|---|
| **Contexto** | "Minicampañas de XP por hacer vídeos para la plataforma". Leído desde el otro lado, puede sonar a *"produce contenido de marketing para mi empresa y te pago con puntos"* — a creadores que ya producen por dinero para tus clientes. En una red pequeña, ese daño reputacional se propaga en un grupo de WhatsApp. |
| **Opciones** | A) Solo XP. B) XP + recompensa tangible (dinero, producto, acceso prioritario o difusión pagada de su contenido), participación opcional declarada explícitamente en la interfaz, y cesión de derechos igual que en cualquier campaña. |
| **Recomendación** | **B, sin excepción.** El XP es el envoltorio y el reconocimiento; nunca el pago. Es la salvaguarda que la propia especificación anticipó en §16. |
| **Impacto** | **Reputacional alto.** Es el punto más delicado de todo el addendum. |
| **Estado** | PROPUESTA |

### DEC-040 — Referidos con consolidación diferida
| | |
|---|---|
| **Contexto** | Un programa de referidos con recompensa es, sin excepción, un imán de fraude: autorreferidos, cuentas falsas, granjas. |
| **Opciones** | A) XP al registrarse el invitado. B) **Consolidación diferida**: 0 al registrarse, parcial al ser aprobado, completo al completar su primera campaña. |
| **Recomendación** | **B**, más topes por periodo y verificación de que referente y referido no comparten documento, teléfono ni medio de pago. El vínculo se conserva siempre aunque el XP no se consolide: es dato de negocio. |
| **Estado** | PROPUESTA |

### DEC-041 — Gamificación y Creator Score no se fusionan
| | |
|---|---|
| **Contexto** | Ambos calculan un número sobre un creador a partir de los mismos eventos. La tentación de unirlos es enorme. |
| **Opciones** | A) Un único sistema de puntuación. B) Dos sistemas con **mismo origen (`DomainEvent`) y semántica opuesta**. |
| **Recomendación** | **B.** El Score es interno, puede bajar y sirve para decidir a quién invitar; el XP es externo, nunca baja y sirve para motivar. Un creador que incumplió una vez debe bajar en el score y **conservar** el XP que ganó antes. |
| **Estado** | PROPUESTA |

---

## Decisiones introducidas por la iteración 2.4 (2026-08-22)

### DEC-042 — 🔴 El servidor es MySQL 5.7 y **no aplica los `CHECK`** *(confirmado empíricamente)*

| | |
|---|---|
| **Contexto** | El servidor de la aplicación es **Percona Server 5.7.44-48** sobre cPanel. La base de desarrollo (`..._dev`) y la de producción viven en él. `docs/03` asumía MySQL 8. |
| **Evidencia** | No es una lectura de la documentación: `php artisan esquema:verificar` lo midió contra el servidor real el 2026-08-22. |

**Lo que devolvió el servidor:**

| Comprobación | Resultado |
|---|---|
| El motor aplica los `CHECK` de verdad | ❌ **Aceptó un valor prohibido** |
| Soporta CTE (`WITH`) | ❌ No disponible |
| Soporta funciones de ventana | ❌ No disponible |
| La base usa `utf8mb4` | ❌ `utf8` / `utf8_unicode_ci` |
| Versión | 5.7.44-48 |

Y en el mismo comando, las **9 comprobaciones de esquema en verde**: las tablas se crearon
correctamente, en InnoDB, en `utf8mb4`, sin `SET NULL`, sin `ENUM`, sin `FLOAT`. La migración es
impecable. Simplemente **la mitad de las reglas que declara no existen en ese servidor**.

| | |
|---|---|
| **Impacto — `CHECK`** | Las 12 restricciones verificadas en 2.4 son decorativas aquí. Un tipo de cambio negativo, un `user_type` inventado o un JSON malformado entrarían sin resistencia. |
| **Impacto — ventanas** | `ROW_NUMBER()` y `RANK()` son exactamente lo que necesitan el ranking de creadores y las **ligas de gamificación** (`docs/13`). Sin ellas hay que calcularlo en PHP o materializarlo por trabajos programados. |
| **Impacto — CTE** | `docs/03` da `WITH` por disponible para D12 Intelligence. Hay que reescribir esas consultas con subconsultas o tablas temporales. |
| **Impacto — charset** | Menor de lo que estimé: las tablas **sí** se crearon en `utf8mb4` porque Laravel lo fuerza por tabla, así que los emoji funcionan. Lo que está en `utf8` es el **valor por defecto de la base**, que afecta a lo que se cree fuera de Laravel. Se corrige con un `ALTER DATABASE`. |
| **Soporte** | MySQL 5.7 y Percona 5.7 terminaron su vida útil en **octubre de 2023**. |
| **Opciones** | **A)** Pedir al hosting MySQL 8 / Percona 8. **B)** Base gestionada externa. **C)** Quedarse en 5.7: `CHECK` → `TRIGGER`, ventanas y CTE → PHP o tablas materializadas. **D)** Validar solo en PHP. |
| **Recomendación (revisada 2026-08-22)** | **A queda descartada.** El servidor aloja más de 20 sitios en producción. Subir el motor entero de 5.7 a 8 los expone a todos —palabras reservadas nuevas, mezclas ilegales de intercalación, desaparición de la caché de consultas, cambio del plugin de autenticación— y **no hay vuelta atrás sin restaurar un volcado**. Arriesgar 20 sitios que funcionan para beneficiar a uno que aún no existe es una relación riesgo/beneficio mala. |
| **Nueva recomendación** | **B o C.** **B:** base MySQL 8 separada solo para este proyecto (~15 USD/mes gestionada), cPanel intacto. **C:** quedarse en 5.7 con un **compilador de restricciones** que emita `CHECK` donde el motor lo aplique y un `TRIGGER` equivalente donde no, desde una única declaración en la migración. **D sigue descartada.** |
| **Por qué C ya no es solo contingencia** | Mi objeción a C era "~40 triggers que mantener a mano". Deja de aplicar si no se escriben a mano: una clase `Restriccion` genera el `TRIGGER` a partir de la misma expresión que declara el `CHECK`, y el esquema se lee igual de claro. El coste pasa a ser tiempo de desarrollo una sola vez, no deuda permanente. |
| **Lo que C no resuelve** | Las funciones de ventana y los CTE siguen ausentes: el ranking y las ligas se calculan por trabajos programados sobre tablas materializadas —que ya estaban previstas como caché en `2.3 §5`— y las consultas de Intelligence se escriben con subconsultas. Y el motor sigue sin parches de seguridad desde 2023, que es un riesgo que ese servidor **ya tiene hoy** con los otros 20 sitios. |
| **Impacto en el plan** | Bloquea **2.7 (Finance)**. Iteraciones 2.5 (Creator), 2.6 (Client y Campaign) siguen sin cambios. |
| **Estado** | 🔴 CONFIRMADA — pendiente de elegir entre B y C |

---

### DEC-043 — Dónde vive la base de datos de desarrollo

| | |
|---|---|
| **Contexto** | Se propone usar **el mismo servidor** que producción, con dos bases separadas: una de desarrollo y otra de producción. No es trabajar contra los datos reales; es usar el mismo motor. Ya hay otras aplicaciones Laravel funcionando en ese servidor sin incidencias. |
| **Lo que resuelve** | Es la alineación de motor bien hecha: desarrollo corre exactamente el mismo Percona 5.7 que producción, así que ninguna diferencia de motor puede esconderse hasta el despliegue. `esquema:verificar` diría la verdad desde el primer día. |
| **Lo que cuesta** | **Latencia.** Cada consulta es un viaje de ida y vuelta por internet. Una migración con 16 tablas se nota; una suite de pruebas con `RefreshDatabase`, que recrea el esquema en cada clase de test, pasa de segundos a minutos. También exige *Remote MySQL* abierto a tu IP —que cambia— y no se puede trabajar sin conexión. |
| **Opciones** | **A)** Solo base remota de desarrollo. **B)** Solo base local. **C)** Las dos: local para el ciclo rápido y las pruebas, remota para verificar antes de desplegar. |
| **Recomendación** | **C.** Es lo que hace un equipo profesional y no cuesta más que A: el motor local se instala una vez. El bucle interno —migrar, probar, deshacer, repetir— tiene que ser rápido o se deja de ejecutar, y unas pruebas que tardan minutos son unas pruebas que nadie corre. La base remota es el ensayo general, no el banco de trabajo. |
| **Qué motor va en local** | El mismo que quede en producción tras `DEC-042`. Si el hosting sube a MySQL 8, local va a MySQL 8. Si se queda en Percona 5.7, local va a 5.7 — con la consecuencia de que **los `CHECK` tampoco se aplicarán en desarrollo** y habrá que pasar a `TRIGGER`. |
| **Estado** | ACORDADA en su intención; el motor concreto depende de `DEC-042` |

> **Por qué las otras aplicaciones Laravel no revelaron el problema de los `CHECK`:** el constructor de
> esquemas de Laravel **no genera restricciones `CHECK` por sí solo**. Una aplicación Laravel normal no
> declara ninguna, así que un motor que las ignora se comporta de forma idéntica a uno que las aplica.
> Que ese servidor lleve años funcionando bien es cierto y no dice nada sobre este punto concreto: es la
> primera vez que se le pide algo que 5.7 no hace.

---

### DEC-044 — Todo pago pertenece a un lote (no existen pagos sueltos)

| | |
|---|---|
| **Contexto** | La primera versión de `payouts` permitía `payout_batch_id NULL`, pensando en el pago puntual fuera de la corrida quincenal. |
| **El problema** | `BR-FIN-005` (doble aprobación, quien crea no aprueba) vive en `payout_batches`. Si un pago puede existir sin lote, **la segregación de funciones se evita simplemente no creando lote**. No hace falta malicia: basta un servicio que cree el `payout` directamente porque era «más simple». |
| **Decisión** | `payout_batch_id` es `NOT NULL`. **Un pago único es un lote de uno.** |
| **Lo que cuesta** | Una fila extra en `payout_batches` por cada pago fuera de corrida, y que el flujo de pago puntual tenga que crear el lote. Es barato. |
| **Lo que compra** | Que no exista ningún camino en el sistema —ni por pantalla, ni por consola, ni por SQL directo— que mueva dinero a un creador sin haber pasado por dos personas distintas. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 2.13) |

---

### DEC-045 — La inmutabilidad financiera se impone con disparadores, no con convención

| | |
|---|---|
| **Contexto** | `BR-FIN-008` («ningún registro financiero se elimina físicamente») y `BR-FIN-002` («una corrección es un asiento de reversión») eran hasta ahora reglas de la capa de aplicación. |
| **El problema** | Una regla que solo vive en el código PHP la incumple cualquiera con acceso a la base: un cliente SQL, un `seeder`, un comando de mantenimiento, una migración de urgencia un viernes. Y no deja rastro de que se incumplió. |
| **Decisión** | Siete disparadores `BEFORE UPDATE` / `BEFORE DELETE` sobre `ledger_entries`, `invoices`, `invoice_lines`, `payouts`, `payments` y `campaign_costs`. En `ledger_entries` el `BEFORE UPDATE` compara las 16 columnas inmutables con `<=>` y solo deja pasar cambios de `status`. |
| **Nota técnica** | Estos disparadores **no** son restricciones del compilador `Restriccion`: van igual en los dos motores, porque expresan algo que ningún `CHECK` puede expresar — prohibir un *verbo*, no un valor. |
| **Matiz deliberado** | Un borrador sí se borra: una `invoice` en `draft` y un `payout` en `pending` no han salido al mundo. La inmutabilidad empieza cuando el documento existe para un tercero. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 2.13) |

---

### DEC-046 — La base impone lo que ve en una fila; el resto lo impone el repositorio

| | |
|---|---|
| **Contexto** | Cinco invariantes de finanzas son de varias filas o varias tablas (suma de líneas = subtotal, cobros ≤ total facturado, moneda de asiento = moneda de su `payout`, signo de una reversión, saldo no negativo). Ningún `CHECK` puede expresarlas. |
| **Opciones** | **A)** Disparadores con subconsulta a otras tablas. **B)** Dejarlas al repositorio, con pruebas de integración. |
| **Decisión** | **B.** |
| **Por qué** | Un disparador con `SELECT` sobre otra tabla se ejecuta en cada fila, no es portable del mismo modo que el resto del compilador, y —lo decisivo— convierte la base en un lugar donde vive lógica de negocio que nadie encuentra al depurar. La línea es: *la base impone lo que puede comprobar mirando una sola fila.* |
| **El riesgo que se acepta** | Que alguien vea 34 restricciones y dé por cubierto lo que no lo está. Por eso las cinco están **listadas explícitamente** en `docs/fase-2/2.13-FINANZAS.md §4`, no escondidas. |
| **Estado** | ADOPTADA (2026-08-22, iteración 2.13) |

---

### DEC-047 — Se factura a todos los países desde Perú

| | |
|---|---|
| **Contexto** | Respuesta a `Q-42`. En lugar de constituir sociedad en cada país, se emiten todos los comprobantes desde la sociedad peruana. Los países se resuelven uno a uno más adelante. |
| **Lo que resuelve** | Elimina de golpe la incertidumbre legal multi-país que `Q-42` dejaba abierta: la correlatividad de comprobantes es la de SUNAT y solo la de SUNAT. Las demás `legal_entities` quedan latentes, sin borrarse. |
| **Lo que NO elimina** | **Que el régimen tributario deje de ser constante.** Facturar desde Perú a un cliente del exterior no es lo mismo que facturar a un cliente peruano: la primera califica como **exportación de servicios** y va **sin IGV**; la segunda va gravada al 18 %. Es una decisión **por factura**, no un valor de configuración. |
| **Consecuencia en el modelo** | `invoices.tax_regime` (`gravado` / `exportacion` / `exonerado` / `inafecto`) y `invoices.receiver_country_snapshot`. Tres restricciones nuevas: una exportación no lleva impuesto, no se exporta a un domiciliado en Perú, y el régimen es de un catálogo cerrado. Guardar solo `tax_amount` habría perdido **el porqué** de ese importe — que es exactamente lo que pregunta una fiscalización tres años después. |
| **Requisito operativo que abre** | Según SUNAT, para que la operación califique como exportación de servicios hay que estar inscrito previamente en el **Registro de Exportadores de Servicios**. Es un trámite, no código, y **es previo a la primera factura al exterior**. → `T-07`. |
| **Límite conocido y aceptado** | `tax_regime` es **por factura, no por línea**. Una factura que mezcle líneas gravadas y exoneradas no es representable. No es un caso real de esta plataforma (se factura un servicio de campaña); si aparece, el campo baja a `invoice_lines`. Queda escrito para que nadie lo descubra por sorpresa. |
| **Estado** | ADOPTADA e implementada (2026-08-22) |

> ⚠️ **§56 — supuesto legal.** Que los servicios de marketing de influencers prestados a un cliente no
> domiciliado califiquen como exportación depende de las condiciones concurrentes del art. 33-A de la Ley
> del IGV (uso o explotación en el exterior, entre otras) y de la lista de servicios aplicable, cuya
> vigencia he visto contradicha entre fuentes. **El modelo admite las cuatro opciones y no fuerza
> ninguna: quién decide el régimen de cada factura es el contador, no el código.** Antes de la primera
> factura al exterior hay que confirmarlo. → `Q-44`.

---

### DEC-048 — La retención tiene tres estados, no dos

| | |
|---|---|
| **Contexto** | Sobre `Q-40` se decidió «que el modelo lo deje configurable». Al implementarlo apareció que el riesgo que yo había señalado —*«alguien pone un número a ojo y nadie se entera»*— **ya estaba dentro del modelo**, y era peor. |
| **El fallo** | `withholding_applies TINYINT(1) NOT NULL DEFAULT 0`. **«No se retiene» y «nadie lo ha mirado todavía» eran el mismo valor.** Un perfil se aprobaba con el defecto puesto —porque nadie sabía qué poner, que es literalmente la situación de `Q-40`—, el pago salía sin retención, y un olvido y una decisión producían la misma fila. |
| **Por qué importa** | Es el error más caro que hay en un modelo de datos: **un valor por defecto que parece una respuesta.** No falla, no avisa, y cuando alguien pregunta ya han pasado dos años de pagos. |
| **Decisión** | `withholding_status` ∈ (`pending_review`, `not_applicable`, `applies`), partiendo de `pending_review`. Un perfil fiscal **no se aprueba** con la retención sin decidir (`ck_ctp_withholding_decided`). Además `withholding_basis` es obligatorio cuando se retiene: la tasa tiene que citar la norma que la sustenta. |
| **Y en el mayor** | Un asiento de retención congela tasa y norma (`ck_ledger_withholding`), y el disparador de inmutabilidad las cubre. Cuando `Q-40` se responda y la tasa cambie, las retenciones de ayer seguirán explicándose con la tasa de ayer. |
| **Efecto sobre `Q-40`** | No la resuelve — nadie inventa la tasa. La hace **doler donde toca**: mientras no haya respuesta, el perfil no se aprueba y el bloqueo es visible, en vez de un pago silencioso sin retención. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 2.15) |

---

### DEC-049 — Sin datos fiscales no hay alta, pero acompañamos a formalizarse

| | |
|---|---|
| **Contexto** | Respuesta a `Q-45`. La propuesta inicial era pagar a un familiar del creador con declaración jurada de parentesco. El análisis de `docs/fase-2/2.14-PAGO-A-TERCEROS.md` identificó tres problemas: el comprobante deja de coincidir con el pago (riesgo de gasto reparable **para la empresa que paga**), señales de alerta de prevención de lavado, y contradicción con `BR-FIN-003`, que ya está implementada. |
| **Decisión** | **Opción B.** Se mantiene `Q-33` —sin datos fiscales vigentes no hay alta— y se acompaña al creador a formalizarse. En Perú obtener RUC y emitir recibo por honorarios es gratuito y en línea. |
| **Por qué B y no C** | C (cesión de derechos de cobro) es la figura legalmente correcta si esto tuviera que existir, pero exige contrato y no elimina la obligación del creador. B resuelve el problema de raíz en vez de rodearlo, y deja al creador formalizado para todas las campañas siguientes, no solo la primera. |
| **Lo que cuesta** | Un instructivo de formalización y algo de soporte del equipo de captación. Contenido, no código. → `T-08`. |
| **Lo que NO se implementa** | El pago a un tercero. `2.14-PAGO-A-TERCEROS.md` se conserva como registro de por qué. |
| **Estado** | ADOPTADA (2026-08-22) |

---

### DEC-050 — Las migraciones se contrastan contra el esquema de referencia, no se confía en que coincidan

| | |
|---|---|
| **El hueco** | Durante toda la Fase 2 se probó el **SQL de referencia** (125 aserciones) y, por separado, se verificó que las migraciones declararan las mismas **restricciones**. **Nunca se comprobó que declararan las mismas columnas.** Y las migraciones nunca se ejecutaron: `composer` no llega a packagist desde el entorno de trabajo. |
| **Por qué es grave** | Una columna que esté en el SQL de referencia y falte en la migración **no la detecta ninguna prueba de restricción**: las 125 siguen en verde, porque corren contra el SQL, mientras la aplicación real trabaja sobre una tabla que no tiene ese campo. El verde es real y no significa nada. |
| **Decisión** | `tools/recolectar-esquema.php` graba lo que cada migración **declara** (columnas, tipos, nulabilidad, índices, claves foráneas, incluido el SQL crudo de las columnas generadas) y `tools/verificar-migraciones.py` lo contrasta contra `information_schema` de la base cargada desde el SQL de referencia. |
| **Límite explícito** | Compara **intención declarada**, no el DDL que Laravel emite. No sustituye a ejecutar `php artisan migrate`; lo que hace es que, cuando se ejecute, no haya sorpresas de forma. |
| **Lo que encontró de entrada** | Una divergencia real: los índices de `social_account_snapshots` se llamaban `ix_social_account_snapshots_*` en el SQL y `ix_sas_*` en la migración. El escáner de colisiones solo mira el SQL, así que una colisión introducida por el lado de las migraciones habría pasado desapercibida. Alineados los dos a `ix_sas_*`. |
| **Falso positivo instructivo** | El contraste marcó `audit_logs.ip_address` como `BLOB` en la migración frente a `VARBINARY(16)` en el SQL. Era **mi grabador**, no la migración: `binary($col, length: 16)` sí produce `VARBINARY(16)` desde Laravel 11. Confirmado en la documentación antes de tocar nada — el instinto de "corregir" el código habría roto lo que estaba bien. |
| **Estado** | ADOPTADA e implementada (2026-08-22) |

---

### DEC-051 — El CI corre la suite contra los dos motores, no contra uno

| | |
|---|---|
| **Contexto** | El CI validaba formato, análisis estático, fronteras entre módulos, migraciones y pruebas. Nada de eso comprueba que las restricciones se apliquen **en el motor de producción**, que no aplica `CHECK`. |
| **Decisión** | El flujo de trabajo carga el esquema de referencia y la copia sin `CHECK` con triggers generados, y ejecuta **la misma suite contra las dos**, más los cuatro verificadores. Si divergen, el CI se pone rojo. |
| **Fallo encontrado al escribirlo** | Los scripts de prueba tenían el cliente fijo a `mariadb` sin credenciales. En Actions el cliente es `mysql -h127.0.0.1 -uroot -proot`: **el CI habría fallado entero en el primer `INSERT`**, o peor, cada fallo de conexión se habría contado como «rechazo» —el fallo del arnés corregido esta misma sesión— y habría salido **verde sin ejecutar nada**. Ahora el cliente sale de `MYSQL_CMD`. |
| **Estado** | ADOPTADA (2026-08-22). Recordatorio: `tools/github-workflow-ci.yml` sigue pendiente de mover a `.github/workflows/ci.yml` a mano — es ruta protegida |

---

### DEC-052 — MySQL no admite subconsultas sobre la tabla que se está modificando

| | |
|---|---|
| **Cómo apareció** | El CI, sobre MySQL 8. Dos aserciones fallaban en `2.13-finanzas.sh` que en local (MariaDB) pasaban desde el primer día. |
| **La limitación** | MySQL rechaza con el **error 1093** (`You can't specify target table '<t>' for update in FROM clause`) cualquier sentencia que lea la misma tabla que está modificando: `UPDATE t ... WHERE id = (SELECT id FROM t ...)`, y lo mismo en `DELETE` e `INSERT ... VALUES`. **MariaDB lo permite.** |
| **Por qué NO es solo un problema de las pruebas** | Producción es **Percona 5.7**, que es MySQL, no MariaDB. La limitación aplica igual. Cualquier consulta del repositorio con esa forma —«marcar como pagadas las facturas cuyo saldo sea cero», «anular los costos del último lote»— **funcionará en desarrollo y reventará en producción**. Es el mismo patrón que `DEC-042`, en otra capa. |
| **El rodeo, portable en los dos motores** | Envolver la subconsulta en una tabla derivada, que se materializa antes: `WHERE id = (SELECT id FROM (SELECT id FROM t WHERE ...) x)`. |
| **Regla para la Fase 3** | Ninguna consulta de escritura lee la tabla que modifica sin pasar por una tabla derivada. En Eloquent aparece al usar `whereIn(..., fn ($q) => $q->from('misma_tabla'))`: hay que resolver los ids en una consulta aparte, o envolver. |
| **Lo que lo hizo visible** | Que el CI corra las suites **en el motor de producción y no solo en el de desarrollo**. Con MariaDB sola, esto se descubre el día del despliegue. |
| **Efecto secundario que también salió** | Una de las aserciones estaba **pasando por el motivo equivocado**: daba RECHAZO por el error 1093, no por el disparador que pretendía comprobar. Un arnés que no distingue el motivo del rechazo miente en verde. |
| **Estado** | ADOPTADA (2026-08-22) |

---

### DEC-053 — Autorización por permiso, sin atajo para el administrador

| | |
|---|---|
| **El agujero** | Desde 2.4 existían `roles`, `permissions` y sus pivotes, y se sembraban 15 permisos y 6 roles. Pero **`permission_role` estaba vacía** y no había middleware: las rutas solo exigían `auth`. La infraestructura de autorización estaba construida y **desconectada**. Cualquiera con sesión llegaba a todo. |
| **Por qué no se notaba** | Solo había un usuario, el administrador. Que es exactamente cómo estos agujeros llegan a producción. |
| **Decisión** | Middleware `permiso:<codigo>` en cada ruta de negocio; el código pregunta por el **permiso**, nunca por el rol; `permission_role` sembrada con la matriz de `docs/fase-3/3.1-PERMISOS.md §3`. |
| **Lo que se rechazó** | `Gate::before(fn ($u) => $u->esAdmin() ? true : null)`. Cómodo, pero abre un agujero que ninguna prueba detecta: el rol tendría en silencio permisos que nadie le concedió, incluidos los futuros. `admin` recibe todos los permisos **como filas**, y la comprobación no tiene casos especiales. Así «quién puede aprobar un lote» se responde con una consulta, no leyendo código. |
| **403 y no 404** | Ocultar el recurso es defendible en una API pública; en un back-office de usuarios internos y conocidos, un 404 solo consigue que quien no tiene permiso crea que la pantalla está rota. La vista dice qué permiso falta. |
| **Guarda estructural** | `RutasProtegidasTest` falla si alguna ruta tras `auth` no declara permiso. Olvidar el middleware al añadir una pantalla **no falla**: la pantalla funciona, y se nota cuando alguien ve lo que no debía. Las excepciones (`panel`, `salir`) están escritas con su motivo, y otra prueba vigila que no crezcan. |
| **⚠️ Para tu revisión** | Dos concesiones son criterio de negocio: que `campaign_manager` vea el **margen interno** (`BR-FIN-007`) y que `finance` vea **datos fiscales y cuentas bancarias**. La segunda es inevitable para poder pagar; la primera es discutible y se quita con una línea. |
| **Nota sobre `BR-FIN-005`** | El rol `finance` tiene `payout.create` y `payout.approve` a la vez. No es descuido: la regla habla de **usuarios**, y `ck_pbatch_segregation` impide en la base que la misma persona cree y apruebe el mismo lote. Consecuencia intencionada: **finanzas necesita al menos dos usuarios** para operar. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 3.1) |

---

### DEC-054 — La bitácora es inmutable en la base, no por convención

| | |
|---|---|
| **Contexto** | `audit_logs` existía desde 2.4 y **nadie escribía en ella**. Al ir a llenarla apareció el segundo problema: la tabla admitía `UPDATE` y `DELETE` como cualquier otra. |
| **La regla del cliente** | «El registro de auditoría no debe ser fácilmente modificable desde la aplicación.» Hasta ahora era un comentario en la migración, no un hecho. |
| **Decisión** | Dos disparadores `BEFORE UPDATE` / `BEFORE DELETE` sobre `audit_logs` que abortan siempre. Mismo criterio que `ledger_entries` (`DEC-045`): prohibir un **verbo** no lo puede expresar ningún `CHECK`, así que van iguales en los dos motores y sin pasar por el compilador de restricciones. |
| **Comprobado** | Insertar sí, actualizar y borrar no — en MariaDB y en la copia sin `CHECK` con triggers generados. |
| **Consecuencia operativa** | La retención de la bitácora se aplicará por proceso (exportar y truncar con un usuario distinto), nunca con un `DELETE` desde la aplicación. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 3.2) |

---

### DEC-055 — La primera pantalla de escritura no toca la identidad

| | |
|---|---|
| **Contexto** | Primera pantalla del proyecto que escribe: edición de creador. |
| **Decisión** | Solo se editan contacto y preferencias comerciales (`display_name`, `phone`, `city`, `payment_term_days`, `preferred_currency_code`, `locale`, `timezone`). **No** se editan nombre legal, fecha de nacimiento, documento, correo ni estado. |
| **Por qué** | Cambiar el documento o la fecha de nacimiento no es corregir un dato: es decir que se trata de otra persona, o corregir un error que necesita evidencia y aprobación — lo mismo que `BR-CREATOR-007` ya exige para lo fiscal. Además son clave de `uq_creators_identity`. El `status` tiene su propia tabla de transiciones y su flujo: un `<select>` con «blacklisted» dentro de un formulario de contacto es una mala idea. |
| **Cómo se impone** | `validated()` devuelve **solo** lo declarado en las reglas del `FormRequest`. Omitir campos del formulario no protege nada —enviarlos a mano es trivial—; lo que protege es no usar nunca `$request->all()`. Hay una prueba que envía documento, correo, nombre legal y `status=blacklisted` a la vez y verifica que ninguno se movió. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 3.2) |

---

### DEC-056 — La bitácora se ordena por `id`, no por fecha, y redacta lo sensible

| | |
|---|---|
| **Orden** | El listado por defecto usa `ORDER BY id DESC`. La clave primaria ya es monótona con la inserción, así que recorrerla al revés sale gratis y sin `filesort`. **Y hay un motivo de corrección, no solo de velocidad:** `occurred_at` empata —dos entradas del mismo milisegundo saldrían en orden arbitrario, y en una paginación eso significa filas que se repiten o desaparecen entre páginas—. `id` no empata nunca. |
| **Índice** | Se añade `ix_audit_logs_occurred` para lo que el orden no resuelve: **filtrar** por rango de fechas. Sin él, ese filtro escanea una tabla que solo crece. |
| **Filtro de acción** | Por **prefijo** (`like 'creator.%'`), no por contención. Un `%x%` no usa índice; en auditoría eso se nota el día que hay millones de filas, no antes. |
| **Redacción** | Regla del cliente: «no guardar información sensible innecesariamente en logs». Hasta ahora dependía de que quien llamara recordara no auditar la columna equivocada — eso no es una política, es una esperanza. Ahora, si el nombre del campo contiene `password`, `token`, `secret`, `api_key`, `account_number`, `encrypted`, `fingerprint`, `card` o `cvv`, **el valor no se escribe**: queda `[redactado]`. |
| **Qué sí se conserva** | El **nombre del campo**. Saber que alguien tocó la cuenta bancaria es información de auditoría; saber cuál era, no. Un `account_number_encrypted` en claro en la bitácora anularía el cifrado de la tabla de origen. |
| **Actor congelado en pantalla** | Se muestra `actor_label` (nombre y correo de entonces), no el nombre actual vía `JOIN`. Lo segundo habría reescrito el pasado cada vez que alguien corrige una errata en su nombre. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 3.3) |

---

### DEC-057 — Aprobar una solicitud no activa al creador

| | |
|---|---|
| **Decisión** | Aprobar crea al creador en **`pending`**, nunca en `active`. |
| **Por qué** | `BR-CREATOR-006` exige completitud operativa mínima: identidad verificada, una red social validada, datos fiscales y un medio de pago verificado. Nada de eso existe en el momento de aprobar. |
| **El error que evita** | Un creador «aprobado» al que se invita a una campaña, que entrega el contenido, y al que después **no se le puede pagar** porque nunca verificó una cuenta bancaria. El problema aparece al final, con un cliente esperando. |
| **Duplicados (`BR-CREATOR-003`)** | Se avisa al abrir la solicitud (solo por correo, que es lo único que trae) y se **comprueba en el servidor al aprobar** (correo y documento, ya tecleado). La casilla de «confirmo que revisé» **no salta la comprobación**: dice que el revisor miró, no le da permiso para crear una colisión. Una confirmación que desactiva una validación es una validación que no existe. |
| **Identidad tecleada a mano** | El nombre legal, la fecha de nacimiento y el documento los introduce el revisor. No es pereza: es el punto donde una persona se hace responsable de que el documento coincide con quien dice ser — por eso queda en la bitácora con su nombre, y por eso `DEC-055` no deja editarlos luego desde la ficha de contacto. |
| **Rechazo con explicación obligatoria** | Mínimo 10 caracteres. Un rechazo sin motivo no se puede comunicar al creador ni auditar. Dos motivos distintos: `rejected` cierra la puerta, `duplicate` apunta a que ya está dentro. |
| **Estado** | ADOPTADA e implementada (2026-08-22, iteración 3.4) |

---

### DEC-058 — La identidad verificada es evidencia, no una casilla

| | |
|---|---|
| **Decisión** | Verificar la identidad de un creador escribe **tres** columnas en `creators`: cuándo, **quién** y **con qué documento archivado**. Un `CHECK` obliga a que vayan las tres o ninguna. |
| **El hueco que cierra** | `BR-CREATOR-006` exige «identidad verificada» desde la iteración 2.1 y **no había dónde anotarlo**. Lo único parecido era `identity_gate`, que es una columna generada para que dos creadores no compartan documento — nada que ver. Una condición que no se puede registrar no se comprueba, así que la regla decía otra cosa de la que estaba escrita. |
| **Alternativa descartada** | Una sola columna booleana `identity_verified`. Dice *«alguien lo miró»* y no dice quién ni con qué. Dentro de dos años eso no se puede defender ante nadie. |
| **Decisión del negocio** | Consultada expresamente (`§107`, `§56`): el negocio eligió **«marca del revisor + documento adjunto»** frente a un simple indicador. |
| **Lo que además blinda** | `ck_creators_active_identity`: un creador `active` sin identidad verificada **lo rechaza la base**, no la aplicación. De las cinco condiciones de `BR-CREATOR-006` es la única que vive en la propia fila y por tanto la única que un `CHECK` puede imponer. Que solo se pueda blindar una no es razón para no blindarla: un `UPDATE` a mano en una consola queda fuera. |
| **De paso** | `ck_creators_activation` obliga a que un creador activo tenga `activated_at`. La columna existía desde 2.3 y era decorativa: las dos filas «activas» de la semilla de pruebas no tenían fecha y la base las aceptaba. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.5) |

---

### DEC-059 — Se acepta una versión de los términos, no una página

| | |
|---|---|
| **Decisión** | Dos tablas: `terms_versions` (documento, versión, vigencia y **huella `sha256`** del contenido) y `terms_acceptances` (**solo INSERT**: quién aceptó qué versión, cuándo, por qué canal, con qué evidencia y quién lo registró). |
| **El hueco que cierra** | Dos reglas escritas desde la fase 1 sin ninguna tabla detrás: `BR-CREATOR-006` («aceptación vigente de los términos») y `BR-PRIV-001` («cada consentimiento se registra con su texto versionado, fecha, canal y evidencia»). Busqué `terms`, `consent` y `accept` en las 62 tablas del modelo: nada. |
| **Por qué versionar** | Un texto que se edita en su sitio deja todas las aceptaciones anteriores apuntando a algo que ya no existe. Quien aceptó en enero aceptó otra cosa, y no hay forma de demostrar cuál. |
| **NO hay revocación, y es deliberado** | `terms_acceptances` no tiene `revoked_at`. Lo vigente es *la aceptación de la versión vigente*: publicar unos términos nuevos cierra los anteriores y, en ese instante, todas las aceptaciones viejas dejan de contar **solas**. Es exactamente lo que se compra al versionar; un `revoked_at` sería pagarlo dos veces y confiar en que alguien se acuerde de la segunda. |
| **«Aceptó» no es la palabra de quien teclea** | `ck_terms_acceptances_backing`: si el canal no es `portal`, hacen falta **revisor y archivo adjunto**. Y `ck_terms_acceptances_portal` cierra el atajo evidente —marcar `portal` para librarse de adjuntar nada— exigiendo que en el portal no haya nadie registrando en nombre de otro. |
| **Decisión del negocio** | Consultada expresamente: el negocio eligió **«tabla versionada; el revisor registra la aceptación»**. Cuando exista el portal del creador, la misma tabla se llena sola con `channel='portal'` y sin operador. No habrá que tocar nada. |
| **Los términos NO se siembran** | Sería cómodo dejar un texto de relleno en `CimientosSeeder` para desbloquear la puerta. Sería también un texto inventado por el equipo técnico convertido en «lo que el creador aceptó», que es lo que `§56` prohíbe. En su lugar hay `php artisan terminos:publicar`, que lo hace quien tiene el documento legal delante. Ver **T-09**. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.5) |

---

### DEC-060 — Verificar y activar son permisos distintos, pero hoy el mismo rol

| | |
|---|---|
| **Decisión** | Dos permisos: `creator.verify` (registrar evidencia de identidad y de términos) y `creator.activate` (activar al creador). Ambos se conceden hoy a `admin` y a `campaign_manager`. **No** se exige que sean personas distintas. |
| **Por qué separados** | Registrar evidencia es trabajo de reclutamiento; activar abre las campañas y los pagos. Fundirlos ahora no ahorra nada y separarlos después obligaría a repasar cada ruta. |
| **Por qué sin segregación de personas** | El equipo de reclutamiento es pequeño. Exigir dos personas por cada alta pararía el embudo, y el daño de un error aquí es reversible: se suspende al creador. La segregación estricta se reserva para el dinero (`DEC-044`, `BR-FIN-005`), donde el daño no se deshace. |
| **A revisar si** | El volumen de altas crece o aparece un incidente de identidad falsa. La restricción sería idéntica a la de los lotes de pago y el modelo ya la sabe expresar. |
| **Lo que sí queda separado** | `finance` **no** puede activar, aunque vea datos fiscales y bancarios (`DEC-053`). Hay una prueba que lo fija. |
| **Estado** | ADOPTADA (2026-08-23, iteración 3.5) — decisión de negocio revisable |

---

### DEC-061 — Las pruebas usan una base local y desechable, nunca la de desarrollo

| | |
|---|---|
| **Decisión** | `phpunit.xml` declara su propia conexión: `latam_social_test` en `127.0.0.1`. Las pruebas no tocan jamás la base a la que apunta el `.env`. |
| **El fallo que corrige** | `phpunit.xml` no declaraba ninguna base, así que las pruebas heredaban el `DB_DATABASE` del `.env` — que apunta al **servidor remoto de desarrollo**. Y `RefreshDatabase` empieza siempre por `migrate:fresh`, que es un `DROP` de todas las tablas. **Cada `php artisan test` destruía la base de desarrollo.** |
| **Y además tardaba** | Creaba 64 tablas y 161 restricciones por Internet contra un hosting compartido, una sentencia por viaje de ida y vuelta: la suite se quedaba colgada. En local tarda **60 segundos**. |
| **Desde cuándo** | Desde la iteración 3.1, la primera con pruebas. No dolía con pocas tablas y sin datos; con el modelo completo, sí. Es un fallo mío que debí ver entonces. |
| **Por qué no sqlite en memoria** | Es lo que suele hacerse y estaba comentado en `phpunit.xml` desde el principio. No sirve aquí: el esquema usa columnas generadas `STORED`, disparadores, `VARBINARY` y la sonda de mecanismo de `Restriccion`. Unas pruebas que pasan contra un motor que no se parece al de producción son peores que no tenerlas. |
| **CI no se ve afectado** | PHPUnit solo aplica un `<env>` si la variable no existe ya en el entorno, y el flujo de CI las define a nivel de paso. Allí siguen mandando las suyas. |
| **Herramientas que salieron de aquí** | `tools/crear-bd-pruebas.php` (crea la base sin necesitar el cliente `mysql` en el PATH) y `tools/diagnostico.php` (ejecuta las cuatro puertas y vuelca la salida en UTF-8; `> archivo.txt` en PowerShell escribe UTF-16 y convierte el stderr en objetos de error, lo que hizo ilegibles dos informes seguidos). |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.5) |

---

### DEC-062 — Los dos permisos fiscales van al mismo rol; la separación la impone la base

| | |
|---|---|
| **Decisión** | `creator.tax.manage` (capturar) y `creator.tax.approve` (aprobar) se conceden **al mismo rol**: `finance` y `admin`. La separación de funciones la garantiza `ck_ctp_segregation`, que exige que el aprobador **no sea la misma persona** que el capturador. |
| **Por qué no repartirlos entre roles** | Habría obligado a dar `creator.view_sensitive` a `campaign_manager` para que pudiera capturar, y `DEC-053` decidió que finanzas es el único rol no administrador con acceso a datos fiscales del creador. Repartir los permisos habría deshecho esa decisión por la puerta de atrás. |
| **Por qué aquí sí hay segregación de personas y en `DEC-060` no** | Aquí se decide **con qué tasa se retiene**. Eso toca dinero, y es el mismo criterio de `DEC-044` y `BR-FIN-005`. Verificar una identidad, en cambio, es reversible. |
| **Consecuencia operativa** | Hacen falta **al menos dos usuarios internos**. Con uno solo, ningún perfil fiscal se aprueba y ningún creador se activa. `UsuarioAdminSeeder` crea uno; el segundo hay que crearlo. |
| **De paso se cerró `H-03`** | La restricción tenía la rama `created_by_user_id IS NULL`, y la columna admitía NULL: bastaba aprobar sin decir quién había capturado para saltarse el control. Se comprobó que funcionaba antes de cerrarlo. La columna pasa a NOT NULL —como en `payout_batches`— y la restricción se simplifica *porque* el modelo se volvió más estricto. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.6) |

---

### DEC-063 — Los umbrales de coherencia son juicio, no verdad; y no rechazan nada

| | |
|---|---|
| **Decisión** | `BR-CREATOR-004` exige «chequeos de coherencia» sobre las métricas sociales y **no da números**. Los pongo yo, con dos comprobaciones —engagement fuera de rango y salto de seguidores en ambos sentidos— y con los umbrales en `config('latam.redes')`, no en el código. |
| **Por qué configurables** | Un 3 % de engagement es excelente en una cuenta de un millón de seguidores y mediocre en una de mil. Estos números se van a ajustar con datos reales, y ajustarlos no debe requerir un despliegue de código. |
| **Nunca se rechaza** | La regla lo dice literalmente: *«se marcan para revisión humana, nunca se rechazan automáticamente»*. Una métrica anómala se guarda igual, con el motivo escrito. La alternativa —rechazarla— haría que el operador la volviera a teclear cambiando el número hasta que entrara, que es peor que no comprobar nada. |
| **De paso se cerró `H-06`** | `is_anomalous TINYINT NOT NULL DEFAULT 0`: «no es anómalo» y «nadie lo ha mirado» eran el mismo cero, el mismo fallo que `DEC-048`. Cada métrica insertada afirmaba haber pasado unos chequeos que **no existían**. Pasa a `coherence_status` con tres estados; las filas viejas se convierten a `pending_review`, no a `clean` — no se asciende un olvido a «limpio». |
| **Y `H-05`** | `verification_method` era texto libre y la restricción solo exigía la fecha: una cuenta podía quedar verificada sin decir cómo ni quién. Misma lección que `DEC-058` una tabla más allá. |
| **Y `H-07`** | `social_account_snapshots` era «solo inserción» por convención —no tiene `updated_at`— pero admitía `DELETE`. Lo encontró una aserción escrita dando por hecho lo contrario. Ahora lo impiden dos disparadores, como en `audit_logs` y `ledger_entries`. |
| **A revisar** | Con los primeros 200 creadores reales: si el ruido de falsos positivos es alto, se suben los umbrales. Que sea configuración lo hace un ajuste, no una migración. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.7) — umbrales revisables |

---

### DEC-064 — El enfriamiento de un medio de pago son 24 horas, y es configuración

| | |
|---|---|
| **Decisión** | `BR-FIN-006` exige un «período de enfriamiento» para un medio de pago nuevo o modificado y **no da número**. Son **24 horas**, en `config('latam.pagos.enfriamiento_horas')`. Verificar una cuenta no la habilita: le fija `eligible_from` en el futuro. |
| **Por qué 24 h** | Es el margen para que el aviso al canal de contacto anterior llegue y el creador reaccione si **no fue él** quien pidió el cambio. Menos no da tiempo a leer un correo; mucho más convierte cada alta legítima en una queja. |
| **Por qué no cero** | Cero cumpliría la letra de la regla —`eligible_from` existiría y sería `NOT NULL`— y no su intención: si alguien consigue que le verifiquen una cuenta, cobra en el acto. La regla existe para que exista una ventana. |
| **Por qué configuración** | Mismo motivo que `DEC-063`: es un juicio que se va a ajustar con datos reales, y ajustarlo no debe requerir un despliegue. |
| **Y no se puede acortar después** | `tg_cpm_inmutable` rechaza cualquier `UPDATE` que cambie `eligible_from` una vez fijada. Sin eso, la regla estaría a un `UPDATE` de distancia. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.8) |

---

### DEC-065 — La misma cuenta en dos creadores se marca, no se rechaza

| | |
|---|---|
| **Decisión** | Si una cuenta bancaria ya está registrada en **otro** creador, el alta **se admite** y queda en `shared_account_status = 'pending_review'`. Un humano la da por buena (`cleared`) o retira el medio. Nunca se rechaza automáticamente. |
| **Por qué no rechazar** | Hay un caso legítimo y frecuente: dos hermanos menores cuyos pagos van, los dos, a la cuenta del mismo tutor (`BR-CREATOR-010`). Rechazarlo obligaría a abrir una excepción a mano cada vez, y las excepciones a mano acaban siendo la norma. |
| **Por qué no callarlo** | Es también la señal clásica de una misma persona con varios perfiles de creador. La huella ya se calculaba y se guardaba: no mirarla era renunciar gratis a una señal de fraude. |
| **La marca la pone la BASE** | `tg_cpm_compartida`, un disparador `BEFORE INSERT`. Si lo escribiera la aplicación, una inserción podría afirmar `unique` sin haber mirado nada — que es exactamente el fallo de `H-06` y de `DEC-048`. Y el valor por defecto de la columna es `pending_review`, nunca `unique`. |
| **No bloquea la activación** | `CompletitudOperativa` lo dice en el detalle del requisito pero no lo tumba: marcar no es rechazar. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.8) |

---

### DEC-066 — La cuenta de un medio de pago es inmutable: se sustituye, no se edita

| | |
|---|---|
| **Decisión** | El número, la huella, la máscara, el titular y su documento **no se pueden cambiar nunca**. «Cambiar de cuenta» es dar de alta una nueva y retirar la anterior. No hay pantalla de edición porque no hay operación de edición: `tg_cpm_inmutable` rechaza el `UPDATE`. |
| **Qué lo motivó (`H-12`)** | Se comprobó contra una base real: un `UPDATE` cambiaba el número de cuenta de un medio **ya verificado**, la fila seguía diciendo `verified` y el dinero pasaba a ir a otro sitio. Eso vacía `BR-FIN-006` entero, porque el enfriamiento existe precisamente para las modificaciones. |
| **Por qué no «editar y volver a pending»** | Era la alternativa cómoda y pierde el rastro: la cuenta anterior desaparece y ya no se puede reconstruir a dónde apuntaba el medio cuando se emitió un pago viejo. Con filas separadas, cada pago histórico sigue apuntando a la cuenta que era. |
| **Precedente** | Es cómo funciona la banca real, y es la misma familia que `BR-FIN-002` con los asientos del ledger: lo que documenta dinero no se reescribe. |
| **Coste** | Un creador que se equivoca tecleando genera dos filas en vez de una corrección. Es barato y deja el error a la vista, que es lo correcto. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.8) |

---

### DEC-067 — Los dos permisos de medios de pago van al rol `finance`

| | |
|---|---|
| **Decisión** | `creator.payment.manage` y `creator.payment.verify` van los dos al rol `finance`. La separación de personas la impone la base (`ck_cpm_segregation`), no el reparto de roles. |
| **Por qué no repartirlos entre roles** | Es literalmente `DEC-062`, una tabla más allá. Dar la captura a `campaign_manager` obligaría a darle acceso a cuentas bancarias, que es justo lo que `DEC-053` decidió no hacer. |
| **Por qué la base y no el código** | Un permiso dice **qué puede hacer** un usuario; no dice que dos actos los hayan hecho dos personas. Eso solo lo sabe la fila, y por eso vive en una restricción. |
| **Estado** | ADOPTADA e implementada (2026-08-23, iteración 3.8) |

---

### DEC-068 — Cero es un precio válido, pero hay que declararlo

| | |
|---|---|
| **Decisión** | `amount = 0` solo se admite con `is_gratis = 1`, y `is_gratis = 1` solo con `amount = 0`. Las dos direcciones. |
| **Por qué** | El canje por producto y la primera colaboración existen, así que cero es un precio real. Pero con `amount >= 0` a secas, «trabaja gratis» y «nadie le preguntó su tarifa» eran **el mismo cero**, y esas dos cosas se responden distinto delante de un cliente. Es `DEC-048` aplicado al precio. |
| **Dónde vive** | `ck_creator_rates_amount`, en la base. El formulario ni siquiera pide el importe cuando se marca gratuita. |
| **Estado** | ADOPTADA e implementada (2026-08-24, iteración 3.9) |

---

### DEC-069 — Las tarifas tienen permiso propio: `creator.rate.manage`

| | |
|---|---|
| **Decisión** | Fijar tarifas, disponibilidad y bloqueos pide `creator.rate.manage`. Verlos basta con `creator.view`. Va a `campaign_manager` y a `finance`. |
| **Por qué no `creator.manage`** | Habría bastado, pero la tarifa es el **costo del creador** y alimenta el margen que `BR-FIN-007` protege. Un permiso propio permite dárselo a campañas sin abrir además los datos fiscales y la cuenta bancaria, que siguen detrás de `creator.view_sensitive` (`DEC-053`). |
| **Por qué ver es más barato que fijar** | Para armar una campaña hay que saber cuánto cuesta un creador. Cerrar la lectura obligaría a dar el permiso de escritura a quien solo necesita mirar. |
| **Estado** | ADOPTADA e implementada (2026-08-24, iteración 3.9) |

---

### DEC-070 — Un bloqueo de agenda se registra aunque pise una campaña aceptada

### DEC-071 — Un perfil fiscal nuevo no puede empezar antes que el vigente

**Contexto.** Al cerrar `T-12` había que decidir qué pasa cuando el perfil nuevo
entra en vigor antes —o el mismo día— que el que está vigente. En tarifas
(`DEC-068` y alrededores) se eligió rechazarlo. Pero un cambio de régimen ante
SUNAT **sí puede ser retroactivo**, así que rechazar no era obviamente correcto.

**Decisión.** Se rechaza, igual que en tarifas. El histórico fiscal no se
reescribe desde la pantalla.

**Por qué.** La alternativa —dejar que el perfil nuevo anule al vigente— convierte
la pantalla de perfil fiscal en una herramienta para reescribir un histórico del
que sale la retención practicada. Eso necesita rastro de quién y por qué, y eso
es otra iteración, no un `if`.

**Consecuencia asumida y explícita.** Si SUNAT emite una resolución retroactiva,
hoy hay que corregirlo en base de datos. Queda como `Q-48`: si pasa más de una
vez, la opción buena es la tercera que se descartó —permitirlo solo a Finanzas,
con motivo obligatorio en la bitácora—.

### DEC-072 — `creator_addresses` queda fuera de la regla de periodos

### DEC-073 — Un prospecto se apunta en cualquier país; activarlo exige cobertura

**Contexto.** `BR-LE-004` dice que si ninguna sociedad cubre el país del cliente
la operación se bloquea con un mensaje accionable. Faltaba decidir **qué
operación**.

**Decisión.** Dar de alta o mantener un cliente como `prospect` no exige
cobertura. Pasarlo a `active` sí.

**Por qué.** Un cliente potencial en un país que todavía no cubrimos es una
oportunidad comercial legítima; prohibir apuntarla obliga a llevarla en una hoja
aparte, que es justo lo que este sistema viene a eliminar. Pero `active`
significa «se le puede facturar», y sin sociedad que cubra su país eso es falso.

**Confirmación.** El esquema ya apuntaba a esta respuesta: `status` nace en
`prospect` por defecto, no en `active`.

**Consecuencia.** La ficha del cliente muestra quién le facturaría **siempre**,
también mientras es prospecto, para que la falta de cobertura se vea el día que
se apunta y no el día que hay que cobrar.

**Contexto.** De las cinco tablas con histórico sin blindar, cuatro necesitaban
la regla. `creator_addresses` no.

**Decisión.** No se le pone regla de solape.

**Por qué.** Su clave es `uq_creator_addresses_default (default_gate, creator_id,
address_type)`. El esquema **ya decidió** que puede haber varias direcciones del
mismo tipo y que una está marcada como la de por defecto. Prohibir el solape ahí
no sería endurecer el diseño, sería contradecirlo. Se confirmó antes de tocar
nada en lugar de blindar las cinco de una pasada.

| | |
|---|---|
| **Decisión** | El bloqueo entra siempre. La pantalla dice qué campañas **ya aceptadas** quedan dentro, con su código y su estado. |
| **Por qué no rechazarlo** | Si el creador se opera o viaja, el bloqueo es un hecho y el sistema no lo va a cambiar discutiendo. Rechazarlo obligaría al operador a pelearse con la aplicación justo cuando hay una urgencia, y el resultado sería que apunta la ausencia en otro sitio — que es peor que no tenerlo. |
| **Por qué no callarlo** | Es un choque con un compromiso con un cliente. Alguien tiene que hablar con el creador **hoy**, no cuando no llegue el entregable. |
| **Qué cuenta como compromiso** | Desde `accepted` en adelante, incluidos los estados de producción. `shortlisted` e `invited` no: invitar no es aceptar. |
| **Precedente** | Tercera vez que se aplica el mismo criterio: `DEC-063` con las métricas, `DEC-065` con la cuenta compartida, y esta. Marcar para revisión humana en vez de rechazar automáticamente. |
| **Estado** | ADOPTADA e implementada (2026-08-24, iteración 3.9) |

---

### DEC-074 — Al dar de alta un cliente se crea su primera marca

**Contexto.** Al revisar 4.1 surgió la pregunta de si cliente y marca no son lo
mismo. El modelo los distingue por tres razones sólidas —`uq_cb_name` es por
cliente, el conflicto de marca de `BR-CAMPAIGN-007` se coteja por categorías de
la **marca**, y la factura sale del cliente y no de la marca—, pero para un
cliente de una sola marca esa distinción es papeleo puro.

**Decisión.** El alta de cliente crea automáticamente una marca con su mismo
nombre, visible y editable, y lo dice en el mensaje de éxito.

**Por qué.** Que el modelo distinga no obliga a que la pantalla lo imponga. El
caso simple cuesta un formulario, el complejo sigue siendo posible, y nadie tiene
que entender la diferencia hasta que le hace falta.

**Consecuencia.** Un cliente nunca está sin marcas, así que `campaigns`
—que tiene `client_brand_id NOT NULL`— siempre tiene a dónde apuntar.

### DEC-075 — El relevo del contacto principal se hace, no se rechaza

**Contexto.** `uq_contacts_primary` deja **un contacto principal activo por
cliente y por tipo**. Marcar a un segundo choca en la base con
`Duplicate entry '1-1-commercial' for key 'uq_contacts_primary'`, y hay tres
caminos que llevan ahí sin querer: subir a un suplente, reactivar a quien
conservaba `is_primary = 1`, y mover a alguien a un tipo cuyo puesto está
ocupado. Los tres están comprobados contra el motor en `4.3-contactos.sh`.

**Decisión.** La aplicación **releva**: baja al que ocupa el puesto y sube al
nuevo, en una transacción, **en ese orden**, y nombra al relevado en el mensaje
de éxito. El formulario avisa antes, con nombre y apellidos, de a quién se
desplazaría.

**Por qué.** La alternativa era negarse —*«quítaselo primero al otro»*—, y
obliga a una maniobra de dos pasos en la que, entre paso y paso, **el cliente se
queda sin contacto principal de ese tipo**. Una regla que exige pasar por un
estado peor que el de partida está mal puesta.

**Por qué en ese orden.** No es estilo: al revés choca. Subir antes de bajar deja
dos filas con `primary_gate = 1` a la vez, aunque sea dentro de la misma
transacción. Hay una aserción dedicada a fijarlo.

**Consecuencia.** El relevo es un cambio real hecho por un efecto lateral, así
que **se anuncia siempre**. Un desplazamiento silencioso es un cambio que nadie
va a deshacer porque nadie se enteró. Si más adelante se decide que el relevo
deba confirmarse en dos pasos, el sitio es `ContactosController`, no el esquema.

### DEC-076 — La lista de suites de restricción vive en un solo archivo

**Contexto.** La lista estaba escrita a mano en **cuatro** sitios:
`tools/pruebas/correr-todo.sh` y los tres bloques de motor del CI (CHECK nativo,
disparadores, Percona 5.7). Al registrar la suite de 4.3 se descubrió que
`3.10-periodos`, `3.11-anulacion` y `3.12-no-borrar` se habían añadido **solo al
bloque de Percona**: durante tres iteraciones corrieron en un motor de los tres
y el CI salía verde.

**Decisión.** La lista vive en `tools/pruebas/SUITES`, una suite por línea.
`correr-todo.sh` y los tres bloques del CI la leen. Añadir una suite es añadir
una línea.

**Por qué.** El fallo no fue un despiste: fue que había cuatro sitios donde
despistarse y ninguno que se quejara. **Un CI no puede echar de menos una prueba
que nadie le nombró**, y esa es justo la clase de agujero que no se nota porque
todo está verde.

**Consecuencia.** El total de aserciones subió de 654 a 696 sin escribir una
sola prueba nueva de más: son las 21 de 4.3 por dos motores. Las tres suites
recuperadas ya pasaban; lo que faltaba era ejecutarlas.

### DEC-077 — Un contacto no cambia de cliente

**Contexto.** `contacts.client_organization_id` es editable en la base. El
formulario podría ofrecerlo.

**Decisión.** No lo ofrece. El cliente sale de la ruta y en la edición no se
toca. Si la persona cambió de empresa, es un contacto nuevo en el cliente nuevo
y el anterior pasa a `inactive`.

**Por qué.** Mover la fila reescribe el histórico: deja de ser verdad que en su
día se habló con esa persona en aquella empresa. Y el contacto puede estar
referenciado desde una campaña o una factura de la etapa en que sí lo era.

**Consecuencia.** Duplicidad aparente de personas entre clientes. Es correcta:
lo que se guarda no es la persona, es **el contacto con esa empresa**.


### DEC-078 — El histórico fiscal del cliente está congelado

**Contexto.** `client_tax_profiles` guarda la identidad fiscal del cliente por
país y con vigencia. Un periodo cerrado es el registro de quién era el cliente
entre esas fechas, y es de donde se explica una factura pasada. Desde `3.12` la
tabla no admite `DELETE`, pero sí admitía `UPDATE`.

**Decisión (negocio, 2026-08-25).** Sólo se corrige el periodo **vigente**. Los
cerrados no tienen pantalla: la ruta devuelve 404, no un formulario
deshabilitado. Un cambio de identidad se hace abriendo un periodo nuevo, que
cierra el anterior el día antes.

**Por qué.** Si la fila no se puede borrar, poder reescribirla sin dejar rastro
es la misma pérdida por otra puerta. Y el caso legítimo —el cliente cambió de
razón social— no necesita reescritura: necesita un periodo.

**Por qué esto es seguro.** `invoices` guarda
`receiver_legal_name_snapshot`, `receiver_tax_id_snapshot` y
`receiver_address_snapshot`: una corrección de hoy **no reescribe una factura de
ayer**. Sin esos snapshots, corregir incluso el vigente sería peligroso.

**Consecuencia.** Un RUC tecleado mal que no se detecta hasta después de cerrar
el periodo no tiene hoy forma de arreglarse por pantalla. Es deliberado y está
anotado como `Q-54`: si se decide permitirlo, será como la anulación de perfiles
fiscales de creador (`3.11`) —permiso propio y motivo escrito—, no editando.

### DEC-079 — La identidad fiscal del cliente tiene permiso propio, en dos roles

**Contexto.** Hasta ahora todo lo del cliente estaba detrás de `client.manage`.
De la identidad fiscal salen la razón social y el documento que se **imprimen en
una factura**.

**Decisión (negocio, 2026-08-25).** Permiso nuevo `client.tax.manage`, asignado
a **`finance` y a `campaign_manager`**.

**Por qué un permiso propio.** Permite que alguien edite la ficha comercial del
cliente sin poder tocar lo que va en un documento legal.

**Por qué NO sigue la simetría de `creator.tax.manage`.** Ese vive sólo en
`finance` porque el documento de un creador es dato **personal** sensible, y
`DEC-053` decidió expresamente no abrírselo a campañas. El de una empresa es
**público**: en Perú cualquiera consulta un RUC en SUNAT. Aquí el riesgo no es
fuga, es **error**, y quien habla con el cliente —campañas— es quien tiene el
dato. Copiar la simetría habría obligado a un traspaso entre dos roles para cada
alta, sin proteger nada.

**Consecuencia.** `finance` gana `client.tax.manage` sin ganar `client.manage`:
puede corregir la identidad con la que emite, no reorganizar la ficha comercial.


### DEC-080 — Un gate que comprueba que los nombres entre capas existen

**Contexto.** En el contenedor donde se escribe este código **no se puede correr
PHPUnit**: packagist está bloqueado, Composer no puede instalar y no hay
`vendor/`. Es la razón estructural por la que varias iteraciones se entregaron en
rojo. Las suites SQL cubren el esquema y `verificar-fixturas.py` cubre los
`INSERT` de las pruebas; la capa de en medio no la cubría nadie, y es justo donde
se rompen las pruebas de característica.

**Decisión.** `tools/verificar-pantallas.py`, en `correr-todo.sh` y en el CI.
Contrasta siete cosas que la aplicación **nombra** contra lo que **tiene**:
nombres de ruta, plantillas, permisos, roles de prueba, métodos de controlador,
claves leídas de `validated()` y variables que una plantilla usa sin que su
controlador se las pase.

**Por qué esos siete.** Todos son errores de **una letra** que dejan una suite
entera en rojo con un mensaje que no señala la causa —`RouteNotFoundException`,
un 403 en todas las pruebas de una pantalla, un «Undefined array key» que sale
como un 500—, y todos se ven leyendo archivos, sin Laravel y sin base de datos.

**No sustituye a PHPUnit.** No comprueba lógica, ni redirecciones, ni permisos
efectivos. Reduce la superficie de lo que sólo se sabe al ejecutar.

**Cómo se comprobó que sirve.** Rompiendo a propósito cada una de las siete
cosas, sobre una copia en `/tmp`, y exigiendo que el gate lo denunciara. Un gate
que dice «todo bien» sin que nadie haya comprobado que sabe decir «todo mal» no
prueba nada. Las siete se pillan.

**Dos falsos positivos que tuvo antes de servir**, y que valen como advertencia:
`->route('uuid')` no es el ayudante `route()` sino el accesor del parámetro, y
acusaba a cuatro sitios sanos; y `GuardarPerfilFiscalRequest` existe **dos
veces** —en `Modules/Creator` y en `Modules/Client`—, así que un índice por
nombre corto hacía que el gate leyera las reglas de la clase equivocada y acusara
al controlador de cliente de ocho claves que sí declara. Ahora resuelve por los
`use` del archivo que nombra, y **si el nombre es ambiguo se calla**: adivinar
entre dos clases homónimas es como se fabrica una acusación falsa.


### DEC-081 — Dar de baja una sociedad cierra sus coberturas abiertas

**Contexto.** `uq_lec_country` es `(current_gate, country_id)`: una sola
cobertura **abierta** por país, mire o no el estado de la sociedad. Pero quien
resuelve quién factura sólo cuenta las sociedades `active`. Las dos cosas juntas
dejan un país **incomunicado**: se desactiva la sociedad que lo cubre sin cerrar
su cobertura, ninguna activa lo cubre —`BR-LE-004` bloquea toda operación— y
ninguna otra puede empezar, porque la fila abierta de la inactiva sigue ocupando
el sitio. Comprobado contra el motor.

**Decisión (negocio, 2026-08-25).** La baja pide la fecha efectiva, **cierra las
coberturas abiertas en esa fecha dentro de la misma transacción**, y el mensaje
dice qué países quedan descubiertos y **desde cuándo** —el día siguiente al
último cubierto, no el último cubierto—.

**Por qué no bloquear la baja hasta que haya relevo.** Se consideró. Garantizaría
que ningún país quede descubierto, pero obliga a un orden que la realidad no
siempre permite: una sociedad puede cesar antes de que la sucesora esté
constituida. Y un sistema que impide registrar lo que ya pasó empuja a
registrarlo mal.

**Consecuencia.** Un país puede quedar temporalmente descubierto, y eso **se
dice en el momento**, no el día de facturar. `BR-LE-004` prohíbe continuar en
silencio, y enterarse tarde es esa clase de silencio.

**Dos casos que la pantalla tampoco deja pasar**, por la misma razón: una
sociedad inactiva no puede declarar cobertura —fabricaría el bloqueo a mano—, y
no se da de baja si deja una cobertura que empieza *después* de la fecha de baja,
porque esa no se puede cerrar (`ck_lec_dates`) ni borrar (es evidencia).

### DEC-082 — Las sociedades del grupo las gestiona sólo `admin`

**Contexto.** Hasta 4.5 no había pantalla: la cobertura se declaraba con el
seeder o SQL a mano (`Q-51`).

**Decisión (negocio, 2026-08-25).** Permiso nuevo `legal_entity.manage`, **sólo
en `admin`**.

**Por qué.** Dar de alta una sociedad es constituir una empresa dentro del
sistema: de ella salen la numeración de comprobantes (`BR-LE-007`), el emisor
congelado en cada factura (`BR-LE-005`) y las cuentas de cobro (`BR-LE-006`). Se
toca dos o tres veces al año. `finance` emite **desde** estas sociedades; no
necesita crearlas.

**Consecuencia.** Si finanzas descubre un país sin cubrir, depende de un admin
para desbloquearlo. Es el coste aceptado: el listado enseña los países
descubiertos en la portada de la pantalla precisamente para que se vea antes de
que bloquee una factura.


### DEC-083 — «Cuenta compartida» se calcula al leer, no se guarda

**Contexto.** `tg_cpm_compartida` es `BEFORE INSERT` y sólo puede escribir `NEW`.
Cuando el creador 2 da de alta la cuenta del creador 1, la fila del 2 queda
`pending_review` y **la del 1 sigue diciendo `unique`**: el operador que abre la
pantalla del creador 1 —el que probablemente cobre primero— ve «única» mientras
la cuenta está duplicada (`T-19`).

**Lo obvio no se puede.** Un `AFTER INSERT` que marcase también la fila anterior
choca contra el motor:

> `ERROR 1442: Can't update table 'creator_payment_methods' in stored
> function/trigger because it is already used by statement which invoked this
> stored function/trigger.`

Comprobado. Un disparador no puede tocar su propia tabla.

**Decisión (negocio, 2026-08-25).** El hecho **no se guarda dos veces**.
«Compartida» es una propiedad del conjunto de filas con la misma huella, no de
una fila: se pregunta al leer (`Creator\Services\CuentasCompartidas`) y entonces
todas las filas implicadas dicen lo mismo por construcción.

`shared_account_status` deja de ser la DETECCIÓN y pasa a ser el resultado de la
REVISIÓN: `cleared` significa «una persona miró esto y dijo que está bien», que
sí es un hecho de la fila y sí hay que conservar.

**Por qué no marcarlo desde la aplicación.** Funcionaría, y dejaría la regla
fuera de la base: cualquier importación u orden de consola se la saltaría. Este
proyecto pone las reglas en el esquema a propósito, y cuando el esquema no puede,
la respuesta es no duplicar el dato — no moverlo a un sitio más débil.

**Consecuencia.** `T-20` desaparece: si el estado no se guarda, el comando que
recalcula huellas tras rotar `APP_KEY` no puede dejarlo desfasado. Y
`revisarCompartida()` ya funciona desde la pantalla de **cualquiera** de los dos
creadores, no sólo del segundo.

### DEC-084 — «Perfil fiscal vigente» exige que ya haya empezado

**Contexto.** «Vigente» se definía como `status = 'approved' AND valid_to IS NULL`,
sin mirar `valid_from`, y nada acotaba la fecha en el formulario. Un perfil
aprobado hoy con `valid_from = 2027-01-01` se declaraba vigente **y era el que
decidía la retención que se mostraba**: la pantalla decía «NRUS, no aplica
retención» cuando ese día aplicaba RER al 8 %. La activación congela esa frase en
la bitácora como evidencia.

**Decisión (negocio, 2026-08-25).** «Vigente» exige además
`valid_from <= CURDATE()`. El perfil futuro se ve como «aprobado, rige desde el
…» y el que decide hoy sigue siendo el anterior.

**Por qué no prohibir las fechas futuras.** Se consideró, y es más simple. Pero
un cambio de régimen ante SUNAT tiene fecha conocida de antemano, y prohibirlo
obliga a acordarse de entrar ese día exacto. `BR-CREATOR-013` habla de la
retención que se **practica**, y esa es la de la fecha de la operación, no la del
último papel firmado: el modelo ya sabe expresar eso, lo que faltaba era leerlo.


### DEC-085 — La bitácora la protegen dos usuarios de base de datos, no el esquema

**Contexto.** `audit_logs` rechaza `UPDATE` y `DELETE` con dos disparadores.
`TRUNCATE TABLE audit_logs` **no dispara triggers** y deja la tabla a cero. No es
un descuido del esquema: no hay forma de escribir un disparador que lo pare,
porque `TRUNCATE` es una operación de **esquema**, no de datos.

Lo único que lo detiene es no tener el privilegio `DROP`, que es el que
`TRUNCATE` exige. Comprobado en los dos motores:

| | usuario sin `DROP` |
|---|---|
| `UPDATE` | `1644` — lo para el disparador |
| `DELETE` | `1644` — lo para el disparador |
| `TRUNCATE` | `1142 DROP command denied` |

**Decisión.** Dos usuarios: `latam_app` con
`SELECT, INSERT, UPDATE, DELETE, EXECUTE`, y `latam_mig` con `ALL PRIVILEGES`.
Las migraciones se corren sobrescribiendo las credenciales:
`DB_USERNAME=latam_mig php artisan migrate`.

**Por qué NO una segunda conexión en `config/database.php`.** Es lo que el
`.env.example` prometía con `DB_MIGRATION_USERNAME`, y habría sido peor: las
migraciones de este proyecto generan DDL con `DB::statement()` (`Restriccion`,
`Periodo`), y `DB::statement()` va **siempre** por la conexión por defecto aunque
la migración declare otra. La mitad del DDL habría ido por un usuario y la otra
mitad por el otro.

**Y se comprueba, no se promete.** `php artisan seguridad:privilegios` lee los
privilegios reales del usuario conectado. No intenta el `TRUNCATE` para ver si
falla: `TRUNCATE` hace *commit implícito*, así que la comprobación habría vaciado
la bitácora para demostrar que se puede vaciar.

**Lo que había antes era una promesa incumplida.** El docblock de la migración de
trazabilidad afirmaba que el usuario de aplicación no tenía `UPDATE` ni `DELETE`
—falso: eso lo hacen los disparadores, con cualquier usuario— y remitía a
`DB_MIGRATION_USERNAME`, una variable que **no leía ninguna configuración**. Una
promesa escrita y no cumplida es peor que no prometer nada, porque nadie va a
comprobarla.


### DEC-086 — La contraseña temporal deja de ser válida en el primer acceso

**Contexto.** `usuarios:crear` escribía `must_change_password = 1` desde la
primera iteración de identidad, y **nadie lo leía nunca**: única aparición de la
columna en todo el árbol, aparte de la migración. No había middleware que la
comprobara ni pantalla donde cambiar la contraseña (`T-23`).

O sea: el administrador que da de alta a la persona de finanzas teclea su
contraseña, se la dice, y esa contraseña sigue siendo válida indefinidamente.

**Por qué no es sólo higiene.** La base **exige dos personas distintas** para lo
que toca dinero: `ck_ctp_segregation` al aprobar un perfil fiscal,
`ck_cpm_segregation` al verificar un medio de pago (`DEC-044`, `BR-FIN-005`). Esa
garantía se apoya entera en que dos `user_id` distintos sean dos personas
distintas. Si un tercero conoce la credencial de la segunda, la separación es una
columna en una tabla y nada más.

**Decisión.** Middleware en el grupo `web` —no ruta a ruta: una obligación que
hay que acordarse de poner en cada pantalla nueva se salta la primera que alguien
olvide— con tres excepciones: la pantalla de cambio, su acción, y `salir`. Sin
esas tres es un bucle de redirecciones, que es la forma más rápida de dejar a
alguien fuera de su propia cuenta.

**Tres reglas que hacen que sirva de algo:**

1. **La nueva tiene que ser distinta.** Sin esto, teclear la temporal dos veces
   limpia la marca y deja válida la contraseña que conoce el administrador:
   cumplido en la base de datos y sin cumplir en la realidad.
2. **Se pide la contraseña actual**, aunque el cambio sea obligatorio. «Entró con
   ella» y «sigue delante» no son lo mismo: una sesión abierta y desatendida
   bastaría para dejar fuera al dueño de la cuenta.
3. **Sin permiso.** Si cambiar la propia contraseña dependiera de un permiso, un
   usuario al que se le han revocado no podría cambiarla — y es al que más urge.
   Entra en `RutasProtegidasTest::SIN_PERMISO` con ese motivo escrito.

**Y la comprobación contra filtraciones es configurable, a propósito.**
`Password::uncompromised()` es una llamada HTTP saliente que **falla en abierto**:
sin salida a internet, Laravel da la contraseña por buena. Un servidor endurecido
sería justo donde la comprobación no comprueba, y sin decirlo. Así que la defensa
son los 12 caracteres y la mezcla; esto es un extra que quien despliega enciende
o apaga sabiendo lo que hace. En pruebas va apagado: una prueba no debe depender
de la red.


## Decisiones pendientes de información del negocio

| # | Pregunta | Bloquea |
|---|---|---|
| Q-01 | ¿Cómo se pagará legalmente a creadores sin RUC? (`DEC-005`) | F9 |
| Q-02 | ¿El operador factura la campaña completa o solo su servicio? (`DEC-004`) | F9 |
| Q-03 | ¿Qué proveedor de facturación electrónica se usará? | F12 |
| Q-04 | ¿Existen clientes que quieran pagar con tarjeta? (`DEC-007`) | F12 |
| ~~Q-05~~ | ✅ **30 días por defecto, configurable por creador.** Visible al aceptar la campaña | — |
| ~~Q-06~~ | ✅ **Contrato por campaña**, sin acuerdos marco | — |
| ~~Q-07~~ | ✅ **Sí, con autorización firmada del tutor**; se paga a nombre del tutor. Requiere documento del tutor y acreditación de parentesco. Abre modelado nuevo: ver `docs/16 §3.1` | — |
| ~~Q-08~~ | ✅ **2 rondas** incluidas en el precio | — |
| ~~Q-09~~ | ✅ **Se define por campaña y por red.** El creador adjunta el enlace y el sistema lo valida — con los límites de `docs/16 §3.2` | — |
| Q-10 | ¿Quién asume el costo del producto enviado y de la logística? | F7.8, margen |
| ~~Q-11~~ | ✅ No hay base previa. Plantilla entregada: `tools/plantilla-importacion-creadores.csv` | — |
| Q-12 | ~~Nombre comercial~~ resuelto: **LATAM Social**. Falta el **dominio** definitivo y el correo del remitente. | F5 |
| Q-13 | 🔴 ¿Cómo se paga a un creador **no domiciliado** desde CTS Perú? ¿Qué retención aplica y qué documento se le exige? | F9 |
| ~~Q-14~~ | ✅ **Sí**, exportación de servicios | — |
| ~~Q-15~~ | ✅ **CTS Perú → PE, EC, CL, MX, US** · **CTS Colombia → CO** (ya constituida) | — |
| ~~Q-16~~ | ✅ **SUNAT directo** en Perú y **DIAN directo** en Colombia. Sobre Colombia tengo una objeción: `docs/16 §2.3` | — |
| Q-17 | Si se constituye una segunda sociedad, ¿se migran los clientes existentes o se quedan con la original? | Diseño de migración de cobertura |
| Q-18 | ¿"LATAM Social" es marca registrada, y a nombre de qué sociedad? Afecta contratos y licencias de contenido. | Textos legales |
| ~~Q-19~~ | ✅ Perú: `invoicing` y `exchange_rate`. Colombia: opcionales | — |
| ~~Q-20~~ | ✅ SMTP Google Workspace · almacenamiento sin contratar · tipo de cambio **Decolecta**. Dos reservas en `docs/16 §3.3` y §3.4 | — |
| Q-21 | ¿El proveedor de facturación electrónica permite una cuenta por sociedad, o exige contrato separado por RUC? | F12 |
| ~~Q-22~~ | ✅ **100% interna** | — |
| Q-23 | **¿Qué desbloquea realmente el nivel?** Define si la gamificación es reconocimiento o política comercial. | Diseño de recompensas |
| Q-24 | ¿El XP podrá convertirse en dinero o bienes? Si no, el problema fiscal y legal se reduce drásticamente. | Validación legal |
| Q-25 | ¿Cuál es el hito de consolidación del referido: aprobación, o primera campaña completada? | `DEC-040` |
| Q-26 | ¿Los retos internos pagarán en dinero, producto o acceso prioritario? | `DEC-039` |
| Q-27 | ¿Hay presupuesto para la Academia del creador? Es contenido, no software. | F14 |
| ~~Q-23~~–~~Q-28~~ | ✅ Gamificación: se adopta la recomendación de que no sea obligatoria. Sigue en F14–F15 con `DEC-039` intacta | — |
| ~~Q-33~~ | ✅ **RESUELTA (negocio, 2026-08-22):** todo creador debe tener datos fiscales legales y vigentes. No hay pago informal. Sin ellos no se activa; si no se regulariza, se rechaza. → `BR-CREATOR-013` | — |
| **Q-40** | 🔴 **SIGUE ABIERTA — pero ya no puede pasar desapercibida.** Con qué **tasa** se retiene a un creador no domiciliado. `DEC-048` no la responde (nadie inventa una tasa tributaria): lo que hace es impedir que un perfil fiscal se apruebe con la retención sin decidir, y obligar a citar la norma cuando se decida. **Requiere contador** | F9, antes del primer pago a no domiciliado |
| ~~Q-41~~ | ✅ **RESUELTA (negocio, 2026-08-22):** **30 días** de gracia antes de rechazar por falta de datos fiscales. Configurable. → `BR-CREATOR-014` | — |
| **Q-34** | ⚠️ **ADOPTADA MI RECOMENDACIÓN, contraria a lo que dijiste — revísala.** Dijiste *"DIAN directo"*. Recomiendo **empezar con un proveedor tecnológico certificado** y dejar la habilitación propia para después. Motivo: habilitarse como proveedor propio ante la DIAN es un proceso de **meses** con pruebas de conformidad, y bloquearía la operación en Colombia mientras tanto. Con proveedor se factura en semanas y la habilitación propia se hace en paralelo sin urgencia. **Si tu prioridad es no depender de terceros, dilo y lo revierto** | F12 |
| ~~Q-35~~ | ✅ **ADOPTADA (recomendación, 2026-08-22):** el **cliente** asume producto y logística, y ambos se registran como *Direct Cost* de la campaña e impactan el margen (`BR-FIN-011`, que ya lo decía). El creador asume el **costo de creación**, no el del producto — eso ya lo confirmaste. El producto **no se devuelve** salvo pacto expreso en la campaña | — |
| ~~Q-36~~ | ✅ **RESUELTA (negocio, 2026-08-22):** **todo por Google Workspace por ahora** — el volumen actual no llega a 100 correos. El proveedor transaccional queda como **opción de configuración**, no como código nuevo: el correo es una conexión de integración con `purpose = transactional_mail` (`docs/12`), así que cambiarlo es editar una conexión en el back-office. **Disparador para cambiar, escrito ahora para no discutirlo luego:** cuando un solo envío de campaña supere ~200 destinatarios en un día, o cuando empiecen a salir avisos de pago. Ver `docs/fase-2/2.10 §8` | Configuración |
| ~~Q-37~~ | ✅ **ADOPTADA (recomendación, 2026-08-22):** `min_age = 18` en **alcohol, tabaco y vapeo, apuestas y casinos, contenido adulto, armas, criptoactivos, préstamos y créditos, y suplementos de pérdida de peso**. Los tres últimos no son ilegales para menores pero sí indefendibles reputacionalmente. Los valores son **datos del catálogo `categories`, no código**: se ajustan sin desplegar | — |
| **Q-38** | ¿Cuántos desarrolladores? Con uno solo las estimaciones se multiplican por 1,7 | Todo el plan |
| ~~Q-42~~ | ✅ **RESUELTA (2026-08-22):** se factura a todos los países **desde Perú**. Ver `DEC-047`. Los países se resuelven uno a uno más adelante; las tablas específicas por país se crearán en su momento | — |
| **Q-44** | ⚠️ **§56 — nuevo, abierto por `DEC-047`.** ¿Los servicios de marketing de influencers prestados a un cliente **no domiciliado** califican como **exportación de servicios** (sin IGV), o van gravados al 18 %? Depende de las condiciones concurrentes del art. 33-A de la Ley del IGV y de la lista de servicios aplicable, **cuya vigencia he encontrado contradicha entre fuentes**. El modelo admite las cuatro opciones y no fuerza ninguna. **Requiere contador** | Antes de la primera factura al exterior |
| ~~Q-45~~ | ✅ **RESUELTA (2026-08-22):** opción **B** — sin datos fiscales no hay alta, pero se acompaña al creador a formalizarse. Ver `DEC-049`. El pago a un tercero **no se implementa**; el análisis queda en `docs/fase-2/2.14-PAGO-A-TERCEROS.md` | — |
| **Q-46** | ⚠️ **§56 — nuevo, abierto por `DEC-059`.** Cuando se publique una **versión nueva de los términos**, ¿qué pasa con los creadores **ya activos**? Hoy el sistema **no los desactiva**: siguen activos con la aceptación de la versión anterior. Las opciones son (a) dejarlo así y pedir la nueva aceptación solo la próxima vez que hagan algo relevante, (b) bloquear invitaciones hasta que re-acepten, (c) suspenderlos. Tiene consecuencias legales y operativas, y **no lo decido yo** | Antes de publicar la segunda versión de los términos |
| **Q-47** | ⚠️ **Abierto por la iteración 3.6.** `BR-CREATOR-014` fija un **periodo de gracia de 30 días** antes de rechazar a un creador por falta de datos fiscales, y dice «configurable». ¿Configurable **globalmente** (una constante de despliegue) o **por creador**, como `payment_term_days`? No lo invento: son dos modelos de datos distintos. Hasta que se responda, el periodo de gracia no está implementado | Iteración de rechazo de creadores |
| ~~T-12~~ | ✅ **RESUELTA (2026-08-24), y el registro se entero el 2026-08-25.** Las dos mitades estaban hechas desde hacia un dia: la migracion `no_overlapping_tax_periods` --que ocupa periodo con `approved` **y** `superseded`, porque filtrando solo `approved` no habria cazado el defecto que venia a arreglar-- y el cierre el dia antes en `PerfilFiscalController`. Esta entrada se quedo en 📋 y asi siguio un mes. Al ir a abrirla se descubrio que ademas ese sitio restaba el dia **a mano** en vez de por `Vigencia`: era la octava copia. De ahi sale 4.7 | — |
| ~~T-16~~ | ✅ *(hecho en 3.12)* **`creator_tax_profiles` se puede borrar con un `DELETE`.** Lo llevan `payouts`, `invoices`, `ledger_entries`, `creator_payment_methods` y cinco tablas más, pero ésta no. Anular (`3.11`) existe justamente para no destruir el histórico —guarda quién, cuándo y por qué—, y todo eso se va con un `DELETE`. Salió al escribir la suite de 3.11: la aserción que iba a escribir habría dicho «el DELETE funciona», o sea habría fijado el hueco como si fuera lo correcto, que es el mismo error que `PerfilFiscalTest` cometió con `T-12`. Hace falta `tg_ctp_no_delete` y revisar si alguna otra tabla de histórico está igual | Antes de la primera declaración que se apoye en este histórico |
| **Q-54** | ❓ **¿Se puede corregir un periodo fiscal ya CERRADO?** `DEC-078` dice que no: sólo se corrige el vigente. Cubre el caso normal —el cliente cambió de razón social, y eso es un periodo nuevo, no una reescritura— pero deja fuera el RUC tecleado mal que no se detecta hasta meses después, cuando el periodo ya se cerró. Hoy eso se arregla en base de datos. Si se decide permitirlo por pantalla, la forma es la de la anulación de perfiles fiscales de creador (`3.11`): permiso propio, motivo escrito y rastro de quién. Lo que no puede ser es un `UPDATE` normal | Primera correccion real de un histórico |
| **Q-55** | ❓ **¿Se valida el formato del documento fiscal por país?** Hoy **no**: `tax_id_number` acepta cualquier cosa de hasta 40 caracteres y la pantalla avisa de que no se comprueba. Escribir una tabla de expresiones regulares (11 dígitos para un RUC peruano, dígito verificador del NIT colombiano…) es fácil y es una trampa: en cuanto una esté mal o falte un país, el sistema rechaza un documento válido y **no hay forma de meterlo**, que es peor que aceptar uno malo —eso se detecta al facturar—. Si se hace, tiene que ser dato de catálogo por país y editable, no código | Alta de clientes en el segundo país |
| ~~T-18~~ | ✅ **RESUELTA (2026-08-25).** `DEC-085`: dos usuarios de base de datos, `.env.example` con las concesiones exactas, y `php artisan seguridad:privilegios` que lo comprueba en vez de prometerlo. La suite `3.12` fija las dos mitades con usuarios reales, incluida la incomoda: **con `DROP` la bitacora si se vacia**. Falta ejecutar los `GRANT` en el servidor de produccion | Al desplegar |
| ~~T-19~~ | ✅ **RESUELTA (2026-08-25).** Se calcula al leer (`DEC-083`). Un disparador no puede actualizar su propia tabla (`1442`, comprobado), asi que el estado dejo de guardarse: `CuentasCompartidas` resuelve la pregunta y las dos filas dicen lo mismo por construccion | — |
| ~~T-20~~ | ✅ **CERRADA DEL TODO (2026-08-25, iteracion 4.10).** La primera mitad la resolvio `DEC-083`. El resto era el `1062` del recalculo cuando dos filas del MISMO creador convergen: con la clave rotada, dos medios de la misma cuenta llevan huellas distintas, `uq_cpm_open_account` no los ve y **los dos entran**; al recalcular convergen y la transaccion se cae con un `Duplicate entry` en crudo, dejando el recalculo sin hacer. Y este choque **no se absorbe**: reintentar da la misma huella y choca igual. Es el lado opuesto de `DEC-087` --el valor no lo eligio el sistema, lo eligio la realidad-- y la respuesta es contar, no recalcular: dos medios abiertos del mismo creador que son la misma cuenta, y cual sobrevive depende de cual esta verificado y si tiene pagos detras. Ahora se ve en la revision, antes de escribir nada, comparando el estado DESPUES del recalculo --mirar solo las pendientes entre si dejaria pasar el caso mas probable, una fila vieja y una nueva-- y solo sobre las filas ABIERTAS. El `1062` que sobreviva a la carrera se traduce con `Choque::esDe`. Ver `docs/fase-4/4.10-CONVERGENCIA-DE-HUELLAS.md` | — |
| ~~T-21~~ | ✅ **RESUELTA (2026-08-25).** «Vigente» exige `valid_from <= CURDATE()` (`DEC-084`), y el mensaje de aprobacion distingue «aprobado y vigente» de «aprobado, todavia NO rige» | — |
| ~~T-22~~ | ✅ **RESUELTA (2026-08-25).** La guarda de solicitudes deja de filtrar por estado —las unicas se apoyan en `identity_gate`, que no lo mira— y el mensaje distingue un duplicado administrativo de una persona en lista negra. La de redes sociales comprueba ademas las cuentas propias, que era el caso frecuente | — |
| ~~T-23~~ | ✅ **RESUELTA (2026-08-25).** `DEC-086`: middleware en el grupo `web`, pantalla de cambio, y tres reglas que hacen que sirva —la nueva distinta de la actual, se pide la actual, y sin permiso—. La marca que se escribia desde 3.1 por fin significa algo | — |
| ~~T-24~~ | ✅ **RESUELTA (2026-08-25).** `max(1, ...)` en el config. Un comentario que dice «esto no puede pasar» y no lo impide es una nota, no una regla | — |
| ~~T-25~~ | ✅ **RESUELTA (2026-08-25). La suite de PHPUnit se ejecuto por primera vez.** 228 pruebas, 695 aserciones. Seis iteraciones (4.1–4.5 y los ~23 arreglos de las revisiones) solo se habian comprobado con suites SQL y puertas estaticas. Unico fallo real: `tests/Feature/ExampleTest.php`, la prueba de ejemplo de Laravel que afirma que `/` devuelve 200 cuando `Route::redirect('/', '/panel')` devuelve 302. Llevaba roja desde 1.1 y nadie lo sabia porque **nada la ejecutaba**: la puerta `pruebas` existe, pero el CI se instalo este mismo dia. `RutasTest` dice en su propio docblock que la *sustituye* — se escribio el reemplazo y no se borro el original. Se borra | — |
| ~~T-26~~ | ✅ **RESUELTA (2026-08-25). Verificado el verificador.** Que 228 pruebas nunca ejecutadas salgan verdes a la primera es sospechoso, asi que se comprobo con tres mutaciones. Dos se detectaron por la prueba que dice detectarlas (`Vigencia::puedeRelevar()` sin normalizar fechas → `VigenciaTest`; `different:actual` fuera → `CambioPasswordTest`). **La tercera sobrevivio:** devolver el `where('le.status','active')` a `Cobertura::abiertaEnPais()` dejaba las quince pruebas de 4.5 en verde. La suite SQL si lo veia —el disparador de no-solapamiento rechaza el `INSERT`— pero ninguna prueba comprobaba que la capa PHP lo evita ANTES, o sea que el operador recibiria un `45000` en crudo. Se anadio la prueba 16, que con la mutacion se pone roja | — |
| **DEC-087** | ✅ **Un `1062` se absorbe o se cuenta segun quien eligio el valor, y hay que NOMBRAR el indice para absorberlo.** Si el valor lo calculo el sistema —el slug de una marca— el choque se recalcula y se reintenta en silencio. Si lo escribio la persona —un RUC, el nombre de una marca— tiene que llegar arriba con palabras. `App\Shared\Database\Choque` obliga a elegir: `reintentar()` exige el nombre del indice y vuelve a lanzar cualquier otro a la primera, porque un `catch (QueryException)` a secas absorberia el RUC repetido igual que el slug. Tres intentos como tope: un bucle sin tope convierte un indice mal entendido en una peticion eterna. El nombre se lee cortando por el ultimo punto —MySQL 8 antepone la tabla y Percona 5.7 no—, comprobado contra los dos motores | 4.6 |
| **DEC-088** | ✅ **La aritmetica de vigencias vive en `Vigencia`, y una puerta lo comprueba.** El error de un dia --cerrar un periodo el mismo dia en que empieza el siguiente, siendo `valid_to` inclusivo-- ha aparecido NUEVE veces. `Vigencia` (4.5) le dio un sitio; `tools/verificar-vigencias.php` impide que vuelva a salir de el. Dos reglas: ninguna aritmetica de dias fuera de `Vigencia`, y ninguna comparacion de una columna de vigencia contra algo sin normalizar. Escrita en PHP con `token_get_all()` y no en Python con expresiones regulares, porque los comentarios de este proyecto **nombran** el defecto y las migraciones llevan SQL con `>=` dentro: una regex se acusaria a si misma. Al estrenarla quedaban tres sitios sueltos, los tres escritos DESPUES de `Vigencia`, y uno era un fallo real --`--desde=2026-2-1` contra `effective_from` como cadenas, dejando dos textos legales vigentes el mismo dia--. Ver `docs/fase-4/4.7-PUERTA-VIGENCIAS.md` | 4.7 |
| **DEC-089** | ✅ **La sociedad que factura una campana se resuelve a `starts_on`.** `BR-LE-003` dice «en la fecha de la operacion»; para una campana esa fecha es cuando empieza a prestarse el servicio, que es lo que un contador defiende ante SUNAT. Consecuencia: una campana creada en diciembre para arrancar en febrero usa la cobertura de FEBRERO, asi que si la cobertura cambia el 1 de enero la campana nace ya con la sociedad correcta en vez de con una que habria que corregir --y corregirla es justo lo que `DEC-090` impide-- | 7.1 |
| **DEC-090** | ✅ **Se congela al confirmar, no al salir de borrador.** `BR-LE-002` dice «inmutable una vez emitido» y para una campana «emitido» es ambiguo. Mientras es `draft` o `pending_approval` se corrige un dedazo; con `confirmed_at` puesto, no se toca. Lo impone `tg_camp_entidad_congelada` y no el controlador: de este dato depende que una factura de dentro de dos anos siga sabiendo quien la emitio, asi que tiene que sobrevivir a un `UPDATE` de mantenimiento. La alternativa --congelar al salir de borrador-- llenaria el historico de campanas `cancelled` que no se cancelaron por negocio, y un estado que miente sobre por que existe es peor que un campo editable un rato mas | 7.1 |
| **DEC-091** | ✅ **Aprobar una campana es de finanzas, no de quien la monta.** Aprobar fija el ingreso comprometido y congela la sociedad emisora: es una decision de dinero, y la misma separacion que `DEC-044` impone en la base para perfiles fiscales y medios de pago. Permiso nuevo `campaign.approve`, y vive en el GRAFO de transiciones, no en la ruta: una ruta con permiso fijo obligaria a partir la accion en dos y a acordarse de las dos al anadir un estado | 7.1 |
| **DEC-092** | ✅ **«Brief definido» son los REQUISITOS, no el texto del briefing.** `BR-CAMPAIGN-004` exige «brief definido» para aprobar y no dice que es. Se decide: **al menos una fila en `campaign_requirements`** --un formato, una cantidad, un plazo--. El campo `briefing` sigue siendo texto libre opcional porque no se puede comprobar de verdad: un espacio en blanco cumpliria cualquier `NOT NULL`, asi que exigirlo anadiria friccion sin anadir garantia. Los formatos si se comprueban, y son lo que convierte «hay una campana» en «hay algo que entregar»: es lo minimo que un creador necesita para decidir si acepta | 7.2 |
| **DEC-093** | ✅ **Ingreso cero es valido, pero hay que declararlo.** `revenue_amount = 0` respondia a dos preguntas distintas con el mismo numero --«esta campana se regala» (canje, cortesia, prueba) y «nadie le ha puesto precio»--, y ante un margen descuadrado la diferencia entre las dos es la diferencia entre «salio como se planeo» y «se nos escapo». Columna `is_gratis` y `ck_camp_revenue_declarado`. Va en la BASE y no en la pantalla porque la pregunta que hay que poder responder dentro de un ano es «¿esta campana de cero se regalo o se nos olvido cobrarla?», y esa respuesta tiene que sobrevivir a una importacion y a la proxima pantalla que alguien escriba. Mismo valor por omision que parecia una respuesta que `DEC-048` y `DEC-068` | 7.2 |
| **DEC-094** | ✅ **La regla se recorta a los estados NO iniciales, como sus dos hermanas.** La primera version de `ck_camp_revenue_declarado` no lo hacia y **habria rechazado toda campana recien creada**: `revenue_amount` e `is_gratis` nacen los dos a cero, asi que el formulario vacio violaba la regla antes de que nadie pudiera teclear el precio. Es la misma forma que `ck_camp_confirmed` y `ck_camp_billing_entity`: *o estas todavia escribiendo la campana, o el dato existe*. Un borrador tiene derecho a estar a medias; lo que no puede es salir de ahi asi | 7.2 |
| ~~7.2~~ | ✅ **CERRADA (2026-08-25).** `BR-CAMPAIGN-004` estaba escrita, con su identificador y su color 🟠, y **no la impedia nada**: en 7.1 se dejaba aprobar una campana que solo tenia sociedad emisora --sin decir que habia que entregar y sin que nadie hubiera puesto un precio--. **Tercer caso del mismo patron** (`BR-LE-001` en 7.1, `must_change_password` antes de `T-23`): una regla del documento que ningun `CHECK` y ninguna pantalla comprobaban. La restriccion nueva volvio a alcanzar a la semilla, al esquema de referencia y a la suite de 7.1 --que empezo a rechazar por el motivo de 7.2 creyendo que probaba el suyo--, y **no alcanzo a ningun fixture escrito a mano**: `ConFixturas::campanaDe()` absorbio el cambio en un sitio, que es para lo que se creo en `T-13`. Ocho mutaciones, las ocho en rojo; una de ellas destapo que `test_la_ficha_ensena_lo_que_falta` pasaba con el veto desactivado, porque la frase que afirmaba tambien esta en un texto fijo de la pantalla. 300 pruebas, 888/878 aserciones de restriccion. Ver `docs/fase-7/7.2-BRIEF.md` | — |
| **DEC-095** | ✅ **Una campana necesita al menos un mercado para salir de borrador.** Tercer motivo de `BR-CAMPAIGN-004`, junto al brief y al ingreso declarado. Una campana que no dice donde se ejecuta es una campana que nadie puede empezar: de ahi sale a quien se puede invitar (7.4). Cliente y marca los garantiza el esquema; el mercado no puede --un `CHECK` no cuenta filas de otra tabla-- asi que vive en `Campanas::loQueFaltaParaSalirDeBorrador()`, con los otros dos y diciendolos todos de una vez | 7.3 |
| **DEC-096** | ✅ **De una campana confirmada se ANADE un mercado, no se quita.** Ampliar a un pais nuevo es una decision comercial normal y no rompe nada de lo prometido; quitar puede dejar fuera a creadores ya invitados o aceptados, y eso exige una enmienda aceptada por las dos partes (`BR-CAMPAIGN-003`). Lo impide `tg_cm_no_quitar_confirmada`, un `BEFORE DELETE` que mira el estado de la CAMPANA y no una columna propia: el congelado de un mercado no es un hecho del mercado. Y hace falta el disparador precisamente porque la regla es **asimetrica**: una simetrica --«confirmada, no se toca»-- se explica de memoria; una asimetrica se olvida en la mitad de los sitios | 7.3 |
| **DEC-097** | ✅ **El pais de un mercado no necesita cobertura de facturacion.** `BR-LE-003` resuelve quien factura por el pais del CLIENTE: un cliente peruano puede pagar una campana que corre en Colombia sin que el grupo tenga sociedad alli, y exigir cobertura en el mercado bloquearia hoy toda campana fuera de Peru. Lo que si puede hacer falta es como se le paga a un creador colombiano, y eso es `Q-40`, que sigue abierta y desde 7.3 tiene caso de uso | 7.3 |
| **DEC-098** | ✅ **Que el mercado sea DE la campana lo garantiza el esquema, con foraneas COMPUESTAS.** `campaign_requirements.campaign_market_id` y `campaign_creators.campaign_market_id` apuntaban a `campaign_markets(id)`, y una foranea asi solo comprueba que el mercado exista: nada impedia un requisito de la campana A colgado del mercado de la campana B, ni un creador aceptado atribuido al pais de otra campana. Se anade `uq_cm_id_campaign (id, campaign_id)` --redundante como clave, necesaria como destino-- y las dos foraneas pasan a `(campaign_market_id, campaign_id)`. Se prefiere a un disparador porque la comprueba el motor en las dos direcciones --tambien impide mover un mercado de campana-- y porque Percona 5.7 si tiene foraneas compuestas. Y el `NULL` con significado de `N-03` sobrevive sin un caso especial: en MySQL una foranea compuesta con un componente NULL no se comprueba | 7.3 |
| ~~T-33~~ | ✅ **RESUELTA (2026-08-25, iteracion 7.3).** `ck_creq_deadline` y `ck_creq_permanence`. Los acotaba solo `GuardarRequisitoRequest`, y `permanence_days` es «cuanto debe seguir publicado»: de ahi sale lo que se le exige al creador y lo que se le promete al cliente. Un 100.000 --273 anos-- entraba por cualquier importacion, y lo que se rompe no es la base sino un acuerdo que nadie puede cumplir | — |
| ~~7.3~~ | ✅ **CERRADA (2026-08-25).** `N-03` --«el brief de mercado REEMPLAZA al general, no se mezcla»-- estaba escrita desde la Fase 2 y **nada la implementaba**, con el agravante de que es la UNICA excepcion consciente del modelo: el unico sitio donde un `NULL` significa «todos» en vez de «no aplica». Una excepcion que nadie implementa es una excepcion que alguien va a interpretar mal, asi que la pantalla del mercado ahora lo dice con palabras. Salieron dos cosas mas: **una asercion de la suite salia verde por un `1093` de MySQL 8** --`DELETE` con subconsulta sobre la misma tabla, que MariaDB permite y MySQL no-- midiendo el error del motor en vez de la foranea; y `recolectar-esquema.php` no tenia `dropForeign`/`dropUnique`/`dropIndex`, asi que una migracion que sustituye una foranea dejaba las DOS grabadas. No habia dado la cara porque ninguna migracion anterior habia sustituido una foranea. 318 pruebas, 940/930 aserciones de restriccion. Ver `docs/fase-7/7.3-MERCADOS.md` | — |
| **DEC-099** | ✅ **El buscador ensena a todos los activos; el veto real salta al anadir.** Un creador activado en junio al que en agosto se le retiro el medio de pago sigue con `status = 'active'`: sale en la busqueda, con lo que le falta a la vista, y NO entra en la lista corta. Dos motivos: revalidar los seis requisitos de `BR-CREATOR-006` por candidato y por busqueda es caro --en el veto se hace una vez, sobre uno-- y un creador que desaparece sin explicacion parece un fallo del sistema, mientras que uno que sale con «le falta el medio de pago» es una tarea. El veto usa `CompletitudOperativa`, la MISMA clase que decide la activacion y no una copia: si manana se anade un septimo requisito, lo hereda sin que nadie se acuerde de venir aqui | 7.4 |
| **DEC-100** | ✅ **Una categoria que el creador declaro que NO trabaja lo excluye del buscador.** Es media `BR-CAMPAIGN-007` --roja, y hasta 7.4 sin nada detras-- y es la mitad que ya se puede comprobar con lo que hay en el modelo: `creator_restrictions` contra `client_brand_categories`. El creador ya dijo que no; invitarlo es hacerle perder el tiempo a los dos. Se sigue viendo con el interruptor de descartados, para poder auditar por que falta alguien. Competidores y exclusividades vigentes llegan en 7.11 | 7.4 |
| **DEC-101** | ✅ **En la lista corta se entra a un mercado CONCRETO, derivado del pais del creador.** No se pide: es el unico que puede ser, y pedirlo seria pedirle al operador que repita un dato que el sistema ya sabe. De ahi sale el cupo por mercado (`target_creators`, 7.3) y el reparto de entregables por pais en 7.5. `agreed_amount` nace en CERO a proposito: el compromiso economico se congela al aceptar (`BR-CREATOR-008`), no al meter a alguien en una lista, y un candidato con importe es un acuerdo que nadie ha firmado | 7.4 |
| **DEC-102** | ✅ **Nadie entra en una campana cerrada, y una participacion que ya estaba solo se puede cancelar.** `campaign_creators` existia desde la Fase 2 y hasta 7.4 nadie habia escrito una fila; en cuanto se escribe la primera aparece el hueco. Una participacion en una campana terminada devenga en el ledger (9.3) contra un periodo ya liquidado, sale en el reporte «reproducible» del cliente (10.4) y cuenta en el Creator Score (14.3) por un trabajo que nunca existio. Disparadores y no `CHECK` porque la condicion esta en otra tabla. El de `UPDATE` hace falta aparte porque cerrar la campana y mover al creador son operaciones distintas; se deja pasar `cancelled` porque cerrar una campana con candidatos dentro tiene que poder dejarlos resueltos, y la alternativa seria borrar la fila --lo que `3.12` prohibe-- | 7.4 |
| **T-34** | 📋 **`BR-CREATOR-012` dice menos de lo que el esquema implica.** El texto habla solo de creadores CON TUTELA ACTIVA, y `min_creator_age` es una columna de `campaigns` que no menciona la tutela: aplicarla solo a los menores dejaria pasar a un creador de 20 anos en una campana de 21. En 7.4 se aplica a TODOS, y la edad minima efectiva es el maximo entre la de la campana y la de las categorias de la marca. Lo que hay que corregir es el texto de la regla, no el codigo | Al revisar `docs/06` |
| ~~7.4~~ | ✅ **CERRADA (2026-08-26).** El buscador de creadores es la primera pantalla que LEE el modelo del creador entero: cuatro iteraciones de la Fase 3 lo construyeron sin que nada lo leyera de una vez. Los filtros no se teclean --salen de la campana-- y se declaran UNA vez para aplicarlos y para explicarlos, porque una lista de descartes que no sea exactamente la misma que el filtro miente sobre por que falta alguien. **Dos mutaciones sobrevivieron a la primera version de las pruebas**, las dos sobre el solape de agenda: la prueba tenia el borde izquierdo y no el derecho, asi que se creia completa y miraba media regla. Es el error de un dia --once apariciones-- entrando por el lado que nadie miraba. Salieron tres cosas mas: los datos por omision del apoyo de creadores eran fijos y 7.4 es la primera iteracion que necesita varios a la vez; `verificar-pantallas.py` acusaba en falso por un `foreach` dentro de un `@php`; y `ck_terms_acceptances_backing` rechazo el apoyo nuevo --exactamente cuando tenia que hacerlo--. 339 pruebas, 980/970 aserciones de restriccion. Ver `docs/fase-7/7.4-CANDIDATOS.md` | — |
| **DEC-103** | ✅ **«2 reels» en el brief es lo que entrega CADA creador, no el total de la campana.** El brief es lo que se le dice a UNA persona; cuantos creadores hay lo dice `target_creators` de cada mercado (7.3). La alternativa --repartir un total entre los seleccionados-- obliga a decidir quien entrega que y a recalcular cada vez que alguien entra o sale, y un creador no sabria que acepta hasta que la lista este cerrada. Ademas confirma lo ya construido: el coste estimado de 7.4 ya multiplicaba tarifa por cantidad | 7.5 |
| **DEC-104** | ✅ **El monto acordado se fija al INVITAR y se congela al ACEPTAR.** `BR-CREATOR-008`: la tarifa declarada es referencia, el precio vinculante es el monto congelado en la participacion. Se fija al invitar porque el creador no puede aceptar un numero que no ha visto --`tg_ccr_compromiso` impide pasar a `invited` con cero-- y se congela al aceptar porque a partir de ahi es un acuerdo entre dos partes. Congelarlo ya en la invitacion obligaria a cancelar y rehacerla por un dedazo, llenando el historico de invitaciones canceladas que no se cancelaron por negocio: **mismo argumento que `DEC-090`**. Excepcion coherente con 7.2: en una campana declarada gratuita si se invita con cero | 7.5 |
| **DEC-105** | ✅ **Pasarse del presupuesto de creadores se BLOQUEA, y finanzas lo autoriza dejando el motivo.** La regla dice «sin aprobacion explicita de un rol autorizado, que queda auditada», asi que la autorizacion es un dato de la fila: quien, cuando y por que. `ck_camp_budget_override` exige las tres o ninguna --una firma sin explicacion no responde «por que esta campana se paso» dentro de un ano, misma forma que `ck_inv_responded`--. Lo firma finanzas y no quien monto la campana (`DEC-091`, `DEC-044`), y el motivo exige al menos diez caracteres porque «porque» no es una explicacion. Lo que cuenta como comprometido son las participaciones VIVAS: un creador que rechazo no consume presupuesto, y contarlo dejaria campanas bloqueadas por dinero que nadie se va a gastar | 7.5 |
| ~~7.5~~ | ✅ **CERRADA (2026-08-26).** `BR-CAMPAIGN-005` es roja y nombra «el presupuesto de creadores de la campana»: `campaigns` tenia `revenue_amount` y **nada mas**. Quinto caso del patron de `T-23`, 7.1, 7.2, 7.3 y 7.4, y el peor de todos: en los otros faltaba el CODIGO de una regla que se podia comprobar; aqui **faltaba el dato que la regla nombra**. Salieron dos cosas mas: el formulario de alta de campana estaba copiado en **cuatro** clases de prueba y la columna nueva las rompio a las cuatro a la vez con un error que no nombra el campo que falta --`H-16` por cuarta vez, ahora en `ConFixturas::datosDeCampana()`--; y una foranea escrita ENTRE dos `CHECK` se quedaba huerfana de coma al generar la copia sin-`CHECK`, asi que cargaba bien en desarrollo y reventaba con un 1064 en la base que imita a produccion. 358 pruebas, 1028/1018 aserciones de restriccion. Ver `docs/fase-7/7.5-COMPROMISO.md` | — |
| **DEC-106** | ✅ **Del correo enviado se guarda la HUELLA del cuerpo, no el cuerpo.** `email_log` guarda plantilla, version, idioma, asunto y `body_sha256`. El cuerpo lleva el nombre de la persona, a veces importes y a veces datos fiscales: guardarlo convierte esa tabla en una SEGUNDA COPIA de la ficha del creador, que hay que proteger, anonimizar y borrar igual que la primera. Y no se pierde nada, porque la version de la plantilla es inmutable --publicar la siguiente cierra la anterior, no la edita-- y la huella demuestra que el cuerpo enviado era exactamente el que sale de renderizarla. «Me llegaron condiciones distintas» se contesta con la version y la huella. Es la regla del proyecto sobre logs, aplicada con cabeza | 4.9 |
| **DEC-107** | ✅ **Sin plantilla en el idioma del destinatario se cae al de por defecto, y se ANOTA.** Un aviso que no llega es peor que uno en el idioma equivocado. Lo que hace que no sea una chapuza es la segunda mitad: `email_log` guarda `locale_requested` junto a `template_locale`, asi que «que plantillas faltan por traducir» es una consulta sobre envios reales, y sale en la pantalla como lo unico que pide una accion. Sin eso la caida seria silenciosa y nadie se enteraria nunca de que el portugues no existe. `pt-BR` cae antes a `pt` que a `es`: es una caida mucho mas pequena y son dos lineas | 4.9 |
| **DEC-108** | ✅ **Tres intentos espaciados (1, 5 y 15 minutos) y luego `failed` VISIBLE.** Cubre la caida pasajera del proveedor sin insistir eternamente sobre una direccion que no existe, que es la causa mas comun. `ck_el_failed` exige en la BASE que un fallido lleve cuando fallo Y por que: un `failed` sin motivo obliga a mirar el log del servidor, que es exactamente lo que esta tabla existe para evitar. Y la pantalla abre FILTRADA POR FALLIDOS, porque el modo de fallo que importa --«al creador no le llego su enlace»-- lo descubre operaciones, no un desarrollador leyendo storage/logs | 4.9 |
| ~~F4.9~~ | ✅ **CERRADA (2026-08-26).** El correo paraba media Fase 7: sin el no se invita a un creador (`7.6`), no se le manda su enlace de contrasena al aprobarlo (`5.9`), no se recupera una contrasena (`4.1`) y no se le avisa cuando cambian sus datos fiscales (`T-10`, y `BR-CREATOR-007` es 🔴). Se adelanto a proposito rompiendo el orden del roadmap. **Una prueba destapo un fallo de diseno**: `Plantillas::vigente()` resolvia por la columna puerta `current_gate`, que identifica «la ultima publicada» y no «la vigente»; publicar una version para dentro de un mes cerraba a la anterior y dejaba UN MES sin ninguna vigente. Se resuelve por PERIODO, que es `T-21` otra vez. Y sobrevivio una mutacion: cambiar el `throw` del job por un `return` dejaba el correo en `queued` para siempre --peor que `failed`, porque uno se ve y el otro parece que sigue en camino--. PHPStan caza ademas que la opcion `--version` del comando choca con la de Symfony Console: no era estilo, el comando no se podia registrar. 380 pruebas, 1070/1060 aserciones de restriccion. Ver `docs/fase-4/4.12-CORREO.md` | — |
| **DEC-109** | ✅ **El aviso de cambio sensible sale al CAPTURAR, no al aprobar.** Es lo unico que le da al creador margen para decir «yo no fui» mientras el cambio todavia se puede parar. Avisar despues de aprobarlo es contarle un hecho consumado: si alguien suplanto su cuenta bancaria, el dinero ya va camino de otro sitio. Hay prueba dedicada: el aviso sale con el perfil todavia en `pending` | 4.13 |
| **DEC-110** | ✅ **El correo de aviso NO lleva el dato dentro.** «Alguien registro un cambio en sus datos de pago; si no ha sido usted, respondanos.» Sin numero, sin banco, sin regimen, sin RUC. Un correo se lee en pantallas ajenas, se reenvia y se queda en buzones que no controlamos --y el escenario del que nos defendemos es precisamente que alguien tenga acceso a ese buzon--. Para decir «yo no fui» no hace falta ver el numero. Misma regla que ya gobierna la bitacora (la mascara, nunca el numero) y `email_log` (la huella, nunca el cuerpo) | 4.13 |
| **DEC-111** | ✅ **Si el aviso no se puede enviar, el cambio sigue adelante.** Bloquear la captura de un dato fiscal porque un SMTP se cayo convierte un problema de infraestructura en un creador al que no se le puede corregir un dato. Pero el fallo NO se traga: si el correo se encolo aparece en `/correos` como `failed` con su motivo, y si ni siquiera se pudo encolar --no hay plantilla, que es un fallo de configuracion-- se registra con `report()` y queda la fila de `domain_events` diciendo que el hecho ocurrio sin que saliera aviso | 4.13 |
| **DEC-112** | ✅ **Los modulos se comunican por EVENTOS, no llamandose.** `deptrac.yaml` no deja que Creator conozca Communication ni al reves, y no es burocracia: si Creator importara Communication, un SMTP caido podria tumbar la captura de un dato fiscal. `App\Shared\Eventos` estrena `domain_events` --disenada en 2.4 y sin una sola fila hasta hoy-- y reparte asi: Creator levanta un nombre y un array, Communication recibe un nombre y un array, y ninguno importa al otro. El hecho se GUARDA antes de despachar, para que conste aunque el oyente reviente. Y no es la bitacora: `Bitacora` responde «quien toco que y que cambio» y es evidencia; esto responde «que paso, para que otros reaccionen» y dispara trabajo | 4.13 |
| **DEC-113** | ✅ **El enlace de alta dura 72 h; el de recuperacion, 1 h.** Son la misma pieza con dos relojes: el de alta llega SIN AVISAR y puede caer un viernes por la noche; el de recuperacion lo pide alguien que esta delante de la pantalla ahora. Un solo plazo obligaria a elegir entre dejar la ventana larga del alta valiendo para recuperar, o darle una hora a quien no sabia que iba a recibir nada. La caducidad se guarda **en la fila**, no es una constante global: un enlace sabe cuando muere | 5.9 + 4.1 |
| **DEC-114** | ✅ **De un token de contrasena se guarda la HUELLA, nunca el token.** `bin2hex(random_bytes(32))` --no `Str::random()`, no `uniqid()`, no el `uuid` de la fila-- y en `password_links` solo entra su `sha256`. Misma decision que `email_log` (la huella del cuerpo, no el cuerpo) y que los medios de pago (la mascara, no el numero), y aqui es la mas importante de las tres: **quien lea esta tabla no puede entrar en ninguna cuenta**. Corolario que obligo a romper el patron de `DEC-112`: el aviso a Communication **no** puede ir por `Eventos::ocurrio()`, porque ese bus PERSISTE el payload y el payload lleva el enlace dentro. Va por un evento propio de Identity, que Communication ya tiene permitido conocer. El HECHO --«se emitio un enlace de tipo X para el usuario Y»-- si se guarda en `domain_events`, y sin el token | 5.9 + 4.1 |
| **DEC-115** | ✅ **Pedir recuperacion contesta lo mismo exista o no el correo.** «Si esa direccion tiene una cuenta activa, le acaba de salir un enlace», a los dos, y sin enviar nada en el segundo caso. Distinguirlos convierte la pantalla en un buscador de cuentas dadas de alta: con una lista de direcciones se sabe en un rato cuales son clientes nuestros, y eso vale dinero para quien hace phishing dirigido. **El precio es real**: quien se equivoca de correo no se entera, y se compensa diciendolo en la propia pantalla en vez de callarlo. La prueba lo afirma byte a byte quitando el testigo anti-CSRF: comparar solo la clave de sesion dejaria pasar una version que escribiera «no tenemos ese correo» dentro de la vista | 4.1 |
| **DEC-116** | ✅ **Un enlace nuevo invalida el anterior, y usar uno mata todos los demas.** Quien pide otro suele hacerlo porque sospecha del primero; dejarlo vivo seria dejar abierta justo la puerta que queria cerrar. Lo garantiza la BASE con la decimotercera columna puerta (`uq_pl_vigente`), no el servicio. Y **revocar no es marcar usado**: son dos columnas a proposito, porque el dia que alguien pregunte «.llego a usar el enlace?» la respuesta tiene que salir de una columna que solo se escribe cuando lo uso. La primera version de la tabla las mezclaba --una columna menos-- y ademas chocaba con `ck_pl_used`, que exige la IP de quien lo usa: a un enlace que sustituyes no le puedes poner una IP. Caducar no se escribe: se deduce de `expires_at` | 5.9 + 4.1 |
| **DEC-117** | ✅ **El token no se queda en la barra de direcciones.** El enlace del correo lo lleva --no hay otra forma--, pero esa ruta solo lo guarda en la sesion y redirige a una URL sin el. Una URL con un token dentro viaja en la cabecera `Referer` a cualquier recurso externo de la pagina --y estas pantallas cargan tipografias de un dominio de terceros--, se queda en el registro de accesos del servidor y en el historial, y se copia entera cuando alguien la pega en un chat preguntando que es. Al guardarlo la sesion se **vacia y renueva** (`invalidate()`, no `regenerate()`: el segundo cambia el identificador y conserva el contenido, y aqui el contenido es lo que sobra) | 5.9 + 4.1 |
| **DEC-118** | ✅ **La portada se bifurca por TIPO DE USUARIO, y `SESSION_DRIVER=database` pasa a ser requisito de seguridad.** Dos consecuencias de la misma causa: `5.9` crea la primera cuenta que no es del equipo. **(a)** `/panel` ensenaba a cualquier autenticado los totales de creadores, clientes y campanas mas el inventario del esquema; nadie lo habia pensado porque hasta hoy todos los usuarios eran internos. Se bifurca por `user_type` y no por permiso --un creador no tiene ninguno, y comprobar permisos que dicen «no» a todo deja una pantalla vacia en vez de una que explica donde esta--. **(b)** Poner una contrasena nueva borra las filas de `sessions` de ese usuario y rota `remember_token`; con `SESSION_DRIVER=file` lo primero es imposible --no hay forma de saber que archivo es de quien-- y una contrasena nueva convivira con la sesion abierta de quien entro con la vieja, que es no haber hecho nada | 5.9 + 4.1 |
| ~~5.9~~ | ✅ **CERRADA (2026-08-26).** Aprobar una solicitud crea la cuenta del creador, escribe `creators.user_id` --una columna que existia desde la Fase 3 y no escribia nadie-- y le manda su enlace de 72 h. La cuenta nace con el hash de 32 bytes aleatorios que **no se guardan, no se muestran y no se devuelven**: no se puede entrar en ella hasta usar el enlace. Y crear la cuenta va FUERA de la transaccion de la aprobacion: aprobar es la decision de negocio, dar acceso es una consecuencia, y un correo ya ocupado por un usuario interno no puede deshacer la decision. Ver `docs/fase-5/5.9-ENLACE-DE-CONTRASENA.md` | — |
| ~~4.1~~ | ✅ **CERRADA (2026-08-26).** `/recuperar`, con `DEC-115` (misma respuesta), `DEC-117` (URL sin token) y limite doble: por IP en la ruta y **por correo** en el controlador, que es el que impide inundar un buzon concreto desde IPs distintas. Se construyo junto con `5.9` porque comparten la unica pieza dificil. 426 pruebas, 1.118/1.108 aserciones de restriccion, 20 mutaciones y las seis puertas en verde. **Dos mutaciones sobrevivieron y las dos ensenaron algo**: una era codigo equivalente --se arreglo haciendolo dejar de serlo, porque «no hay token en la sesion» y «el token no vale» piden cosas distintas de la persona-- y la otra destapo que mi propia prueba era un FALSO VERDE: comparaba el identificador de sesion antes y despues, y el cliente de PHPUnit ya lo cambia solo | — |
| **T-36** | 📋 **`usuarios:crear` sigue tecleando la contrasena del usuario interno.** La pieza que hace falta --`EnlacesDeContrasena::emitir($id, 'initial')`-- ya existe y funciona desde `5.9`, asi que el comando puede pasar a crear la cuenta sin contrasena utilizable y mandar el enlace, cerrando del todo el hueco de `T-23`: hoy sigue habiendo un momento en el que **dos personas conocen la credencial** de quien aprueba perfiles fiscales. Es un cambio de pocas lineas pero toca un comando con su propio flujo (`--generar`, el aviso de `B-2`) y merece hacerse mirando | Proxima iteracion de identidad |
| **T-37** | 📋 **`VARBINARY` faltaba en el reconocedor de tipos de `generar-triggers.py` y puede faltar algo mas.** `^BINARY` no casaba con `VARBINARY(16)`: la columna no entraba en la lista y el `CHECK` que la nombraba se generaba como un disparador con el identificador suelto. Arreglado, y **se vio porque MySQL grita**; el hueco llevaba desde la Fase 2 en `audit_logs.ip_address` y `creator_identity_verifications.ip_address`, dos columnas que nunca habian aparecido en un `CHECK`. Lo que queda por hacer es la comprobacion que lo habria cazado antes: que el generador **falle** cuando una linea de una tabla parece definicion de columna y el reconocedor de tipos la descarta, en vez de seguir en silencio | Antes de anadir un tipo de columna nuevo |
| **DEC-119** | ✅ **El creador contesta la invitacion EL MISMO, por enlace.** Su portal (`F6`) sigue bloqueado por `T-09`, asi que no hay pantalla donde entre a decidir. La alternativa era que un operador tecleara «dijo que si por WhatsApp», y eso convierte una ACEPTACION --de la que sale lo que se le paga a una persona-- en la palabra de un tercero. Con enlace hay una IP, una hora y un token que solo el tenia. Y no habia que construir nada: es la pieza de `5.9`, ya probada. `invitations.channel` admite otros canales desde la Fase 2; el dia que exista el de WhatsApp, la fila ya sabe distinguirlo | 7.6 |
| **DEC-120** | ✅ **El plazo para contestar vive en la CAMPANA, no en cada invitacion.** `campaigns.invitation_hours`, 72 por omision, entre 1 hora y 30 dias (`ck_camp_invitation_hours`). Un solo numero que explicar y un solo sitio donde cambiarlo; por invitacion seria un dato que hay que decidir cuarenta veces y que nadie mira dos. **Y caducar necesita un comando aunque la caducidad se deduzca**: `expires_at` contra el reloj ya impide contestar, pero la participacion seguiria en `invited` para siempre, su importe seguiria contando contra el presupuesto (`BR-CAMPAIGN-005` bloqueando a otros por dinero que nadie se va a gastar) y el cupo del mercado no se liberaria. `invitaciones:caducar` cada diez minutos: el plazo minimo es una hora, asi que diez minutos deja el retraso maximo en un 17% del plazo mas corto posible | 7.6 |
| **DEC-121** | ✅ **Rechazar no cierra la campana para ese creador, y quedan las DOS rondas.** Pasa de verdad: rechaza por el importe, finanzas sube el presupuesto, se vuelve a preguntar. Prohibirlo obligaria a renegociar fuera del sistema. La constancia vive en `invitations` --una fila por ronda, con su motivo y con lo que se ofrecio entonces--, que es justo para lo que la Fase 2 diseno la tabla («cuantas veces hubo que insistir alimenta el Creator Score»). Lo que si se limpia al reinvitar es `campaign_creators.declined_at`: esa columna dice cuando se rechazo la ronda ACTUAL, y dejarla puesta haria que un `accepted` conviviera con una fecha de rechazo. El motivo es de lista cerrada para poder contestar «.por que nos dicen que no?» --si el 70% es el importe, el problema es el presupuesto y no el reclutamiento-- y **no decide quien se puede reinvitar** | 7.6 |
| **DEC-122** | ✅ **La invitacion COPIA el importe con el que salio, y el importe no se mueve mientras este viva.** `BR-CREATOR-008` congela el precio al ACEPTAR (`tg_ccr_compromiso`, 7.5) y entre el envio y la respuesta `agreed_amount` se podia mover: al creador le llegaba «te pagamos 1.500», alguien lo bajaba a 900, y aceptaba 900 **sin haberlo visto nunca**. No hace falta mala fe; basta con dos personas sobre la misma campana. Se cierra por los dos lados: `amount_snapshot` --misma decision que `payment_term_days_snapshot` y `billing_legal_entity_id`-- y `tg_ccr_monto_con_invitacion`, que obliga a anular la invitacion antes de renegociar. Y como en `5.9`, **responder y anular son dos columnas**: `responded_at` tiene que contestar «.llego a contestar?» sin ambiguedad. Decimocuarta columna puerta del esquema | 7.6 |
| **DEC-123** | ✅ **Pedir un correo se hace desde `Shared`, no desde el modulo que lo necesita.** `deptrac.yaml` dice `Communication: [Framework, Shared, Core, Identity]`. En `5.9` el evento del enlace vivio una iteracion dentro de Identity y funciono **de milagro**: Identity esta en esa lista. Campaign no, y `7.6` necesita mandar un correo igual. Sube a `App\Shared\Eventos\CorreoPedido` con un solo oyente en Communication: cualquier modulo puede pedir un correo, ninguno conoce a Communication y Communication no conoce a ninguno. **No es `EventoOcurrido`** y no puede serlo --ese persiste su payload en `domain_events` y aqui el payload lleva el token en claro--; el HECHO si se registra, por separado y sin el token | 7.6 |
| ~~7.6~~ | ✅ **CERRADA (2026-08-26).** La invitacion, con la tabla `invitations` que llevaba desde la Fase 2 sin una sola fila. 471 pruebas, 1.182/1.172 aserciones de restriccion, 16 mutaciones y las seis puertas en verde. **Tres cosas se descubrieron probando.** (a) «Caducada» y «te mandamos otra» decian lo mismo: en la tabla las dos muertes son un `revoked_at`, y a quien se pasaba del plazo se le mandaba a buscar en su buzon un correo que no existe. (b) Una asercion de la suite SQL salia verde **por el motivo equivocado**: ponia 900 sobre una fila que ya tenia 900 --sin cambio no se dispara ningun trigger-- y ademas las dos participaciones de la semilla estan aceptadas, donde manda `tg_ccr_compromiso` y no la regla de esta iteracion. (c) Una mutacion sobrevivio: quitar el veto del controlador no ponia nada en rojo porque todas las pruebas del back-office recorrian el camino feliz. Ver `docs/fase-7/7.6-INVITACIONES.md` | — |
| ~~T-39~~ | ✅ **RESUELTA (2026-08-26, iteracion 7.6). Un fallo INTERMITENTE en `ConFixturas` que llevaba desde 4.9 escondido.** `creadorActivo()` pasaba `eligible_from => now()->subDay()` y `medioDePago()` ponia `verified_at => now()->subDay()`: dos llamadas distintas, separadas por la creacion de un usuario. `ck_cpm_eligible_after` exige `eligible_from >= verified_at` --un enfriamiento negativo no significa nada-- y cuando las dos llamadas caian a los dos lados de un segundo, la base rechazaba el INSERT. Salio una vez en una corrida de 471 pruebas, en `CandidatosTest`, que no tiene **nada** que ver con medios de pago: la peor forma de fallar, porque parece un problema de la prueba que toco. Ahora `medioDePago()` toma UN instante y deriva de el las dos columnas | — |
| **DEC-127** | ✅ **Cuatro alertas en el panel de seguimiento, y su orden NO es por gravedad.** Campana sin confirmar con el arranque a menos de 14 dias, preguntas de creadores sin atender, invitaciones que caducan en menos de 12 h, y cupo de mercado sin cubrir. La de «sin confirmar» va PRIMERA porque **bloquea a las demas**: sin confirmar no se puede invitar a nadie, asi que un cupo corto ahi no se arregla reclutando. Y las preguntas van antes que las invitaciones urgentes porque un creador que pregunto y no recibe respuesta MIENTRAS su plazo corre es la combinacion exacta que convierte un si en un silencio. Cada alerta dice **que pasa y que hacer**: una que solo dice que hay un problema obliga a buscarlo, y entonces deja de leerse. **Y «cubierto» es ACEPTADO, no invitado**: una invitacion sin contestar es una plaza esperando, y contarla como cubierta es como se llega al dia de arranque con la mitad del equipo y los numeros en verde | 7.7 |
| **DEC-128** | ✅ **En el seguimiento, el dinero SI y el margen solo con su permiso.** Presupuesto, comprometido y disponible los ve quien puede ver la campana: sin esos tres numeros no se decide a quien invitar, y ponerlos detras de un permiso que entonces necesitaria todo el mundo seria un permiso que no protege nada. El margen sigue detras de `campaign.view_margin` (`BR-FIN-007`) y **no se calcula y luego se esconde: no se calcula** --un dato que llega a la vista es un dato que un `@if` mal puesto puede ensenar, y `BR-SEC-001` es 🔴--. La diferencia no es sutil: lo comprometido es lo que se le paga a los creadores; el margen es lo que gana la empresa. Y el disponible se ensena NEGATIVO cuando lo es: redondearlo a cero esconderia justo el caso que hay que ver, una campana que se paso porque finanzas lo autorizo | 7.7 |
| ~~7.7~~ | ✅ **CERRADA (2026-08-26).** El panel de seguimiento, que el roadmap llama «la pantalla mas usada del sistema». Pantalla aparte de la ficha a proposito --la ficha contesta «que es» y se abre al montar; esta contesta «como va»-- y el listado enlaza a una o a otra segun `confirmed_at`. 530 pruebas, **14 mutaciones y 14 detectadas a la primera**, cosa que no habia pasado hasta ahora: este servicio **no tiene estado**, todo son consultas puras sobre datos que las iteraciones anteriores ya dejaron probados. Lo que si fallo fue **el fixture**: `ConFixturas::mercadoDe()` declara cupo 5 por omision, asi que la campana nacia con una alerta y todas las afirmaciones de «sale UNA alerta» habrian salido verdes con dos. Lo caza una asercion de premisa en el `setUp`. Ver `docs/fase-7/7.7-SEGUIMIENTO.md` | — |
| **DEC-124** | ✅ **Preguntar NO mueve el plazo de la invitacion.** La alternativa --congelarla hasta que alguien conteste-- deja el importe comprometido para siempre si nadie contesta, que es justo el agujero que `invitaciones:caducar` existe para tapar. La pantalla se lo dice al creador con todas las letras: callarlo dejaria a alguien esperando tranquilo mientras su invitacion caduca, que es peor que no dejarle preguntar. Quien invito ve la pregunta en la lista de candidatos y decide; si hace falta mas tiempo, anula y manda otra. **Y no hay respuesta dentro de la aplicacion**: el equipo contesta por correo, que es donde el creador ya esta. Un hilo de ida y vuelta es un modulo de mensajeria --notificaciones, no leidos, permisos-- y habria multiplicado por tres la iteracion para resolver lo que un boton de «Responder» ya resuelve. Lo que si hay es un DUENO: «me hago cargo» marca quien atiende y el segundo clic no pisa al primero | 7.6b |
| **DEC-125** | ✅ **Cuando un creador contesta, se avisa a QUIEN LE INVITO y a nadie mas.** Es quien espera la respuesta y quien va a hacer algo con ella. Avisar a todo el equipo son cuarenta correos por campana a cada uno, y un buzon asi se filtra a una carpeta y deja de leerse --la forma cara de no avisar a nadie--. **El precio, dicho**: una invitacion mandada por alguien que luego se va de la empresa queda MUDA. A una cuenta desactivada no se le escribe: no avisa a nadie y puede acabar en un buzon reasignado. El hecho no se pierde, queda en `domain_events` y se ve en la lista | 7.6b |
| **DEC-126** | ✅ **`usuarios:crear` deja de teclear la contrasena; el alta interna usa el mismo enlace de 72 h que la del creador.** Cierra `BR-SEC-004` (🔴) del todo. `must_change_password` era el parche: obligaba a cambiarla DESPUES y dejaba una ventana --de minutos o de meses-- en la que dos personas conocian la credencial, y justo en las cuentas donde la base **exige dos personas distintas** para el dinero. Ahora no hay ventana: la contrasena no existe hasta que la escribe su dueno. `usuarios:contrasena --enlace` hace lo mismo para reponer y **no toca la contrasena actual** --si el correo no llega, la persona no se queda peor de como estaba--. Teclearla a mano sigue existiendo a proposito, como cristal de emergencia para cuando el correo no sale (`Q-20`), y el comando avisa cada vez de que ese es el camino excepcional | 7.6b |
| ~~T-36~~ | ✅ **RESUELTA (2026-08-26, iteracion 7.6b).** Ver `DEC-126`. La pieza ya estaba: `Cuentas::paraCreador()` y `Cuentas::paraInterno()` se diferencian en tres argumentos, y eso es lo que se cobro la decision de `5.9` de poner esa clase en Identity en vez de en Creator | — |
| ~~T-38~~ | ✅ **RESUELTA (2026-08-26, iteracion 7.6b).** Ver `DEC-124`. Ver `docs/fase-7/7.6b-CABOS-SUELTOS.md` | — |
| ~~T-40~~ | ✅ **RESUELTA (2026-08-26). Un 500 al repetir un formato en el brief de UN MERCADO, encontrado por el usuario probando.** `campaign_requirements` tiene DOS indices unicos --`uq_creq_general` y `uq_creq_market`, que llego en 7.3-- y el controlador solo traducia el primero: cuando se escribio, los mercados no existian. El `1062` del segundo se escapaba del `catch` y salia la traza entera. **No fallaba en pruebas**: la suite SQL comprobaba que la base lo rechaza --y lo rechaza-- pero nadie comprobaba que la PANTALLA lo explique. Es la leccion de siempre un piso mas arriba: que la regla exista no significa que alguien la sepa contar | — |
| ~~T-41~~ | ✅ **RESUELTA (2026-08-26). La bitacora entera caida por UNA fila con una lista dentro, encontrado por el usuario probando.** `MarcasController` guarda `categorias => ['antes' => [1,2], 'despues' => [3]]` --correcto: una marca tiene varias categorias y el cambio interesante es la lista entera-- y la vista hacia `{{ $v['antes'] }}` a pelo. Con un array eso es `htmlspecialchars(): must be of type string, array given`, o sea un 500 que se lleva la pagina entera: bastaba UNA fila asi para no poder ver NINGUNA. Y la bitacora es precisamente lo que se mira cuando algo ha ido mal. Se arregla en `Bitacora::legible()` y no en la vista, porque la clase que decide que se guarda es la que sabe como se lee, y porque hay filas viejas con arrays dentro | — |
| **DEC-129** | ✅ **Los entregables se generan SOLOS al aceptar, y la generacion es idempotente.** El brief ya lo dice todo --cuantos, de que formato, con cuantos dias de plazo--, asi que pedirle a alguien que lo teclee otra vez por cada creador es pedirle que copie un dato que el sistema ya tiene. Lo que decide no es el ahorro sino el MODO DE FALLO: si se teclea a mano, el dia que se olvide **ese creador no tiene nada que entregar y nadie se entera** hasta que la campana termina sin su contenido; si se generan solos, el fallo es que sobra un entregable y se ve en la lista. Va por evento (`campaign_creator.accepted` --> `GenerarEntregables`) y **no dentro de `aceptar()`**, porque `Campaign` no puede conocer `Content`: la misma frontera que se cobro el panel de 7.7. Idempotente porque aceptar dos veces no puede pasar --el enlace es de un solo uso-- pero una reanudacion de la cola o un reintento manual si, y duplicar el trabajo de un creador es la clase de error que se descubre tarde y mal | 8.1 |
| **DEC-130** | ✅ **El brief que se usa es el EFECTIVO, no el general.** `Mercados::briefEfectivo()`, que ya resolvia `N-03`: si el mercado del creador tiene brief propio, ese REEMPLAZA al general. Un creador peruano en una campana con brief especifico para Peru recibe el de Peru, y las etiquetas tambien --que es justo por lo que 7.2 dejo los hashtags fuera hasta que existieran los mercados--. Un entregable generado del brief general cuando hay uno de mercado le pide al creador lo que la campana NO quiere de el, y el error solo se ve al revisar | 8.1 |
| **DEC-131** | ✅ **Se entrega un ENLACE externo, y opcionalmente una imagen.** El contenido de verdad --un reel de 80 MB-- no sube aqui y no deberia: el creador ya lo tiene en Drive o en la propia plataforma, y almacenarlo otra vez es coste y responsabilidad sin contrapartida. Se guarda el enlace, obligatoriamente `https://`, mas una imagen de referencia opcional por `Almacen` (10 MB). El `https://` se comprueba **en tres sitios a proposito**: `EntregarRequest` da el mensaje junto al campo, `vetoParaEntregar()` lo da junto a los demas motivos, y `ck_dv_url_https` impide la fila **venga de donde venga**. Sin el tercero, un comando o un import mete `javascript:alert(1)` en una columna que una vista pinta dentro de un `href`; sin el segundo, el creador ve un 3819 | 8.1 |
| **DEC-132** | ✅ **Si al caption le faltan las etiquetas del brief, NO SE ENVIA, y se le dice cuales.** Comprobacion objetiva y barata que ahorra una ronda de correccion entera: el creador lo arregla en diez segundos en vez de enterarse tres dias despues. Avisar y dejar pasar acaba siempre en un aviso que nadie lee; dejarlo para la revision traslada a una persona la clase de tarea que una maquina hace mejor. **Sin distinguir mayusculas** --`#ACMEVerano` y `#acmeverano` son el mismo hashtag para las plataformas, y rechazar por la caja seria rechazar por algo que da igual-- y devolviendo **todos** los motivos a la vez, porque quien entrega desde el movil prefiere una lista de tres cosas que arreglar a tres intentos | 8.1 |
| ~~T-43~~ | ✅ **RESUELTA (2026-08-26). Cuatro mensajes que en PRODUCCION no caben, rotos desde 7.4 y verdes en las tres bases.** `MESSAGE_TEXT` es `VARCHAR(128)` y MySQL 8 y Percona 5.7 **no truncan**: sueltan `1648 Data too long for condition item` en lugar del `45000` que el disparador queria dar. MariaDB si lo deja pasar, o sea que el motor de desarrollo perdona y el de produccion no. Ninguna suite lo vio porque todas comprobaban «esto tiene que fallar» y **1648 tambien es fallar**: el rechazo se leia igual estuviera la regla puesta o rota. Se cierra por los dos lados: los cuatro mensajes caben ahora con margen, y **`tools/verificar-mensajes.py`** lee `information_schema.TRIGGERS` de la base de verdad --no el fuente, que se arma con concatenacion de PHP en las migraciones y con heredocs en el esquema-- y falla si alguno pasa de 128. Corre contra los dos motores en `correr-todo.sh` y **contra Percona** en el CI. Y la leccion se aplico donde nacio: 7.3, 7.4, 7.6 y 8.1 ya no comprueban «esto falla» sino «esto falla POR ESTO» | 8.1 |
| ~~T-44~~ | ✅ **RESUELTA (2026-08-26). Una suite que se creia limpia y corria sobre filas de otra.** `8.1-entregables.sh` abria con `DELETE FROM deliverables` y exigia la tabla vacia. El DELETE fallaba con `1451` --`content_reviews` y `publications` apuntan a esas filas con `ON DELETE RESTRICT`, y quien las creo fue la suite 2.12, que corre antes **en la misma base**-- y el `2>/dev/null` se comia el error. Resultado: cinco fallos, y varias aserciones que esperaban RECHAZO **pasando por el motivo equivocado**. La premisa correcta no era «la tabla esta vacia» --nunca lo va a estar, y exigirlo es pedirle a una suite hermana que no haga su trabajo-- sino «no hay filas MIAS». La suite trabaja ahora sobre su propia participacion, elegida POR NOMBRE y no por `ORDER BY id LIMIT 1`, y **comprueba que su limpieza funciono** en vez de mandar el error a `/dev/null` | 8.1 |
| **Q-56** | ❓ **.`deliverable_versions` es evidencia?** Hoy se puede borrar fisicamente. El criterio de 3.12 para entrar en la lista de `no_delete` es «la fila es EVIDENCIA de algo que paso, y de ella depende dinero o una obligacion legal», y una version entregada cumple los dos: es lo que el cliente aprueba y lo que dispara el pago. Borrar la version 1 despues de aprobar la 2 reescribe la historia de una ronda de correccion que se facturo. **No se ha cambiado en 8.1** porque 3.12 eligio dieciocho tablas de una lista y no hay documento que diga si esta se considero y se descarto o si simplemente no estaba (`T-29`): decidirlo con el documento delante, no de memoria. Nota practica si se decide que si: la suite de 8.1 dejaria de poder limpiar lo suyo, que es el caso «TOZUDAS» que `verificar-fixturas.py` ya documenta, y la propia suite lo dice en el sitio donde se rompera | 8.1 |
| **DEC-133** | ✅ **Solo las rondas del CLIENTE cuentan contra el precio, y son POR ENTREGABLE.** Dos cosas en una porque las dos las decidio la misma conversacion. (1) Que nuestro equipo le pida al creador rehacer el encuadre antes de ensenarselo a nadie es control de calidad NUESTRO; cobrarselo al cliente seria cobrarle nuestro propio error. `reviewer_side` distinguia `platform` de `client` desde la Fase 2 y lo que faltaba era que alguien lo usara. (2) `revision_rounds_used` vivia en `campaign_creators` --dos rondas POR CREADOR--, asi que con un creador que entrega dos reels y tres stories, dos correcciones sobre el primer reel dejaban las otras cuatro piezas **sin ninguna ronda**, y el cliente habia pagado dos por cada una. Baja a `deliverables`, que es donde se aplica la regla. **Y la columna vieja se va, no se queda como suma**: una suma almacenada que nadie recalcula se desvia en silencio, y la suma por creador --la que alimenta el Creator Score-- sale de `content_reviews` con un `SUM()` que nunca miente. Tenia cero filas: quitarla hoy es gratis y dentro de tres meses no | 8.3 |
| **DEC-134** | ✅ **Hasta `8.5`, quien lleva la cuenta marca de parte de quien viene la correccion.** El portal del cliente es `8.5`. Sin esto, en `8.3` **nada** consumiria ronda y el veto de «rondas agotadas» naceria muerto: el QUINTO caso de una regla escrita sobre una tabla vacia en un proyecto que ya lleva cuatro (`campaign_creators` en 7.4, `domain_events` en 4.13, `invitations` en 7.6, `deliverables` en 8.1). La salida no es inventarse el portal: es reconocer como trabaja una agencia HOY --el ejecutivo traslada el comentario del cliente y marca que es suyo--. En `8.5` el enlace firmado escribe la misma fila sin intermediario y `Revisiones` no se entera. Quien traslada queda anotado igual en `reviewer_user_id`: quien la escribio responde de ella. Y `ck_cvw_firma` exige usuario solo en las NUESTRAS, porque la del cliente en 8.5 no tendra cuenta detras | 8.3 |
| **DEC-135** | ✅ **Pasarse de las rondas incluidas se BLOQUEA y exige decidir y firmar; y el cargo NO va a `campaign_costs`.** No un aviso: un aviso que se puede saltar acaba en un cargo que se descubre al facturar, que es tarde para discutirlo con el cliente. Cuando la ronda que se va a pedir es la tercera de dos incluidas hay que decir si se cobra o se absorbe, y quien lo dice queda en `authorized_by_user_id` (`ck_cvw_over`, en la base y no solo en la pantalla). **`campaign_costs` seria el sitio equivocado y era tentador**: es lo que gastamos NOSOTROS y resta del margen (`BR-FIN-011`), mientras que una ronda de mas facturada al cliente es INGRESO --meterla ahi bajaria el margen de una campana que acaba de ganar dinero--. Aqui queda la decision y la pantalla de entregables la ensena como pendiente de facturar; donde acaba la linea cuando exista F9 es `Q-57`, y se decide con `invoice_lines` delante | 8.3 |
| **DEC-136** | ✅ **Tres permisos y no uno: revisar, aprobar y autorizar una ronda de mas.** `content.review` abre la cola y pide cambios; `content.approve` da el visto bueno --lo que deja el contenido listo para el cliente y, con F9, lo que dispara el pago--; `content.extra_round` autoriza el cargo. Mismo criterio que separo capturar de aprobar en el perfil fiscal (`DEC-062`). `content_reviewer` tiene los dos primeros y **no** el tercero: esa decision es de dinero --o se le cobra al cliente o se come el margen-- y quien revisa contenido no tiene por que cargar con ella; la tiene `campaign_manager`, que responde del margen y es quien va a tener que explicarsela al cliente. **Los tres se comprueban en el POST** y no solo en la ruta: los tres botones llegan por el mismo formulario, y esconder un boton no es una regla de autorizacion. Y la cola es una BANDEJA GLOBAL, no una por campana: revisar es trabajo por lotes, y una cola por campana obliga a recorrer campanas para descubrir si hay algo esperando --lo que se descubre recorriendo se descubre tarde--. Ordenada por lo que lleva mas esperando y no por lo que vence antes, que es como una cola deja a alguien esperando para siempre | 8.3 |
| ~~T-45~~ | ✅ **RESUELTA (2026-08-26). «Un veredicto no se edita» lo decia el documento de 2.12 desde el primer dia y no lo impedia nada.** `content_reviews` se describia como append-only y aceptaba `UPDATE` sin mas. Un veredicto justifica una ronda cobrada: si se puede reescribir, reconstruir por que se facturo algo depende de que nadie lo tocara. `tg_cvw_inmutable` bloquea el UPDATE ENTERO y no columna por columna, porque no hay ninguna columna de esa tabla que tenga sentido cambiar despues | 8.3 |
| ~~T-46~~ | ✅ **RESUELTA (2026-08-26). Una puerta que daba rojo por algo que nadie podia arreglar, desde 4.9.** `verificar-equivalencia.py` denunciaba una diferencia entre motores que no era del proyecto: MariaDB no tiene tipo `JSON` --es `LONGTEXT` mas un CHECK implicito `json_valid(<columna>)` que se llama COMO LA COLUMNA-- y MySQL y Percona si. En el CI salia verde porque alli la base con CHECK es MySQL; en la maquina de quien desarrolla, roja siempre, y una puerta que da rojo por algo que no se puede arreglar ensena a ignorar el rojo. Se ignora ahora por las DOS condiciones a la vez --nombre que no empieza por `ck_` Y clausula con `json_valid`--: el proyecto declara cuatro CHECK de `json_valid` a proposito (`ck_domain_events_payload`, `ck_audit_logs_changes`, `ck_pev_payload`, `ck_sas_extra`) y filtrar solo por la clausula los borraba a los cuatro. Esa habria sido la version mala del arreglo: una puerta que deja de ver lo que existe para vigilar | 8.3 |
| **Q-57** | ❓ **.Donde acaba la linea del cargo por una ronda de correccion de mas?** Hoy queda la DECISION en `content_reviews` --se cobra o se absorbe, quien lo autorizo y cuando-- y la pantalla de entregables la ensena como pendiente de facturar. No va a `campaign_costs` a proposito (`DEC-135`). Cuando exista facturacion (F9) habra que decidir si es una `invoice_lines` que se genera sola, un concepto aparte, o algo que finanzas teclea leyendo esta lista. **Se decide con `invoice_lines` delante, no de memoria**, y hasta entonces la lista de la pantalla es el registro | 8.3 |
| **DEC-137** | ✅ **El puntero apunta a la version APROBADA, y su clave ajena es COMPUESTA.** «La ultima» sale de un `MAX(version_number)` y guardarla seria una copia que se puede desviar --el proyecto acaba de quitar una columna por eso mismo, el contador de rondas de 8.3--. La aprobada no es derivable sin recorrer `content_reviews`, y es el dato del que cuelgan `8.6` --se publica lo aprobado-- y `8.7` --se archiva evidencia de eso--; sin ella las dos tendrian que adivinar cual. **Y ahi esta casi todo el valor**: `FOREIGN KEY (approved_version_id, id) REFERENCES deliverable_versions(id, deliverable_id)`. Una clave simple garantizaria que la version EXISTE; esta garantiza que es DE ESTE ENTREGABLE --sin la segunda columna, un UPDATE mal escrito deja el entregable de Ana apuntando a la version aprobada del de Luis y la fila sigue siendo valida--. Mismo patron que `fk_ccr_market_campaign` (7.3). Ademas impide borrar la version apuntada mientras siga aprobada, y `ck_del_approved_version` exige que aprobado y puntero vayan juntos o no vayan: un dato que a veces esta es igual de util que no tenerlo. **La columna espero dos iteraciones a proposito**: antes de 8.3 no habia nadie que la escribiera, y habria sido el sexto caso de una columna que existe y no tiene una sola fila | 8.2 |
| **DEC-138** | ✅ **Un entregable aprobado se puede REABRIR, con motivo de lista cerrada y firma.** Con 8.3 era un callejon sin salida --ni mas veredictos ni mas versiones-- y el cliente cambia de opinion y a veces se aprueba por error: el unico camino cuando eso pasara, y va a pasar, era que alguien tocara la base a mano. **Reabrir no deshace nada**: es otra fila en `content_reviews` con `outcome='reopened'`, su motivo y su firma, y la aprobacion anterior se queda en el historial. Misma forma que la anulacion de un perfil fiscal en 3.11, y por la misma razon: el registro tiene que contestar «.que paso?» y no solo «.como esta ahora?». El motivo es de lista cerrada para poder contestar «.por que se reabren las piezas?» con un numero --si el 70% es «se aprobo por error», el problema es la pantalla de revision y no el cliente-- y «otro» exige explicacion, porque «otro» sin explicar es la casilla que se marca para poder seguir. **El permiso lo tienen los DOS roles que revisan**: «se aprobo por error» lo descubre normalmente quien aprobo, y obligarle a pedirselo a otro convierte un clic en un correo --y en la practica, en que nadie lo arregle--. Reabrir no gasta ronda: la gasta pedir un cambio, y aqui todavia no se ha pedido ninguno | 8.2 |
| ~~T-47~~ | ✅ **RESUELTA (2026-08-26). El veto de «no se entrega sobre un entregable cerrado» vivia en el servicio desde 8.1 y la base no lo conocia.** `Entregables::vetoParaEntregar()` lo impedia desde la pantalla y NADA lo respaldaba: un comando, un import o la pantalla de manana podian meter una version encima de algo ya aprobado, y el entregable pasaria a tener aprobado un contenido que nadie aprobo --con el puntero senalando la version vieja y una mas reciente encima--. `tg_dv_entregable_abierto`. Es la TERCERA vez en esta fase que aparece la misma forma: la regla escrita en el servicio y la base sin enterarse | 8.2 |
| ~~T-48~~ | ✅ **RESUELTA (2026-08-26). Una asercion que dependia del ORDEN en que un motor evalua los CHECK.** La suite de 8.3 afirmaba «aprobar sin decir quien se rechaza por `ck_del_aprobador`». Al anadir `ck_del_approved_version`, MariaDB siguio rechazando por el primero y MySQL empezo a rechazar por el segundo: el orden no esta garantizado, y una asercion que depende de el no afirma lo que dice. Se arreglo dandole el puntero, para que lo unico que falte sea la firma. Es `T-43` en otra forma --un rechazo solo prueba algo si rechaza POR SU MOTIVO-- y lo cazo el mismo mecanismo: la suite exige el nombre de la restriccion, no un error cualquiera. De paso, la limpieza de 8.3 dejo de funcionar en silencio --borrar la version apuntada es justo lo que la clave ajena impide-- y lo cazo la asercion de premisa que 8.1 introdujo | 8.2 |
| **Q-58** | ❓ **.Se puede reabrir un entregable ya PUBLICADO o VERIFICADO?** Hoy no: `tg_cvw_entregable_abierto` cierra `published`, `verified` y `cancelled` sin excepcion, y solo deja reabrir lo `approved`. No hay diferencia practica todavia porque `publications` se llena en `8.6`, pero cuando la haya habra que decidir que pasa con la evidencia archivada (`8.7`) y con la permanencia minima (`8.8`) de un post que se retira para rehacerlo. Se decide con `publication_evidence` delante | 8.2 |
| **DEC-139** | ✅ **El post lo reporta el CREADOR, y el equipo tambien puede.** El creador es quien lo sabe primero y quien tiene el enlace en la mano: lo pega en su portal en cuanto publica. Y el equipo puede hacerlo por el, porque el caso real existe --el enlace llega por WhatsApp y el creador no entra--. Las alternativas eran peores por el mismo motivo en direcciones opuestas: solo el equipo convierte cada publicacion en una tarea nuestra --con 150 creadores, 150 tareas que perseguir-- y solo el creador deja sin camino el caso de «me lo mando por WhatsApp». **Las dos puertas pasan por el MISMO servicio y el MISMO veto**; solo cambia quien firma `reported_by_user_id`. Una segunda puerta con sus propias reglas es una segunda puerta con sus propios agujeros | 8.6 |
| **DEC-140** | ✅ **Solo se publica lo aprobado, y ESA version.** `BR-CONTENT-002` dice que nada llega al cliente sin aprobacion interna, y registrar la publicacion de algo no aprobado es darlo por bueno a posteriori con la firma de nadie. No basta con «el entregable esta aprobado»: se guarda QUE version se publico y tiene que ser la aprobada (`tg_pub_version_aprobada`), porque de esta fila cuelga el pago --8.7 la verifica y 8.8 cuenta su permanencia--. `publications.deliverable_version_id` es un SNAPSHOT, igual que `amount_snapshot` en 7.6: registra lo que se publico ENTONCES y sobrevive a que el entregable se reabra y se apruebe otra version despues, asi que se guarda aunque el puntero de 8.2 diga cual es la aprobada de hoy. Su clave ajena es COMPUESTA, como la de 8.2. **Detalle que la suite dice en voz alta**: al INSERTAR gana el disparador --la version de otro entregable nunca puede ser la aprobada de este-- y donde la clave se ve sola es en un UPDATE, porque el disparador es BEFORE INSERT y no los mira; ahi esta su asercion, y no en un caso inventado que nunca pasa | 8.6 |
| **DEC-141** | ✅ **La red se DEDUCE del enlace, y tiene que ser la del brief.** El brief compro un reel de Instagram; un TikTok no es eso, por bueno que sea. La red no se pregunta --un desplegable en el formulario solo sirve para que alguien elija mal, y el enlace ya lo dice--: sale de `platforms.url_pattern`, que es una expresion regular y vive en el catalogo desde 2.6 sin que la usara nadie. Si el enlace no es de ninguna red conocida, tampoco entra. **Y la huella**: `uq_pub_fingerprint` existe desde la Fase 2 para que dos creadores no reclamen el mismo post, y con la URL cruda no impediria nada --`?utm_source=ig` basta para esquivarla--. Se normaliza antes de firmar: esquema y host a minusculas, `www.` fuera, barra final fuera, fragmento fuera, parametros de MEDICION fuera y el resto ordenados. **Lo que identifica el post no se toca**: la ruta conserva mayusculas --`/p/AbC` y `/p/abc` son dos posts-- y la lista de parametros a quitar es explicita, porque `youtube.com/watch?v=` lleva el identificador en la query y borrar la query entera fundiria todos los videos del canal en una huella. Esa es la mitad que hace dano: rechazar un post legitimo diciendo que ya esta reclamado. La URL se guarda TAL CUAL: la huella es para comparar, no para sustituir | 8.6 |
| ~~T-49~~ | ✅ **RESUELTA (2026-08-26). La suite de 2.12 empezo a pasar en un motor y no en el otro.** Al exigir 8.6 que un entregable este APROBADO antes de publicarlo, esa suite paso a hacer `UPDATE deliverables ... WHERE id=(SELECT ... FROM deliverables)`. MariaDB lo tolera; MySQL da `1093 You can't specify target table for update in FROM clause`. Es la misma trampa que la suite de 8.1 ya documentaba en otro sitio, y se arregla igual: tabla derivada. De paso aparecio que esa suite insertaba entregables sin `submitted_at` --algo que `ck_del_approved`, de la propia 2.12, prohibia desde el primer dia-- y nadie lo habia visto porque nadie habia intentado aprobar nada alli | 8.6 |
| **DEC-142** | ✅ **La evidencia de que un post existe es una CAPTURA, no una comprobacion HTTP.** Tomada con la limitacion delante: Instagram y TikTok devuelven `200` con un muro de login, `403` a todo lo que no sea un navegador, y `200` otra vez cuando el post no existe. Un `http_status` **no distingue «el post existe» de «nos bloquearon»**, y `BR-CONTENT-004` es 🔴 --de `verified` cuelga el pago--. Asi que lo que se archiva es un `screenshot` con `file_id` y `tg_pub_verificada_con_evidencia` lo impone en la base; la sonda HTTP se guarda como dato complementario y NO decide. **Lo que si automatizaria esto son las APIs oficiales** --Instagram Graph, TikTok--, que exigen app revisada por Meta y tokens del creador: semanas, y son `F12`; elegirlas ahora dejaria 8.7, 8.8 y el pago bloqueados hasta entonces. El dia que existan cambia la forma de capturar, no el modelo: `publication_evidence` ya admite `api_snapshot`. `§56` del prompt maestro pide exactamente esto: no disenar sobre algo extremadamente fragil | 8.7 |
| **DEC-143** | ✅ **Verificar tiene su propio permiso, `content.verify`.** Mismo criterio que separo revisar de aprobar en 8.3: de `verified` cuelga el pago, asi que es una firma con dinero detras. Lo tienen `campaign_manager` y `content_reviewer`; **finanzas NO**, porque paga contra lo verificado y darle el permiso convertiria una comprobacion en una autoaprobacion. Se comprueba en el POST y no solo escondiendo el boton: los dos veredictos llegan por el mismo formulario | 8.7 |
| **DEC-144** | ✅ **Si el post no esta, el entregable vuelve al CREADOR, no a revision.** La publicacion queda `rejected` con su motivo y el entregable vuelve a `approved`. El contenido no tenia nada malo --ya se aprobo--: lo que falla es que no esta publicado, asi que lo que hay que rehacer es el ENLACE y no la pieza. Reabrir el entregable entero seria mas duro de lo necesario y dejarlo rechazado sin mas deja una campana con una pieza que nadie va a mover. Motivo de lista cerrada, como en 7.6 y 8.2, para poder contestar «.por que se caen las publicaciones?» con un numero. Y se le avisa por correo con el motivo dentro; el correo dice «no lo encontramos» y no «no publicaste», que es la diferencia que importa cuando la cuenta era privada o el enlace se copio mal. Verificar NO manda correo: no le pide nada, su parte termino | 8.7 |
| **DEC-145** | ✅ **Retirar el post antes de tiempo BLOQUEA el pago, y el sistema no descuenta nada.** `BR-CONTENT-006` decia que el sistema alerta y no decia nada del dinero, que es donde deja de ser tecnico. Las otras dos opciones se descartaron: el **descuento proporcional automatico** exige que el contrato lo diga por escrito Y que la deteccion sea fiable --y lo segundo hoy no se cumple, ver `DEC-146`--; **solo alertar** deja al cliente sin palanca, porque el post se retira, se cobra igual y la unica consecuencia es un correo. Lo que hace: la publicacion pasa a `removed` con motivo, firma y fecha; el entregable pasa a `removed` --sale de `verified`, que es de donde F9 va a pagar--; y ahi se para. La decision de que se le paga la toma una persona con el expediente montado delante. `test_firmar_la_caida_para_el_pago_y_no_descuenta_nada` afirma las DOS mitades: que el entregable sale de verificado y que el importe acordado sigue intacto. La segunda es la que impide que alguien «mejore» esto manana metiendo una regla de tres | 8.8 |
| **DEC-146** | ✅ **La sonda marca; una persona confirma.** Es `DEC-142` otra vez y por lo mismo --Instagram y TikTok responden igual ante un post borrado, un perfil en privado y un bloqueo geografico-- pero aqui duele mas: en 8.7 un falso negativo retrasa una verificacion, aqui **acusa a un creador de incumplir un contrato**. Asi que `Permanencia::anotar()` archiva la comprobacion y **no toca `publications`**, y lo que mueve una publicacion a `removed` es una persona con `tg_pub_permanencia` exigiendole DOS cosas: una comprobacion con `is_live = 0` y una captura tomada **DESPUES** de haber verificado el post. La segunda condicion es la que costo pensar: la captura vieja probo que el post existia, y reutilizarla como prueba de que ya no esta seria archivar lo contrario de lo que ensena. El comando `permanencia:vigilar` **no sale a Internet**: cierra ventanas cumplidas --comparar una fecha con el calendario si lo puede hacer solo, y es lo que habilita el pago-- y cuenta las desatendidas. La comprobacion automatica buena son las APIs oficiales, que son F12 | 8.8 |
| **DEC-147** | ✅ **Se avisa al creador y al equipo; al cliente no.** El creador tiene algo que hacer --reponer el post, decir que la cuenta se puso privada, o decir que lo bajo-- y el equipo lo ve en su bandeja sin que nadie le mande nada. El cliente no se entera hasta que hay algo confirmado: avisarle de un falso positivo cuesta mas que el propio incidente. El correo no dice «incumpliste», dice «no lo encontramos», y dice expresamente que el pago esta en pausa: enterarse de eso cuando no llega el dinero es peor que enterarse ahora | 8.8 |
| **DEC-148** | ✅ **El entregable caido tiene estado propio, y `expired` pasa a llamarse `fulfilled`.** Devolver el entregable a `published` bastaba para que no cobre, y estaba mal: `published` significa «esperando a que alguien lo mire» y el dia que F9 construya esa cola meteria incumplimientos dentro --un estado que significa dos cosas es el fallo de `T-50`--. `ck_del_status` gana `removed`, y los dos disparadores que definen «entregable cerrado» lo tratan como cerrado; sin eso se le podria subir una version nueva encima o emitir un veredicto sobre el, y el incumplimiento se disolveria solo. Aparte: `ck_pub_status` tenia `expired` desde 2.12 con CERO filas y con un nombre que se lee al reves de lo que significa --la ventana cumplida es lo BUENO, es lo que habilita el pago-- asi que se renombra ahora, que no cuesta nada; con mil filas habria costado una migracion de datos y una discusion. De paso, `permanence_checks` entra en la lista de `3.12`: el criterio de `T-16` la incluia desde el primer dia --la fila es evidencia y de ella depende dinero-- y nadie la habia mirado | 8.8 |
| ~~T-51~~ | ✅ **RESUELTA (2026-08-27). Dos aserciones mas que dependian del orden de evaluacion del motor.** `is_live = 7` sin notas viola `ck_pc_is_live` **y** `ck_pc_caida_motivada` a la vez; `status = 'expired'` con la permanencia puesta viola `ck_pub_status` **y** `ck_pub_permanence`. MariaDB contestaba con el primero de cada par y MySQL 8 con el segundo: la suite salia verde en un motor y roja en el otro sin que el esquema tuviera nada malo. Es `T-48` otra vez y el arreglo es el mismo --dejar la fila con UNA sola cosa mal--, porque un rechazo solo prueba algo si rechaza por SU motivo. La misma leccion entro en el diseno del disparador: la rama de `fulfilled` de `tg_pub_permanencia` **no** comprueba los NULL a proposito. Con NULL la comparacion es NULL, el IF no entra, y de los NULL responde `ck_pub_fulfilled`; si el disparador los cubriera tambien, ese CHECK no seria asertable en ningun motor | 8.8 |
| **Q-59** | ❓ **¿La ventana de permanencia se alarga por los dias que el post estuvo caido?** Hoy no: reponer devuelve la publicacion a `verified` y `permanence_until` no se toca. Si el post estuvo tres dias fuera, la marca perdio tres dias de exposicion y el creador cumple igual. Alargarla seria lo justo y alargarla por mi cuenta seria inventarme una clausula, asi que va con el texto del contrato, junto a `T-09` | Con el contrato del creador |
| **DEC-149** | ✅ **Se trabaja en `main` hasta que haya produccion.** La pregunta fue suya y era la correcta: *«no entiendo el sentido de tener varias ramas si al final haremos merge»*. Medido en el repositorio: `main (4.9) -- 5.9 -- 7.6 -- 7.6b -- 7.7 -- 8.1 -- 8.1-8.7 -- 8.8` es una **linea recta**; nunca hubo dos cosas a la vez. Las tres ramas no eran tres caminos sino tres nombres sobre el mismo camino, y dos apuntaban a puntos ya superados --`feat/5.9-4.1-enlace-contrasena` es el PADRE de la rama de 7.6, comprobado, no supuesto--. De las cinco cosas para las que sirve una rama, hoy no aplica **ninguna**: un solo desarrollador, ninguna revision externa, **nada desplegado**, y el CI que podria opinar antes ya lo hace `php tools/diagnostico.php` en la maquina. Y el coste si se pagaba: la rama que existia para proteger `main` lo dejo **nueve iteraciones obsoleto**, o sea mintiendo a quien clonara. Eso es la ceremonia sin ninguno de los beneficios. Lo que sustituye a la rama no es la confianza, son las seis puertas: **no se commitea en rojo**. **Se revierte el dia que haya produccion**, y entonces una rama por iteracion **fusionada el mismo dia**; lo que no se repite nunca es la rama larga que acumula iteraciones, que no es una rama sino un `main` con otro nombre. De paso, `develop` sale del `on: push` del CI: esa rama no ha existido jamas, y `CONTRIBUTING.md` la nombraba desde el principio | 8.8 |
| ~~T-52~~ | ✅ **RESUELTA (2026-08-27). El CI llevaba VEINTICUATRO ejecuciones en rojo por un archivo que yo no podia ver.** Ultimo verde: la ejecucion 24, en `4.1 Clientes`; desde `4.2 Marcas` todas rojas, y el paso 7 del protocolo --«comprobar que el CI corrio y en verde»-- no se cumplia. La causa: `resources/views/welcome.blade.php`, la portada que trae Laravel de fabrica, llama a `route('login')` y `route('register')` y este proyecto no declara ninguna de las dos. Nadie renderizaba esa vista --la raiz es `Route::redirect('/', '/panel')`-- asi que no rompia nada visible, pero `verificar-pantallas.py` la veia y tenia razon. **Por que mis puertas no la vieron**: mi contenedor no tiene una copia del repositorio sino `stage/`, que es SOLO lo que yo he entregado, y la herramienta elige la raiz con `CODIGO = RAIZ / 'stage' if (RAIZ/'stage/app').is_dir() else RAIZ`. En su maquina escanea el repositorio entero; aqui escanea lo mio. **Todo archivo que exista solo en su copia es invisible para mis seis puertas.** Se borra el archivo --era codigo muerto de `laravel new`-- y se documenta el punto ciego en `docs/19 §4`, porque el que vuelva a pasar depende de que alguien se acuerde. Dato que la ejecucion regalo de paso: todo lo anterior a ese paso estaba VERDE, **Percona 5.7 incluido** | 8.8 |
| **DEC-150** | ✅ **El techo de rondas pasa a la base, y los disparadores siguen siendo GUARDAS.** `8.3` construyo el limite entero --`rondas()`, `vetoParaRevisar()`, `content.extra_round`, `over_included`, la cola con «quedan/incluidas»-- y lo dejo TODO en PHP. Tres reglas se estaban aplicando sin que nada las respaldara en el esquema: **(a)** `ck_cvw_round` no decia DE QUIEN era la correccion, asi que una revision nuestra podia gastarle una ronda al cliente --`DEC-133` vivia solo en `consumeRonda()`--; **(b)** nada obligaba a `over_included` a decir la verdad, y esa columna es la que `cargosPendientes()` cuenta para facturar, o sea que una fila que miente ahi es una correccion que se trabaja y no se cobra; **(c)** el contador podia bajar, y bajarlo devuelve rondas ya gastadas sin la firma de nadie. `tg_cvw_techo` mira las DOS direcciones --agotadas sin declarar, y declarada sin estarlo-- y es cross-table, asi que un CHECK no puede verlo; lee el contador ANTES de que `emitir()` lo suba, que es el mismo valor con el que el servicio calculo su `$exceso`. **Y no se hizo lo tentador**: que el contador lo subiera un `AFTER INSERT` habria hecho imposible la desincronizacion, pero ninguno de los 504 disparadores de este esquema modifica una fila y un disparador que HACE cosas convierte el esquema en algo que actua a espaldas de quien lee el codigo. Habia fecha para esto: **8.5 escribe revisiones del cliente desde un enlace firmado**, sin pasar por `emitir()` | 8.4 |
| ~~T-53~~ | ✅ **RESUELTA (2026-08-27). `tg_del_rondas` nacio demasiado estricto y lo dijeron siete pruebas.** La primera version exigia `+1` exacto y rompio siete pruebas que ponian el contador a 2 para simular una pieza gastada --y tenian razon: es un estado que la aplicacion alcanza sola, y una importacion desde otro sistema tendria el mismo problema--. Se relaja a MONOTONO porque el dano no es simetrico: **bajarlo** no necesita la firma de nadie y regala rondas ya gastadas; **subirlo de golpe** hace que la siguiente correccion del cliente se cobre, y eso ya exige firma y decision de facturacion, las dos auditadas. Se cierra la mitad que no tiene dueno y se deja escrito por que la otra queda abierta | 8.4 |
| ~~T-54~~ | ✅ **RESUELTA (2026-08-27). Dos suites estaban verdes por una premisa que nunca escribieron.** Al poner `tg_cvw_techo` se cayeron dos aserciones antiguas, y las dos tenian razon en caerse. **`8.3`** afirmaba `over_included = 1` con el contador a CERO: nunca fue un estado real, pasaba porque nada miraba el contador. **`2.12`** insertaba una correccion que gastaba ronda **sin decir de quien era**, y el valor por defecto de `reviewer_side` es `platform` --o sea que la suite fundacional del modulo llevaba desde la Fase 2 fijando como correcto justo lo que `DEC-133` prohibe--. Las dos escriben ahora su premisa y la afirman. Mismo patron que `8.1`: una asercion solo prueba algo si su premisa es cierta | 8.4 |
| **Q-60** | ❓ **¿`deliverables.revision_rounds_used` deberia existir?** Es una desnormalizacion que anadio 8.3. Las revisiones son append-only (`tg_cvw_inmutable`), asi que el numero exacto se deriva con un `COUNT(*)` sobre `content_reviews` donde `consumes_round = 1`, y entonces no hay contador que desincronizar ni que proteger --`tg_del_rondas` sobraria--. A cambio, cada lectura del techo cuesta un JOIN. Se decide cuando ese numero sea una linea de factura | Facturacion (F9) |
| ~~T-50~~ | ✅ **RESUELTA (2026-08-26). Una publicacion rechazada bloqueaba su propio enlace, y el del vecino.** `uq_pub_fingerprint` era unica GLOBAL desde la Fase 2 --el mismo post no se reclama dos veces--, y al conectar el rechazo salio el agujero: se rechaza porque «el enlace no lleva a ningun post», se le pide al creador que arregle el post y **vuelva a registrar el mismo enlace** --que es lo que el correo le dice-- y se estrella con un `1062`. El caso hermano es peor: se rechaza el post de Ana porque «esta publicado en otra cuenta», esa cuenta es la de Luis, y **Luis no puede registrar su propio post**. Una fila rechazada no reclama nada: se miro y no valia. La unicidad pasa a mirar solo las VIVAS con una columna puerta --`viva_gate`, la decimoquinta del esquema-- y el filtro equivalente vive en `Publicaciones::reclamadoPorOtro()` para que el aviso llegue con palabras antes que el 1062. De paso, `ck_pev_screenshot` cierra un hueco de 2.12: `ck_pev_content` solo pedia «algo», asi que una fila que decia `screenshot` y traia un 200 pelado se leia como una captura que nadie hizo --y esa es justo la que va a mirar quien discuta un pago-- | 8.7 |
| ~~T-42~~ | ✅ **RESUELTA (2026-08-26). `verificar-fixturas.py` no sabia distinguir un fixture invalido A PROPOSITO.** Una prueba que afirma «la base rechaza esto» necesita escribir una fila mala, y la herramienta la marcaba en rojo con toda la razon tecnica y ninguna practica. Las que afirman un rechazo de UNICIDAD se escapaban por casualidad --un insert aislado no choca con nada-- y las que afirman un `CHECK` de una sola fila, no. Ahora hay un marcador que se escribe a mano --lo que impide ponerlo por costumbre-- y las excepciones se cuentan y se imprimen SIEMPRE: una lista que nadie mira crece sola | — |
| ~~T-10~~ | ✅ **RESUELTA (2026-08-26, iteracion 4.13).** El aviso al creador cuando cambian sus datos fiscales o su medio de pago. Cierra la mitad que faltaba de `BR-CREATOR-007` (🔴): la aprobacion interna existia desde 3.6 y 3.8, la NOTIFICACION no --«la pantalla se lo recuerda al operador para que lo haga a mano», que es otra forma de decir que no se hacia--. 390 pruebas, siete mutaciones en rojo, las seis puertas en verde con Deptrac incluido: que el grafo siga acilico ES parte del resultado. Ver `docs/fase-4/4.13-AVISO-DE-CAMBIO.md` | — |
| ~~7.1~~ | ✅ **CERRADA (2026-08-25).** `campaigns` existia desde la Fase 2 **sin `billing_legal_entity_id`**, y `BR-LE-001` es 🔴 y nombra la campana explicitamente. Sin esa columna, «quien facturo esta campana de 2026» se respondia mirando la cobertura de HOY: una respuesta plausible y falsa. Al construirla salieron tres cosas mas: la semilla de las suites creaba una campana imposible, **el esquema de referencia no tenia NINGUNA cobertura sembrada** --asi que ninguna campana podia existir fuera de borrador-- y tres fixtures a mano quedaron obsoletos, que es el sintoma literal de `T-13`. 284 pruebas, 846/836 aserciones de restriccion. Ver `docs/fase-7/7.1-CAMPANA.md` | — |
| ~~T-28~~ | ✅ **RESUELTA (2026-08-25). El paso `verificar-pantallas.py` del CI estaba roto desde que se instalo el workflow.** Apuntaba a `stage/routes/web.php` y `stage/app`, que es la disposicion del area de entrega y no la del repositorio: reventaba con un `FileNotFoundError` sobre una carpeta que en la maquina no existe. No salia verde en falso --eso habria sido peor-- pero el paso no comprobaba nada y el mensaje no decia nada util. Las dos herramientas resuelven ahora la disposicion mirando cual existe. Y la puerta nueva sale con codigo 2 si recorre CERO archivos: contar que no hay problemas cuando lo que no hay es busqueda es el modo de fallo mas caro de una comprobacion automatica, y con este van tres en dos dias (`T-25`, la regex de `verificar-fixturas.py`, y esta) | — |
| ~~T-27~~ | ✅ **RESUELTA (2026-08-25, iteracion 4.11).** `tools/pruebas/4.11-concurrencia.sh`: dos clientes de verdad contra el mismo motor, **sin un solo `sleep` de sincronizacion**. B pone `innodb_lock_wait_timeout=1` y se afirma el **1205**: mientras A tenga el bloqueo, B falla siempre, tarde lo que tarde la maquina; si no lo tiene, pasa. Los dos resultados son deterministas. Para saber que una sesion ya ejecuto se usan **cerrojos con nombre**: escribir una marca y buscarla en la salida no funciona porque el cliente bufferiza, y un `GET_LOCK` no es transaccional --se ve desde fuera aunque quien lo tomo siga con su transaccion abierta-- y se pregunta con SQL. Demuestra en el motor lo que hasta hoy estaba **deducido y no medido**: que el `UPDATE` que baja al principal no toma ningun bloqueo cuando el puesto esta libre, que por eso los dos llegan al `INSERT`, y que con el bloqueo de la fila del cliente se ponen en fila antes de escribir. Lleva su propio contraejemplo (con el puesto OCUPADO **si** espera), sin el cual la primera asercion saldria verde aunque el aparato no funcionase. Bateria: 812 (MariaDB) y 802 (MySQL 8), cero fallidas. El CI no hubo que tocarlo: los tres bloques leen `SUITES` desde `DEC-076`. Ver `docs/fase-4/4.11-CONCURRENCIA.md` | — |
| **T-35** | 📋 **El runbook de despliegue existe pero nadie lo ha ejecutado.** `docs/18-RUNBOOK-DESPLIEGUE.md` (2026-08-26) recoge por fin los pasos que vivian en conversaciones: los dos `GRANT` de `DEC-085`, las DOS lineas de cron --la de la cola y la del scheduler, que no son la misma y falta cualquiera de las dos rompe algo distinto--, el orden de despliegue y las comprobaciones posteriores. Escribirlo no lo ejecuta: sigue pendiente hacerlo en el servidor real y **corregir el documento con lo que se descubra**, porque un runbook que nadie ha seguido es una hipotesis | Al primer despliegue |
| **T-29** | 📋 **Cinco iteraciones de la Fase 3 sin documento.** 3.9 (tarifas), 3.11 (anulacion), 3.12 (no borrar), 3.13 (terminos) y 3.14 (rotacion de clave). Todas las demas iteraciones del proyecto tienen el suyo. El trabajo esta hecho y verificado por sus suites; lo que falta es el documento que explica **por que**, que es justo lo que no se puede reconstruir leyendo el codigo dentro de seis meses | Antes de que se olvide el porque |
| ~~T-30~~ | ✅ **RESUELTA (2026-08-25).** `09-NEXT-ITERATION.md` llevaba desde el 21 de agosto diciendo «me detengo aqui; no hay codigo, no hay esquema y no hay wireframes». Cuatro dias y 64 tablas despues, el documento cuyo unico trabajo es decir que viene ahora describia un proyecto que ya no existe. Reescrito entero: estado medido, que bloquea y a quien le toca, y la siguiente iteracion propuesta con su motivo. **Y tres tareas mas estaban en el mismo estado**: `T-11`, `T-15` y `T-16` decian «hecho en 3.14 / 3.11 / 3.12» y llevaban el marcador de ABIERTA. Es el mismo defecto que dejo `T-12` en 📋 durante un mes estando resuelta | — |
| ~~T-31~~ | ✅ **RESUELTA (2026-08-25). Dos de las seis puertas no se podian ejecutar del lado del contenedor, y por eso se entrego codigo con tres errores de PHPStan.** PHPStan y Deptrac no estaban instalados: packagist esta bloqueado desde aqui, asi que durante toda la sesion esas dos salieron «sin comprobar» mientras las otras cuatro pasaban. Se resolvio trayendo los `.phar` por el puente desde la maquina del usuario --`phpstan.phar` (27 MB), `deptrac.phar` (1,5 MB), `larastan` y sus dependencias--, mas `phpstan.neon` y `deptrac.yaml`. Costo un rato: **la limpieza de rutas colgantes del autoloader de `T-25` habia dejado la entrada PSR-4 de `Larastan` con el array VACIO**, y en PHP la ultima definicion de una clave gana, asi que rellenarla arriba no servia de nada --habia que rellenar la duplicada de abajo--. Es el mismo arreglo mordiendose la cola dos iteraciones despues. Ahora `puertas` corre las seis aqui. Las seis, en verde | — |
| ~~T-32~~ | ✅ **RESUELTA (2026-08-25).** Los tres errores que PHPStan encontro en la maquina del usuario. Dos eran de tipos puros --`collect()` sobre un `mixed`, y `Collection<int, object>` donde llega `Collection<int, stdClass>`, que no vale porque `TValue` **no es covariante**--. El tercero escondia un defecto de negocio: `$antes` salia de `pluck()` sin castear y `$despues` ya venia en `int`, asi que `['1'] !== [1]` era SIEMPRE cierto y la bitacora anotaria un cambio de categorias cada vez que se guarda una marca, haya cambiado o no. **No se pudo reproducir**: depende de si PDO devuelve cadenas o enteros, y en el contenedor devuelve enteros. Queda fijado por `MarcasTest`, que documenta la intencion y dice explicitamente que no demuestra el fallo | — |
| ~~T-17~~ | ✅ **RESUELTA (2026-08-25, iteracion 4.6).** Las dos carreras cerradas por caminos opuestos, porque son problemas opuestos. **Contactos:** se bloquea la fila del CLIENTE —que siempre existe— antes de tocar el puesto de principal; el `UPDATE` que baja al anterior no bloquea nada cuando el puesto esta libre, que es justo el caso normal. **Marcas:** el slug lo calcula el sistema, asi que su choque se absorbe y se reintenta (`DEC-087`). Comprobado contra el motor que reintentar «volviendo a preguntar» **no converge** —en `REPEATABLE READ` la lectura sigue viendo la foto vieja— y que un `FOR UPDATE` sobre una fila inexistente cambiaria el `1062` por un `1213`. Por eso el que reintenta le dice a `slugUnico()` que ya probo. Ver `docs/fase-4/4.6-CARRERAS.md` | — |
| **Q-52** | ❓ **¿Un cliente debería exigir contacto principal de facturación antes de poder estar `active`?** Hoy nada lo obliga: `contacts` no tiene regla que exija un principal de ningún tipo, y un cliente puede activarse sin un solo contacto. Cuando llegue la facturación habrá que saber a quién se le manda la factura, y descubrirlo entonces significa perseguir clientes ya activos. La ficha lo **avisa en ámbar** y no lo impide, porque convertirlo en requisito es una decisión de negocio: bloquear el alta de un cliente por un dato que a veces llega después tiene su propio coste. Si la respuesta es sí, el sitio es la activación del cliente, no `contacts` | Facturación (F9) |
| **Q-53** | ❓ **¿El mismo correo repetido dentro del mismo cliente y tipo es un error?** `contact_email` **no** es único a propósito: es un canal comercial y puede compartirse (`facturacion@cliente.com` para varias personas), a diferencia de `users.email`, que es la identidad de acceso y sí lo es. Pero dos contactos con el mismo correo, el mismo cliente **y el mismo tipo** parece captura duplicada más que reparto. No se ha añadido regla: una validación que solo vive en el formulario se la salta cualquier importación o `INSERT` de mantenimiento. Si se decide que es error, el sitio es el esquema | Importación de clientes |
| ~~Q-51~~ | ✅ **RESUELTA (2026-08-25, iteracion 4.5).** La pantalla de entidades legales existe: alta y edicion de sociedades, cobertura por pais con vigencia, y baja que **cierra las coberturas abiertas** (`DEC-081`). El listado enseña arriba los paises con clientes que hoy no puede facturar nadie, que es la pregunta por la que se entra. Al construirla aparecio un bloqueo real del esquema: desactivar sin cerrar la cobertura dejaba el pais sin cubrir **y sin poder cubrirse** | — |
| **Q-50** | ❓ **¿`campaign_creators`, `agreement_amendments`, `domain_events` y `status_transitions` son evidencia?** Se quedaron fuera de `3.12` a propósito. `campaign_creators` lleva `agreed_amount` —el precio pactado, congelado—, y borrar una participación borraría el precio con ella; pero el módulo de campañas no está construido y decidir ahora si una participación se borra o se cancela sería adivinar. Los otros dos son rastros de auditoría de alto volumen, donde puede hacer falta purga por antigüedad. Que lo decida la iteración que los construya, con el caso de uso delante | Al construir el módulo de campañas |
| ~~T-15~~ | ✅ *(hecho en 3.11)* **No existe anular un perfil fiscal aprobado.** `superseded` significa *reemplazado* —o sea que estuvo vigente— y `rejected` significa que se rechazó en revisión, antes de aprobarse. No hay forma de decir «esto se aprobó y no debió aprobarse nunca». Lo destapó `test_para_un_menor_el_perfil_del_creador_no_cuenta` al ponerse en rojo con `DEC-071`: un perfil fiscal a nombre de un menor no fue válido ni un día, pero la única salida hoy es cerrarlo la víspera del correcto, y eso deja en el histórico un periodo cubierto por un perfil que no valía. Hace falta decidir el mecanismo (¿un estado `annulled`? ¿un `voided_at` con motivo y autor?) y quién puede hacerlo — es la misma conversación que `Q-48` | Cuando haya que corregir un perfil aprobado por error |
| ~~T-14~~ | ✅ **RESUELTA (2026-08-25, iteracion 4.8).** Los cuatro disparadores de 3.9 pasan a `Periodo::sinSolape()`: 48 lineas de SQL tecleado a mano por 2 declaraciones. Pero lo que escondian valia mas que la duplicacion. **(a)** La referencia SQL y la migracion creaban disparadores **distintos** --`<=>` frente a `=`, y otros mensajes--: las suites llevaban desde 3.9 probando un disparador que no era el de produccion. Es `DEC-042` por la puerta de atras. Hoy no cambia el comportamiento porque las tres columnas de serie son NOT NULL, comprobado; el dia que una admita NULL, `=` deja pasar el solape en silencio. **(b)** `tools/verificar-periodos.py` existe exactamente para cazar eso, pero solo ve lo declarado con `Periodo::`: cuatro `DB::unprepared` eran invisibles. Paso de 6 reglas/12 disparadores a **8/16**. **(c)** La comprobacion previa de la migracion miraba los solapes existentes **solo en `creator_rates`**: `creator_availability` recibia su disparador sin que nadie hubiera mirado sus datos, y un disparador no valida lo que ya esta dentro. **(d)** Las cuatro reglas no estaban en `schema_constraints`. Baterias: 794 (MariaDB) y 784 (MySQL 8), cero fallidas. Ver `docs/fase-4/4.8-DISPARADORES-DE-3.9.md` | — |
| ~~T-13~~ | ✅ **RESUELTA (2026-08-25, iteracion 4.9).** `tests/Apoyo/ConFixturas.php`: **16 copias de `usuarioCon` en 15 archivos** y **9 `insert` de creador en 6** pasan a un sitio. Lo caro no era escribirlos: era saber que escribir. Un creador `active` son CUATRO reglas a la vez --`ck_creators_activation`, `ck_creators_active_identity`, `ck_creators_identity_evidence` y la foranea `fk_creators_identity_file`--, y se descubrian a base de `4025`, uno por intento, con un `1452` de premio final. `creadorActivo()` ahorra los cuatro intentos, no las teclas. La version compartida de `usuarioCon` es la de `PermisosTest` --la unica de las dieciseis que admitia `null`, o sea un usuario SIN rol, que es el que comprueba que una pantalla protegida rechaza-- y ademas avisa cuando el rol no existe en vez de dejar un `1048` que acusa a la tabla. Salto a la primera con `creator_manager`, que suena perfecto y no existe. De paso cayo la copia **numero once** del defecto de `H-16`: `publicarTerminos()` cerraba `effective_to` a mano y la puerta `vigencias` no la veia porque solo miraba `app/`. Ahora mira tambien `tests/`, con la regla A acotada alli --medido: cuatro sitios de aritmetica de dias en pruebas, tres legitimos--. 253 pruebas, 747 aserciones. Ver `docs/fase-4/4.9-APOYO-DE-PRUEBAS.md` | — |
| ~~T-11~~ | ✅ *(hecho en 3.14)* **Rotar `APP_KEY` invalida las huellas de las cuentas bancarias.** Los números siguen siendo recuperables (`Crypt` conserva `APP_PREVIOUS_KEYS`), pero la huella es un HMAC con esa clave: tras una rotación, la detección de cuentas repetidas (`DEC-065`) deja de funcionar sobre las filas viejas. Hace falta un comando que las recalcule. No es un problema hoy y sí lo será el día de la primera rotación | Antes de la primera rotación de clave |
| **T-09** | 📋 **Publicar la primera versión real de los términos del creador**, revisada legalmente, con `php artisan terminos:publicar`. **Ningún creador puede activarse hasta entonces** — la pantalla lo dice explícitamente | Bloquea toda activación |
| Q-29 | ¿Se aprueba la propuesta tipográfica (Sora + Plus Jakarta Sans + IBM Plex Mono), o hay una tipografía corporativa ya comprada? | Iteración 3.2 |
| Q-30 | ¿Existe versión editable del wordmark (AI/Figma) con la tipografía real que usó el diseñador? Si la hay, sustituye a mis contornos. | Calidad del logotipo |
| Q-31 | ¿Se sustituye el kit original por las versiones corregidas, o conviven? Recomiendo sustituir: dos favicon distintos garantizan que alguien use el roto. | Gobernanza de marca |
