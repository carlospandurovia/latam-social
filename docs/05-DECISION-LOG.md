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
| **T-12** | 📋 **`creator_tax_profiles` cierra el perfil anterior con `valid_to = valid_from` del nuevo**, o sea que se solapan un día. `uq_ctp_current` garantiza un solo perfil *vigente*, pero el histórico fiscal tiene el mismo defecto que `H-16` cerró en tarifas: «qué régimen aplicaba el 1 de mayo» puede tener dos respuestas. En un historial fiscal eso se paga en una declaración. Encontrado al escribir 3.9; merece iteración propia, no colarlo aquí | Antes de la primera declaración con dos regímenes |
| **T-16** | ✅ *(hecho en 3.12)* **`creator_tax_profiles` se puede borrar con un `DELETE`.** Lo llevan `payouts`, `invoices`, `ledger_entries`, `creator_payment_methods` y cinco tablas más, pero ésta no. Anular (`3.11`) existe justamente para no destruir el histórico —guarda quién, cuándo y por qué—, y todo eso se va con un `DELETE`. Salió al escribir la suite de 3.11: la aserción que iba a escribir habría dicho «el DELETE funciona», o sea habría fijado el hueco como si fuera lo correcto, que es el mismo error que `PerfilFiscalTest` cometió con `T-12`. Hace falta `tg_ctp_no_delete` y revisar si alguna otra tabla de histórico está igual | Antes de la primera declaración que se apoye en este histórico |
| **Q-50** | ❓ **¿`campaign_creators`, `agreement_amendments`, `domain_events` y `status_transitions` son evidencia?** Se quedaron fuera de `3.12` a propósito. `campaign_creators` lleva `agreed_amount` —el precio pactado, congelado—, y borrar una participación borraría el precio con ella; pero el módulo de campañas no está construido y decidir ahora si una participación se borra o se cancela sería adivinar. Los otros dos son rastros de auditoría de alto volumen, donde puede hacer falta purga por antigüedad. Que lo decida la iteración que los construya, con el caso de uso delante | Al construir el módulo de campañas |
| **T-15** | ✅ *(hecho en 3.11)* **No existe anular un perfil fiscal aprobado.** `superseded` significa *reemplazado* —o sea que estuvo vigente— y `rejected` significa que se rechazó en revisión, antes de aprobarse. No hay forma de decir «esto se aprobó y no debió aprobarse nunca». Lo destapó `test_para_un_menor_el_perfil_del_creador_no_cuenta` al ponerse en rojo con `DEC-071`: un perfil fiscal a nombre de un menor no fue válido ni un día, pero la única salida hoy es cerrarlo la víspera del correcto, y eso deja en el histórico un periodo cubierto por un perfil que no valía. Hace falta decidir el mecanismo (¿un estado `annulled`? ¿un `voided_at` con motivo y autor?) y quién puede hacerlo — es la misma conversación que `Q-48` | Cuando haya que corregir un perfil aprobado por error |
| **T-14** | ✅ *(hecho en 3.10)* **Los cuatro disparadores de 3.9 siguen escritos a mano.** `creator_rates` y `creator_availability` imponen la misma regla que ahora genera `App\Shared\Database\Periodo`, pero con SQL tecleado. Son doce líneas duplicadas cuatro veces, y un arreglo futuro habría que aplicarlo en dos sitios. Migrarlos es mecánico y la suite de 3.9 (23 aserciones) lo verifica sin depender de PHPUnit. Se deja para su propia iteración porque 3.9 todavía no tiene la suite de PHPUnit confirmada en verde | Cuando 3.9 esté en verde |
| **T-13** | 📋 **Los `insert` de los fixtures están escritos a mano en 10 sitios de 7 archivos.** Cada tabla que gana una restricción los deja obsoletos de uno en uno, y el aviso llega como «14 failed» en la máquina de quien recibe la entrega. `tools/verificar-fixturas.py` ya detecta la contradicción, pero no la evita: hace falta un apoyo compartido en `tests/` que sepa construir un creador `pending` y uno `active` **con su evidencia** (fecha de activación, identidad verificada, revisor y documento). Hoy nadie puede escribir un creador activo en una prueba sin descubrir tres restricciones a base de errores 4025 | Cuando una prueba necesite un creador activo |
| **T-11** | ✅ *(hecho en 3.14)* **Rotar `APP_KEY` invalida las huellas de las cuentas bancarias.** Los números siguen siendo recuperables (`Crypt` conserva `APP_PREVIOUS_KEYS`), pero la huella es un HMAC con esa clave: tras una rotación, la detección de cuentas repetidas (`DEC-065`) deja de funcionar sobre las filas viejas. Hace falta un comando que las recalcule. No es un problema hoy y sí lo será el día de la primera rotación | Antes de la primera rotación de clave |
| **T-10** | 📋 **Aviso al creador cuando cambian sus datos fiscales.** `BR-CREATOR-007` lo exige y el módulo Communication no existe. Hoy la pantalla se lo recuerda al operador para que lo haga a mano; queda pendiente automatizarlo | Fase de Communication |
| **T-09** | 📋 **Publicar la primera versión real de los términos del creador**, revisada legalmente, con `php artisan terminos:publicar`. **Ningún creador puede activarse hasta entonces** — la pantalla lo dice explícitamente | Bloquea toda activación |
| Q-29 | ¿Se aprueba la propuesta tipográfica (Sora + Plus Jakarta Sans + IBM Plex Mono), o hay una tipografía corporativa ya comprada? | Iteración 3.2 |
| Q-30 | ¿Existe versión editable del wordmark (AI/Figma) con la tipografía real que usó el diseñador? Si la hay, sustituye a mis contornos. | Calidad del logotipo |
| Q-31 | ¿Se sustituye el kit original por las versiones corregidas, o conviven? Recomiendo sustituir: dos favicon distintos garantizan que alguien use el roto. | Gobernanza de marca |
