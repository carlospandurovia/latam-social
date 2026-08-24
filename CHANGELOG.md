# CHANGELOG

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/).

## [Fase 3 · 3.9 — Tarifas, disponibilidad y agenda] — 2026-08-24

Lo que faltaba para poder invitar a un creador a una campaña.
`campaign_creators.agreed_amount` congela lo pactado; esta iteración es **de
dónde sale ese número**.

### Corregido
- **`H-16`: el histórico de precios admitía periodos solapados.**
  `uq_creator_rates_current` garantiza una tarifa *vigente* por creador, formato
  y moneda, no un histórico coherente. Reproducido:
  `el 2026-05-01 la tarifa era: 1000.0000, 2500.0000`. Un histórico con dos
  respuestas para una fecha no sirve para lo único para lo que existe.
- **`H-17`: `source` llevaba `DEFAULT 'self_declared'`.** El silencio se
  convertía en «el creador declaró este precio». La diferencia con `estimated`
  es si el creador sostiene el número o nos lo inventamos nosotros.
- **`H-18`: nadie firmaba el precio.** No existía `created_by_user_id`.

### Añadido
- Pantalla `/creadores/{uuid}/comercial`: tarifas, disponibilidad y bloqueos.
- Cuatro disparadores que impiden el solape en `creator_rates` y
  `creator_availability`, en INSERT y en UPDATE.
- `DEC-068` cero es un precio pero hay que declararlo · `DEC-069` permiso propio
  `creator.rate.manage` · `DEC-070` el bloqueo se registra aunque pise una
  campaña aceptada, y se avisa.
- `T-12` — `creator_tax_profiles` tiene el mismo defecto de solape que `H-16`
  cerró aquí, un día por perfil. Encontrado al escribir 3.9; merece iteración
  propia y **no** se coló en esta.
- `tools/pruebas/3.9-tarifas.sh` (23 aserciones) y
  `tests/Feature/PerfilComercialTest.php` (14 pruebas).

### Decisión con consecuencias
`valid_to` es **inclusivo**, así que cerrar una tarifa es ponerle el día
**anterior** al inicio de la siguiente, no el mismo día. De eso se encarga el
controlador; la base solo impide el solape.

### Verificación
- 498 aserciones de restricción × 2 variantes, en verde.
- 70 sentencias de migración ejecutadas de verdad en MariaDB y MySQL 8.
- 773 columnas sin discrepancias; 174 restricciones equivalentes entre motores.
- Pint pasa el proyecto entero.

### Nota de proceso
Dos aserciones de RECHAZO de la disponibilidad **pasaban por el motivo
equivocado** —el disparador de solape, no la regla que decían comprobar— y solo
se vio porque la tercera, la que espera OK, falló. Tercera vez en esta fase que
la aserción de lo permitido descubre que las de rechazo mentían.

## [Fase 3 · 3.8 — se cae MariaDB, entra Percona 5.7 de verdad] — 2026-08-23

### Corregido
- **Los 15 fallos de `ActivacionCreadorTest` eran un fixture de 3.5 sin
  actualizar.** `1364 Field 'created_by_user_id' doesn't have a default value`:
  actualicé la semilla SQL al añadir la columna obligatoria (`H-11`) y me olvidé
  del fixture equivalente en PHPUnit. De paso, ese archivo hacía tres cosas que
  3.8 ya no permite —desverificar un medio, dejar como predeterminado uno sin
  verificar, y mover `eligible_from` después de crearlo— y las tres eran, bien
  mirado, lo que las reglas nuevas vinieron a impedir.

### Cambiado
- **MariaDB sale de la matriz de pruebas.** No está en el stack de nadie: la
  máquina de desarrollo tiene MySQL 8, CI tiene MySQL 8 y producción es Percona
  5.7. Era lo que traía el entorno de trabajo, y ha salido cara:
  - `H-08` —el `ERROR 1832` que costó 542 s— **existió porque el desarrollo se
    hacía contra MariaDB**, que perdona ese `ALTER`. Con MySQL 8 delante no
    habría salido de ahí.
  - El `ERROR 1901` que se reportó como «divergencia cazada» es **exclusivo de
    MariaDB**. Se rediseñó una columna por un motor que nadie ejecuta.

  La matriz pasa a ser MySQL 8 con `CHECK` y MySQL 8 solo con disparadores.

### Añadido
- **Percona 5.7 en CI, de verdad.** Hasta ahora «el motor sin CHECK» se simulaba
  cargando el esquema `-sin-check` en MySQL 8. Eso comprueba que los
  disparadores generados imponen las mismas reglas —que no es poco— pero **no
  comprueba que Percona 5.7 acepte el esquema**, y son motores distintos: van
  tres divergencias en esta fase. Producción es 5.7 y 5.7 no aparecía en ningún
  punto de la cadena. Ahora el flujo levanta un servicio `percona:5.7` y corre
  contra él las 226 aserciones, el esquema completo y el SQL crudo de las
  migraciones.

### Nota de proceso
Esta decisión sale de una pregunta del cliente: por qué se gastaba tanto
esfuerzo en compatibilidad local si la base está en la nube. Tenía razón en la
mitad: MariaDB era esfuerzo perdido y encima dañino. La otra mitad —la base
local de pruebas— se queda, porque las pruebas hacen `migrate:fresh` y apuntar
eso a la nube fue lo que destruyó la base de desarrollo (`DEC-061`).

## [Fase 3 · 3.8 — corrección `H-15`] — 2026-08-23

### Corregido
- **`H-15`: la migración `000490` consultaba `closed_at` antes de crearla.** Las
  **18** pruebas de `MediosPagoTest` fallaron de golpe, ninguna llegó a
  ejecutarse: el fallo estaba en `setUp()`, que corre las migraciones.
  `ERROR 1054: Unknown column 'closed_at' in 'where clause'`, reproducido contra
  MySQL 8. Había un `if (Schema::hasColumn(...) && $sinCierre > 0)` puesto
  precisamente para eso, y no protegía nada: **el cortocircuito de un `&&` no
  salva a una consulta que ya se ha ejecutado** una línea más arriba.

### Añadido
- **El grabador de migraciones comprueba las columnas que se CONSULTAN.** Era el
  último hueco grande de la cadena: las 452 aserciones prueban el SQL de
  referencia, `verificar-migraciones.py` compara lo que las migraciones
  *declaran*, y `verificar-ddl-crudo.py` ejecuta solo el SQL *literal*. Nada
  ejecutaba el constructor de consultas. Ahora el doble anota cada columna
  consultada y la contrasta con las que existían en ese punto de la secuencia;
  invoca las clausuras de `where(fn ($q) => ...)`; `Schema::hasColumn` deja de
  devolver `true` siempre; y `DB::statement` registra los `ADD COLUMN` en el
  momento. `verificar-migraciones.py` falla si encuentra alguno, así que ya
  corre en CI. **Comprobado que reproduce el fallo** y que calla con la
  migración corregida.
- **`tools/diagnostico.php` vuelca también la puerta EN CURSO.** Solo escribía
  al terminar cada puerta, así que un cuelgue en PHPUnit —justo cuando hace
  falta saber dónde— dejaba el archivo sin una sola línea de esa puerta. Ahora
  vuelca cada dos segundos y anota cuántos segundos lleva callada.

### Notas de proceso
- La primera versión del verificador nuevo daba **nueve falsos positivos**:
  miraba todos los argumentos, y `whereIn('status', ['rejected','disabled'])`
  denunciaba dos columnas que eran valores. Corregido antes de entregarlo. Un
  verificador que grita por nada enseña a ignorarlo.
- Pint se corre ahora **de verdad** en el entorno de trabajo, con el build
  tomado de `vendor/`, sobre un espejo con la estructura real del repositorio.

## [Fase 3 · 3.8 — Medios de pago] — 2026-08-23

Cierra la última condición de la puerta de activación (`BR-CREATOR-006`) y, de
paso, siete defectos en **la fila que dice a dónde va el dinero**. Todos
reproducidos contra una base real antes de tocar nada.

### Corregido
- **`H-09`: se podía pagar a una cuenta que nadie había verificado.** El más
  grave de la tanda. `fk_payout_method` solo comprobaba que la fila existiera:
  entró un pago de 1500 PEN contra un medio en estado `pending`, sin verificar y
  sin fecha de elegibilidad. `BR-FIN-003` estaba **escrita**, no impuesta —vivía
  en `CompletitudOperativa`, que decide activaciones, no pagos—. Tampoco se
  comprobaba que la cuenta fuera del creador al que se le paga. Ahora lo
  impiden `tg_payout_medio_valido` y `tg_payout_medio_inmutable`; el segundo
  porque sin él la comprobación se saltaba con un `UPDATE` detrás.
- **`H-10`: una restricción con forma de control que no controlaba nada.** El
  comentario decía «la máscara nunca puede contener más de 4 dígitos» y debajo
  había un `CHAR_LENGTH(...) <= 30`. Se comprobó: el número de cuenta entero, en
  claro, era una máscara válida. Lo peor no es el hueco, es que el comentario
  aseguraba lo contrario.
- **`H-11`: quien capturaba una cuenta bancaria podía verificarla él mismo.**
  Faltaba `created_by_user_id`. Es `H-03` una tabla más allá; la columna nace
  `NOT NULL`, que es lo que aquella enseñó.
- **`H-12`: se podía cambiar la cuenta de un medio ya verificado.** Seguía
  diciendo `verified` y apuntaba a otro sitio. Eso vacía `BR-FIN-006`, que
  existe justamente para las modificaciones. La cuenta pasa a ser inmutable
  (`DEC-066`).
- **`H-02`: `verified` sin decir desde cuándo se le puede pagar.**
- **`H-13`: se podía borrar un medio de pago**, contra `BR-FIN-008`.
- **`H-14`: el predeterminado podía estar rechazado.** `default_gate`
  garantizaba que hubiera uno solo, no que sirviera.
- **La misma cuenta se podía registrar tres veces en el mismo creador.**

### Añadido
- Pantalla `/creadores/{uuid}/pagos`: alta, verificación, retirada y
  predeterminado. **Sin botón de editar**, porque no existe la operación.
- `App\Shared\Crypto\CuentaBancaria` — cifrado reversible del número, huella
  **HMAC-SHA256** para comparar sin descifrar, y máscara de cuatro dígitos. HMAC
  y no SHA-256 pelado: el espacio de números de cuenta es pequeño y
  estructurado, y la huella está en un índice sin cifrar.
- `DEC-064` enfriamiento de 24 h configurable · `DEC-065` la cuenta compartida
  se marca y no se rechaza · `DEC-066` la cuenta es inmutable · `DEC-067` los
  dos permisos van al rol `finance`.
- `T-11` — rotar `APP_KEY` invalida las huellas; hará falta un comando que las
  recalcule.
- Permisos `creator.payment.manage` y `creator.payment.verify`.
- `tools/pruebas/3.8-pagos.sh` — 41 aserciones en las dos direcciones.
- `tests/Feature/MediosPagoTest.php` — 18 pruebas, cuatro de ellas contra el
  esquema que construyen **las migraciones** y no contra el SQL de referencia.
  Esa distinción es la que dejó pasar `H-08`.

### Notas de portabilidad
- **MariaDB rechaza con `ERROR 1901` una columna generada `STORED` cuyo `CASE`
  devuelve una cadena; MySQL 8 la acepta.** Misma familia que `H-08`, cazada
  esta vez en el entorno de trabajo y no en la máquina de desarrollo — que era
  exactamente para lo que se instaló MySQL 8 aquí. Se resolvió volviendo al
  patrón de puerta que ya usa el resto del esquema.
- `REGEXP` y no `REGEXP_REPLACE`: la segunda no existe en Percona 5.7.

### Verificación
- 452 aserciones de restricción (26·99·29·16·15·41 × 2 variantes) en verde en
  MariaDB 10.11 **y** en MySQL 8.0.46.
- `verificar-ddl-crudo.py`: 58 sentencias ejecutadas de verdad en los dos motores.
- `verificar-migraciones.py`: 64 tablas y 771 columnas sin discrepancias.
- `verificar-equivalencia.py`: 174 restricciones, mismo conjunto de reglas en
  los dos motores.
- Pint, PHPStan, Deptrac y PHPUnit siguen dependiendo de la máquina de
  desarrollo y de CI: aquí no hay `vendor/`.

### Migración
`000490` **se niega a correr** si los datos no admiten las reglas nuevas, y lo
dice todo de una vez en vez de fallar de uno en uno. `created_by_user_id` pasa a
obligatoria y no hay ningún valor verdadero que inventar: se pide
explícitamente en `config('latam.pagos.capturador_migracion')`.

## [Fase 3 · 3.6/3.7 — corrección `H-08` y ejecución real de las migraciones] — 2026-08-23

### Corregido
- **`H-08`: la migración `000470` pasaba en MariaDB y reventaba en MySQL 8.**
  `ALTER TABLE creator_tax_profiles MODIFY created_by_user_id BIGINT UNSIGNED NOT NULL`
  sobre una columna que lleva `fk_ctp_creator_user` encima: MariaDB lo acepta,
  MySQL 8 responde `ERROR 1832` y se planta. Los 13 tests de
  `PerfilFiscalTest` fallaban por esto —542 s de migraciones reintentándose, no
  un cuelgue— y la iteración se había dado por entregada. Ahora `up()` y
  `down()` hacen el baile portable: quitar la foránea, modificar, volver a
  ponerla.
- **Diagnóstico equivocado antes del bueno.** Atribuí el cuelgue a un bloqueo de
  metadatos de MySQL. Lo marqué como hipótesis y no como diagnóstico, pero era
  falso y costó tiempo. Queda escrito en `docs/fase-3/3.6-PERFIL-FISCAL.md §10`.
- **Las bases de referencia se quedaban viejas.** Al cerrar 3.7, los
  verificadores denunciaron 26 discrepancias inexistentes: las migraciones
  tenían razón y la base de contraste era de dos iteraciones antes.

### Añadido
- **`tools/verificar-ddl-crudo.py` — nueva puerta: EJECUTA el SQL crudo de las
  migraciones.** Hasta ahora `verificar-migraciones.py` contrastaba lo que las
  migraciones *declaran*, con un grabador que **simula** `DB::statement`: esas
  sentencias no se ejecutaban en ningún sitio salvo CI, y CI se saltó al apilar
  3.5, 3.6 y 3.7 sin empujar. La puerta nueva hace ida y vuelta
  (`down()` → `up()`) sobre una copia limpia del esquema de referencia y
  **está comprobado que reproduce `H-08`**: verde en MariaDB, rojo con el 1832
  en MySQL 8.
- **`recolectar-esquema.php --crudo`** — modo nuevo: emite el SQL literal de
  cada migración separado por `up()`/`down()`, y avisa si `down()` revienta.
- **MySQL 8.0.46 en el entorno de trabajo**, en el puerto 3307, junto al
  MariaDB. Aquí solo había MariaDB —el motor que perdona— y por eso las
  divergencias solo aparecían en la máquina de desarrollo.
- **`tools/rehacer-referencia.sh`** — rehace las dos bases de referencia de un
  tirón. El orden de carga lo calcula leyendo los `REFERENCES` en vez de estar
  escrito a mano en dos sitios.
- **`tools/pruebas/correr-todo.sh`** — las cinco baterías contra los dos motores
  lógicos, con base limpia y total al final.

### Verificación
- `verificar-ddl-crudo.py`: 38 sentencias ejecutadas de verdad, ninguna
  rechazada, en MariaDB 10.11 **y** en MySQL 8.0.46.
- 370 aserciones de restricción (26·99·29·16·15 × 2 motores lógicos) en verde en
  MariaDB **y**, por primera vez, en MySQL 8.
- `verificar-migraciones.py`: 64 tablas y 766 columnas sin discrepancias.
- `verificar-equivalencia.py`: 166 restricciones, los dos motores imponen el
  mismo conjunto de reglas.
- Pint, PHPStan, Deptrac y PHPUnit **no** se pueden correr aquí: Packagist no es
  alcanzable desde este entorno y no hay `vendor/`. Siguen dependiendo de la
  máquina de desarrollo y de CI.

### Flujo de CI
- Ejecuta `verificar-ddl-crudo.py` contra MySQL 8 antes de `php artisan migrate`.
- Monta los esquemas de referencia con `rehacer-referencia.sh` en vez de con una
  lista de módulos escrita a mano.

## [Fase 3 · 3.7 — Cuentas sociales y coherencia de métricas] — 2026-08-23

### Corregido
- **`H-06`: «no es anómalo» y «nadie lo ha mirado» eran el mismo cero.**
  `is_anomalous TINYINT NOT NULL DEFAULT 0`, con `BR-CREATOR-004` exigiendo
  chequeos de coherencia y **ni una línea de código que los ejecutara**. Cada
  métrica insertada afirmaba haberlos pasado. Es el mismo fallo que `DEC-048`.
  Pasa a `coherence_status` con tres estados; las filas viejas se convierten a
  `pending_review`, no a `clean`.
- **`H-05`: verificar una cuenta no obligaba a decir cómo ni quién.**
  `verification_method` era texto libre —la única columna con pinta de estado sin
  lista cerrada— y la restricción solo exigía la fecha. Ni siquiera existía
  `verified_by_user_id`. Misma lección que `DEC-058`, una tabla más allá.
- **`H-07`: «solo inserción» era una convención, no un candado.**
  `social_account_snapshots` no tiene `updated_at` y `esquema:verificar` lo daba
  por bueno, pero admitía `DELETE` — y ahí vive la justificación de cuánto se le
  pagó a cada creador. Lo encontró una aserción escrita dando por hecho lo
  contrario. Ahora lo impiden `tg_sas_no_update` y `tg_sas_no_delete`.
- **`CompletitudOperativa` daba el motivo equivocado para un menor sin tutela.**
  La consulta del medio de pago usaba `$tutor?->id ?? 0` —un id centinela que no
  casa con nada—, así que respondía «no hay ningún medio de pago registrado»
  cuando lo que faltaba era el tutor. Lo destapó PHPStan señalando el `?->` como
  innecesario; el mensaje malo estaba detrás.
- **`tools/diagnostico.php` solo volcaba el archivo al terminar las cuatro
  puertas.** Mientras las pruebas corrían quedaba en disco el informe de la
  ejecución anterior, y quien lo abriera leía un resultado viejo creyéndolo
  actual. Ahora vuelca después de cada puerta.
- **`CoherenciaMetrica` medía la ventana contra hoy**, no contra la fecha de la
  captura, y buscaba el último snapshot a secas en vez del último anterior a
  esa captura: importar una métrica vieja producía un salto inventado y del
  signo contrario.

### Añadido
- Pantalla `/creadores/{uuid}/redes`: alta, verificación y captura de métricas.
- `App\Modules\Creator\Services\CoherenciaMetrica` — dos chequeos, umbrales
  configurables (`DEC-063`), y **nunca rechaza**: marca para revisión humana,
  que es lo que dice `BR-CREATOR-004` con esas palabras.
- `BR-CREATOR-018`, `tools/pruebas/3.7-redes.sh` (15 aserciones × 2 motores) y
  12 pruebas de PHPUnit. **185 aserciones de restricción en verde.**

### Nota operativa
- `oauth` no se ofrece como método de verificación: no está implementado, y
  ofrecerlo dejaría marcar una cuenta como verificada por la plataforma cuando
  la plataforma no ha dicho nada.

## [Fase 3 · 3.6 — El perfil tributario del creador] — 2026-08-23

### Corregido
- **`H-03`: la separación de funciones del perfil fiscal se apagaba sola.**
  `ck_ctp_segregation` decía «aprobador distinto del capturador, **salvo que
  alguno sea NULL**», y `created_by_user_id` admitía NULL. Bastaba aprobar sin
  decir quién había capturado para saltarse el control entero — se comprobó que
  funcionaba antes de cerrarlo. Es el mismo patrón que `DEC-048`: **un NULL que
  desactiva un control**. La columna pasa a NOT NULL, como en `payout_batches`
  (`DEC-044`), y la restricción se simplifica *porque* el modelo se volvió más
  estricto.
- **`H-01`: el perfil fiscal no decía de quién era.** `creator_payment_methods`
  y `creator_tax_documents` ya distinguían creador de tutor; este no. Para un
  menor, el `tax_id_number` guardado es el del tutor y nada lo indicaba. Ahora
  `holder_type` + `holder_guardian_id`, con `ck_ctp_holder` calcada de
  `ck_cpm_owner`.
- **`CompletitudOperativa` daba por bueno cualquier perfil aprobado.** Para un
  menor exige ahora el del **tutor activo**, y el mismo al que pertenece el
  medio de pago: antes podían apuntar a personas distintas sin que nadie lo
  notara.
- **`recolectar-esquema.php` reventaba** si una migración consultaba datos antes
  de endurecer una columna, y no sabía leer `ALTER TABLE … MODIFY … NOT NULL`,
  por lo que el verificador denunciaba una discrepancia inexistente. Las dos
  cosas arregladas.

- **`H-04`: rechazar escribía «aprobado por» y reventaba.** `ck_ctp_segregation`
  compara esa columna con el capturador sea cual sea el estado, así que el
  capturador no podía retirar una captura equivocada suya (error 4025). El
  arreglo no es relajar la restricción —existe para impedir la autoaprobación,
  no la autocorrección— sino dejar de usar «aprobado por» para un rechazo. Quién
  rechazó queda en la bitácora, que además es inmutable.
- **El perfil anterior se cerraba «hoy»**, lo que solapaba los periodos si el
  nuevo entraba en vigor en otra fecha. Ahora se cierra cuando empieza el nuevo.
- **No se capturaban datos fiscales de un creador anonimizado.** La verificación
  de identidad ya lo comprobaba; esta pantalla no (`BR-CREATOR-009`).

### Añadido
- Pantalla `/creadores/{uuid}/fiscal`: histórico de perfiles, captura y
  resolución. Permisos `creator.tax.manage` y `creator.tax.approve` (`DEC-062`).
- **La retención se decide al aprobar, no al capturar** (`DEC-048`). El
  formulario de captura ni siquiera ofrece el campo: quien teclea el RUC no es
  quien conoce la norma.
- Aprobar un perfil **cierra el anterior** en la misma transacción
  (`BR-CREATOR-007`). El orden lo impone `uq_ctp_current`, no una convención.
- `BR-CREATOR-017`, `tools/pruebas/3.6-fiscal.sh` (16 aserciones × 2 motores) y
  14 pruebas de PHPUnit.

### Abierto
- `Q-47` — el periodo de gracia de `BR-CREATOR-014` dice «configurable»: ¿global
  o por creador? Son dos modelos distintos. Sin responder, no se implementa.
- `T-10` — aviso al creador cuando cambian sus datos fiscales. Hoy la pantalla
  se lo recuerda al operador; falta automatizarlo.

## [Fase 3 · 3.5 — La puerta de activación] — 2026-08-23

### Corregido
- **`BR-CREATOR-006` exigía cinco condiciones y el modelo solo sabía comprobar
  tres.** «Identidad verificada» no tenía dónde anotarse y «aceptación vigente de
  los términos» no tenía **ninguna tabla**. Una condición que no se puede evaluar
  no falla: no se evalúa. La regla llevaba así desde la iteración 2.1.
- **`BR-PRIV-001`** («cada consentimiento se registra con su texto versionado,
  fecha, canal y evidencia») describía desde la fase 1 una tabla que no existía.
- **`activated_at` era decorativa.** Nada impedía un creador `active` sin fecha de
  activación; las dos filas activas de la semilla de pruebas no la tenían.
- **`status_transitions` llevaba vacía desde la 2.4** — el mismo caso que
  `audit_logs` antes de la 3.2. Activar es una transición de estado y ahora la
  escribe.
- **`files` tenía claves foráneas apuntando a una tabla vacía** desde la 2.6.
  Ahora hay una única puerta de entrada: `App\Shared\Files\Almacen`.

### Añadido
- `terms_versions` y `terms_acceptances` (`DEC-059`). La aceptación es de una
  **versión concreta**, con huella `sha256` del contenido. **Sin `revoked_at` a
  propósito**: publicar una versión nueva deja fuera de vigencia las aceptaciones
  anteriores sola, que es justo lo que se compra al versionar.
- `creators.identity_verified_at` / `_by_user_id` / `_document_file_id`
  (`DEC-058`), con un `CHECK` que obliga a que vayan **las tres o ninguna**.
- `ck_creators_active_identity` y `ck_creators_activation`: un creador activo sin
  identidad verificada o sin fecha **lo rechaza la base**, no la aplicación. Un
  `UPDATE` a mano en una consola queda fuera.
- `App\Modules\Creator\Services\CompletitudOperativa` — evalúa las seis
  condiciones (las cinco de `BR-CREATOR-006` más la tutela de `BR-CREATOR-010`) y
  devuelve **la lista de lo que falta**, no un booleano.
- Pantalla `/creadores/{uuid}/activacion`: lista de requisitos, formularios de
  evidencia y activación. Permisos nuevos `creator.verify` y `creator.activate`
  (`DEC-060`).
- `php artisan terminos:publicar` — los términos **no se siembran**: un texto
  inventado por el equipo técnico convertido en «lo que el creador aceptó» es lo
  que `§56` prohíbe. → **T-09**
- `App\Shared\Workflow\Transicion` y `App\Shared\Files\Almacen`.
- `App\Modules\Core\Providers\CoreServiceProvider`: registrar el comando desde
  `ModuleServiceProvider` habría hecho que la capa `Shared` dependiera de `Core`,
  y Deptrac lo habría rechazado con razón.
- `tools/pruebas/3.5-activacion.sh` — **29 aserciones × 2 motores**. Comprueban lo
  que sigue siendo cierto **sin la aplicación en medio**.
- 20 pruebas de PHPUnit. La central pone las seis condiciones y quita **una sola**,
  cinco veces.

### Corregido durante la verificación
- **`phpunit.xml` no declaraba base de datos**, así que las pruebas usaban la del
  `.env` —el servidor remoto de desarrollo— y `RefreshDatabase` empieza por
  `migrate:fresh`. **Cada `php artisan test` borraba la base de desarrollo**, y
  además tardaba tanto que la suite se colgaba. Ahora usan `latam_social_test` en
  local: 60 segundos (`DEC-061`). El fallo venía de la iteración 3.1.
- **`Almacen` guardaba archivos de 0 bytes en Windows.** Hacía `putFileAs()` y
  luego preguntaba `size()` al disco; ahí discrepaban. Ahora los bytes se leen
  una vez y de ellos salen contenido, tamaño y huella. Lo destapó `ck_files_size`.
- **Fixtures de 3.2 y 3.4 creaban creadores «activos» sin nada detrás.** Los
  rechazó `ck_creators_activation` — a través de Eloquent, no solo por SQL.

### Añadido (herramientas)
- `tools/diagnostico.php` — ejecuta las cuatro puertas y vuelca todo en UTF-8.
  `> archivo.txt` en PowerShell escribe UTF-16 y convierte el stderr en objetos
  de error: dos informes seguidos salieron ilegibles antes de detectarlo.
- `tools/ci-github.php` — trae el resultado del último CI al mismo formato.
- `tools/crear-bd-pruebas.php` — crea la base de pruebas sin necesitar el cliente
  `mysql` en el PATH; distingue «no hay servidor» de «te rechaza el usuario».

### Abierto
- `Q-46` — qué pasa con los creadores **ya activos** cuando se publica una versión
  nueva de los términos. Hoy **no se desactivan**. Decisión de negocio.
- `H-01` — `creator_tax_profiles` no dice si el titular es el creador o su tutor,
  mientras que `creator_payment_methods` sí lo dice. Ambigüedad en un dato fiscal.
- `H-02` — `eligible_from` admite `NULL` en un medio de pago ya verificado: «no hay
  enfriamiento» y «nadie lo ha fijado» son el mismo valor, el mismo fallo que
  `DEC-048` corrigió en la retención. Mientras tanto, `NULL` cuenta como **no
  elegible**: el silencio no da permiso.

## [Fase 3 · 3.4 — Bandeja de solicitudes] — 2026-08-22

### Añadido
- Bandeja de solicitudes de creador: listado por estado, ficha, aprobación con
  alta, y rechazo con explicación obligatoria. Permiso `creator.approve`.
- **Aprobar crea al creador en `pending`, no en `active`** (`DEC-057`). Activar
  exige la completitud operativa de `BR-CREATOR-006` y es otra puerta.
- Detección de duplicados en dos momentos: al abrir la solicitud (correo) y en
  el servidor al aprobar (correo y documento). La casilla «ya lo revisé» **no
  salta la comprobación** (`BR-SEC-008`).
- 11 pruebas. La principal verifica que un creador recién aprobado queda en
  `pending` con `activated_at` nulo.

## [Fase 3 · 3.3 — Consulta de bitácora] — 2026-08-22

### Añadido
- Pantalla `/bitacora` con filtros por entidad, actor, acción, y rango de fechas.
  Permiso `audit.view`, que existía desde 2.4 sin pantalla donde usarse.
- `ix_audit_logs_occurred` — el filtro por fechas escaneaba la tabla entera.
- **Red de redacción en `Bitacora`** (`BR-SEC-007`): si el nombre del campo
  contiene `password`, `token`, `secret`, `api_key`, `account_number`,
  `encrypted`, `fingerprint`, `card` o `cvv`, el valor no se escribe. El nombre
  del campo sí: saber que alguien tocó la cuenta es auditoría, saber cuál era no.
- 12 pruebas, con `DataProvider` sobre los seis nombres sensibles del esquema.

### Decidido
- `DEC-056` — el listado ordena por `id` y no por `occurred_at`. No es solo
  velocidad: `occurred_at` empata, y en una paginación eso hace que aparezcan
  filas repetidas o que desaparezcan entre páginas.

## [Fase 3 · 3.2 — Bitácora y primera escritura] — 2026-08-22

### Corregido
- **`audit_logs` existía desde 2.4 y nadie escribía en ella.** Una bitácora vacía
  da la impresión de que hay rastro.
- **La bitácora admitía `UPDATE` y `DELETE`.** La regla «el registro de auditoría
  no debe ser fácilmente modificable desde la aplicación» era un comentario, no
  un hecho. Ahora lo impiden dos disparadores (`DEC-054`), probados en los dos
  motores.

### Añadido
- `App\Shared\Audit\Bitacora` — registra solo lo que cambió, congela el actor
  y empaqueta la IP con `inet_pton` para que una IPv6 entre entera.
- Primera pantalla de **escritura**: edición de contacto y preferencias
  comerciales del creador, con `FormRequest`, CSRF y permiso propio
  (`creator.manage`, distinto de `creator.view`).
- 10 pruebas. La que más importa: enviar documento, correo, nombre legal y
  `status=blacklisted` en la petición y verificar que **ninguno se movió**
  (`DEC-055`, `BR-SEC-005`).
- `BR-SEC-004` a `BR-SEC-006`.

## [Fase 3 · 3.1 — Permisos] — 2026-08-22

### Corregido
- **`permission_role` estaba vacía y nada comprobaba permisos.** Había 15
  permisos y 6 roles sembrados desde la iteración 2.4, sin una sola concesión y
  sin middleware: **cualquier usuario con sesión llegaba a todas las pantallas**.
  No se notaba porque solo existía el administrador.

### Añadido
- `App\Shared\Auth\Permisos` — resuelve permisos por usuario, con caché por
  petición. Sin Eloquent y sin depender de `App\Models\User`, que no pertenece a
  ninguna capa de Deptrac.
- `App\Shared\Http\Middleware\ExigirPermiso` — `permiso:codigo` en las rutas.
  Con varios códigos la semántica es O.
- `App\Shared\Providers\AutorizacionServiceProvider` — conecta con `Gate` para
  `@can` en las vistas. `Gate::before` devuelve `true` o `null`, **nunca `false`**:
  un `false` ahí denegaría todas las autorizaciones del sistema.
- Matriz rol→permiso sembrada (`DEC-053`) y permiso `catalog.view`, que faltaba.
- Vista de 403 propia, que dice qué permiso falta.
- Menú lateral filtrado por permiso — sin dejar de comprobar en la ruta.
- **12 pruebas**, dos de ellas estructurales: que ninguna ruta autenticada quede
  sin permiso, y que ningún permiso quede sin rol.

### Pendiente
- ⚠️ Dos concesiones para revisión de negocio: margen interno para
  `campaign_manager`, y datos fiscales para `finance`. Ver `DEC-053`.

## [Fase 2 · CERRADA Y VERIFICADA] — 2026-08-22

**El CI pasa en verde de punta a punta**, sobre una máquina limpia y un motor
distinto al de desarrollo. Es la primera verificación independiente del proyecto.

| Puerta | Estado |
|---|---|
| Pint · PHPStan (nivel 6) · Deptrac | ✅ |
| Nombres sin colisiones · migraciones ↔ esquema · columnas del generador | ✅ |
| **125 aserciones con `CHECK` nativo** (MySQL 8) | ✅ |
| **125 aserciones sin `CHECK`, solo triggers generados** | ✅ |
| Los dos motores imponen el mismo conjunto (150 restricciones) | ✅ |
| **`php artisan migrate`** — 62 tablas, por primera vez ejecutado de verdad | ✅ |
| `php artisan test` — 18 pruebas propias | ✅ |
| Build del frontend | ✅ |

### Añadido
- `tests/Unit/RestriccionTest.php` — 14 pruebas del compilador de restricciones,
  sin base de datos. Cubre los casos que ya rompieron algo: literal `'status'`,
  `status_code` partido por `status`, comilla escapada, y `IF NOT (expr)`.
- `tests/Feature/RutasTest.php` — enrutado y middleware. Sustituye a la prueba de
  ejemplo de Laravel, que afirmaba que `/` devuelve 200: aquí no hay portada
  pública, `/` redirige al panel y el panel exige sesión.
- Puerta de CI para el frontend: nada verificaba que el CSS compilara.

### Corregido
- **`DEC-052`** — MySQL rechaza (error 1093) toda subconsulta sobre la tabla que
  se está modificando; MariaDB la permite. Afecta al código de aplicación de la
  Fase 3, no solo a las pruebas: producción es Percona 5.7.
- Siete herramientas tenían rutas absolutas del entorno de desarrollo.
- `pint.json` exigía `declare(strict_types=1)` en archivos del framework.
- `deptrac.yaml` no declaraba ninguna capa para Laravel: todo uso del framework
  contaba como dependencia sin cubrir.
- `env()` fuera de `config/` ignoraba `ADMIN_PASSWORD` con la config cacheada.

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
