# CHANGELOG

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/).

## [Fase 2 · cierre — verificación de migraciones] — 2026-08-22

### Añadido
- `tools/recolectar-esquema.php` + `tools/verificar-migraciones.py` — contrastan lo que **declaran las
  migraciones** (62 tablas, 732 columnas, tipos, nulabilidad, índices y foráneas) contra el esquema SQL
  de referencia. Cierra el hueco de `DEC-050`.
- CI ampliado: carga el esquema de referencia **y** la copia sin `CHECK` con triggers, corre la suite
  completa contra **los dos motores** y ejecuta los cuatro verificadores (`DEC-051`).

### Corregido
- **Divergencia real:** los índices de `social_account_snapshots` se llamaban
  `ix_social_account_snapshots_*` en el SQL y `ix_sas_*` en la migración. El escáner de colisiones solo
  mira el SQL, así que una colisión por el lado de las migraciones habría pasado desapercibida.
- **Los scripts de prueba tenían el cliente fijo a `mariadb` sin credenciales.** En GitHub Actions el
  cliente es `mysql -h127.0.0.1 -uroot -proot`: el CI habría fallado entero, o —combinado con el fallo
  del arnés corregido antes— habría salido **verde sin ejecutar ni una aserción**. Ahora sale de
  `MYSQL_CMD`.

### Verificado
- `binary($col, length: 16)` sí produce `VARBINARY(16)` desde Laravel 11. El contraste lo marcó como
  divergencia y era **el grabador**, no la migración. Confirmado en la documentación antes de tocar nada.

## [Fase 2 · 2.15 — Retenciones] — 2026-08-22

### Corregido
- **`withholding_applies TINYINT NOT NULL DEFAULT 0`: «no se retiene» y «nadie lo ha mirado todavía»
  eran el mismo valor.** Un perfil fiscal se aprobaba con el defecto puesto —porque nadie sabía qué
  poner, que es literalmente la situación de `Q-40`—, el pago salía sin retención, y un olvido y una
  decisión producían la misma fila. Sustituido por `withholding_status` con tres estados.

### Añadido
- `ck_ctp_withholding_decided` — un perfil fiscal **no se aprueba** con la retención sin decidir.
- `withholding_basis` obligatorio cuando se retiene: la tasa tiene que citar la norma que la sustenta.
- `ck_ctp_segregation` — quien captura el dato fiscal no lo aprueba (igual que `DEC-044`).
- `ledger_entries.withholding_rate_snapshot` y `withholding_basis_snapshot`, congelados e inmutables.
- 15 pruebas. Total 2.13: **99 con `CHECK` y 99 con triggers**. 150 restricciones, equivalentes.
- `docs/fase-2/2.15-RETENCIONES.md`.

### Decidido
- `DEC-048` — la retención tiene tres estados, no dos.
- `DEC-049` — `Q-45` resuelta con la opción **B**: sin datos fiscales no hay alta, pero se acompaña al
  creador a formalizarse. **El pago a un familiar no se implementa.**

### Pendiente
- 🔴 `Q-40` sigue abierta, pero ya no puede pasar desapercibida: sin decisión, el perfil no se aprueba.
- `T-08` — instructivo de formalización para el equipo de captación. Contenido, no código.

## [Fase 2 · 2.13b + 2.14] — 2026-08-22

### Añadido
- `invoices.tax_regime` y `invoices.receiver_country_snapshot` con 3 restricciones (`DEC-047`,
  `BR-FIN-018`). Facturar todo desde Perú hace que el régimen **no sea constante**: al cliente peruano
  se le grava con IGV, al del exterior la operación califica como exportación de servicios y va sin IGV.
- 7 pruebas de régimen tributario. Total 2.13: **84 con `CHECK` y 84 con triggers**.
- `docs/fase-2/2.14-PAGO-A-TERCEROS.md` — análisis del pago a un familiar del creador. **No implementado**
  a propósito: abre `Q-45`.

### Corregido
- **El arnés de pruebas daba verde con el motor apagado.** Un fallo de conexión se contaba como
  «rechazo», así que las aserciones de rechazo pasaban igual. Se vio de verdad: **25 pruebas en verde
  contra un socket muerto**. Ahora un error de conexión es un fallo explícito y una base caída reporta
  0 de 84.
- La suite no es idempotente (inserta correlativos fijos). Sobre una base ya usada salía roja por el
  motivo equivocado. Ahora aborta con un mensaje claro en vez de confundir.

### Decidido
- `DEC-047` — se factura a todos los países desde Perú.

### Pendiente
- 🔴 `Q-40` **sigue abierta.** La respuesta recibida contesta otra pregunta (`Q-33`) y además no elimina
  la retención: si hay renta de fuente peruana hay que retener, cobre quien cobre.
- 🔴 `Q-45` — pago a un tercero por cuenta del creador. Decisión del negocio + contador.
- ⚠️ `Q-44` — si estos servicios califican como exportación. **Requiere contador.**
- `T-07` — inscripción en el Registro de Exportadores de Servicios, previa a la primera factura al exterior.

## [Fase 2 · 2.13 — Finanzas] — 2026-08-22

**Cierra la Fase 2.** 62 tablas · 141 restricciones · 103 pruebas, verdes con `CHECK` nativo y con
`TRIGGER` generado.

### Añadido
- `docs/fase-2/2.13-FINANZAS.md` — iteración 2.13.
- **7 tablas**: `campaign_costs`, `payout_batches`, `payouts`, `ledger_entries`, `invoices`,
  `invoice_lines`, `payments`. 34 restricciones.
- **7 disparadores de inmutabilidad**: el libro mayor es solo-inserción y los documentos financieros
  emitidos no se borran físicamente. Impuesto por la base, no por la aplicación (`DEC-045`).
- `tools/generar-triggers.py` + `tools/generar-triggers.php` — producen el esquema sin `CHECK` y los
  disparadores equivalentes usando la clase de producción, no una reimplementación.
- `tools/verificar-triggers-generados.py` — contrasta las columnas que el generador dedujo contra
  `information_schema`.
- `tools/verificar-equivalencia.py` — comprueba que la base con `CHECK` y la base con `TRIGGER`
  imponen exactamente el mismo conjunto de reglas. Es la respuesta medida a `DEC-042`.
- `tools/pruebas/semilla.sql` y `tools/pruebas/2.13-finanzas.sh` (77 aserciones).
- `esquema:verificar`: nueva sonda de **modo estricto** (`STRICT_TRANS_TABLES`).
- `BR-FIN-013` a `BR-FIN-017`.

### Corregido
Tres fallos **del generador de disparadores**, los tres con el mismo síntoma: la regla se aplicaba en
desarrollo y **no existía en producción**, sin ningún mensaje de error.

- El extractor de columnas descartaba las líneas que empiezan por `PRIMARY` **sin `\b`**, así que la
  columna `primary_color` desaparecía y su restricción no se generaba.
- Las líneas de continuación de un `CHECK` multilínea (`OR (...`) se tomaban por definiciones de
  columna, produciendo una columna fantasma `OR` y un trigger con ``NEW.`OR` `` que no se creaba.
- Los `CHECK` declarados por `ALTER TABLE ADD CONSTRAINT` (los dos de `users`) no se veían.

Ninguno lo detectaron las pruebas de restricción: pasaban igual. Los detectó contrastar el parser
contra `information_schema`, que ahora es una herramienta del repositorio.

### Decidido
- `DEC-044` — todo pago pertenece a un lote. `payouts.payout_batch_id` pasa a `NOT NULL`: permitir
  pagos sueltos dejaba una puerta trasera a la doble aprobación de `BR-FIN-005`.
- `DEC-045` — la inmutabilidad financiera se impone con disparadores, no con convención.
- `DEC-046` — la base impone lo que ve en una fila; los cinco invariantes multi-fila quedan en el
  repositorio, **listados explícitamente** para que nadie los dé por cubiertos.

### Pendiente
- 🔴 `Q-40` — tasa de retención a creador no domiciliado. **Requiere contador.** Desde 2.13 bloquea
  código, no solo diseño.
- ⚠️ `Q-42` — la correlatividad de comprobantes está implementada según SUNAT (Perú). Para los demás
  países es un supuesto mío y **requiere asesor local** antes del primer documento.

## [Fase 2 · 2.4] — 2026-08-22

### Añadido
- `docs/fase-2/2.4-ATRIBUTOS-TIPOS-INDICES.md` — iteración 2.4.
- **8 migraciones, 16 tablas**: catálogos (`currencies`, `countries`, `exchange_rates`, `categories`,
  `platforms`, `content_formats` y traducciones), trazabilidad (`domain_events`, `status_transitions`,
  `audit_logs`) e identidad (`users` extendida, `roles`, `permissions` y pivotes).
- `App\Shared\Providers\ModuleServiceProvider` — carga las migraciones desde cada módulo.
- `database/seeders/CimientosSeeder` — monedas, países, redes, formatos, categorías, roles y permisos.
- `php artisan esquema:verificar` — 9 reglas de esquema como puerta de CI.

### Corregido
- `docs/03-ARCHITECTURE.md` especificaba `utf8mb4_0900_ai_ci`, **que no existe en MariaDB**. El entorno
  de desarrollo es MariaDB 10.4 (XAMPP), no MySQL 8: la primera migración habría fallado.

### Decidido
- `DEC-042` — alinear el motor local con el de producción. MariaDB 10.4 no tiene soporte desde junio 2024.
- `users` se extiende, no se sustituye: la migración base de Laravel se conserva.
- Unicidad de email solo entre usuarios no desactivados, con columna generada (no hay índices parciales).
- `DATETIME(3)` en vez de `TIMESTAMP`: `TIMESTAMP` convierte según la zona de la sesión y muere en 2038.

### Verificado
- 18/18 pruebas de restricción contra un MariaDB real.
- 9/9 reglas del verificador en verde, y 6/6 incumplimientos inyectados a propósito detectados.

## [Fase 2 · 2.3] — 2026-08-22

### Añadido
- `docs/fase-2/2.3-NORMALIZACION.md` — iteración 2.3.
- Entidad `CreatorGuardian`: el beneficiario del pago puede no ser el creador (`BR-CREATOR-010`).
- `CreatorRatePackage` + `CreatorRatePackageItem` (post-MVP).
- `BR-FIN-013`: todo `Payout` enviado tiene exactamente un `LedgerEntry` de pago, y suman cero.
- `BR-CREATOR-012`: edad mínima por categoría y por campaña.
- `Q-39` 🔴 beneficiario al cumplir la mayoría de edad en mitad de campaña — requiere revisión legal.

### Corregido
- `Creator.followers_count` eliminado: duplicaba `SocialAccountSnapshot` y violaba `BR-CREATOR-005`.
- `Campaign.total_creator_cost` eliminado: derivable, y se contradiría con la primera enmienda.
- `CampaignCreator.brand_name` eliminado: no es dato histórico congelado.
- `CreatorPaymentMethod` gana `owner_type`/`owner_guardian_id`: sin ello `BR-FIN-003` validaba el medio
  de pago de la persona equivocada cuando el beneficiario es un tutor.
- Fechas con efecto legal (`Invoice.issue_date`, `Payout.value_date`) pasan a `DATE` en la zona de la
  entidad legal: en `DATETIME` UTC, una factura de fin de mes cae en el período tributario equivocado.
- `permanence_days` incorporado a `CampaignRequirement`: el negocio lo pidió y no tenía dónde vivir.

### Decidido
- `Contact` y `User` siguen separados; `ClientUser` desaparece del vocabulario.
- Estados: columna vigente + `StatusTransition` append-only, con comando de verificación que los enfrenta.
- `Payout` y `LedgerEntry` son dos filas, 1:1, con invariante de importe verificada.
- Nunca el tipo `ENUM` de MySQL: `VARCHAR` + `CHECK`.
- Unicidad de creador activo mediante columna generada + índice único (MySQL 8 no tiene índices parciales).

## [No publicado]

### Añadido
- **F1.2** — Esqueleto del proyecto Laravel 12 con la estructura modular de `docs/03 §1.1`:
  13 módulos con sus capas `Domain` / `Application` / `Infrastructure` / `Http` / `Database` / `Tests`.
- **F1.3** — Puertas de calidad en CI: Pint, PHPStan (nivel 6), Deptrac y Pest.
- `deptrac.yaml` — las fronteras entre módulos de `docs/00 §D.1`, ahora verificables.
- `resources/css/tokens.css` — sistema de diseño derivado del kit de marca (`docs/14`).
- `public/img/brand/` — logotipos e iconos corregidos (`docs/14 §2`).
- `tools/plantilla-importacion-creadores.csv` — plantilla de carga inicial.

### Decidido
- `DEC-001` Laravel 12 · `DEC-002` sin multitenancy · `DEC-005` modelo mixto por umbral.
- Dos entidades legales desde el arranque: CTS Perú y CTS Colombia.
- Facturación electrónica directa: SUNAT en Perú, DIAN en Colombia.
- Tipo de cambio: Decolecta.

### Pendiente antes de la primera migración
- Iteraciones 2.3 y 2.4 del modelo de datos (`docs/15 §4`).
