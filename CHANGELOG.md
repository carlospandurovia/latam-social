# CHANGELOG

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/).

## [9.8 · Mis ingresos] — 2026-08-27

La primera vez que un creador ve su dinero en el sistema. Sin migración: el libro
mayor de `9.3` ya tenía todo lo que hacía falta.

### Añadido
- **`/mis-ingresos`**, segunda pantalla del portal del creador. Saldo **por
  moneda** —sumar dos exige un tipo de cambio, y el de hoy no es el del día del
  pago— y la lista de movimientos con su estado.
- **Sin un solo botón.** El creador no mueve dinero: lo mira. Darle una acción
  aquí sería darle una palanca sobre un libro que no se edita.
- Mientras un asiento está devengado se enseña **qué falta**, con las palabras de
  las cinco condiciones. Es accionable; una fecha no lo sería (`DEC-173`).

### Sabido y dicho
- **El motivo interno de una retención no cruza** (`DEC-172`). Se escribe para el
  expediente y puede nombrar sospechas sin confirmar. El creador ve «En revisión
  — te escribimos», y el correo de `8.8` es donde se le explica bien.
- Tampoco cruzan los asientos anulados, ni el margen, ni el dinero de otro
  creador — y si no hay creador para el usuario, **404 y no 403**.

## [9.4 · El devengo por evento] — 2026-08-27

`9.3` dejó `Ledger::devengar()` y nadie lo llamaba. Ahora aceptar una invitación
anota el asiento solo, y el ciclo se cierra.

### Añadido
- **`DevengarParticipacion`**, escuchando `campaign_creator.accepted`. Se devenga
  **al aceptar** porque es entonces cuando existe la deuda; que aún no se pueda
  pagar vive en el estado (`DEC-170`).
- **La red de seguridad**: `ledger:revisar` anota las aceptaciones que se
  quedaron sin devengo, antes de revisar, y **avisa en amarillo**. Que esa lista
  no salga en cero es la noticia, no el arreglo (`DEC-171`).

### Sabido y dicho
- **Dos finales no son fallos**: que ya hubiera devengo —`uq_ledger_devengo`
  haciendo su trabajo— y que la colaboración sea un canje. Escribirlos como
  errores haría que el log gritara cada vez que alguien acepta un canje, y a la
  tercera nadie lo lee.

## [9.3 · El libro mayor del creador] — 2026-08-27

`ledger_entries` llevaba en el esquema desde la Fase 2 con doce `CHECK` y sus dos
disparadores de inmutabilidad. **Y cero filas.** Aquí empieza a haber dinero.

### Añadido
- **`Ledger`**: `devengar`, `requisitos`, `revisarPagable`, `retener`, `liberar`,
  `anular`, `saldo`. El saldo es una **suma**, nunca una columna (`BR-FIN-001`).
- **`ledger:revisar`**, a las 06:30 — después de `permanencia:vigilar`, porque de
  ella depende que una publicación cuente como verificada. Pasar a pagable lo
  hace el sistema: las cinco condiciones de `BR-FIN-003` ya las firmó alguien una
  por una (`DEC-166`).
- **`uq_ledger_devengo`**: un devengo por participación. `BR-FIN-015` lo daba por
  hecho sin decirlo. Vigesimosexta columna puerta — y un devengo anulado libera
  el sitio, porque anularlo significa que no debió existir.
- **`tg_ledger_estado`**: el grafo de estados, con `paid` y `void` terminales. Un
  pago que se deshace se corrige con un asiento de reversión, no cambiando el
  estado (`DEC-169`).
- Un post caído **retiene** el asiento y espera a una persona (`DEC-167`); el
  asiento va en la **moneda pactada** y se convierte al pagar (`DEC-168`).

### Corregido
- **`T-62`: el motivo de la transición anterior explicaba la siguiente.** Los dos
  campos siguen en la fila después del movimiento previo, así que un `UPDATE …
  SET status='on_hold'` a secas pasaba. **Lo cazó la suite de `2.13`** — que a su
  vez tenía una aserción afirmando ese hueco como correcto desde la Fase 2, igual
  que `T-16`. Ahora `status_changed_at` tiene que cambiar.
- Y de ahí, lo segundo: un `Carbon` sin formatear se escribe sin fracción aunque
  la columna sea `DATETIME(3)`, así que dos transiciones en el mismo segundo
  quedaban idénticas — y pasar a pagable y retener seguido es constante.

## [9.2 · Que las tasas lleguen solas] — 2026-08-27

`9.1` dejó la máquina montada y vacía. Esto le da cuerda: un cron, un cliente de
Decolecta y una pantalla.

### Añadido
- **`cambio:traer`**, todos los días a las 05:30. Pide **tres** días, no uno: si
  el cron no corrió viernes ni sábado, pedir sólo el domingo deja dos huecos que
  nadie rellena a mano (`DEC-164`).
- **Pantalla de Tipos de cambio**: qué fuente manda para cada par, las últimas
  tasas, los últimos intentos del cron, carga manual, y la credencial.
- **`fx_fetch_runs`**: cada intento con su resultado. Enseña que el cron murió
  **antes** del día de la liquidación, que es cuando lo detectaría
  `Cambio::DIAS_ATRAS`. Con aviso en pantalla **sólo si hay algo que mirar**.
- **La credencial** se configura desde la pantalla, **pero**: el entorno manda si
  está, en la base va cifrada, no se reenseña jamás, la bitácora guarda los
  cuatro últimos y nunca el valor, y `tg_fxs_credencial_firmada` exige quién la
  puso y cuándo (`DEC-162`). Permiso propio: `integration.manage`, no
  `fx.manage`.
- **Cada final tiene su nombre** — `sin_credencial`, `error_http`, `error_red`,
  `respuesta_rara` — porque exigen arreglos distintos y en un `catch` genérico se
  ven iguales. Un 404 no se pinta como avería (`DEC-163`).

### Corregido
- **`T-61`: la traída podía escribir a medias y jurar que no.** Un `sell_price`
  malo dejaba la compra ya anotada mientras el resultado decía `respuesta_rara`,
  y `ck_ffr_nuevas` obliga a que una corrida fallida diga cero — o sea una fila
  en `exchange_rates` que el registro juraba que no existía, en una tabla que no
  se puede corregir. Ahora se validan las dos antes de anotar ninguna.
- **`env()` fuera de `config/`** (lo cazó PHPStan). Con `config:cache` habría
  sido un cron que corre, no trae nada, y dice «no hay credencial» teniéndola
  delante — lo mismo que le pasó al seeder del administrador.
- **Aritmética de fechas a mano** en `declararOficial()` (lo cazó la puerta de
  vigencias). Y de paso: relevar «desde el mismo día» no se arregla moviendo
  fechas, se contesta con palabras (`Cambio::vetoParaDeclarar()`).

### Sabido y dicho
- **Decolecta sólo trae `USD → PEN`**, porque SUNAT sólo publica el dólar. El
  catálogo no declara ningún otro par, la pantalla lo explica, y la carga manual
  se guarda con fuente `manual` y no disfrazada de `sunat` (`DEC-165`, `Q-64`).

## [9.1 · Tipos de cambio] — 2026-08-27

Empieza la fase de finanzas. `exchange_rates` llevaba en el esquema desde la
Fase 2 con **cero filas y cero lecturas**, y tenía tres agujeros que sólo se ven
cuando la tabla tiene datos.

### Añadido
- **`fx_official_sources`**: qué fuente manda para cada par de monedas y desde
  cuándo, con periodos. Antes, dos fuentes podían tener tasa el mismo día y nada
  decía cuál se aplica — el mismo empate que una vez emitió una factura desde la
  sociedad equivocada (`DEC-158`). Vigesimoquinta columna puerta.
- **`side`** en `exchange_rates`: SUNAT publica compra y venta el mismo día y no
  son intercambiables. `Cambio::tasa()` exige el lado **sin valor por defecto**,
  porque un defecto ahí es cómo una decisión contable se toma sola (`DEC-159`).
- **`Cambio`**: `tasa()`, `convertir()`, `declararOficial()`, `anotar()`.
  `convertir()` devuelve las siete cosas de `BR-FIN-004`, no un número. La
  multiplicación la hace el motor con `DECIMAL` — **`bcmath` no se usa a
  propósito**: no está en todos los hostings compartidos, y descubrirlo en
  producción sería descubrirlo convirtiendo dinero.
- Un día sin tasa usa la última anterior y **guarda la fecha de esa tasa**, no la
  de la operación. Con un corte de 10 días, para que un cron parado no se
  disfrace de feriado (`DEC-160`).
- Suite `9.1-cambio.sh`: 21 aserciones, verdes en los dos motores.

### Corregido
- **`tg_fx_inmutable`**: una tasa publicada se podía reescribir, pese a que
  `BR-FIN-009` dice que los históricos no se recalculan. `tg_fx_no_delete` estaba
  desde `3.12`; el `UPDATE` no lo miraba nadie.
- **`fk_fx_source`**: la fuente era texto libre, así que una tasa podía decir que
  la publicó `bcrp` sin que nadie hubiera dicho nunca quién es `bcrp`.
- **`T-60`, tres reglas puestas que no eran las que contestaban**: `side` nació
  `VARCHAR(4)` y el ancho de la columna respondía antes que `ck_fx_side`; el
  comentario sobre mayúsculas en la clave ajena era falso (el cotejamiento no
  distingue) y ahora está afirmado en la suite; y la primera migración del
  proyecto que sembraba datos rompió `recolectar-esquema.php`.

### Cambiado
- **`porque()` admite alternancia** (`DEC-161`). Los dos motores contestan cosas
  distintas a la misma regla —nombre en uno, mensaje en el otro— y eso obligaba a
  dejar toda regla de `Restriccion` en un `probar … RECHAZO` a secas. Es la
  herramienta que faltaba para bajar el trinquete de las 297.
- El comentario de `BuscadorDeCreadores` decía «llega en 9.1». Llegó: ahora dice
  lo que falta de verdad, que es `Q-63`.

### No se hizo
- **Traer tasas de ningún sitio.** El cron con Decolecta, la pantalla y las
  credenciales son `9.2`. Aquí está la máquina; falta quien le dé cuerda.

## [8.13 · Una corrección y un adelanto] — 2026-08-27

Sin código. Un error mío de ayer, y una regla 🔴 que llegaba diez iteraciones
tarde.

### Corregido
- **`T-59`: `DEC-156` afirmaba que «`payouts` no tiene ninguna columna de
  sociedad», y es falso.** `payout_batches.legal_entity_id` existe desde la
  migración de finanzas, y un pago no puede existir sin lote. Quien paga **sí**
  está dicho. Lo escribí mirando `payouts` y no mirando su lote — el mismo error
  que `T-58` una hora antes: afirmar sobre un dato sin leer la fila.
- Lo que de verdad falta es otra cosa: **nada garantiza que sea quien debe.**
  `ledger_entries` ata un asiento a su campaña y a su pago, la campaña lleva su
  sociedad, y entre los dos extremos no hay ninguna restricción. Hoy un lote de
  CTS Colombia podría pagar el trabajo de una campaña de CTS Perú.

### Cambiado
- **`DEC-157`: la comprobación de `BR-LE-009` se adelanta de `9.11` a la
  iteración que estrene `payout_batches`**, y baja a la base. Una regla 🔴 en la
  posición 11 de 14 llega después de que diez iteraciones se hayan construido
  dándola por buena — es la forma de fallo de `8.4`, pero aquí lo que se cuela
  no es una ronda: es dinero saliendo de la sociedad equivocada.
- **`DEC-020` deja de ser PROPUESTA.** Preguntaba si permitir que la entidad que
  factura y la que liquida divergieran; `DEC-156` la contesta con la opción C y
  además le quita el «en el MVP».

## [8.12 · La sociedad que factura, y quién paga] — 2026-08-27

Sin migración. Un defecto de dinero que llevaba escondido desde 7.1, y una
decisión de negocio que faltaba por escribir.

### Añadido
- La ficha de campaña dice **por qué** es esa sociedad —«CTS Perú factura a Perú
  desde el … (sociedad local)»—, no sólo cuál. Una sociedad a secas no se puede
  comprobar (`DEC-155`).
- Y dice que esa sociedad **paga a todos los creadores** de la campaña, también a
  los de otro país (`BR-LE-009`). Es donde se mira antes de invitar a nadie.
- El formulario de **edición** adelanta quién va a facturar. El de alta no: sin
  cliente ni fecha no hay respuesta, y resolver «con la cobertura de hoy» para
  enseñarlo es el «deducirlo de la configuración vigente» que prohíbe
  `BR-LE-001`.
- `Cobertura::sociedad()` y `Campanas::quienFacturaEsta()`.

### Corregido
- **`T-58`: la ficha enseñaba la sociedad que tocaría HOY bajo el rótulo de la
  guardada.** Imprimía lo que devuelve el resolver, con la única condición de que
  la campaña tuviera alguna sociedad guardada — mientras nadie tocara la
  cobertura, las dos coinciden y no se nota. El comentario de encima del bloque
  decía «se enseña el dato GUARDADO… cuando los dos no coinciden se dice»:
  describía código que nunca se escribió. Ahora manda la guardada y la
  discrepancia se avisa.

### Cambiado
- **`BR-LE-009` pierde el «en el MVP» y sube a 🔴** (`DEC-156`): la sociedad de la
  campaña paga a todos sus creadores, sea cual sea el país de cada uno. El país
  del creador determina **cómo** se le paga —retención, moneda, documento—, nunca
  **quién**. Lo implementa `F9`. *(La primera versión de esta línea decía que
  `payouts` no tiene columna de sociedad. Es falso — ver 8.13, `T-59`.)*
- **`Q-40` cambia de eje.** La columna «¿quién le paga?» no era función del país
  del creador, y con ella la tabla le pedía al contador peruano que opinara sobre
  pagos colombianos. Ahora son dos tablas: *CTS Perú paga* y *CTS Colombia paga*.
  España deja de ser un hueco ahí — sigue siéndolo en `Q-15`, que es otra cosa.

### No se hizo
- **Un desplegable «CTS Perú / CTS Colombia» en la campaña.** `uq_lec_country` y
  `tg_lec_sin_solape_*` garantizan como mucho una sociedad por país y fecha, así
  que tendría siempre una opción — y una opción que se puede cambiar es una que
  alguien puede cambiar mal. Antes de 3.10 ese empate existía, y fue una factura
  emitida por la sociedad equivocada.

## [8.11 · QA de la Fase 8] — 2026-08-27

No añade funcionalidad. Añade la séptima puerta, y arregla tres cosas que la fase
daba por hechas.

### Añadido
- **La séptima puerta**: `tools/verificar-suites.py`. Comprueba que las suites
  comparten ayudantes y lleva un **trinquete** sobre las aserciones negativas que
  sólo afirman que algo falló — hoy **297**, y sólo pueden bajar.
- **`tools/pruebas/comun.sh`**: los cuatro ayudantes en un sitio. Estaban
  copiados en las treinta suites, en **seis variantes**.
- Cada `probar … RECHAZO` enseña ahora **qué restricción respondió**, en gris.

### Corregido
- **Nueve suites se habrían puesto verdes con el motor apagado** (`T-55`).
  Medido con `7.6` contra una base que no existe: el ayudante viejo daba
  `3 correctas, 0 fallidas`; el compartido da `0 correctas, 39 fallidas`. Y no
  era una lección nueva: `2.13` lleva escrito desde la Fase 2 «25 aserciones en
  verde contra un socket muerto».
- **`cargosPendientes()` no sabía cuáles estaban pendientes** (`T-56`). Devolvía
  todas las rondas de más para siempre, porque no existe la columna por la que
  decía filtrar — y no puede existir, porque `content_reviews` es append-only.
  Ahora se llama `rondasCobrables()` y la pantalla lo dice. Abre `Q-61`.
- **El contador de columnas puerta iba por 17 y son 24** (`T-57`). Se numeraban
  a mano y el número se heredaba de un documento al siguiente. Ahora sale de
  contar el esquema, y los ordinales desaparecen de los documentos.

## [8.5 · El visto bueno del cliente] — 2026-08-27

La primera vez que entra al sistema alguien de la **marca**. Sin portal, sin
cuenta y sin contraseña. Cierra el ciclo de contenido.

### Añadido
- **`approval_links`** y la pantalla pública del cliente: ve la campaña, el
  formato, la versión aprobada y el contenido — y **ni un solo importe**
  (`BR-SEC-001`). La frontera es `Aprobaciones::pieza()`, que enumera columnas.
- **Su respuesta se registra y no mueve la pieza** (`DEC-151`). No es
  burocracia: su corrección gasta ronda, y desde `8.4` una ronda de más exige
  firma y decisión de facturación — que él no puede poner contra sí mismo.
- **El silencio no hace nada** (`DEC-152`), y por eso esto **no estrena comando
  de caducidad** al revés que `7.6`: allí una invitación sin contestar dejaba
  dinero comprometido; aquí no hay nada que cerrar.
- **Una petición sin rondas queda pendiente de autorizar** (`DEC-153`).
- **`tg_apl_version_aprobada`** cierra la otra mitad de `BR-CONTENT-002`: al
  cliente le llega lo aprobado **y esa versión**. El puntero de `8.2` es lo que
  hace comprobable esa frase.
- **Decimoséptima columna puerta**: un enlace vivo por pieza. Dos son dos
  respuestas contradictorias del mismo cliente sin forma de saber cuál vale.
- **`approval_links` entra en la lista de `3.12`**: es la conformidad del
  cliente, y de ella depende que se publique.

### Notas
- Del token se guarda **la huella**, y no se queda en la barra de direcciones
  (`DEC-117`). Misma pieza de `5.9` y `7.6`.
- `ck_apl_cambios` exige diez caracteres al pedir cambios y **no al aprobar**: un
  «Perfecto» de ocho letras es una respuesta válida.

## [8.4 · El techo de rondas, en la base] — 2026-08-27

`8.3` construyó el límite entero y lo dejó todo en PHP. Un `if` de un servicio
sólo protege al que pasa por ese servicio — y `8.5` escribe revisiones del
cliente desde un enlace firmado.

### Añadido
- **`ck_cvw_round` gana el lado**: sólo la corrección del **cliente** gasta
  ronda. `DEC-133` vivía únicamente en `Revisiones::consumeRonda()`, así que una
  revisión nuestra podía gastarle una ronda al cliente.
- **`tg_cvw_techo`**, en las dos direcciones: con las rondas agotadas hay que
  declarar la de más, y no se cobra como extra lo que todavía entraba. Es
  cross-table —el techo está en `campaigns`, lo gastado en `deliverables`— y lee
  el contador **antes** de que `emitir()` lo suba, que es el mismo valor con el
  que el servicio calculó su `$exceso`.
- **`tg_del_rondas`**: el contador no baja. Bajarlo devolvía rondas ya gastadas
  sin la firma de nadie.

### Corregido
- **Dos suites estaban verdes por una premisa que nunca escribieron** (`T-54`).
  `8.3` afirmaba `over_included = 1` con el contador a cero, y `2.12` insertaba
  una corrección que gastaba ronda **sin decir de quién era** — el valor por
  defecto de `reviewer_side` es `platform`, así que la suite fundacional del
  módulo llevaba desde la Fase 2 fijando como correcto lo que `DEC-133` prohíbe.
- **`docs/09` decía que el límite no existía.** Era falso: estaba entero desde
  `8.3`. Anotado en el documento y en el de la iteración.

### Notas de diseño
- **Los disparadores siguen sin modificar filas** (`DEC-150`). Que el contador lo
  subiera un `AFTER INSERT` habría hecho imposible la desincronización, pero
  ninguno de los 504 disparadores de este esquema toca una fila, y uno que
  **haga cosas** convierte el esquema en algo que actúa a espaldas del código.
- **`tg_del_rondas` es monótono y no «+1»** (`T-53`). La primera versión rompió
  siete pruebas que tenían razón. El daño no es simétrico: bajarlo no necesita a
  nadie; subirlo obliga a firmar y a decidir facturación.

## [Proceso · `main` vuelve a ser la línea de trabajo] — 2026-08-27

### Cambiado
- **Se trabaja en `main`** (`DEC-149`). El historial era una línea recta —nunca
  hubo dos cosas a la vez— así que las ramas no separaban nada, y la que existía
  para proteger `main` lo dejó **nueve iteraciones obsoleto**. Lo que sustituye a
  la rama son las seis puertas: no se commitea en rojo. Vuelven las ramas —una
  por iteración, fusionada el mismo día— el día que haya producción.
- **`develop` sale del `on: push` del CI.** Esa rama no ha existido nunca, y
  `CONTRIBUTING.md` la nombraba desde el principio.
- **`docs/19` §3** reescrito con la medición delante, y `CONTRIBUTING.md`
  alineado con la realidad.

### Corregido
- **El CI vuelve a verde después de 24 ejecuciones en rojo** (`T-52`).
  `resources/views/welcome.blade.php` —la portada que trae Laravel de fábrica—
  llamaba a `route('login')` y `route('register')`, que este proyecto no declara.
  Nadie renderizaba esa vista (la raíz redirige a `/panel`), pero
  `verificar-pantallas.py` la veía y tenía razón. **Mis seis puertas no podían
  verla**: mi contenedor tiene `stage/`, que es sólo lo que yo entrego, y la
  herramienta escanea esa raíz cuando existe. Punto ciego documentado en
  `docs/19 §4`.
- Primera ejecución verde completa de la historia del proyecto con la cola
  entera: **Percona 5.7**, el build del frontend y las 687 pruebas.

## [8.8 · La permanencia mínima del post] — 2026-08-27

Qué pasa cuando un creador retira el post antes de tiempo, y por qué eso **no**
lo puede decidir una máquina.

### Añadido
- **La bandeja de permanencia**, en `/permanencia`. Las caídas abiertas primero
  —son las que tienen un pago parado detrás— y luego lo vigilado que nadie mira.
  `permanence_checks` estrena su primera fila.
- **Retirar el post bloquea el pago, y el sistema no descuenta nada**
  (`DEC-145`). La publicación pasa a `removed` con motivo, firma y fecha; el
  entregable sale de `verified`; y ahí se para. La decisión de qué se le paga la
  toma una persona con el expediente montado delante.
- **La sonda marca, una persona confirma** (`DEC-146`). Una comprobación se
  archiva y **no cambia el estado de nada**. `tg_pub_permanencia` exige una
  comprobación fallida **y** una captura tomada después de haber verificado el
  post: la que probó que existía no prueba que ya no esté.
- **`permanencia:vigilar`**, diario a las 06:00. Cierra las ventanas cumplidas
  —`verified` → `fulfilled`, que es lo que habilita el pago— y cuenta las
  desatendidas. **No sale a Internet**, y eso es la decisión, no un descuido.
- **Se avisa al creador y al equipo; al cliente no** (`DEC-147`). El correo no
  dice «incumpliste»: dice «no lo encontramos», y dice que el pago está en pausa.
- **El entregable caído estrena estado** (`DEC-148`). `removed`, y no `published`
  reutilizado: `published` significa «esperando a que alguien lo mire», y un
  estado que significa dos cosas es el fallo de `T-50`.
- **Decimosexta columna puerta**: `uq_pc_sonda_dia`, una sonda por publicación y
  día. Un cron duplicado —dos servidores, o alguien probando el comando a mano—
  mandaba dos correos al creador por la misma caída.

### Cambiado
- **`expired` pasa a llamarse `fulfilled`** en `publications`. Tenía cero filas
  desde `2.12` y un nombre que se lee al revés de lo que significa: la ventana
  cumplida es lo bueno, es lo que habilita el pago.
- **`permanence_checks` entra en la lista de `3.12`**: append-only y sin borrado.
  El criterio de `T-16` la incluía desde el primer día —la fila es evidencia y de
  ella depende dinero— y nadie la había mirado.
- **`docs/18` §2** explica ahora qué cuelga de la línea de `schedule:run` y qué
  se rompe en silencio si no está.

### Corregido
- **Dos aserciones que dependían del motor** (`T-51`). `is_live = 7` sin notas y
  `status = 'expired'` con la permanencia puesta violaban **dos** restricciones a
  la vez, y cuál responde depende del orden de evaluación: verde en MariaDB, rojo
  en MySQL 8. Es `T-48` otra vez, y el arreglo es el mismo — un rechazo sólo
  prueba algo si rechaza por su motivo.

## [8.7 · La prueba de que el post existió] — 2026-08-26

Y por qué esa prueba es una captura de pantalla y no una comprobación
automática.

### Añadido
- **La cola de verificación**, en `/verificacion`, y el archivado de la
  evidencia. `publication_evidence` estrena su primera fila.
- **La evidencia es una captura** (`DEC-142`). Un `http_status` no distingue «el
  post existe» de «nos bloquearon» —Instagram devuelve `200` con un muro de
  login— y de `verified` cuelga el pago. La sonda HTTP se guarda como dato
  complementario y **no decide**; `tg_pub_verificada_con_evidencia` lo impone.
- **Permiso propio `content.verify`** (`DEC-143`), comprobado en el POST.
  Finanzas no lo tiene: paga contra lo verificado, no verifica.
- **Si el post no está, el entregable vuelve al creador** (`DEC-144`), a
  `approved` y no a revisión: el contenido estaba bien, lo que falla es el
  enlace. Motivo de lista cerrada y correo con el motivo dentro.
- **`permanence_until`** se calcula al verificar, desde `published_at` y con los
  días del requisito. Es lo que `8.8` va a vigilar.

### Corregido
- **Una publicación rechazada bloqueaba su propio enlace, y el del vecino**
  (`T-50`). `uq_pub_fingerprint` era única global, así que cuando se le pedía al
  creador que arreglara el post y volviera a registrar el mismo enlace, se
  estrellaba con un `1062`. Y peor: se rechaza el post de Ana por «está
  publicado en otra cuenta», esa cuenta es la de Luis, **y Luis no podía
  registrar su propio post**. Ahora la unicidad mira sólo las vivas, con la
  **decimoquinta columna puerta** del esquema.
- **`ck_pev_screenshot`**, que cierra un hueco de `2.12`: `ck_pev_content` sólo
  pedía «algo», así que una fila que decía `screenshot` y traía un `200` pelado
  se leía como una captura que nadie hizo.

### Verificación
**659 pruebas / 2.051 aserciones** · **1.438** aserciones de restricción en
MariaDB y **1.428** en MySQL 8 · 14 mutaciones, 3 sobrevivieron y las tres están
cerradas · las seis puertas en verde.

Una de las tres sobrevivía porque **la fecha coincidía**: la permanencia se
calcula desde `published_at`, y el post se reportaba *hoy*, así que sustituir
`published_at` por `now()` no rompía nada. Ahora se registra con fecha de hace
cinco días. Es el mismo patrón que la mutación del plazo en `8.1` —dos pruebas
usando el mismo número— en otra forma.

---

## [8.6 · El post publicado] — 2026-08-26## [8.6 · El post publicado] — 2026-08-26

Donde el trabajo sale de nuestras pantallas y aparece en las de todo el mundo.
`publications` estaba diseñada desde la Fase 2 con cero filas — la **sexta** tabla
del proyecto en esa situación.

### Añadido
- **El creador pega el enlace de su post** desde `/mis-entregas`, en cuanto
  publica. Y **el equipo puede hacerlo por él** (`DEC-139`), porque el caso real
  existe: el enlace llega por WhatsApp y el creador no entra. Las dos puertas
  pasan por el mismo servicio y el mismo veto; sólo cambia quién firma.
- **Sólo se publica lo aprobado, y esa versión** (`DEC-140`). Se guarda **qué**
  versión se publicó, como snapshot y con clave ajena compuesta, porque de esa
  fila cuelga el pago: `8.7` la verifica y `8.8` cuenta su permanencia.
- **La red se deduce del enlace** y tiene que ser la del brief (`DEC-141`).
  Estrena `platforms.url_pattern`, en el catálogo desde `2.6` sin usar.
- **La huella normaliza la URL** antes de firmarla, que es lo único que hace útil
  a `uq_pub_fingerprint`: con la URL cruda, `?utm_source=ig` basta para reclamar
  dos veces el mismo post. Y **lo que identifica el post no se toca** — la ruta
  conserva mayúsculas, y la lista de parámetros que se quitan es explícita
  porque `youtube.com/watch?v=…` lleva el identificador en la query.

### Corregido
- **La suite de `2.12` pasaba en un motor y no en el otro** (`T-49`). Al exigir
  aprobación antes de publicar, pasó a hacer `UPDATE … WHERE id = (SELECT … FROM
  la misma tabla)`: MariaDB lo tolera, MySQL da `1093`. Misma trampa que la suite
  de `8.1` ya documentaba. De paso apareció que esa suite insertaba entregables
  sin `submitted_at`, algo que `ck_del_approved` —de la propia `2.12`— prohibía
  desde el primer día y que nadie había visto porque nadie había aprobado nada allí.

### Verificación
**638 pruebas / 2.006 aserciones** · **1.398** aserciones de restricción en
MariaDB y **1.388** en MySQL 8 · 18 mutaciones, **las 18 detectadas a la
primera** · las seis puertas en verde.

Ocho de esas mutaciones son sobre la normalización de la URL, en las dos
direcciones: las que la aflojan —no quitar `utm`, no quitar `www`, no ordenar los
parámetros— y las que la aprietan de más —bajar la ruta a minúsculas, borrar la
query entera—. Las segundas importan igual: una huella demasiado agresiva rechaza
un post legítimo diciendo que ya está reclamado, y quien lo reporta no tiene
forma de saber por qué.

---

## [8.2 · Qué versión es la buena] — 2026-08-26## [8.2 · Qué versión es la buena] — 2026-08-26

Y cómo se vuelve atrás cuando la buena deja de serlo.

### Añadido
- **`deliverables.approved_version_id`**: qué versión se aprobó (`DEC-137`). No
  «la última» —eso sale de un `MAX()` y guardarlo sería una copia que se
  desvía— sino **la aprobada**, que es el dato del que van a colgar `8.6` y `8.7`.
- La clave ajena es **compuesta**, y ahí está casi todo el valor: una simple
  garantizaría que la versión existe; ésta garantiza que es **de este
  entregable**. Sin la segunda columna, un `UPDATE` mal escrito deja el
  entregable de uno apuntando a lo aprobado del otro y la fila sigue siendo
  válida para la base.
- **Reabrir** un entregable aprobado, con motivo de lista cerrada y firma
  (`DEC-138`). **No deshace nada**: la aprobación anterior se queda en el
  historial y la reapertura es otra línea. Permiso `content.reopen`, en los dos
  roles que revisan — «se aprobó por error» lo descubre normalmente quien aprobó.
- **`tg_dv_entregable_abierto`** (`T-47`): «no se entrega sobre un entregable
  cerrado» vivía en el servicio desde `8.1` y **la base no lo conocía**. Un
  comando o un import podían meter una versión encima de algo ya aprobado.

### Corregido
- **Una aserción que dependía del orden de evaluación de los CHECK** (`T-48`).
  `8.3` afirmaba que aprobar sin firma se rechaza por `ck_del_aprobador`; con la
  restricción nueva encima, MariaDB seguía rechazando por el primero y MySQL
  empezó por el segundo. El orden no está garantizado. Es `T-43` en otra forma.
- **La limpieza de la suite de `8.3` dejó de funcionar en silencio**: borrar la
  versión apuntada es justo lo que la nueva clave ajena impide. Lo cazó la
  aserción de premisa que `8.1` introdujo — que es literalmente para lo que está.

### Verificación
**606 pruebas / 1.952 aserciones** · **1.360** aserciones de restricción en
MariaDB y **1.350** en MySQL 8 · 11 mutaciones, 2 sobrevivieron y las dos están
cerradas · las seis puertas en verde.

Las dos supervivientes eran vetos del servicio que la pantalla nunca alcanza
porque el `FormRequest` los filtra antes. Los dos siguen haciendo falta: sin el
del motivo, `reabrir()` compone su texto con una clave inventada y eso es un
fatal; sin el del estado, escribe una revisión sobre un puntero nulo y la clave
ajena lo tumba con un error crudo en la cara de quien revisa.

---

## [8.3 · La revisión] — 2026-08-26## [8.3 · La revisión] — 2026-08-26

Lo que cierra el ciclo: un creador entrega, alguien lo mira, y la pieza pasa a
estar buena o a volver.

### Añadido
- **La cola de revisión**, en `/revision`. **Bandeja global** y no una por
  campaña (`DEC-136`): revisar es trabajo por lotes, y una cola por campaña
  obliga a recorrer campañas para descubrir si hay algo esperando. Ordenada por
  **lo que lleva más esperando**, no por lo que vence antes.
- **El veredicto**, con el brief al lado y el historial debajo. Pedir cambios
  exige decir cuáles (`ck_cvw_comments`).
- **Las rondas incluidas dejan de ser una frase del contrato**: son un contador
  que bloquea. Sólo cuentan las que pide el **cliente** (`DEC-133`) —las nuestras
  son control de calidad y cobrárselas sería cobrarle nuestro propio error— y
  hasta `8.5` quien lleva la cuenta marca de parte de quién viene (`DEC-134`).
- **Pasarse exige decidir y firmar** (`DEC-135`): se cobra o se absorbe, y queda
  quién lo autorizó. El cargo **no** va a `campaign_costs` —eso es lo que
  gastamos nosotros y resta del margen; una ronda de más al cliente es ingreso—.
  La pantalla de entregables la enseña como pendiente de facturar.
- **Tres permisos**: `content.review`, `content.approve` y `content.extra_round`,
  comprobados **en el POST** y no sólo en la ruta.
- **Aviso al creador** cuando le piden cambios, con el comentario dentro. Sólo la
  corrección manda correo: una aprobación no le pide nada y la ve en su portal.

### Cambiado
- **El contador de rondas se mudó de tabla.** Estaba en `campaign_creators` —dos
  rondas **por creador**—, así que con un creador que entrega dos reels y tres
  stories, dos correcciones sobre el primer reel dejaban las otras cuatro piezas
  sin ninguna, habiéndolas pagado el cliente. Ahora vive en `deliverables`, y la
  columna vieja **se fue**: la suma por creador sale de `content_reviews` con un
  `SUM()` que nunca se desvía.

### Corregido
- **`tg_cvw_inmutable`** (`T-45`). *«Append-only: un veredicto no se edita, se
  emite otro»* lo decía el documento de `2.12` desde el primer día y **no lo
  impedía nada**. Un veredicto justifica una ronda cobrada.
- **Una puerta que daba rojo por algo que nadie podía arreglar** (`T-46`), desde
  `4.9`. MariaDB no tiene tipo `JSON` y añade un CHECK implícito `json_valid`
  que se llama como la columna; `verificar-equivalencia.py` lo contaba como una
  diferencia entre motores. En el CI salía verde —allí la base con CHECK es
  MySQL— y en la máquina de quien desarrolla, roja siempre.
- **En `2.12`, cinco aserciones sobre una versión que nadie revisaría nunca**:
  apuntaban a la *primera* versión de un entregable que ya tenía dos.

### Verificación
**591 pruebas / 1.922 aserciones** · **1.320** aserciones de restricción en
MariaDB y **1.310** en MySQL 8 · 20 mutaciones, **1 sobrevivió y está cerrada** ·
las seis puertas en verde.

La superviviente fue `lockForUpdate()` sobre el contador de rondas, que no tiene
resultado observable en una conexión. Se cerró como en `8.1`: la prueba mira **el
SQL**. Sin ese `FOR UPDATE`, dos revisores sobre la misma pieza leen el mismo
contador y la segunda ronda del cliente **no se cuenta** — el cliente consigue
una corrección gratis y nadie se entera, porque el número que queda es plausible.

---

## [8.1 · Los entregables] — 2026-08-26## [8.1 · Los entregables] — 2026-08-26

La primera pantalla que ve un **creador**. Hasta aquí el sistema hablaba sólo con
el equipo.

### Añadido
- **Los entregables se generan solos al aceptar** (`DEC-129`), del brief
  **efectivo** del mercado y no del general (`DEC-130`). Va por evento, porque
  `Campaign` no puede conocer `Content`. Es idempotente: un reintento de la cola
  no duplica el trabajo de nadie.
- **`/mis-entregas`**, el portal del creador, con `creator.portal` — el **primer
  permiso de ámbito EXTERNAL** del sistema. Lo que ata la pantalla a sus datos no
  es el permiso sino `creators.user_id = Auth::id()`, comprobado en cada acción;
  lo de otro devuelve **404, no 403**.
- **Entregar es un enlace `https://`, y opcionalmente una imagen** (`DEC-131`).
  El `https://` se comprueba en el formulario, en el servicio y en la base, a
  propósito: sin el último, un import mete `javascript:` en una columna que una
  vista pinta dentro de un `href`.
- **Si al caption le faltan los hashtags del brief, no se envía y se le dice
  cuáles** (`DEC-132`). Sin distinguir mayúsculas, y con todos los motivos a la
  vez.
- **Pantalla interna** de lo entregado por campaña, con acción de recuperación
  para un aceptado que se quedó sin entregables.
- **`tools/verificar-mensajes.py`**, gate nuevo (ver abajo).

### Corregido
- **Cuatro mensajes de la base que en producción no caben**, rotos desde 7.4
  (`T-43`). `MESSAGE_TEXT` es `VARCHAR(128)` y MySQL 8 y Percona 5.7 **no
  truncan**: sueltan `1648` en lugar del `45000` del disparador. MariaDB sí lo
  deja pasar, o sea que el motor de desarrollo perdona y el de **producción** no.
  Ninguna suite lo vio porque todas comprobaban «esto tiene que fallar» y **1648
  también es fallar**.
- **Una suite que se creía limpia y corría sobre filas de otra** (`T-44`). El
  `DELETE` de apertura de `8.1-entregables.sh` fallaba con `1451` y el
  `2>/dev/null` se comía el error; varias aserciones pasaban **por el motivo
  equivocado**. La premisa correcta no era «la tabla está vacía» sino «no hay
  filas mías».

### Cambiado
- Las suites de **7.3, 7.4, 7.6 y 8.1** ya no comprueban «esto falla» sino «esto
  falla **por esto**» (`porque` en vez de `probar`). Es lo que destapó `T-43` y
  es lo único que lo habría destapado.

### Verificación
**562 pruebas / 1.872 aserciones** · **1.260** aserciones de restricción en
MariaDB y **1.250** en MySQL 8 · 17 mutaciones, **5 sobrevivieron** y las cinco
están cerradas · las seis puertas en verde.

Las cinco supervivientes valen más que las doce que murieron. Dos eran agujeros
de verdad —se podía entregar sobre un entregable ya **aprobado**, y la fecha de
la primera entrega se reescribía en cada corrección—, una era dos pruebas usando
**el mismo número** (7 y 7, así que sustituir el plazo por un 7 a pelo no rompía
nada), y otra era `lockForUpdate()`, que no tiene resultado observable en una
conexión: esa prueba mira **el SQL**, y dice en su comentario que eso es lo que
hace y por qué.

---

## [7.7 · El panel de seguimiento] — 2026-08-26## [7.7 · El panel de seguimiento] — 2026-08-26

*«La pantalla más usada del sistema»*, según el roadmap. Lo que decide una pantalla
así no es qué se puede enseñar: es **qué pregunta contesta**.

### Añadido
- **Las alertas, arriba del todo** (`DEC-127`): campaña sin confirmar con el
  arranque cerca, preguntas sin atender, invitaciones que caducan, cupo sin
  cubrir. El orden **no es por gravedad**: la de «sin confirmar» va primera porque
  bloquea a las demás. Cada una dice qué pasa **y qué hacer**.
- **El embudo por estado**, con todos los pasos aunque estén a cero — uno que
  esconde los ceros enseña dónde llegó la gente, no dónde se atasca.
- **El cupo por mercado**, donde **cubierto es aceptado, no invitado**. Una
  invitación sin contestar es una plaza esperando; contarla como cubierta es cómo
  se llega al día de arranque con la mitad del equipo y los números en verde.
- **El dinero**, con el margen detrás de `campaign.view_margin` y **sin
  calcularse** cuando no toca (`DEC-128`). El disponible se enseña negativo cuando
  lo es.

### Cambiado
- El listado de campañas enlaza al **seguimiento** cuando la campaña está
  confirmada, y a la ficha cuando todavía se está montando.

### Verificación
530 pruebas / 1.806 aserciones · 14 mutaciones, **14 detectadas a la primera** ·
las seis puertas en verde. El esquema no se toca.

Lo que sí falló fue **el fixture**: `ConFixturas::mercadoDe()` declara cupo 5 por
omisión, así que la campaña nacía con una alerta y todas las afirmaciones de «sale
UNA alerta» habrían salido verdes con dos. Lo caza una aserción de premisa en el
`setUp`.

---

## [7.6b · Los tres cabos sueltos] — 2026-08-26

Ninguna de las tres cosas era una funcionalidad nueva: eran huecos que dejaron
`5.9` y `7.6` y que se notan en cuanto alguien usa el sistema de verdad. Que es
exactamente lo que pasó a mitad de iteración.

### Añadido
- **`usuarios:crear` ya no teclea la contraseña** (`T-36`, `DEC-126`). Cierra
  `BR-SEC-004` del todo. `must_change_password` era el parche: obligaba a
  cambiarla *después*, dejando una ventana en la que **dos personas conocían la
  credencial** — justo en las cuentas donde la base exige dos personas distintas
  para el dinero. Ahora la contraseña no existe hasta que la escribe su dueño.
- **`usuarios:contrasena --enlace`**, que además **no toca la contraseña actual**:
  si el correo no llega, la persona no se queda peor de como estaba. Teclearla a
  mano sigue existiendo como cristal de emergencia, y el comando lo avisa.
- **El creador puede preguntar** antes de decidir (`T-38`, `DEC-124`). Sin esto una
  duda se convierte en un rechazo, y ese rechazo entra en `decline_reason` como si
  fuera una opinión sobre la oferta. Preguntar **no mueve el plazo**, y la pantalla
  lo dice con todas las letras.
- **Aviso por correo a quien invitó** cuando el creador acepta, rechaza o pregunta
  (`DEC-125`).

### Corregido — los tres los encontró el usuario probando
- **Un 500 al repetir un formato en el brief de un mercado** (`T-40`).
  `campaign_requirements` tiene **dos** índices únicos y el controlador sólo
  traducía el primero. No fallaba en pruebas: la suite SQL comprobaba que la base
  lo rechaza, pero nadie comprobaba que la **pantalla** lo explique.
- **La bitácora entera caída por una fila con una lista dentro** (`T-41`). Una
  marca guarda sus categorías como lista y la vista la pintaba a pelo. Bastaba
  **una** fila así para no poder ver **ninguna** — y la bitácora es precisamente lo
  que se mira cuando algo ha ido mal.
- **`verificar-fixturas.py` no distinguía un fixture inválido a propósito**
  (`T-42`). Una prueba que afirma «la base rechaza esto» necesita escribir una fila
  mala.

### Verificación
504 pruebas / 1.723 aserciones · 1.196 (MariaDB) y 1.186 (MySQL 8) aserciones de
restricción · 15 mutaciones, 15 detectadas tras tres correcciones · las seis
puertas en verde.

**Una mutación sigue viva y se dice por qué**: cambiar la contraseña aleatoria de
una cuenta nueva por una constante no lo caza ninguna prueba, y no puede — una
prueba de caja negra no distingue 32 bytes del generador criptográfico de una
constante que no conoce.

---

## [7.6 · La invitación a una campaña] — 2026-08-26

`invitations` existía desde la Fase 2 y **no tenía una sola fila**. Tercera vez que
pasa —`campaign_creators` antes de 7.4, `domain_events` antes de 4.13— y las tres
veces la estructura estaba bien pensada.

### Añadido
- **El creador contesta él, por enlace de un solo uso** (`DEC-119`). Su portal
  sigue bloqueado por `T-09`; la alternativa era que un operador tecleara «dijo que
  sí por WhatsApp», y eso convierte una aceptación en la palabra de un tercero.
- **Plazo fijo por campaña** (`DEC-120`), de 1 hora a 30 días, con
  `invitaciones:caducar` cada diez minutos desde el planificador.
- **Rechazo con motivo de lista cerrada**, y **reinvitar dejando constancia de las
  dos rondas** (`DEC-121`).
- **`App\Shared\Eventos\CorreoPedido`** (`DEC-123`): pedir un correo sin conocer a
  Communication. Sube desde Identity, donde en `5.9` funcionaba de milagro —Identity
  está en la lista de dependencias permitidas de Communication; Campaign no—.
- **`App\Shared\Http\EnlaceEnSesion`**: el token no se queda en la URL, ahora en un
  solo sitio para las dos pantallas públicas.

### Corregido
- **`BR-CREATOR-008` tenía una ventana abierta** (`DEC-122`). El precio se congela
  **al aceptar**; entre el envío y la respuesta `agreed_amount` se podía mover. Al
  creador le llegaba «te pagamos 1.500», alguien lo bajaba a 900, y aceptaba 900
  **sin haberlo visto nunca**. La invitación copia el importe y
  `tg_ccr_monto_con_invitacion` lo bloquea mientras haya una oferta encima.
- **Un fallo intermitente en los fixtures, escondido desde 4.9** (`T-39`).
  `eligible_from` y `verified_at` de un medio de pago salían de dos `now()`
  distintos; cuando caían a los dos lados de un segundo, `ck_cpm_eligible_after`
  rechazaba el `INSERT`. Salió una vez en 471 pruebas, en una clase que no tiene
  nada que ver con medios de pago.
- **«Caducada» y «te mandamos otra» decían lo mismo.** En la tabla las dos muertes
  son un `revoked_at`, y a quien se pasaba del plazo se le mandaba a buscar en su
  buzón un correo más reciente que no existe.

### Verificación
471 pruebas / 1.607 aserciones · 1.182 (MariaDB) y 1.172 (MySQL 8) aserciones de
restricción · 16 mutaciones, 16 detectadas tras una corrección · las seis puertas
en verde.

**Una aserción de la suite SQL salía verde por el motivo equivocado**: ponía 900
sobre una fila que ya tenía 900 —sin cambio no se dispara ningún disparador— y
además las participaciones de la semilla están aceptadas, donde manda la regla de
7.5 y no la de esta iteración.

---

## [5.9 + 4.1 · El enlace seguro de contraseña] — 2026-08-26

Dos iteraciones del plan que comparten lo único difícil: un token de un solo uso,
con caducidad, imposible de adivinar y que **no queda guardado en ningún sitio del
que se pueda recuperar**. Construirlas por separado habría sido escribir esa pieza
dos veces.

Antes de esto, `usuarios:crear` tecleaba la contraseña y alguien la dictaba por
teléfono, y olvidarla era una llamada más un comando.

### Añadido
- **`password_links`** — la tabla número 68 y la **decimotercera columna puerta**
  del esquema. Guarda `token_sha256`, nunca el token (`DEC-114`): quien lea esta
  tabla no puede entrar en ninguna cuenta.
- **Alta 72 h, recuperación 1 h** (`DEC-113`). El de alta llega sin avisar y puede
  caer un viernes por la noche; el de recuperación lo pide alguien que está
  delante de la pantalla ahora.
- **`/recuperar`**, con la **misma respuesta exista o no el correo** (`DEC-115`) —
  afirmado byte a byte en la prueba— y límite doble: por IP en la ruta y **por
  correo** en el controlador, que es el que impide inundar un buzón concreto desde
  IPs distintas.
- **La URL no lleva el token** (`DEC-117`): la ruta del correo lo guarda en la
  sesión y redirige a una URL limpia. Una URL con un token dentro viaja en la
  cabecera `Referer` a cualquier recurso externo, y estas pantallas cargan
  tipografías de un dominio de terceros.
- **Aprobar un creador le crea la cuenta** y escribe `creators.user_id` — una
  columna que existía desde la Fase 3 y no escribía nadie. La cuenta nace con el
  hash de 32 bytes aleatorios que no se guardan, no se muestran y no se devuelven:
  no se puede entrar en ella hasta usar el enlace.
- **Usar el enlace cierra las sesiones abiertas** y rota `remember_token`. Poner
  una contraseña nueva y dejar viva la sesión de quien entró con la vieja es no
  haber hecho nada.

### Corregido
- **`/panel` enseñaba los totales internos a cualquier autenticado** (`DEC-118`).
  Nadie lo había pensado porque hasta hoy **todos los usuarios eran internos**;
  `5.9` crea la primera cuenta que no lo es. Un rol sin permisos no protege una
  pantalla que no pide ninguno.
- **`VARBINARY` faltaba en el reconocedor de tipos de `generar-triggers.py`.** El
  `CHECK` que nombraba `used_ip` se generaba como un disparador con el
  identificador suelto. Se vio porque MySQL grita; el hueco existía desde la Fase 2
  en `audit_logs.ip_address` y `creator_identity_verifications.ip_address` (`T-37`).
- **`2026_08_22_000495` no tenía `down()`.** La única de las cuarenta migraciones
  sin él: `php artisan migrate:rollback` moría ahí con un error fatal y se llevaba
  por delante la vuelta atrás de todo lo posterior.

### Cambiado
- **`SESSION_DRIVER=database` pasa a ser requisito de seguridad**, no preferencia
  (`.env.example` decía `redis`). Con otro almacén las sesiones no se pueden
  cerrar; el servicio lo **avisa en el log** en vez de fallar en silencio.
- `BR-SEC-004` deja de ser una regla sin código detrás — para las cuentas de
  creador. Sigue sin cumplirse para los usuarios internos (`T-36`).

### Verificación
427 pruebas / 1.429 aserciones · 1.118 (MariaDB) y 1.108 (MySQL 8) aserciones de
restricción · 20 mutaciones, 20 detectadas tras dos correcciones · las seis
puertas en verde.

**Las dos mutaciones que sobrevivieron enseñaron algo.** Una era código
equivalente, y se arregló *haciéndolo dejar de serlo*: «no hay token en la sesión»
y «el token no vale» piden cosas distintas de la persona. La otra destapó que mi
propia prueba era un **falso verde** —comparaba el identificador de sesión antes y
después, y el cliente de PHPUnit ya lo cambia solo—.

---

## [4.13 · El aviso al creador cuando cambian sus datos sensibles] — 2026-08-26

Cierra **`T-10`** y la mitad que faltaba de **`BR-CREATOR-007`** (🔴). La
aprobación interna existía desde 3.6 y 3.8; la **notificación** no — *«la
pantalla se lo recuerda al operador para que lo haga a mano»*, que es otra forma
de decir que no se hacía.

### El problema, que es de arquitectura
Creator sabe que el dato cambió, Communication sabe enviar, y **`deptrac.yaml` no
deja que se conozcan**. No es burocracia: si Creator importara Communication, un
SMTP caído podría tumbar la captura de un dato fiscal.

La respuesta ya estaba diseñada y sin usar. **`domain_events` existe desde 2.4 y
no tenía una sola fila** — mismo patrón que `campaign_creators` antes de 7.4.

### Añadido
- **`App\Shared\Eventos`** (`DEC-112`): el hecho se **guarda antes** de
  despacharlo, para que conste aunque el oyente reviente. Creator levanta un
  nombre y un array; Communication recibe un nombre y un array. Ninguno importa
  al otro.
- **Aviso al capturar, no al aprobar** (`DEC-109`) — es lo único que le da al
  creador margen para decir «yo no fui» mientras el cambio se puede parar.
- **El correo no lleva el dato dentro** (`DEC-110`). Sin número, sin banco, sin
  RUC: se lee en buzones que no controlamos, y ése es justo el escenario del que
  nos defendemos.
- **Si el aviso no sale, el cambio sigue** (`DEC-111`), pero el fallo se ve.
- **Las plantillas van en un seeder**, idempotente y con fecha de vigencia fija.
  Sin plantilla no sale el aviso, y dejarlo a que alguien la publique en cada
  entorno es el modo de fallo que `DEC-085` ya demostró.

### Verificación
390 pruebas / 1.302 aserciones · 1.070 y 1.060 aserciones de restricción · siete
mutaciones, las siete en rojo · las seis puertas en verde, **Deptrac incluido** —
que el grafo siga acíclico es parte del resultado, no un trámite.

---

## [Runbook de despliegue] — 2026-08-26

`docs/18-RUNBOOK-DESPLIEGUE.md`. Los pasos de despliegue estaban viviendo en
conversaciones, y `DEC-085` —los dos `GRANT` que protegen la bitácora— lleva
desde el 25 de agosto marcado como «falta ejecutarlo» sin más garantía que la
memoria de alguien.

Recoge: los **dos** crones que hacen falta (la cola y el scheduler **no son lo
mismo**, y `schedule:run` no procesa la cola), las dos banderas de
`queue:work` sin las cuales el cron de cada minuto apila procesos hasta tumbar
el servidor, el orden de despliegue con `queue:restart` incluido, la trampa de
`config:cache` con `env()`, y las comprobaciones posteriores.

Y dice **lo que todavía no cubre** —copias restauradas, monitorización, vuelta
atrás— para que no parezca completo cuando no lo está. Anotado como `T-35`: un
runbook que nadie ha seguido es una hipótesis, no un procedimiento.

---

## [4.9 · El correo] — 2026-08-26

**Se adelanta a propósito, rompiendo el orden del roadmap.** 7.6 no se podía
construir: «invitaciones: envío, expiración, aceptación» empieza por enviar. Y no
bloqueaba sólo eso — también el enlace de contraseña al aprobar un creador
(`5.9`), la recuperación de contraseña (`4.1`) y el aviso de cambio de datos
fiscales (`T-10`, con `BR-CREATOR-007` en 🔴).

### Añadido
- **`email_templates`** — versionadas y con vigencia, igual que `terms_versions`.
  Una versión publicada **no se edita**: se publica la siguiente y la anterior se
  cierra el día antes, con `Periodo::sinSolape` imponiéndolo en la base.
- **`email_log`** — guarda plantilla, versión, idioma, asunto y **la huella** del
  cuerpo. El cuerpo no (`DEC-106`): lleva los datos de la persona, y la versión
  inmutable más la huella ya demuestran qué texto salió.
- **Caída de idioma anotada** (`DEC-107`). De la diferencia entre el idioma
  pedido y el enviado sale la lista de traducciones que faltan, y sale en la
  pantalla como lo único que pide una acción.
- **Tres reintentos y `failed` visible** (`DEC-108`), con `ck_el_failed`
  exigiendo en la base que un fallido diga **cuándo y por qué**.
- **`correos:publicar`** y **`correos:probar`**, permiso `comms.view`, y dos
  pantallas de sólo lectura. La de envíos abre filtrada por fallidos.
- **`config/mail.php`** con `MAIL_MAILER=log` por defecto: sin credenciales, un
  `smtp` por defecto llenaría el registro de fallos que no son culpa de nadie.

### Corregido antes de que llegara a producción
- **`Plantillas::vigente()` resolvía por la columna puerta**, que identifica «la
  última publicada» y no «la vigente». Publicar una versión para dentro de un mes
  cerraba la anterior y dejaba **un mes sin ninguna vigente** — por haber
  programado el cambio con antelación. Lo destapó una prueba; ahora se resuelve
  por periodo (`T-21` otra vez).
- **La opción `--version` del comando chocaba con la de Symfony Console.** No era
  un aviso de estilo: el comando no se podía registrar. Lo cazó PHPStan antes de
  que nadie lo ejecutara. Ahora es `--etiqueta`.

### Verificación
380 pruebas / 1.265 aserciones · 1.070 (MariaDB) y 1.060 (MySQL 8) aserciones de
restricción · ocho mutaciones, las ocho en rojo · las seis puertas en verde.

**Una mutación sobrevivió** a la primera versión de las pruebas: cambiar el
`throw` del job por un `return` dejaba el correo en `queued` para siempre — peor
que `failed`, porque uno se ve en la pantalla y el otro parece que sigue en
camino.

---

## [7.5 · El compromiso económico con los creadores] — 2026-08-26

`BR-CAMPAIGN-005` es 🔴 y nombra *«el presupuesto de creadores de la campaña»*.
`campaigns` tenía `revenue_amount` —lo que se le cobra al cliente— y **nada
más**. **Quinto caso del patrón, y el peor:** en `T-23`, 7.1, 7.2, 7.3 y 7.4
faltaba el código de una regla que se podía comprobar; aquí faltaba **el dato que
la regla nombra**.

### Añadido
- **`campaigns.creator_budget_amount`** y las tres columnas de la autorización
  (`quién`, `cuándo`, `por qué`), con `ck_camp_budget_override`: las tres o
  ninguna. Una firma sin explicación no responde «¿por qué esta campaña se pasó?»
  dentro de un año.
- **El veto de sobrecosto trae los tres números** — lo que quedaría comprometido,
  el techo, y por cuánto se pasa. «Excede el presupuesto» obliga a ir a buscarlas.
- **`tg_ccr_compromiso`** (`DEC-104`): no se invita a nadie sin decirle cuánto se
  le paga, y al aceptar el monto queda congelado (`BR-CREATOR-008`). Salvo en una
  campaña declarada gratuita, que es coherencia con 7.2 y no una excepción.
- **`Compromiso::margen()`** — interno, nunca se enseña a un cliente ni a un
  creador, y con el porcentaje guardado contra el ingreso cero de una campaña
  gratuita.

### Decidido
- **`DEC-103`:** «2 reels» en el brief es lo que entrega **cada** creador. Confirma
  lo que el coste estimado de 7.4 ya asumía.

### Corregido
- **El formulario de campaña estaba copiado en CUATRO clases de prueba** y la
  columna nueva las rompió a las cuatro a la vez, con un `Attempt to read property
  "id" on null` que no nombra el campo que falta. `H-16` por cuarta vez; ahora vive
  en `ConFixturas::datosDeCampana()`.
- **Un `1064` que sólo aparecía en la base sin `CHECK`.** La foránea nueva estaba
  escrita entre dos `CHECK` y al quitarlos —para simular Percona 5.7— se quedaba
  huérfana de coma: cargaba bien en desarrollo y reventaba en la base que imita a
  producción.

### Verificación
358 pruebas / 1.201 aserciones · 1.028 (MariaDB) y 1.018 (MySQL 8) aserciones de
restricción · siete mutaciones, las siete en rojo · las seis puertas en verde.

---

## [7.4 · El buscador de creadores y la lista corta] — 2026-08-26

Primera pantalla que **lee el modelo del creador entero**: cuatro iteraciones de
la Fase 3 lo construyeron —país, categorías, formatos, tarifas, redes
verificadas, restricciones, agenda— sin que nada lo leyera de una vez.

La decisión que le da forma: **los filtros no se teclean, salen de la campaña**.
Los mercados, los formatos del brief, la edad mínima y las categorías de la marca
ya están aplicados cuando la pantalla abre. Un buscador con quince casillas
vacías obliga al operador a recordar cuatro reglas a la vez, que es no recordar
ninguna.

### Añadido
- **Buscador de candidatos** por campaña, con filtros duros (derivados) y blandos
  (tecleados), y un interruptor de **«ver también los descartados, con el
  motivo»**: contesta «¿por qué no me sale Fulano?» sin abrir la base.
- **Lista corta** en `campaign_creators` con `status = 'shortlisted'` — el valor
  por omisión de la columna desde la Fase 2, que nadie había escrito nunca.
- **`ListaCorta::vetoParaAnadir()`** — revalida `BR-CREATOR-006` con la misma
  clase que decide la activación, más los filtros de la campaña, y dice **todos**
  los motivos de una vez.
- **Media `BR-CAMPAIGN-007`** (`DEC-100`): la categoría que el creador declaró que
  no trabaja ya excluye. Era 🔴 y no la comprobaba nada.
- **`tg_ccr_campana_cerrada_ins` / `_upd`** (`DEC-102`): nadie entra en una campaña
  cerrada, y una participación que ya estaba sólo se puede cancelar.
- **Coste estimado** por candidato. Nulo con su motivo cuando no se puede
  calcular — nulo **no es cero**, y no se convierte de moneda porque nadie
  mantiene los tipos de cambio todavía.

### Corregido
- **`ConFixturas` usaba documento y correo fijos** para todos los creadores. 7.4
  es la primera iteración que necesita varios a la vez, y el segundo daba un
  `1062`. Ahora varían por llamada, y las tres pruebas que dependían de los
  literales ahora **afirman contra la fila**: una prueba que se rompe al cambiar
  un fixture no estaba afirmando lo que decía.
- **`verificar-pantallas.py` acusaba en falso** — no entendía un `foreach` de PHP
  dentro de un bloque `@php`. Una puerta que acusa en falso se acaba ignorando.

### Anotado
- **`T-34`** — `BR-CREATOR-012` dice «con tutela activa», y `min_creator_age` es
  una columna de `campaigns` que no menciona la tutela. Se aplica a todos; hay que
  corregir el texto de la regla, no el código.

### Verificación
339 pruebas / 1.145 aserciones · 980 (MariaDB) y 970 (MySQL 8) aserciones de
restricción · diez mutaciones, las diez en rojo · las seis puertas en verde.

**Dos mutaciones sobrevivieron a la primera versión de las pruebas**, las dos
sobre el solape de agenda: la prueba tenía el borde izquierdo y no el derecho. El
error de un día —once apariciones en este proyecto— entrando por el lado que
nadie miraba.

---

## [7.3 · Los mercados de la campaña] — 2026-08-25

`N-03` —*«el brief de mercado **reemplaza** al general, no se mezcla»*— estaba
escrita en `docs/fase-2/2.3-NORMALIZACION.md` desde la Fase 2 y **nada la
implementaba**. Con un agravante sobre 7.1 y 7.2: `N-03` es la **única excepción
consciente** de todo el modelo, el único sitio donde un `NULL` significa
*«todos»* en vez de *«no aplica»*. Una excepción que nadie implementa es una
excepción que alguien va a interpretar mal.

### Añadido
- **Los mercados por pantalla** — en qué países corre la campaña y con cuántos
  creadores. Al menos uno para salir de borrador (`DEC-095`), y **añadir sí,
  quitar no** con la campaña confirmada (`DEC-096`): ampliar es comercial, quitar
  puede dejar fuera a creadores ya invitados.
- **`Mercados::briefEfectivo()`** — `N-03`, por fin en código. Y la pantalla del
  mercado dice con palabras si lo que se ve es propio o heredado, porque la
  lectura equivocada («se suman») es la que la regla existe para descartar.
- **Foráneas compuestas** `(campaign_market_id, campaign_id)` (`DEC-098`). Una
  foránea a `campaign_markets(id)` a secas sólo comprueba que el mercado exista:
  nada impedía un requisito de la campaña A colgado del mercado de la campaña B.
- **`ck_cm_target`** — `NULL` es «sin cupo fijado» y vale; cero no dice nada.
- **`ck_creq_deadline` y `ck_creq_permanence`** (`T-33`, cerrada). `permanence_days`
  es lo que se le exige al creador: un 100.000 son 273 años, y entraba.
- **Suite `7.3-mercados`** (26 aserciones) y `MercadosTest` (18 pruebas).

### Corregido
- **Una aserción de la suite salía verde por un `1093` de MySQL 8.** Un `DELETE`
  con subconsulta sobre la misma tabla, que MariaDB permite y MySQL rechaza: la
  aserción esperaba `RECHAZO` y lo obtenía **por el motivo equivocado**, midiendo
  el error del motor en vez de la foránea.
- **`recolectar-esquema.php` no tenía `dropForeign`, `dropUnique` ni `dropIndex`.**
  Caían en `__call` sin hacer nada, así que una migración que sustituye una
  foránea dejaba las **dos** grabadas y el esquema reconstruido decía algo que la
  base no dice. No había dado la cara porque ninguna migración anterior había
  sustituido una foránea. Su `foreign()` tampoco aceptaba arrays.

### Verificación
318 pruebas / 1.045 aserciones · 940 (MariaDB) y 930 (MySQL 8) aserciones de
restricción · siete mutaciones, las siete en rojo · las seis puertas en verde.

---

## [7.2 · El brief y el ingreso declarado] — 2026-08-25

`BR-CAMPAIGN-004` estaba escrita desde el principio —*«una campaña no puede pasar
a `approved` sin presupuesto, cliente, marca y brief definidos»*, 🟠— y **no la
impedía nada**. En 7.1 se dejaba aprobar una campaña que sólo tenía sociedad
emisora: sin decir qué había que entregar y sin que nadie hubiera puesto un
precio.

Tercer caso del mismo patrón, después de `must_change_password` (`T-23`) y
`BR-LE-001` (7.1): una regla del documento con identificador y color que ningún
`CHECK` y ninguna pantalla comprobaban. **Un documento de reglas no es una
garantía: es una lista de deudas.**

### Añadido
- **El brief** — `campaign_requirements` por pantalla: qué formato, cuántas
  piezas, para cuándo y cuánto debe seguir publicado. «Brief definido» = **al
  menos un requisito** (`DEC-092`). El texto libre del `briefing` sigue siendo
  opcional a propósito: un espacio en blanco cumpliría cualquier `NOT NULL`.
- **`is_gratis` y `ck_camp_revenue_declarado`** — cero es un ingreso válido, pero
  hay que declararlo (`DEC-093`). «Esta campaña se regala» y «nadie le ha puesto
  precio» eran el mismo número, y ante un margen descuadrado la diferencia entre
  las dos es la diferencia entre «salió como se planeó» y «se nos escapó».
- **Suite `7.2-brief`** (21 aserciones) y `BriefTest` (15 pruebas).

### Cambiado
- La ficha de campaña dice **todos** los motivos que impiden salir de borrador,
  y los dice **antes** de que nadie pulse el botón. Enterarse de uno por visita
  es una visita por motivo.
- `ConFixturas::campanaDe()` declara ingreso; `requisitoDe()` es nuevo.
- `CambiarContrasenaCommand` deja de usar `App\Models\User` — `app/Models` no
  pertenece a ninguna capa de Deptrac y la puerta de fronteras se puso en rojo.

### Corregido
- **La suite de 7.1 empezó a rechazar por el motivo de 7.2** creyendo que probaba
  el suyo: su `alta()` no ponía importe. Una aserción de rechazo que se cumple
  por el motivo equivocado sigue saliendo verde, y es el fallo que ya costó tres
  aserciones en 4.5.
- `Campanas::requisitos()` unía por `content_formats.name`, columna que no
  existe: el nombre legible del formato **es** su `code`.
- El veto por falta de sociedad había perdido el *«dese de alta la cobertura en
  Entidades legales»* que `BR-LE-004` exige. Lo cazó una prueba de 7.1.

### Verificación
300 pruebas / 927 aserciones · 888 (MariaDB) y 878 (MySQL 8) aserciones de
restricción · ocho mutaciones, las ocho en rojo · las seis puertas en verde.

Una de esas mutaciones destapó que `test_la_ficha_ensena_lo_que_falta` **pasaba
con el veto desactivado**: la frase que afirmaba también está en un texto fijo de
la pantalla. La ejecución no lo detecta; la mutación sí.

---

## [Seguridad · cambio obligatorio de contraseña (`T-23`)] — 2026-08-25

Con esto quedan cerrados **los siete** hallazgos que dejó la revisión adversarial.

`usuarios:crear` escribía `must_change_password = 1` desde la primera iteración
de identidad y **nadie lo leía nunca**. Única aparición de la columna en todo el
árbol, aparte de la migración: ni middleware que la comprobara, ni pantalla donde
cambiar la contraseña.

O sea que el administrador que da de alta a la persona de finanzas teclea su
contraseña, se la dice, y esa contraseña sigue valiendo indefinidamente.

### Por qué no es sólo higiene
La base **exige dos personas distintas** para lo que toca dinero:
`ck_ctp_segregation` al aprobar un perfil fiscal, `ck_cpm_segregation` al
verificar un medio de pago (`BR-FIN-005`). Esa garantía se apoya entera en que
dos `user_id` distintos sean dos personas distintas. Si un tercero conoce la
credencial de la segunda, la separación es una columna en una tabla y nada más.

### Tres reglas, y cada una tapa un agujero distinto
1. **La nueva tiene que ser distinta de la actual.** Es la que justifica la
   iteración: sin ella, teclear la temporal dos veces limpia la marca y deja
   válida la contraseña que conoce el administrador. Cumplido en la base de
   datos, sin cumplir en la realidad. Hay prueba dedicada.
2. **Se pide la contraseña actual**, aunque el cambio sea obligatorio: «entró con
   ella» y «sigue delante» no son lo mismo, y una sesión abierta y desatendida no
   debe bastar para dejar fuera al dueño.
3. **Sin permiso.** Si dependiera de uno, un usuario al que se le han revocado no
   podría cambiar su contraseña — y es al que más urge. Entra en
   `RutasProtegidasTest::SIN_PERMISO` con el motivo escrito.

### El middleware va en el grupo, no ruta a ruta
Una obligación que hay que acordarse de poner en cada pantalla nueva se salta la
primera que alguien olvide. Tres excepciones —la pantalla, su acción y `salir`—
porque sin ellas es un bucle de redirecciones, que es la forma más rápida de
dejar a alguien fuera de su propia cuenta. Se comparan **nombres de ruta**, no
URLs: una URL escrita a mano se desincroniza en cuanto alguien renombra la ruta,
y el síntoma sería el bucle.

### Y una comprobación que decidí no dar por buena en silencio
`Password::uncompromised()` contrasta la contraseña contra filtraciones públicas.
Es una llamada HTTP saliente y **falla en abierto**: sin salida a internet,
Laravel da la contraseña por buena. Un servidor endurecido —donde más importa—
sería justo donde la comprobación no comprueba, sin decirlo.

Así que es configurable y documentado: la defensa son los 12 caracteres y la
mezcla, esto es un extra que quien despliega enciende sabiendo lo que hace. En
pruebas va apagado — una prueba no debe depender de la red.

### Verificación
**794 correctas, 0 fallidas** en MariaDB, 784 en MySQL 8. Nombres entre capas,
fixturas, periodos, migraciones, triggers, equivalencia y DDL crudo sin
discrepancias. Pint `passed`, 174 archivos PHP compilan. `CambioPasswordTest`
(8 pruebas) pendiente de PHPUnit.

## [Seguridad · la bitácora deja de ser truncable (`T-18`)] — 2026-08-25

El pendiente más grave de la revisión, cerrado. `audit_logs` rechaza `UPDATE` y
`DELETE` con disparadores, pero **`TRUNCATE` no dispara triggers** y deja la
tabla a cero. No es un descuido del esquema: no hay forma de escribir un
disparador que lo pare, porque `TRUNCATE` es una operación de esquema, no de
datos.

Lo único que lo detiene es no tener el privilegio `DROP`. Comprobado con usuarios
reales en los dos motores:

```
usuario sin DROP:
  UPDATE   -> 1644  lo para el disparador
  DELETE   -> 1644  lo para el disparador
  TRUNCATE -> 1142  DROP command denied
```

### `DEC-085` — dos usuarios de base de datos
`latam_app` con `SELECT, INSERT, UPDATE, DELETE, EXECUTE`; `latam_mig` con `ALL
PRIVILEGES` y sólo para migrar. El `.env.example` trae las concesiones exactas y
la razón real —antes decía que lo que protegía era la falta de `UPDATE`/`DELETE`,
que es falso: eso lo hacen los disparadores con cualquier usuario—.

**No hay segunda conexión en `config/database.php`**, y es deliberado: es lo que
el `.env.example` prometía con `DB_MIGRATION_USERNAME`, y habría sido peor. Las
migraciones generan DDL con `DB::statement()`, que va **siempre** por la conexión
por defecto aunque la migración declare otra: la mitad del DDL habría ido por un
usuario y la otra mitad por el otro.

### Se comprueba, no se promete
`php artisan seguridad:privilegios` lee los privilegios reales del usuario
conectado y, si puede vaciar la bitácora, imprime los `GRANT` que hacen falta.
Avisa en desarrollo —donde el usuario suele ser `root` y eso no es un fallo— y
falla con `--exigir` o en producción.

No intenta el `TRUNCATE` para ver si falla: `TRUNCATE` hace *commit implícito*,
así que la comprobación habría **vaciado la bitácora** para demostrar que se
puede vaciar.

### La suite fija las dos mitades, incluida la incómoda
`3.12` crea dos usuarios de verdad y comprueba que el de aplicación puede anotar,
no puede reescribir, no puede borrar y **no puede vaciar** — y que el de
migraciones **sí la vacía, sin que salte nada**. Esa última aserción es la que
justifica que existan dos usuarios; sin ella, la sección sólo diría que algo
funciona.

En MySQL 8 la sección se omite por fontanería del contenedor (el envoltorio fija
`-uroot`), y se dice en la salida. La propiedad se comprobó a mano allí: mismo
`1142`. Importa porque producción es Percona 5.7, familia MySQL.

### Verificación
**794 correctas, 0 fallidas** en MariaDB (784 en MySQL 8: las 10 de diferencia
son esta sección, omitida y anunciada). Nombres entre capas, fixturas, periodos,
migraciones, triggers, equivalencia y DDL crudo sin discrepancias. Pint
`passed`, 169 archivos PHP compilan.

### Queda por hacer en el servidor
Ejecutar los `GRANT`. El código ya no puede hacer más: esto se concede en el
servidor, no se declara en una migración.

## [Herramientas · el CI se copia solo] — 2026-08-25

`.github/workflows/` es ruta protegida —quien puede editar el fichero de CI
puede hacer que el CI ejecute cualquier cosa—, así que el fichero vive en
`tools/github-workflow-ci.yml` y la copia se hace desde la máquina del
desarrollador. Mientras eso fue «acuérdate de hacerlo a mano», **no se hizo**:
el CI estuvo dos días corriendo una versión que no conocía las suites 4.3, 4.4
ni 4.5 ni el gate de nombres.

Un CI desactualizado **no falla**: sale verde comprobando menos cosas, que es la
peor forma de fallar.

### `tools/sincronizar-ci.php`
```
php tools/sincronizar-ci.php              # copia si hace falta
php tools/sincronizar-ci.php --comprobar  # solo avisa, no escribe
```
Es idempotente, crea `.github/workflows/` si no existe, enseña qué líneas entran
antes de copiar —para que no sea a ciegas— y relee el fichero de disco para
confirmar: dar por bueno lo que uno *pretendía* escribir no confirma nada.

En PHP y no en PowerShell por dos razones: ya hay PHP instalado (es lo que corre
`diagnostico.php`) y este proyecto ya se quemó una vez con las rarezas de
redirección de PowerShell.

### Y una quinta puerta en `diagnostico.php`
La puerta `ci` corre `--comprobar` en cada pasada. Si el fichero se queda atrás,
el diagnóstico lo dice y ofrece el comando exacto para arreglarlo. Es la única
forma de que esto no vuelva a pasar: lo que depende de acordarse, se olvida.

Comprobado con los cinco casos —desactualizado, copia, segunda pasada, carpeta
inexistente y la puerta en fallo dentro del diagnóstico— sobre una copia en
`/tmp`, nunca sobre los ficheros del repositorio.

## [Cierre de la deuda de la revisión: T-19 a T-24] — 2026-08-25

Cinco de los siete hallazgos que quedaron anotados, cerrados. Dos exigían una
decisión de negocio y se tomaron.

### `DEC-083` — «cuenta compartida» se calcula al leer
Cuando dos creadores comparten cuenta bancaria, sólo se marcaba el **segundo**:
`tg_cpm_compartida` es `BEFORE INSERT` y un disparador sólo escribe `NEW`. El
operador que abre la pantalla del primero —el que probablemente cobre primero—
veía «única» mientras la cuenta estaba duplicada.

Lo obvio, un `AFTER INSERT` que marcase también la fila anterior, **no existe**:

> `ERROR 1442: Can't update table 'creator_payment_methods' in stored
> function/trigger because it is already used by statement which invoked this
> stored function/trigger.`

Comprobado contra el motor. Así que el hecho deja de guardarse dos veces:
«compartida» es una propiedad del conjunto de filas con la misma huella, se
pregunta al leer (`CuentasCompartidas`), y las dos filas dicen lo mismo por
construcción. `shared_account_status` pasa a guardar sólo el resultado de la
**revisión** (`cleared`), que sí es un hecho de la fila.

Se descartó marcar las hermanas desde la aplicación: funcionaría y dejaría la
regla fuera de la base, donde cualquier importación se la salta. Cuando el
esquema no puede imponer algo, la respuesta es no duplicar el dato — no moverlo a
un sitio más débil.

Efecto: `T-20` desaparece, y `revisarCompartida()` ya funciona desde la pantalla
de cualquiera de los dos creadores en vez de sólo del segundo.

### `DEC-084` — «vigente» exige que ya haya empezado
Un perfil fiscal aprobado con `valid_from` futuro se declaraba vigente **y era el
que decidía la retención mostrada**: decía «NRUS, no aplica retención» cuando ese
día aplicaba RER al 8 %, y la activación congelaba esa frase en la bitácora como
evidencia. Ahora «vigente» exige `valid_from <= CURDATE()`, y el mensaje de
aprobación distingue «aprobado y vigente» de «aprobado, todavía NO rige».

Se descartó prohibir las fechas futuras: un cambio de régimen ante SUNAT tiene
fecha conocida de antemano, y prohibirlo obliga a acordarse de entrar ese día.

### Dos guardas de duplicado que dejaban pasar un `1062`
La de solicitudes filtraba por `status IN ('pending','active','suspended')`,
pero las únicas que protegen se apoyan en `identity_gate`, que **no mira el
estado**: un creador en lista negra que volvía a postular reventaba con `1062`
dentro de la transacción y el revisor veía un 500. Ahora no filtra por estado y
el mensaje distingue un duplicado administrativo de una persona ya apartada — que
no se resuelve corrigiendo datos.

La de redes sociales sólo miraba cuentas verificadas **de otros** creadores, pero
`uq_social_accounts_creator_handle` no tiene puerta de estado: volver a teclear
una cuenta propia desactivada daba `1062`. Era el caso frecuente, no el exótico.

### Y el enfriamiento de pagos, acotado
Con `0`, la pantalla decía «no es pagable hasta dentro de 0 h» mientras la cuenta
ya era pagable; con un valor negativo, `45000` sin traducir. El comentario del
config ya decía «cero no es una opción»: ahora lo impone. Un comentario que dice
«esto no puede pasar» y no lo impide es una nota, no una regla.

### Verificación
**784 correctas, 0 fallidas** en los dos motores (776 → 784). La suite de pagos
fija ahora la asimetría real del disparador **y** que la consulta que usa la
aplicación sí ve a los dos creadores: queda escrito por qué se calcula al leer.
Nombres entre capas, fixturas, periodos, migraciones, triggers, equivalencia y
DDL crudo sin discrepancias. Pint `passed`, 168 archivos PHP compilan.

### Sigue abierto
`T-18` (la bitácora se puede `TRUNCATE`: hacen falta dos usuarios de base de
datos, es despliegue) y `T-23` (`must_change_password` se escribe y nadie lo
lee: hace falta una pantalla de cambio de contraseña).

## [Revisión adversarial del módulo Creator y de Shared] — 2026-08-25

La revisión de 4.1–4.5 encontró siete defectos reales. Ese ritmo justificaba
mirar el resto: el módulo **Creator** —que toca tarifas, retenciones y cuentas
bancarias— y la **infraestructura compartida**. Dos revisiones independientes,
~20 hallazgos. Se arreglan ocho; los siete restantes quedan anotados con su
escenario (`T-18` a `T-24`) en vez de a medias.

### Lo más grave: la aplicación hacía DDL en producción
`Restriccion::motorAplicaCheck()` sondea el motor con
`DROP TABLE` + `CREATE TABLE` + `DROP TABLE`. `PanelController` la llamaba sólo
para pintar «el motor aplica CHECK: sí/no», así que **cada worker de PHP frío
hacía DDL porque alguien abrió la portada**. Tres consecuencias, y la tercera es
la que importa: el DDL hace *commit implícito* de cualquier transacción abierta,
y obliga a que el usuario de la aplicación tenga `CREATE` y `DROP` — y con `DROP`
se puede `TRUNCATE` la bitácora, que rechaza `UPDATE` y `DELETE` con disparadores
pero **no sobrevive a un `TRUNCATE`** (`T-18`). Ahora la sonda sólo corre en
consola; fuera devuelve el caso conservador sin tocar nada.

### El compilador de restricciones se rompía con `\'`
`partirFueraDeLiterales()` entendía `''` pero no `\'`, así que
`note <> 'it\'s status'` cerraba el literal antes de tiempo: el nombre de
columna se reescribía **dentro** de la cadena y el `status` de verdad se quedaba
sin `NEW.`. El disparador compilaba, se instalaba, y comparaba contra la fila
**vieja**: la comprobación quedaba puesta y no comprobaba nada. Es exactamente el
fallo silencioso que `DEC-042` existe para eliminar, escondido en su propio lexer.

### El RUC entero entraba en claro en una bitácora que no se puede corregir
`REDACTAR` cubría cuentas bancarias y no documentos de identidad ni fiscales, así
que `client_tax_profile.created` escribía `"RUC 20512345678"` en `audit_logs` —una
tabla de sólo inserción— visible para cualquiera con `audit.view`, un permiso
distinto de `client.tax.manage`. Y la redacción **sólo miraba el primer nivel**:
un `document_number` dentro de un array anidado salía intacto. Ahora la lista
incluye documentos y la redacción es recursiva. `ruc` y `dni` se dejaron **fuera**
a propósito: se comparan por contención y `str_contains('estructura', 'ruc')` es
cierto — una entrada demasiado corta esconde campos legítimos, que es el mismo
pecado al revés.

### Documentos de identidad detrás del permiso equivocado
El listado y la ficha de creadores imprimían el `document_number` **entero** con
sólo `creator.view` —que `content_reviewer` tiene— y el listado dejaba **buscar**
por él: `LIKE '%40000001%'` contesta «¿está esta persona en el sistema y quién
es?». Nuevo `Shared\Auth\Confidencial`: sin `creator.view_sensitive` se ven los
últimos cuatro dígitos, la búsqueda por documento se desactiva y el marcador del
buscador deja de prometerla. Se enmascara en el controlador, no en la plantilla,
para que el valor entero no llegue a la vista.

### Desmarcar «acepta presencial» lo guardaba como SÍ
Una casilla sin marcar no viaja, así que `validated()` no traía la clave y la
columna tomaba su `DEFAULT`. `accepts_travel` y `accepts_product_only` tienen
default 0 y coincidían por suerte; `accepts_in_person` tiene **DEFAULT 1**. El
operador declaraba «no acepta presencial» y la tabla de la misma pantalla lo
mostraba como que sí. Las tres se escriben ahora con su valor explícito. Es la
misma trampa de la casilla que apareció en marcas — y no es casualidad.

### Y tres más
- **`"- - - -"` pasaba como número de cuenta**: `min:6` y el patrón miran el
  número crudo, y normalizaba a cadena **vacía** — máscara `****` y todas las
  cuentas vacías con la misma huella, o sea `pending_review` entre creadores sin
  relación. La regla nueva pregunta por lo normalizado.
- **`date` en tres fechas que van directas a columnas `DATE`**: `01/15/2026` pasa
  la validación y MySQL lo rechaza con `1292` dentro de una transacción.
- **`PerfilComercialController` calculaba el cierre a mano** en dos sitios y
  comparaba fechas como cadenas, teniendo `Vigencia` al lado. Hoy salía bien; era
  el mismo par de cálculos que ya falló seis veces.

### Lo que NO se arregló, y por qué
Siete hallazgos quedan anotados con su escenario reproducible: la bitácora
truncable (`T-18`, es despliegue: hacen falta dos usuarios de base de datos), la
asimetría de la cuenta compartida (`T-19`), el comando de recálculo de huellas
(`T-20`), el perfil fiscal con fecha futura llamado «vigente» (`T-21`), dos
guardas de duplicado que dejan pasar un `1062` (`T-22`), `must_change_password`
que nadie lee (`T-23`) y el enfriamiento de pagos sin acotar (`T-24`). Cada uno
necesita una decisión o un cambio de esquema, no un parche a ciegas.

### Verificación
**776 correctas, 0 fallidas** en los dos motores. DDL crudo, nombres entre capas,
fixturas, periodos, migraciones, triggers y equivalencia sin discrepancias. Pint
`passed`, 167 archivos PHP compilan. El lexer y la redacción se comprobaron
ejecutándolos con los casos exactos que los rompían.

## [Revisión adversarial de 4.1–4.5] — 2026-08-25

Cinco iteraciones sin poder pasar por PHPUnit son cinco iteraciones sin verificar.
En vez de añadir una sexta, esta entrega somete 4.1–4.5 a una **revisión
adversarial independiente** y arregla lo que apareció. Cada defecto se comprobó
contra el motor antes de tocar nada.

### El más grave: un nombre de cliente largo tumbaba el alta entera
`client_organizations.commercial_name` admite **160** y `client_brands.name` son
**120**. Como el alta de cliente crea su primera marca con el mismo nombre
(`DEC-074`), una razón social de 121 a 160 caracteres daba
`1406 Data too long`, y al ir dentro de una transacción **se perdía el cliente
entero**: un 500, no un mensaje. La marca se recorta a 120; el cliente conserva
su nombre completo, que es lo correcto — son campos distintos.

### El más sutil: las fechas se comparaban como cadenas
`'2026-2-1' > '2026-11-01'` es **cierto** en PHP. Y la regla `date` de Laravel
acepta `2026-2-1`. Así que la guarda que existe para que el operador no vea un
`45000` decía que sí se puede relevar, cerraba el periodo anterior *antes* de su
propio `valid_from`… y salía el `45000`. Afectaba a **cuatro** sitios:
`Vigencia::puedeRelevar()`, `Cobertura::noCerrablesEn()`, el veto `DEC-071` del
perfil fiscal del creador, y por extensión toda pantalla con periodos.

Cerrado en dos capas: `Vigencia` normaliza a `Y-m-d` antes de comparar (y expone
`Vigencia::fecha()` para quien tenga que comparar fechas en otro sitio), y las
validaciones pasan de `date` a `date_format:Y-m-d` en **siete** peticiones.
`VigenciaTest` (9 pruebas, sin base de datos) lo fija.

### La unicidad fiscal estaba desactivada en toda edición de sociedad
`country_id` no se pide al editar, así que la regla leía `null`, buscaba
`country_id = 0` y no encontraba nunca nada. Poner en una sociedad el documento
de otra pasaba la validación y salía como `1062` crudo — el mensaje traducido no
se emitía jamás al editar. Ahora el país se lee de la propia sociedad.

### Y tres más
- **Disolver antes de constituirse** (`ck_le_dates`) no tenía veto: `45000`.
- **La ficha de sociedad decía «hoy lo cubre X»** de una sociedad *inactiva*, o
  sea afirmando lo contrario de lo que 4.5 vino a hacer visible. Ahora distingue
  «lo cubre» de «sitio ocupado por X (inactiva: NO puede facturar)».
- **Una sociedad se anunciaba a sí misma como relevada** al redeclarar la
  cobertura de un país que ya cubría. Además había dos lecturas de la fila
  ocupada, una fuera de la transacción: ahora se pasa la que ya se leyó.

### Editar una marca sin mandar categorías las borraba todas
`sincronizarCategorias()` empieza por un `delete()`, y un checkbox sin marcar no
se manda: «ninguna» y «no venía el campo» llegaban iguales. Cualquier petición
sin la sección apagaba la detección de conflictos de marca (`BR-CAMPAIGN-007`) de
esa marca, **en silencio**. Un testigo oculto deshace la ambigüedad, y hay prueba
de que desmarcarlas todas sigue siendo posible — si «ninguna» no se pudiera
expresar, la regla nueva sería otra trampa.

### Lo que NO se arregló, y por qué (`T-17`)
Dos carreras: `Contactos::bajarPrincipal()` no toma bloqueo cuando el puesto está
libre, y `Marcas::slugUnico()` es un `SELECT` y luego un `INSERT`. La base
protege el dato en ambos casos —no hay corrupción— pero el operador vería un
`1062` crudo. Meter una captura amplia de `QueryException` sin poder ejecutar las
pruebas es cambiar un riesgo conocido por uno que no puedo medir. Queda anotado.

### Verificación
**776 correctas, 0 fallidas** en los dos motores. Nombres entre capas, fixturas,
periodos, migraciones, triggers, equivalencia y DDL crudo sin discrepancias.
Pint `passed`, 166 archivos PHP compilan. **16 pruebas nuevas** (9 de `Vigencia`,
3 de marcas, 4 de sociedades) — pendientes de PHPUnit como el resto.

## [Fase 4 · 4.5 — Entidades legales y cobertura] — 2026-08-25

**Cierra `Q-51`**, que era deuda mía. `BR-LE-004` lleva desde 4.1 diciéndole al
operador *«dé de alta la cobertura en Entidades legales»*, y en 4.4 el aviso de
los perfiles fiscales del cliente empezó a decir lo mismo. **Ese sitio no
existía.** Un mensaje accionable que manda a una pantalla inexistente es sólo la
mitad de accionable, y empeoraba con cada iteración que añadía otro mensaje.

### El bloqueo que apareció al construirla (`DEC-081`)
`uq_lec_country` ocupa el sitio de un país **mire o no el estado de la
sociedad**, pero quien resuelve quién factura sólo cuenta las `active`. Juntas
dejan un país **incomunicado**: se desactiva la sociedad que lo cubre sin cerrar
su cobertura, ninguna activa lo cubre —así que `BR-LE-004` bloquea todo— y
**ninguna otra puede tomarlo**, porque la fila abierta de la inactiva sigue
ocupando el sitio. No se puede facturar y no se puede arreglar por el camino
evidente.

Comprobado contra el motor antes de escribir aplicación. Ahora la baja **cierra
las coberturas abiertas en la misma transacción** y dice qué países quedan
descubiertos y desde cuándo.

### Un día importa, en los dos sentidos
Al relevar, la anterior se cierra **el día antes**. Al dar de baja, si el último
día facturado es el 30 de junio, el primer día **descubierto** es el 1 de julio.
Decir «desde el 30 no se puede facturar» cuando el 30 sí se podía es el mismo
error de un día, contado al revés.

### El `subDay()` iba camino de la octava copia
Ya se había hecho mal en **seis** sitios; en 4.4 escribí la séptima. Ahora vive
en `Shared\Database\Vigencia` —`cerrarElDiaAntesDe()`, `elDiaDespuesDe()`,
`puedeRelevar()`— y el parámetro se llama **como lo que de verdad se sabe**
(`$empiezaElSiguiente`), porque el error consiste en confundir las dos fechas.
`PerfilesFiscales::cerrarElDiaAntes()` conserva el nombre y delega.

### Y la consulta de cobertura se unificó
*«¿Quién factura a este país en esta fecha?»* vivía sólo dentro de
`CoberturaFacturacion` (Client), y Core no puede depender de Client. Se movió a
`Core\Services\Cobertura`; `CoberturaFacturacion` delega con su API intacta y
conserva lo suyo: **la traducción a un mensaje accionable**. Dos implementaciones
de «quién emite esta factura» habrían divergido, y el síntoma habría sido una
factura emitida por la sociedad equivocada.

### No hecho a propósito
Sin reglas nuevas de esquema. **No se añade `priority` a la cobertura** aunque la
hoja de ruta la mencione: `uq_lec_country` más el no-solape garantizan que en
cualquier fecha hay como mucho una sociedad por país, así que sería un campo que
nunca se lee y que insinúa que los empates son posibles. Tampoco cuentas
bancarias, monedas permitidas ni series de documentos: van con la iteración que
las use.

### Tres errores míos en la suite
Usé países que **no existen** en la semilla (`CL`, `MX`): `country_id` salió
`NULL` y **tres aserciones de rechazo se pusieron verdes por el motivo
equivocado** —las destaparon las de PERMITIR—. Cambié a `CO`/`US` y entonces
pasaba sola y fallaba en la batería, porque `3.10` deja Colombia cubierta. Y el
`DELETE` de «no se borra» apuntaba a cero filas, que **no dispara el
`BEFORE DELETE`** y sale verde sin probar nada (la trampa que `3.12` ya
documentó). Ahora la suite crea sus propios países y comprueba su premisa.

### Verificación
**776 correctas, 0 fallidas** en MariaDB y en MySQL 8 (732 → 776: 22 de esta
iteración × 2 motores). Nombres entre capas, fixturas, periodos, migraciones,
triggers, equivalencia, nombres SQL y DDL crudo sin discrepancias. Pint `passed`,
165 archivos PHP compilan. `EntidadesLegalesTest` (11 pruebas) **pendiente de
PHPUnit**.

## [Herramientas · nombres entre capas] — 2026-08-25

Cuatro iteraciones seguidas (4.1 a 4.4) se han entregado sin pasar por PHPUnit,
porque **aquí no se puede correr**: packagist está bloqueado, Composer no puede
instalar y no hay `vendor/`. En vez de acumular una quinta, esta entrega ataca la
causa por el lado que sí se puede.

### `tools/verificar-pantallas.py` (`DEC-080`)
Contrasta lo que la aplicación **nombra** contra lo que **tiene**, en siete
frentes: nombres de ruta, plantillas, permisos, roles usados en las pruebas,
métodos de controlador referenciados desde las rutas, claves leídas de
`validated()` que la `FormRequest` no declara, y variables que una plantilla usa
sin que su controlador se las pase.

Los siete son errores de **una letra** que tumban una suite entera con un mensaje
que no señala la causa, y los siete se ven leyendo archivos. Cuesta un segundo y
no necesita ni Laravel ni base de datos. **No sustituye a PHPUnit**: reduce la
superficie de lo que sólo se sabe al ejecutar.

### Se comprobó rompiendo, no confiando
Cada uno de los siete se rompió a propósito sobre una copia en `/tmp` —nunca
sobre los archivos entregados— y se exigió que el gate lo denunciara. Los siete
se pillan. Un gate que dice «todo bien» sin que nadie haya comprobado que sabe
decir «todo mal» no prueba nada.

### Dos falsos positivos antes de que sirviera
`->route('uuid')` no es el ayudante `route()` sino el accesor del parámetro de
ruta: acusaba a cuatro sitios sanos. Y **`GuardarPerfilFiscalRequest` existe dos
veces** —en `Modules/Creator` y en `Modules/Client`—, así que indexar por nombre
corto hacía que el gate leyera las reglas de la clase equivocada y acusara al
controlador de cliente de ocho claves que sí declara. Ahora resuelve por los
`use` del archivo, y si el nombre sigue siendo ambiguo **se calla**.

### Resultado sobre 4.1–4.4
Limpio: 92 nombres de ruta, 43 plantillas, 99 permisos, 203 roles, 54 acciones de
controlador y 15 plantillas contrastadas, sin un solo nombre roto. Además, los
157 archivos PHP compilan y Pint pasa.

## [Fase 4 · 4.4 — Identidad fiscal del cliente] — 2026-08-25

Cierra el bloque **7.0** de la hoja de ruta. Hasta ahora la ficha del cliente
**mostraba** los perfiles fiscales y no había forma de crear ninguno: el dato del
que salen la razón social y el RUC de la factura sólo entraba por SQL.

### El defecto que ha aparecido seis veces, cerrado en un solo sitio
`valid_to` es **inclusivo**. Cerrar el periodo anterior con el `valid_from` del
siguiente los deja solapados un día, y ese día *«¿con qué RUC se factura hoy?»*
tiene dos respuestas. Ha pasado en tarifas (`H-16`), en el perfil fiscal del
creador (`T-12`), en sus pruebas, en su suite, en la publicación de términos y en
la suite de activación.

Aquí se cierra con `valid_from - 1 día` y **en un único sitio**
(`PerfilesFiscales::cerrarElDiaAntes()`), lo fija la suite en los dos sentidos, y
`test_ningun_dia_tiene_dos_identidades_aplicables` comprueba la propiedad de
verdad consultando como lo hará la facturación: para el 30, el 31, el 1 y el 2 la
respuesta tiene que ser exactamente una. El mensaje de éxito **dice la fecha de
cierre**, porque es el dato que decide con qué identidad se factura el día del
relevo.

### Dos códigos de la base traducidos a palabras
`ck_ctxp_dates` cuando el periodo nuevo empezaría en o antes del vigente
(`DEC-071`: eso no es cerrar el anterior, es decir que nunca estuvo vigente) y
`uq_ctxp_taxid` cuando el documento ya es la identidad vigente de otro cliente en
ese país. Este último **nombra al otro cliente**: `Duplicate entry
'1-1-RUC-20123456789'` no le dice nada a nadie, y lo que ese choque significa casi
siempre es que la misma empresa está dada de alta dos veces.

### `DEC-078` — el histórico está congelado
Sólo se corrige el vigente; un periodo cerrado devuelve 404. Si la fila no se
puede borrar (`3.12`), poder reescribirla sin rastro es la misma pérdida por otra
puerta. Es seguro corregir el vigente precisamente porque `invoices` guarda
`receiver_*_snapshot`: **una corrección de hoy no reescribe una factura de ayer**.

### `DEC-079` — permiso propio, y a propósito en dos roles
`client.tax.manage`, no `client.manage`. **No** sigue la simetría de
`creator.tax.manage` —que vive sólo en `finance`— y la asimetría es deliberada:
el documento de un creador es dato personal sensible; el de una empresa es
público. Aquí el riesgo no es fuga, es error, así que lo tienen `finance`, que
emite, y `campaign_manager`, que habla con el cliente y tiene el dato.

### No hecho a propósito
Sin reglas nuevas de esquema. No se valida el formato del documento por país
—una tabla de expresiones regulares mal puesta rechaza documentos válidos y no
deja meterlos (`Q-55`)— y la falta de cobertura de facturación **avisa** en vez de
bloquear: `BR-LE-003` se resuelve en la fecha de la operación, y registrar hoy la
identidad de un cliente cuyo país se cubrirá el mes que viene es legítimo.

### Corregido — la suite pasaba sola y fallaba en la batería
La primera versión usaba el cliente de la semilla como «el vigente». Para cuando
le toca el turno, `2.13` y `3.6` ya le han dejado perfiles fiscales en PE y CO, y
tres aserciones acusaban a `uq_ctxp_taxid` de algo que no hizo. Ahora crea sus
propios clientes y comprueba su premisa: *una prueba que sólo pasa si es la
primera no está comprobando lo que dice*.

### Verificación
**732 correctas, 0 fallidas** en MariaDB y en MySQL 8 (696 → 732: 18 de esta
iteración × 2 motores). Fixturas, periodos, migraciones, triggers generados,
equivalencia, nombres SQL y DDL crudo sin discrepancias. Pint `passed`.
`PerfilFiscalClienteTest` (13 pruebas) **pendiente de PHPUnit**.

## [Fase 4 · 4.3 — Contactos del cliente] — 2026-08-25

Con quién se habla en la empresa cliente. `uq_contacts_primary` ya dejaba **un
contacto principal activo por cliente y tipo**; esta iteración construye la
pantalla y, sobre todo, se ocupa de que ese límite **nunca llegue al operador en
forma de `Duplicate entry`**.

### `DEC-075` — el relevo se hace, no se rechaza
Marcar a un segundo principal releva al primero, en una transacción, bajando al
que ocupa **antes** de subir al nuevo. El orden no es estilo: al revés choca, y
hay una aserción que lo fija. La alternativa —*«quítaselo primero al otro»*—
obliga a una maniobra de dos pasos en la que, entre paso y paso, el cliente se
queda sin principal de ese tipo. Una regla que exige pasar por un estado peor que
el de partida está mal puesta.

El relevo se anuncia **antes** (aviso ámbar en el formulario, con nombre) y
**después** (el mensaje de éxito nombra al relevado). Y hay una prueba de que el
mensaje *no* nombra a nadie cuando no se relevó a nadie.

### Tres maniobras, una comprobación
Subir a un suplente, reactivar a quien conservaba `is_primary = 1`, y mover a
alguien a un tipo ocupado dan `1062` en SQL crudo. Son la misma situación vista
desde tres sitios, así que `Contactos::actualizar()` tiene **una** comprobación,
no tres. `4.3-contactos.sh` comprueba que la base las rechaza; `ContactosTest`,
que por la pantalla no llegan.

### `DEC-076` — la lista de suites vive en un solo archivo
Al registrar la suite nueva se descubrió que **`3.10`, `3.11` y `3.12` estaban
sólo en el bloque de Percona del CI**. La lista estaba escrita a mano en cuatro
sitios; durante tres iteraciones esas suites corrieron en un motor de los tres y
el CI salía verde, porque *un CI no puede echar de menos una prueba que nadie le
nombró*. Ahora vive en `tools/pruebas/SUITES` y la leen los cuatro.

### Corregido — el gate de fixturas culpaba al fixture cuando no entendía el error
`verificar-fixturas.py` tomaba la primera línea de `stderr` como texto del error.
El cliente de MariaDB escribe antes el eco de la sentencia entre guiones, así que
el «error» era `--------------`, no casaba con ningún patrón, y
`culpa_del_fixture()` caía hasta el final **acusando al fixture**. Seis fixturas
sanas salían señaladas. Ahora se busca la línea `ERROR`; si no la hay, el
veredicto es *sin veredicto*, no una acusación.

### No hecho a propósito
Sin reglas nuevas de esquema. `contact_email` sigue sin ser única —es un canal
comercial compartible, no una identidad de acceso (`Q-53`)—, no se exige contacto
principal para activar un cliente (se avisa en ámbar, `Q-52`), no se expone
`user_id` (no hay flujo de invitación todavía) y los contactos se desactivan, no
se borran. Y un contacto **no cambia de cliente** (`DEC-077`).

### Verificación
**696 correctas, 0 fallidas** en MariaDB y en MySQL 8 (654 → 696: 21 de esta
iteración × 2 motores). Fixturas, periodos, migraciones, triggers generados,
equivalencia, nombres SQL y DDL crudo sin discrepancias. Pint `passed`.
`ContactosTest` (13 pruebas) **pendiente de PHPUnit**.

## [Fase 4 · 4.2 — Marcas] — 2026-08-25

Esta iteración nace de una pregunta de revisión: *«¿clientes y marcas no son lo
mismo?»*. La respuesta correcta tenía **dos mitades**, y quedarse en la primera
habría dado un sistema correcto y molesto de usar.

**El modelo distingue con razón** —`uq_cb_name` es por cliente, el conflicto de
`BR-CAMPAIGN-007` se coteja por categorías de la *marca*, y la factura sale del
cliente porque una marca no tiene RUC— **pero para un cliente de una sola marca
esa distinción es papeleo puro.**

### `DEC-074`
El alta de cliente crea **su primera marca con el mismo nombre**, visible y
editable, y lo dice en el mensaje. Que el modelo distinga no obliga a que la
pantalla lo imponga.

### Añadido
- Alta y edición de marcas, con categorías, colgadas del cliente en la URL y en
  la comprobación: `MarcasController` exige que la marca sea **de ese cliente**,
  no solo que exista.
- `Marcas::slugUnico()`. El slug es único **globalmente** y quien da de alta un
  cliente no lo eligió ni sabe qué hay cogido en otros: se desambigua solo
  (`acme`, `acme-2`). Y **solo se rehace si cambia el nombre** — regenerarlo
  porque alguien corrigió el sitio web rompería un enlace.
- La ficha del cliente marca en ámbar las marcas **sin categorías**: eso no es un
  campo vacío, es la detección de conflictos apagada para esa marca.

### Verificado antes de escribir las pruebas
Las cuatro unicidades, ejecutadas contra el esquema real: `ACME` en el cliente A
acepta; `ACME` en otro cliente con slug distinto acepta; `ACME` repetida en el
mismo cliente rechaza (`uq_cb_name`); mismo slug en cualquier cliente rechaza
(`uq_cb_slug`).

654 aserciones SQL en verde.

## [Fase 4 · 4.1 — Clientes] — 2026-08-25

Primera mitad de la iteración `7.0` de la hoja de ruta. Con esto empieza la otra
mitad del negocio: hasta ahora el sistema sabía todo del creador y nada de a
quién se le factura.

### Lo que faltaba
`client_organizations` existía desde la fase 2 con sus reglas puestas, pero **no
había ni una ruta**. Y `client.manage` estaba declarado desde 3.1 **sin que
ningún rol lo tuviera**: el permiso existía, el middleware lo comprobaba, y nadie
podía crear un cliente. No fallaba nada porque nadie lo intentaba.

### Añadido
- Listado, alta, ficha y edición de clientes, con bitácora.
- `CoberturaFacturacion`: qué sociedad factura a un país **en una fecha**. La
  fecha es un parámetro y no `now()` porque `BR-LE-003` dice «en la fecha de la
  operación», y una campaña que se factura en marzo se rige por la cobertura de
  marzo.
- `client.manage` para `campaign_manager`: quien monta la campaña da de alta al
  cliente para el que la monta.

### `DEC-073` — dónde se bloquea, que no era obvio
`BR-LE-004` manda bloquear si nadie puede facturar, pero no dice qué operación.
Un **prospecto se apunta en cualquier país** —un cliente potencial donde todavía
no cubrimos es una oportunidad legítima, y prohibirla obliga a llevarla en una
hoja aparte—; pasar a **`active` exige cobertura**, porque `active` significa «se
le puede facturar» y sin sociedad eso es falso. El esquema ya apuntaba a esa
respuesta: `status` nace en `prospect`.

### Una prueba que casi nace saltada
`test_no_se_activa_un_cliente_al_que_nadie_puede_facturar` buscaba un país sin
cobertura con un `whereNotIn`… y no encontraba ninguno: el seeder activa seis
países y cubre los seis. **Habría quedado en *skipped*, que en un informe parece
verde.** Se arregla activando Argentina, que no es un truco: es el caso real —
`BR-LE-004` existe para el día que el negocio se abre a un país nuevo y todavía
no hay quien facture allí. La prueba además afirma su propia premisa antes de
empezar.

### Y una deuda que se cobra sola
El resolver elige por país **y por fecha**. Hasta 3.10 dos sociedades podían
cubrir el mismo país en periodos solapados, y ese empate era una factura emitida
por la sociedad equivocada. Desde `tg_lec_sin_solape_*` no puede pasar, así que
el resolver consulta sin desempatar — y aun así comprueba: si apareciera más de
una, lo dice en vez de elegir.

### Anotado
`Q-51`: el mensaje de `BR-LE-004` manda a declarar la cobertura «en Entidades
legales», y **esa pantalla no existe** — hoy la única forma es el seeder o SQL a
mano. Un mensaje accionable que apunta a una pantalla inexistente es la mitad de
accionable.

654 aserciones SQL en verde; las pruebas de esta iteración van en la próxima
ejecución de PHPUnit.

## [3.13 en PHPUnit: dos fallos míos, y el gate que no los vio] — 2026-08-24

La cadena completa dejó `2 failed, 148 passed`. Los dos eran de `terms_versions`
y los dos eran míos.

### Corregido
- **`publicarTerminos()` tenía los dos defectos que 3.13 vino a cerrar**, y por
  eso se saltaba la regla nueva: cerraba la anterior con `effective_to = hoy` en
  vez de la víspera del inicio de la nueva —**sexta copia** del defecto de
  `H-16`—, y la versión nueva empezaba **siempre** el `2026-01-01`, así que dos
  llamadas producían dos periodos que arrancaban el mismo día. Ahora la fecha es
  un parámetro y el cierre se deriva de ella, como hace el comando de verdad.
- **`publicarTerminosNuevos()`, el helper que escribí en 3.12, se dejaba
  `title`** —columna obligatoria— y reventaba con un `1364`. No hacía falta
  ninguno: para eso ya estaba `publicarTerminos()`, que además es el camino que
  las otras pruebas ejercitan. Se borra y se delega.

### Y lo que importa: el gate lo tapaba
`verificar-fixturas.py` **rellenaba** las columnas obligatorias que el fixture no
daba, para que el fallo que saliera fuese de restricción y no un `1364` que
tapara lo demás. El efecto secundario era que «el fixture se dejó una columna
obligatoria» resultaba **invisible** — y eso siempre revienta en PHPUnit.

Ahora se rellenan **y se denuncian**. Probado al revés sobre una copia: con el
`title` quitado otra vez, el gate lo señala por su nombre y dice qué error dará
en PHPUnit.

**654 aserciones** en verde, y Pint, PHPStan y Deptrac ya estaban OK en la
ejecución que destapó esto.

## [Fase 3 · 3.14 — Rotar la clave sin apagar un control] — 2026-08-24

Cierra `T-11`. El número de cuenta lleva una **huella** HMAC-SHA256 que permite
comparar dos cuentas sin descifrar ninguna: es lo que detecta que dos creadores
declararon la misma cuenta (`DEC-065`).

El HMAC usa `APP_KEY`. **El día que se rote, las huellas dejan de casar** — y no
falla nada. No hay error, ni excepción, ni fila rechazada: el control
simplemente deja de detectar, y nadie se entera hasta que hay dos creadores
cobrando en la misma cuenta.

### Añadido
- `php artisan pagos:recalcular-huellas`. Sin `--aplicar` solo informa;
  idempotente.
- **No escribe nada si una sola fila no se puede descifrar.** Un recálculo a
  medias deja media tabla con huellas de una clave y media de otra: el mismo
  apagón silencioso, pero ya imposible de sospechar porque «el comando ya se
  ejecutó». El error dice qué poner en `APP_PREVIOUS_KEYS`.

### Cambiado
- `tg_cpm_inmutable` trataba la huella como parte de la cuenta y bloqueaba
  cualquier cambio, lo que hacía `T-11` **imposible de cumplir**. La regla se
  afina en vez de relajarse: **la huella no es la cuenta, es un índice derivado
  de ella**, así que puede cambiar *solo mientras el cifrado se quede donde
  estaba*. Lo garantiza la comprobación que ya existía — quien intente cambiar
  el número cambia el cifrado, y eso se rechaza igual que siempre.
- Cinco aserciones nuevas en `3.8-pagos.sh` que fijan el límite por los dos
  lados: la huella sola se puede, el cifrado no, los dos a la vez tampoco, la
  máscara tampoco, y una huella de largo inválido sigue rechazándose.

### Verificado de paso
`shared_account_status` **no** queda obsoleto tras el recálculo, y el comando lo
dice: al recalcularse todas las filas con la misma clave, dos cuentas iguales
siguen dando la misma huella. Cambia el valor, no la relación entre valores.

**654 aserciones** en verde sobre los dos motores.

## [Fase 3 · 3.13 — Los términos tampoco se solapan] — 2026-08-24

**Cuarta reaparición del defecto de `H-16`, y en la peor tabla: la que guarda el
texto legal que el creador aceptó.**

`PublicarTerminosCommand` cerraba la versión vigente con
`effective_to = effective_from` de la nueva, y `effective_to` es inclusivo. El
día de cada publicación había **dos versiones vigentes**. «¿Qué texto regía el 1
de mayo?» tenía dos respuestas — que es exactamente la pregunta que se contesta
el día que alguien discute qué aceptó.

### Por qué se escapó de 3.10, que es lo que hay que recordar
Aquel barrido buscó tablas con columnas **`valid_from`**. Éstas se llaman
`effective_from`. Un defecto de clase escondido detrás de un nombre, tres
iteraciones.

`tools/verificar-periodos.py` busca ahora por **forma** —cualquier par
`X_from` / `X_to` de tipo fecha— y exige que cada tabla así o tenga regla o esté
en una lista de exclusiones **con el motivo escrito**. Hoy: 8 tablas con forma de
periodo, 6 con regla, 2 excluidas a propósito (`creator_addresses` por
`DEC-072`, `creator_guardians` por redundante). Probado al revés: quitando los
disparadores de `terms_versions`, los señala.

### Corregido
- `Periodo::sinSolape` en `terms_versions` (serie: `code`).
- `PublicarTerminosCommand` cierra **el día antes** y rechaza, con un mensaje que
  lo explica, que la versión nueva empiece antes o el mismo día que la vigente.
- `Periodo::exigirSinSolapePrevio()` no admitía otros nombres de columna que
  `valid_*` — **el mismo sesgo que dejó esta tabla fuera del barrido**.
- **Y una quinta copia del defecto**: `3.5-activacion.sh` cerraba la versión
  vigente el mismo día que empezaba la nueva. Van cinco sitios que lo tenían
  escrito como si fuera lo correcto: el controlador fiscal, `PerfilFiscalTest`,
  `3.6-fiscal.sh`, `PublicarTerminosCommand` y esa suite.

### En el gate de fixturas
- Una tabla que **no se deja vaciar** (`no_delete`) conserva las filas de la
  semilla, así que un rechazo de una regla que mira *otras filas* puede venir de
  la semilla y no del fixture. Ahora eso sale como «sin veredicto» con ese
  motivo, en vez de acusar al fixture. Lo destapó `terms_versions` en cuanto
  ganó las dos cosas a la vez.

**644 aserciones** en verde sobre los dos motores.

### Nota sobre `B-1`
Se revisó por si quedaba algo técnico: no queda. `php artisan terminos:publicar`
existe y ahora además cierra bien. **`B-1` está bloqueado únicamente por el texto
legal revisado**, y sigue impidiendo toda activación de creadores.

## [Fase 3 · 3.12 — Lo que es evidencia no se borra] — 2026-08-24

Cierra `T-16`. Nueve tablas ya tenían `no_delete`; otras **nueve** guardaban
evidencia igual de definitiva sin ninguna protección.

### Añadido
- `no_delete` en `creator_tax_profiles`, `creator_tax_documents`,
  `client_tax_profiles`, `terms_acceptances`, `terms_versions`,
  `creator_guardians`, `exchange_rates`, `legal_entity_countries` y
  `publication_evidence`. De 9 tablas protegidas a 18.
- `tools/pruebas/3.12-no-borrar.sh`, y **la mitad que importa** es la segunda:
  comprueba que lo que *no* es evidencia se sigue pudiendo borrar. Una regla
  aplicada a todo no protege nada, solo estorba.

### El criterio, que es uno solo
La fila es **evidencia de algo que pasó**, y de ella depende dinero o una
obligación legal. Los catálogos, las tablas de unión y los datos operativos se
siguen borrando: `creator_blackouts` es el ejemplo — un bloqueo de agenda
apuntado por error se borra y no pasa nada, porque no es prueba de nada.

### Cambiado
- **Una prueba dejaba de simular «no aceptó los términos» borrando la
  aceptación.** El arreglo no fue rodear el disparador: borrar nunca fue lo que
  pasa de verdad. Lo que ocurre en la vida real es que **los términos se
  actualizan** y la aceptación anterior deja de valer, así que eso es lo que
  hace la prueba ahora — y de paso cubre un caso que antes no cubría nadie.
  Es el tercer requisito de esa misma lista que deja de simularse rompiendo
  datos; el fiscal ya se rechazaba y el medio de pago ya se retiraba.

### Dejado fuera a propósito (`Q-50`)
`campaign_creators` (lleva `agreed_amount`, el precio pactado),
`agreement_amendments`, `domain_events` y `status_transitions`. Sus módulos no
están construidos, y decidir ahora si una participación se borra o se cancela
sería adivinar. Que lo decida quien los construya.

### Coste medido, no escondido
El gate de fixturas vacía las tablas donde las pruebas escriben, y siete de
ellas ya no se dejan vaciar. Los sin-veredicto pasan de 6 a 7 de 44. Es el
precio de la protección y sale impreso en cada ejecución.

**634 aserciones** en verde sobre los dos motores, y el gate de DDL crudo pasa
de 74 a **101 sentencias ejecutadas de verdad**: esta migración sí tiene vuelta
en `down()`, así que el viaje de ida y vuelta la ejercita entera.

## [Fase 3 · 3.11 — Anular un perfil fiscal] — 2026-08-24

Cierra `T-15`. El estado del perfil sabía decir dos cosas y le faltaba una
tercera:

| estado | significa |
|---|---|
| `rejected` | no pasó la revisión — nunca llegó a aprobarse |
| `superseded` | otro tomó su lugar — **sí** estuvo vigente |
| `annulled` | **se aprobó y no debió aprobarse nunca** |

La diferencia entre las dos últimas se paga en dinero: de un `superseded` salió
la retención de sus meses; de un anulado, ninguna.

### Añadido
- Estado `annulled` con `annulled_at`, `annulled_by_user_id` y
  `annulment_reason`, los tres obligatorios —y prohibidos si no está anulado—.
  Anular reescribe el histórico del que sale la retención practicada; uno que se
  puede cambiar sin dejar rastro no es un histórico.
- **Permiso propio `creator.tax.annul`**, no `creator.tax.approve`. La prueba
  que lo demuestra no es «un rol sin permisos no puede», es
  `test_quien_aprueba_no_anula_por_eso`: alguien que **sí** puede aprobar
  recibe un 403.
- `tg_ctp_solo_el_vigente_se_anula`: solo se anula el vigente, y **una vez
  anulado la fila se congela**. Uno ya reemplazado se queda como está — durante
  su ventana fue el que había en el expediente, y sobre esa ventana puede
  haberse liquidado dinero.
- Al anular, el creador **deja de cumplir `BR-CREATOR-013`** y no se le invita ni
  se le liquida hasta que se apruebe otro. Es la decisión, no un efecto
  secundario: si el perfil no valía, no hay perfil válido.
- `tools/pruebas/3.11-anulacion.sh` (18 aserciones) y cuatro pruebas de PHPUnit.

### Lo que NO se puso, y por qué se dice
- **No hay segregación entre quien aprueba y quien anula**, al contrario que
  `ck_ctp_segregation`. Anular es *admitir un error*, y exigir una segunda
  persona significa que quien lo cometió no puede arreglarlo — en un equipo
  pequeño eso bloquea más de lo que protege. El control aquí es el rastro, que no
  se reescribe. Si el equipo crece, es lo primero que hay que reconsiderar.

### Encontrado construyéndolo
- **El disparador dejaba re-anular un perfil ya anulado**, o sea reescribir el
  motivo tantas veces como se quisiera. Lo destapó una aserción que leía el
  motivo y encontraba el último, no el primero. Ahora la fila se congela.
- **`T-16`: `creator_tax_profiles` se puede borrar con un `DELETE`.** Anular
  existe para no destruir el histórico, y un `DELETE` se lo lleva entero. La
  aserción que iba a escribir habría dicho «el DELETE funciona» — habría fijado
  el hueco como correcto, el mismo error que `PerfilFiscalTest` cometió con
  `T-12`. Así que no hay aserción, hay una tarea.
- `verificar-migraciones.py` ahora sugiere rehacer la base cuando encuentra
  discrepancias. Compara contra una base **ya construida**, y si está vieja las
  discrepancias son fantasmas — ya pasó una vez y costó 26 hallazgos inventados.

596 aserciones en verde sobre los dos motores.

## [PHPUnit en verde · y un hueco que salió al hacerlo] — 2026-08-24

**1 fallo, 145 pasan (418 aserciones)** — venía de 14 fallos y 129 pasando.

### Corregido
- `test_para_un_menor_el_perfil_del_creador_no_cuenta` capturaba los dos perfiles
  —el del menor y el del tutor— con la misma fecha de inicio, y `DEC-071` lo
  rechaza con razón: el relevo cierra el anterior **el día antes**, y no se puede
  cerrar un perfil la víspera de su propio inicio. Ahora el del tutor empieza en
  febrero.

### Anotado
- **`T-15`: no existe anular un perfil fiscal aprobado.** Es lo que ese caso
  pedía de verdad —un perfil a nombre de un menor no fue válido ni un día—, y no
  hay forma de decirlo: `superseded` significa *reemplazado* (estuvo vigente) y
  `rejected` significa rechazado en revisión (antes de aprobarse). El histórico
  queda honesto —en enero el perfil del expediente era el del menor, y no
  valía— pero no es lo que querríamos poder hacer.

## [T-14 — Un solo generador de periodos] — 2026-08-24

### Cambiado
- **Los cuatro disparadores de 3.9 ahora los genera `Periodo`.** Se escribieron a
  mano en `000495` porque la clase no existía todavía; desde 3.10 había dos
  formas de imponer la misma regla en el mismo repositorio, y un arreglo futuro
  habría que aplicarlo en dos sitios —el segundo es el que se olvida—.
- Los nombres de disparador son los mismos, así que es cambiar el generador sin
  cambiar el resultado. **Verificado sin PHPUnit**: las 23 aserciones de
  `3.9-tarifas.sh` cubren exactamente esos cuatro disparadores y pasan igual
  antes y después, que es la definición de que el cambio no cambia nada.

### Anotado, no arreglado
- `verificar-ddl-crudo.py` solo ejecuta sentencias con inverso en `down()`, así
  que los cuatro `DROP TRIGGER` de `000630` no pasan por ahí. El script los
  lista en su sección «sin vuelta» — no lo esconde. La cobertura real viene de
  `verificar-periodos.py` más el esquema de referencia, que sí se carga en los
  dos motores y en Percona 5.7. `Restriccion::quitar` tiene el mismo hueco desde
  siempre; merece su propia iteración, no un parche al final de ésta.

560 aserciones en verde sobre los dos motores.

## [Fase 3 · 3.10 (2/2) — El relevo de perfil fiscal] — 2026-08-24

### Corregido
- **`T-12` cerrado del todo.** `PerfilFiscalController` cierra ahora el perfil
  anterior **el día antes** de que empiece el nuevo, no el mismo día.
- **El filtro de la regla estaba mal en 3.10 (1/2), y la regla no habría servido
  de nada.** Filtraba `status = 'approved'`, y el controlador marca el anterior
  como `superseded` en la misma transacción en que aprueba el nuevo: nunca hay
  dos `approved` a la vez, así que la regla no se disparaba jamás. Restricción
  puesta, 24 aserciones en verde, y el defecto intacto. Ahora es
  `status IN ('approved', 'superseded')` — que además de funcionar es lo
  correcto: `superseded` quiere decir **reemplazado**, no anulado.
- **El defecto estaba escrito en tres sitios como si fuera lo correcto**: el
  controlador, `PerfilFiscalTest` (`assertSame('2026-07-01', ...)`) y
  `tools/pruebas/3.6-fiscal.sh`. Una prueba puede fijar un defecto igual de bien
  que fija un acierto.

### Añadido
- `DEC-071` en la pantalla: un perfil que entra en vigor antes —o el mismo día—
  que el vigente se rechaza **con palabras**, no con un 45000.
- `test_el_dia_del_relevo_hay_un_solo_regimen_aplicable` — la propiedad dicha
  como pregunta («¿qué régimen aplicaba ese día?»), no como fecha esperada.
- Sección nueva en la suite 3.10 que reproduce **la secuencia exacta del
  controlador** en vez de llegar al mismo estado por un camino que la aplicación
  no recorre nunca. Es lo que faltaba para que la regla probara algo real.

560 aserciones en verde sobre los dos motores.

## [Fase 3 · 3.10 (1/2) — El histórico no se solapa] — 2026-08-24

Siete tablas del esquema llevan `valid_from` / `valid_to`, y las siete
garantizaban que hay una sola fila **vigente**. Ninguna garantizaba que el
histórico tuviera una sola respuesta para una fecha **pasada**.

### Corregido
- **`T-12`: el histórico fiscal del creador admitía dos regímenes el mismo día.**
  `PerfilFiscalController` cierra el perfil anterior con `valid_to = valid_from`
  del nuevo, y `valid_to` es inclusivo: el día del relevo los dos están vigentes.
  En un historial de precios eso se paga explicando una factura; en uno fiscal,
  en una declaración.
- **El mismo agujero en `client_tax_profiles`** — de ahí salen el RUC y la razón
  social con los que se emitió la factura.
- **Y en `legal_entity_countries`, que es el más caro.** `uq_lec_country` impedía
  dos sociedades vigentes a la vez, pero el resolver de facturación elige por
  país **y por fecha**: dos filas cerradas solapadas son exactamente el empate
  que esa clave existía para evitar. Una factura emitida por la sociedad
  equivocada no se corrige con un `UPDATE`.

### Añadido
- **`App\Shared\Database\Periodo`** — una declaración por tabla genera los dos
  disparadores. A diferencia de `Restriccion`, aquí no hay elección de mecanismo:
  la regla mira otras filas y ningún motor admite una subconsulta en un `CHECK`,
  tampoco MySQL 8. Siempre disparador, y queda escrito para que nadie lo
  reintente.
- `Periodo::exigirSinSolapePrevio()` — un disparador no valida lo que ya está
  dentro, así que las tres migraciones se plantan si la tabla ya se contradice.
  Y no arreglan nada: cuál de los dos periodos valía ese día es una respuesta
  contable.
- `tools/pruebas/3.10-periodos.sh` (24 aserciones) y
  `tools/verificar-periodos.py`, que contrasta el esquema de referencia contra
  las migraciones.
- `DEC-071` retroactividad fiscal: se rechaza, como en tarifas ·
  `DEC-072` `creator_addresses` queda fuera a propósito · `T-14` migrar los
  cuatro disparadores de 3.9 a `Periodo`.

### Cerrado en las herramientas
- **`rehacer-referencia.sh` cargaba copias viejas.** La base que imita a Percona
  5.7 se carga de las copias `-sin-check` generadas, no de `tools/sql/*.sql`.
  Editar el esquema sin regenerar dejaba esa base sin la regla recién añadida, en
  silencio. El síntoma fue una suite en rojo acusando a la regla de no funcionar
  cuando lo que pasaba es que no estaba. Regenerar tarda 0,16 s: ahora lo hace el
  propio constructor.
- **El grabador no conocía `Periodo`.** Sin el doble, las dos puertas que leen
  migraciones se caían con «Class not found» sin llegar a comprobar nada. Es el
  mismo hueco que dejó pasar `H-15`.
- **La suite chocaba con la de 3.6**, que escribe en la misma tabla. 3.10 se trae
  ahora su propio creador y no depende del orden.

### Falta
- La segunda mitad: hasta que `PerfilFiscalController` cierre el perfil anterior
  el día **antes**, no se puede reemplazar un perfil fiscal desde la pantalla —la
  base ya lo rechaza, con razón—.

## [Puerta nueva · fixturas contra el esquema] — 2026-08-24

### Corregido
- **`PerfilComercialTest` fallaba entero (14 de 14) en `setUp()`.** Dos veces el
  mismo error en el mismo archivo, y es un error con nombre: **una palabra de
  estado tecleada como si fuera una palabra**.
  - `'status' => 'active'` en `creators`. Aquí `active` no es una cadena: son
    tres restricciones a la vez —`ck_creators_activation` exige fecha de
    activación, `ck_creators_active_identity` exige identidad verificada, y
    `ck_creators_identity_evidence` exige además quién la verificó y con qué
    documento—. Y no hacía falta para nada: `PerfilComercialController` no mira
    el estado del creador en ninguna línea. Lo escribí porque *sonaba* al estado
    correcto para un creador con tarifas. Un valor por defecto que parece una
    respuesta (`DEC-048`), otra vez.
  - `'status' => 'in_progress'` en `campaigns`, sin `confirmed_at`, que es lo
    que exige `ck_camp_confirmed`.

### Añadido
- **`tools/verificar-fixturas.py`** — ejecuta los `insert` de las pruebas contra
  el esquema de referencia, sin necesitar `vendor/`. Es la puerta que faltaba:
  tres iteraciones seguidas se entregaron en rojo por lo mismo (`1054` en 3.6,
  `1364` en 3.8, `4025` en 3.9), y las tres se habrían visto aquí en dos
  segundos. Dice qué restricción se incumple y qué literal del fixture la
  incumple; PHPUnit solo dice «14 failed».
- Enganchada a `tools/pruebas/correr-todo.sh` y a CI, sobre Percona 5.7.
- `T-13` — los fixtures siguen escritos a mano en 10 sitios; la puerta detecta
  la contradicción pero no la evita.

### Sobre la puerta misma
- La primera versión acusaba al fixture de **todo** lo que la base rechazaba:
  de 17 avisos, 12 eran suyos, no de los fixtures —valores de relleno que
  incumplían un `CHECK`—. Un gate que grita sin razón se acaba ignorando, y
  entonces no es un gate. Ahora un rechazo solo se atribuye al fixture si el
  fixture puso un **literal** en alguna columna que esa restricción mira; el
  resto sale como «sin veredicto», contado y visible con `-v`.
- Se comprueba a sí misma: con los dos fallos reintroducidos los señala por
  nombre; con el arreglo puesto, verde.

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
