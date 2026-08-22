# 03 — Arquitectura recomendada

> Versión 0.1 — 2026-08-21. Decisiones estructurales. Cambiarlas después del inicio de F4 es caro.

---

## 1. Estilo arquitectónico: Modular Monolith

Confirmo la decisión de la especificación y la refuerzo con criterios concretos.

**Un solo despliegue, un solo repositorio, módulos con fronteras reales.** Microservicios con un equipo de 1–3 desarrolladores y 150 creadores serían un error grave: multiplicarían el costo operativo sin resolver ningún problema que hoy exista.

Lo que hace que un monolito sea *modular* y no una bola de barro son **cuatro reglas verificables automáticamente**:

1. Cada módulo expone **servicios de aplicación** (casos de uso). Ningún módulo consulta las tablas de otro módulo directamente.
2. Las dependencias entre módulos son **acíclicas** y están declaradas (ver grafo en `00-EXECUTIVE-PRODUCT-DEFINITION.md §D.1`). Se verifica en CI con una herramienta de análisis de dependencias (p. ej. Deptrac o PHPAT).
3. La comunicación "hacia atrás" en el grafo se hace **por eventos**, nunca por llamadas directas.
4. Cada módulo tiene sus propias migraciones, sus propios tests y su propio conjunto de permisos.

Si estas cuatro reglas se cumplen, extraer un servicio en el año 3 es un trabajo de semanas y no de años. Si no se cumplen, ningún diagrama va a salvar el proyecto.

### 1.1 Estructura de código propuesta

```
app/
  Modules/
    Identity/         (D1)  Domain/ Application/ Infrastructure/ Http/ Database/ Tests/
    Core/             (D2)
    Creator/          (D3)
    Crm/              (D4)
    Client/           (D5)
    Campaign/         (D6)
    Matching/         (D7)
    Content/          (D8)
    Measurement/      (D9)
    Finance/          (D10)
    Communication/    (D11)
    Intelligence/     (D12)
  Shared/             Contratos, value objects (Money, Locale, CountryCode), excepciones base
portals/
  public/  admin/  creator/  brand/     (capa de presentación por portal)
```

Dentro de cada módulo:
- `Domain/` — entidades, enums de estado, reglas invariantes, eventos. **Sin dependencias del framework donde sea razonable.**
- `Application/` — casos de uso (`ApproveCreatorApplication`, `IssuePayoutBatch`), DTOs, políticas.
- `Infrastructure/` — repositorios, integraciones externas, adaptadores.
- `Http/` — controladores, requests, resources (uno por audiencia: interno/marca/creador).

## 2. Stack recomendado

| Capa | Recomendación | Justificación |
|---|---|---|
| Lenguaje | **PHP 8.3+** (tipado estricto, enums, readonly, `never`) | Requisito del cliente; lenguaje adecuado. |
| Framework | **Laravel 12 LTS** | Ver `DEC-001`. |
| Base de datos | **MySQL 8.0+** (InnoDB, `utf8mb4_unicode_ci`) | Requisito; adecuado. Se usan CTEs, ventanas y JSON validado por `CHECK`. |

> **Corregido en 2.4:** la versión anterior de esta tabla decía `utf8mb4_0900_ai_ci`. Esa intercalación
> **solo existe en MySQL 8**; en MariaDB no. Como el entorno de desarrollo actual corre MariaDB (XAMPP),
> el esquema se mantiene en el subconjunto portable: `utf8mb4_unicode_ci`, `VARCHAR`+`CHECK` en vez de
> `ENUM`, y `JSON_VALID()` en vez del tipo `JSON` nativo. Ver `DEC-042`.

| Cache / colas / locks | **Redis** | Colas, rate limiting, locks distribuidos, cache. |
| Cola de trabajos | Laravel Queue sobre Redis + Horizon | Observabilidad de colas incluida. |
| Storage | **S3-compatible** (AWS S3, Cloudflare R2, Backblaze B2, DO Spaces) | `DEC-008`. Nunca disco local para archivos de negocio. |
| Frontend admin/portales | **Blade + Tailwind + Alpine.js + HTMX/Livewire** | Ver `DEC-003`. |
| Frontend público (landings) | Blade + Tailwind, HTML semántico, sin SPA | SEO y velocidad. |
| Email transaccional | SMTP con proveedor dedicado (Postmark / SES / Resend / Mailgun) | Entregabilidad. **No SMTP del hosting.** |
| Búsqueda | MySQL FULLTEXT en MVP; Meilisearch si hace falta | No introducir Elasticsearch prematuramente. |
| PDF | Herramienta headless de Chromium o wkhtmltopdf | Para reportes y comprobantes. |
| Tests | PHPUnit/Pest + Laravel Dusk (E2E crítico) | |
| Análisis estático | PHPStan nivel 6+ y creciente, Laravel Pint | |
| Dependencias entre módulos | Deptrac | Hace las fronteras verificables. |
| CI/CD | GitHub Actions (o GitLab CI) | Tests + estático + Deptrac + migraciones en cada PR. |
| Errores | Sentry (o equivalente self-hosted) | Con correlation ID. |

### 2.1 Sobre el framework (resumen de `DEC-001`)

**Recomendación: Laravel.** No por moda, sino porque los siguientes componentes son **requisitos explícitos de esta especificación** y construirlos a mano cuesta meses de trabajo indiferenciado, con peor seguridad:

migraciones · seeders · colas y workers · scheduler · abstracción de storage · mailer con colas y plantillas · hashing seguro de contraseñas · protección CSRF · rate limiting · políticas de autorización · validación · localización (i18n) · logging estructurado · testing HTTP · verificación de email · restablecimiento de contraseña con tokens firmados · URLs firmadas (necesarias para los magic links de la marca) · cifrado de campos (secretos de integraciones).

Un "MVC propio" para este alcance no es simplicidad, es **deuda técnica contratada por adelantado**. Si hay una restricción real (hosting compartido sin Composer/CLI, política corporativa), hay que decirlo ahora porque cambia por completo el plan.

Alternativa válida si se prefiere rigor sobre velocidad: **Symfony 7**. Es una decisión defendible; solo hay que tomarla ahora.

### 2.2 Sobre el frontend (resumen de `DEC-003`)

No recomiendo una SPA (React/Vue) para el backoffice en esta etapa: duplica el trabajo (API + cliente), duplica la autorización y ralentiza cada iteración. Recomiendo **renderizado en servidor con interactividad puntual**. El portal del creador se implementa como **PWA instalable** (manifest + service worker básico), porque el creador vive en el móvil.

La regla que preserva el futuro: **toda la lógica vive en los servicios de aplicación**, de modo que exponer una API JSON más adelante (para app móvil o partners) es escribir controladores nuevos, no reescribir el negocio.

## 3. Aislamiento de datos — **no hay multitenancy**

`DEC-002`, resuelta el 2026-08-21: la plataforma la operan únicamente CTS y sus sociedades, para su propia agencia, y no se venderá a terceros. En consecuencia **no existen inquilinos y no existe `tenant_id`**. Ninguna tabla lo lleva, ningún índice lo prefija y no hay ámbito global de inquilino en el ORM.

Lo que sí existe son dos ejes que **no** son lo mismo y conviene no mezclar:

**Eje 1 — Ámbito del usuario externo (esto sí es aislamiento).** Un usuario de la marca solo puede alcanzar los datos de su `client_organization_id`; un creador, los de su `creator_id`. Se aplica con políticas de recurso, no con un filtro global, porque depende del tipo de usuario y del recurso. Es el control que previene IDOR y el que hay que testear con casos negativos en cada endpoint.

**Eje 2 — Responsabilidad legal (esto no es aislamiento).** `legal_entity_id` indica qué sociedad del grupo responde por un documento. No filtra visibilidad ni separa datos: determina quién factura, con qué serie, con qué configuración fiscal y a qué cuenta se cobra. Ver `docs/11-ADDENDUM-LEGAL-ENTITIES.md §1.2`.

**Dos trampas que conviene tener presentes:**

- **El cliente no es un inquilino.** Sus usuarios entran a la misma instalación con permisos restringidos. Confundir "aislamiento por cliente" con "multitenancy" produce modelos de datos incorrectos y trabajo inútil.
- **Una sociedad nueva tampoco lo es.** CTS Colombia comparte creadores, campañas y equipo con CTS Perú; solo cambia quién factura. Es configuración, no aislamiento.

**Si alguna vez cambia el escenario.** Que un tercero opere su propia red de creadores sobre esta instalación es el único disparador que obligaría a revisar esta decisión, y el coste sería real: añadir la columna a más de 50 tablas con datos en producción y auditar cada consulta del sistema, con riesgo de fuga si alguna se olvida. Está registrado en `DEC-002` para que la decisión se tome con ese número delante y no por sorpresa.

### 3.1 Configuración jerárquica (`DEC-018`)

La configuración no es una lista plana de pares clave-valor: se resuelve en cascada, y el valor más específico que exista gana.

```
Nivel 1 — Plataforma        idiomas soportados, feature flags, storage, límites
Nivel 2 — Marca             identidad visual, dominio, remitente, textos legales
Nivel 3 — Entidad legal     fiscal, series, bancos, monedas, proveedor de facturación
Nivel 3b — Entidad × País   impuestos, leyendas obligatorias, tipos de documento
```

Tres reglas que evitan que esto se vuelva un laberinto:

1. **Cada ajuste declara en qué nivel vive.** No hay ajustes definibles en dos niveles a capricho.
2. **La interfaz muestra siempre el origen del valor efectivo** ("heredado de Plataforma" / "definido en esta entidad"). Sin esto, nadie entiende por qué una factura salió con el dato equivocado.
3. **Los secretos se cifran por entidad y nunca se heredan.** Las credenciales del proveedor de facturación de una sociedad no pueden filtrarse hacia otra.

### 3.2 Registro de integraciones y resolver (`DEC-024`–`DEC-033`)

Las integraciones **no son ajustes** y no viven en la cascada anterior: un ajuste resuelve un valor, una integración resuelve una conexión viva que tiene estado, ciclo de vida y que falla. Comparten el vocabulario de niveles, no el mecanismo.

```
integration_providers      catálogo respaldado por código: existe si hay adaptador
integration_connections    configuración viva (ambiente, endpoints, estado, salud)
integration_assignments    alcance: purpose + [marca] + [entidad legal] + [país] + vigencia
integration_credentials    cifrado sobre, versionado, escritura sin lectura
```

Regla de oro: **ninguna clase instancia un cliente de proveedor directamente.** Todas preguntan:

```
resolver.for(purpose, legalEntity?, country?, brand?, environment) -> Connection + motivo
```

Tres propiedades que el resolver debe garantizar:

- **Determinismo.** Gana la asignación vigente de mayor especificidad (entidad 8 · país 4 · marca 2, `priority` como desempate). Los empates se **rechazan al guardar**, no se resuelven en ejecución.
- **Explicabilidad.** Devuelve el motivo de la resolución y se registra junto a la operación. Sin esto, "¿por qué esta factura salió por el proveedor equivocado?" solo se responde con arqueología sobre una configuración que ya cambió.
- **Aislamiento de ambiente como barrera.** Lanza excepción si el ambiente de la conexión no coincide con el de ejecución. Fuera de producción, el correo pasa siempre por un capturador con independencia de la configuración. Es la protección más importante del subsistema: ver `R-32`.

Webhooks: **una URL por conexión** (`POST /webhooks/{connection_uuid}`), firma verificada con el secreto de esa conexión, acuse inmediato y procesamiento en cola, idempotencia por `(conexión, evento del proveedor)`.

Detalle completo en `docs/12-ADDENDUM-INTEGRATIONS.md`.

## 4. Seguridad (arquitectura, no checklist)

### 4.1 Modelo de autorización

`User → Roles → Permissions` con permisos granulares `dominio.recurso.acción` (`campaign.approve`, `creator.view_financial`, `payout.execute`). Encima, **políticas de recurso** (`Policy`) que responden a la pregunta que el permiso solo no responde: *"¿este usuario puede tocar **este** registro?"*

Este es el control que previene IDOR, que es la vulnerabilidad más probable en este sistema:

```
puede(usuario, acción, recurso) =
      usuario.tiene_permiso(acción)
  AND ámbito_externo_coincide(usuario, recurso)   // client_organization_id o creator_id
  AND estado_permite(recurso, acción)
```

Ningún controlador accede a un recurso por ID sin pasar por esta función. Se testea explícitamente: **cada endpoint debe tener un test de autorización negativo** (usuario del cliente A intenta ver la campaña del cliente B → 404, no 403; no filtramos existencia).

### 4.2 Clasificación de datos

| Nivel | Ejemplos | Tratamiento |
|---|---|---|
| **Público** | Contenido de landings | — |
| **Interno** | Campañas, briefs, métricas | RBAC |
| **Confidencial** | Tarifas, márgenes, precios al cliente | RBAC estricto + segregación por audiencia + auditoría de lectura en campos de margen |
| **Personal (PII)** | Nombre, email, teléfono, dirección, fecha de nacimiento, documento | Ley 29733 / GDPR: consentimiento, minimización, retención, derechos ARCO |
| **Sensible / financiero** | Cuentas bancarias, documentos de identidad, credenciales de integraciones | **Cifrado en reposo a nivel de aplicación**, acceso mínimo, auditoría de cada lectura, enmascarado por defecto en UI |

### 4.3 Controles obligatorios (OWASP Top 10 mapeado)

- Consultas parametrizadas / ORM exclusivamente; prohibida la concatenación de SQL (verificado por revisión y por PHPStan).
- CSRF en todos los formularios; SameSite=Lax, cookies `Secure` + `HttpOnly`.
- Escapado por defecto en plantillas; `Content-Security-Policy` con nonce; sin `eval`.
- Rate limiting por IP y por cuenta en login, recuperación de contraseña, formularios públicos y subida de archivos.
- Bloqueo progresivo de cuenta (backoff exponencial) en vez de bloqueo duro (evita DoS por bloqueo).
- Validación de archivos por **contenido** (MIME real), no por extensión; almacenamiento fuera del webroot; servido por URL firmada temporal; nombres internos aleatorios; hash SHA-256 para deduplicación e integridad.
- Secretos en variables de entorno y/o gestor de secretos; **nunca en el repositorio**; los secretos configurables desde la UI se cifran con la clave de la aplicación y se muestran enmascarados.
- Auditoría append-only sin endpoints de actualización/eliminación; el usuario de BD de la aplicación **no tiene** `UPDATE`/`DELETE` sobre `audit_logs` (privilegio separado).
- Cabeceras de seguridad: HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- MFA obligatorio para roles con acceso a datos financieros o de seguridad.
- **Webhooks entrantes:** verificación de firma + idempotencia por `event_id` + registro del payload crudo.

### 4.4 Amenazas específicas de este dominio (no genéricas)

| Amenaza | Vector | Mitigación |
|---|---|---|
| **Fraude de audiencia** | Creador infla seguidores/engagement con bots o capturas editadas | Verificación por API oficial, chequeos de coherencia (ratio engagement/seguidores, curva de crecimiento), evidencia por video de pantalla, y un histórico que hace difícil mentir consistentemente |
| **Fraude de pago** | Cambio de cuenta bancaria antes de un lote de pago; suplantación de creador | Enfriamiento + reverificación + notificación al canal antiguo + doble aprobación en lotes por encima de un umbral |
| **Fuga de margen** | Un creador o cliente ve el precio del otro lado | Segregación por audiencia a nivel de DTO + tests automáticos que verifican que las respuestas de portales externos no contengan campos prohibidos |
| **Fuga de la red de creadores** | Un usuario interno exporta toda la base | Auditoría de exportaciones, límites por rol, marca de agua en exportaciones |
| **Contenido inapropiado** | Un creador sube contenido ilegal o infractor | Revisión humana obligatoria antes de publicar; términos claros; capacidad de retirar |
| **Pérdida de evidencia** | El post se borra tras el reporte | Archivado de evidencia en el momento de la verificación |

## 5. Internacionalización — decisiones estructurales

Estas cuatro cosas hay que hacerlas al inicio porque retrofitearlas es una reescritura:

1. **Dinero.** Nunca `FLOAT`. `DECIMAL(18,4)` + `currency_code CHAR(3)` **siempre juntos** (value object `Money`). Toda conversión guarda: monto origen, moneda origen, monto destino, moneda destino, tasa, fecha de la tasa, fuente. `BR-FIN-004`.
2. **Tiempo.** Todos los timestamps se almacenan en **UTC**. Se renderizan en la zona del usuario/organización. Las fechas *de calendario* (fecha de publicación acordada, vencimiento de factura) se guardan como `DATE` con su zona de referencia explícita, porque "el 5 de marzo" en México y en España no es el mismo instante y confundirlos genera incumplimientos.
3. **Identificación fiscal.** Catálogo `countries` → `tax_id_types` (código, nombre, regex de validación, longitud, si es de persona natural o jurídica, si requiere verificación externa). Los formularios se **generan** desde el catálogo. Cero reglas de Perú en el código.
4. **Textos.** Separar `locale` de interfaz (`es-PE`, `en-US`, `pt-BR`) del `preferred_language` de comunicaciones. Los catálogos traducibles usan tablas de traducción (`*_translations`), no columnas `name_es`/`name_en`.

> **Pero:** el MVP se lanza **solo en `es-PE`**. La infraestructura de i18n existe y todos los textos pasan por ella; no se traduce a otros idiomas hasta que haya un mercado que lo pida. Traducir tres idiomas de una UI que va a cambiar cada semana es tirar dinero.

## 6. Datos: principios que gobiernan el modelo (detalle en Fase 2)

1. **Append-only donde el histórico es el producto:** ledger financiero, auditoría, versiones de entregables, snapshots de métricas y de audiencia, tarifas, aceptaciones de términos.
2. **Mutable con auditoría donde no lo es:** perfiles, configuraciones, catálogos.
3. **Nunca borrado físico** de: nada financiero, nada auditado, nada legalmente relevante. `soft delete` solo donde tiene sentido semántico; para el resto, estados (`archived`, `inactive`).
4. **Snapshot de precio en el momento del acuerdo:** cuando un creador acepta una campaña, el monto acordado se **congela** en la participación. Si su tarifa cambia mañana, la campaña de ayer no cambia. Este error es catastrófico y frecuente.
5. **Convención de nombres:** tablas en `snake_case` **plural** (`creator_rates`), claves foráneas `<singular>_id`, PK `id` `BIGINT UNSIGNED AUTO_INCREMENT` + `uuid` público (`CHAR(26)` ULID) para exponer en URLs (evita enumeración), timestamps `created_at`/`updated_at`/`deleted_at`, autoría `created_by`/`updated_by`, responsabilidad legal `legal_entity_id` donde aplique, estados como columna `status` con enum de PHP respaldado por `VARCHAR` (no `ENUM` de MySQL: migrarlo es doloroso).
7. **Numeración fiscal correlativa:** las series de documentos se incrementan bajo bloqueo explícito sobre su propia fila (`SELECT … FOR UPDATE` o lock nombrado), en una tabla dedicada y no dentro de la configuración general. Es el único punto del sistema donde se acepta contención deliberada, porque la ley exige correlatividad sin huecos. Ver `DEC-021`.
6. **Índices desde el diseño:** todo `FOREIGN KEY` indexado; índices compuestos ordenados por selectividad real (p. ej. `campaign_id, status` o `country_id, status, followers` para el matching), no índices especulativos.

## 7. Rendimiento y escalabilidad

Objetivo de diseño: que el mismo esquema sirva de 150 a 100.000 creadores sin rediseño, sin optimizar prematuramente.

| Riesgo | Momento en que aparece | Prevención desde el diseño |
|---|---|---|
| N+1 en listados de creadores con sus redes | ~500 creadores | Eager loading obligatorio; test que cuenta consultas en endpoints clave |
| Filtros de matching lentos | ~5.000 creadores | Tabla desnormalizada de proyección (`creator_search_index`) actualizada por evento |
| `metric_snapshots` gigante | ~50 campañas | Particionado por fecha o archivado; agregados precalculados por campaña |
| Reportes que recalculan todo | Año 1 | Reportes materializados al cierre de campaña |
| Storage de video | Inmediato | S3 + ciclo de vida a almacenamiento frío tras N meses |
| Exportaciones que tumban el servidor | Cualquier momento | Exportación como job en cola + descarga por enlace, nunca síncrona |

**No hacer todavía:** réplicas de lectura, sharding, ElasticSearch, CQRS, event sourcing completo, microservicios.

## 8. Ambientes y despliegue

| Ambiente | Propósito | Datos |
|---|---|---|
| Local | Desarrollo | Seeds sintéticos |
| Development | Integración continua | Seeds sintéticos |
| QA | Pruebas funcionales | Seeds + datos anonimizados |
| Staging | Espejo de producción | Anonimizados; integraciones en sandbox |
| Production | Operación real | — |

- Configuración por `.env`, con `.env.example` versionado y **cero credenciales en el repositorio**.
- Migraciones versionadas, siempre hacia adelante, reversibles cuando sea posible; **cambios destructivos en dos fases** (desplegar código compatible → migrar → limpiar).
- Despliegue sin downtime (atomic symlink) + `php artisan down --secret` para ventanas de mantenimiento.
- Cron: un único punto de entrada (`schedule:run`) + supervisor para los workers de cola.
- Backups: BD diaria completa + binlogs; archivos con versionado de bucket; **restauración probada mensualmente y documentada**. Un backup no probado no es un backup.
- Observabilidad: `request_id` propagado a logs, colas y respuestas de error; logs estructurados en JSON; canales separados para aplicación / seguridad / email / colas / integraciones.

## 9. Estrategia de API

No se expone API pública en el MVP, pero:
- Toda operación de negocio vive en un caso de uso invocable sin HTTP.
- Se define desde ya el estilo: REST con versionado en la ruta (`/api/v1/`), autenticación por token (Sanctum) o OAuth2 cuando haya terceros, errores con formato uniforme (`{error: {code, message, request_id, details}}`), paginación por cursor para listados grandes.
- Cuando llegue la app móvil, la superficie será el portal del creador; ese es el primer candidato a API.
