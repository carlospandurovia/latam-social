# 09 — Estado del proyecto y siguiente iteración

> **Versión 3.6 — 2026-08-27.** Actualizado al cerrar `9.8`. **El creador ve su
> dinero.** Saldo por moneda, qué falta para cobrar cada cosa, y la fecha
> prevista cuando ya es pagable.
>
> **Versión 3.5 — 2026-08-27.** Actualizado al cerrar `9.4`. **Aceptar una
> campaña deja el dinero anotado solo.** El ciclo del creador está entero: acepta,
> se devenga, entrega, se aprueba, se publica, se verifica, y el barrido lo pasa
> a pagable.
>
> **Versión 3.4 — 2026-08-27.** Actualizado al cerrar `9.3`. **El libro mayor
> deja de ser una tabla vacía**: hay devengos, un grafo de estados que impide que
> un pagado vuelva, y un barrido diario que pasa a pagable lo que cumple las
> cinco condiciones de `BR-FIN-003`.
>
> **Versión 3.3 — 2026-08-27.** Actualizado al cerrar `9.2`. Las tasas llegan
> solas: cron a las 05:30, pantalla de Tipos de cambio, y la credencial
> configurable sin entrar por SSH — cifrada, firmada y sin reenseñarse nunca.
>
> **Versión 3.2 — 2026-08-27.** Actualizado al cerrar `9.1`. **Empieza la fase
> de finanzas.** `exchange_rates` llevaba desde la Fase 2 en el esquema con cero
> filas y cero lecturas; ahora hay una fuente oficial por par con periodos, los
> dos lados de SUNAT caben sin pisarse, y una tasa publicada no se reescribe.
>
> **Versión 3.1 — 2026-08-27.** Actualizado al cerrar `8.13`. **Con una
> corrección:** la 3.0 daba por bueno que `payouts` no tenía columna de sociedad,
> y `payout_batches` la tiene desde la migración de finanzas (`T-59`). Lo que
> falta no es la columna: es que nada garantice que sea la correcta.
>
> **Versión 3.0 — 2026-08-27.** Actualizado al cerrar `8.12`. La sociedad que
> factura una campaña ya se explica en pantalla, y `DEC-156` fija que **esa
> sociedad paga a todos sus creadores**, sea cual sea el país de cada uno. Eso
> cambia el eje de `Q-40`: la pregunta al contador pasa a ser dos tablas.
>
> **Versión 2.9 — 2026-08-27.** Actualizado al cerrar `8.11`, el QA de la Fase
> 8. Séptima puerta, y tres cosas que la fase daba por hechas.
>
> **Versión 2.8 — 2026-08-27.** Actualizado al cerrar `8.5`. El ciclo de
> contenido queda entero: entregar, revisar, aprobar internamente, **que lo vea
> el cliente**, publicar, verificar y vigilar la permanencia.
>
> **Versión 2.7 — 2026-08-27.** Actualizado al cerrar `8.4`. **Y con una
> corrección:** la versión 2.6 decía que el contador de rondas «cuenta hacia
> arriba sin techo y la autorización no se dispara nunca». Era falso — el límite
> estaba entero desde `8.3`. Lo que faltaba era que algo lo respaldara en la
> base, y eso es lo que hizo `8.4`.
>
> **Versión 2.6 — 2026-08-27.** Actualizado al cerrar `8.8`.
>
> **Versión 2.5 — 2026-08-26.** Actualizado al cerrar `8.7`.
>
> **Versión 2.4 — 2026-08-26.** Actualizado al cerrar `8.6`.
>
> **Versión 2.3 — 2026-08-26.** Actualizado al cerrar `8.2`.
>
> **Versión 2.2 — 2026-08-26.** Actualizado al cerrar `8.3`.
>
> **Versión 2.1 — 2026-08-26.** Actualizado al cerrar `8.1`.
>
> **Versión 2.0 — 2026-08-26.** Actualizado al cerrar `7.7`.
>
> **Versión 1.9 — 2026-08-26.** Actualizado al cerrar `7.6b`.
>
> **Versión 1.8 — 2026-08-26.** Actualizado al cerrar `7.6`.
>
> **Versión 1.7 — 2026-08-26.** Actualizado al cerrar `5.9` + `4.1`.
>
> **Versión 1.6 — 2026-08-26.** Actualizado al cerrar 4.13 (`T-10`).
>
> **Versión 1.5 — 2026-08-26.** Actualizado al cerrar `F4.9` (el correo).
>
> **Versión 1.4 — 2026-08-26.** Actualizado al cerrar 7.5.
>
> **Versión 1.3 — 2026-08-26.** Actualizado al cerrar 7.4.
>
> **Versión 1.2 — 2026-08-25.** Actualizado al cerrar 7.3.
>
> **Versión 1.1 — 2026-08-25.** Actualizado al cerrar 7.2. La versión 1.0
> es de esta misma fecha y ya tenía los números de 4.11: se actualizan aquí
> porque este documento es exactamente el que no puede quedarse atrás.
>
> **Versión 1.0 — 2026-08-25.** Reescrito entero.
>
> La versión 0.1 era del 21 de agosto y decía *«me detengo aquí; no hay código,
> no hay esquema de base de datos y no hay wireframes»*. Cuatro días después eso
> describía un proyecto que ya no existe: hay 64 tablas, 41 migraciones, 259
> pruebas de PHPUnit y 812 aserciones de restricción. **El documento cuyo único
> trabajo es decir qué viene ahora llevaba cuatro días mintiendo**, y nadie lo
> habría notado hasta abrirlo.
>
> Es el mismo defecto que dejó `T-12` marcada como pendiente durante un mes
> estando resuelta. Un registro que no se mantiene no es un registro: es un
> documento antiguo con fecha nueva.

---

## 1. Dónde estamos, medido

| | |
|---|---|
| Tablas | 73 · **26 columnas puerta**, contadas y no heredadas (`T-57`) |
| Migraciones | 61, verdes desde cero en MySQL 8 y con vuelta atrás completa |
| Pruebas de PHPUnit | **779**, 2.520 aserciones |
| Aserciones de restricción (SQL) | **1.684** en MariaDB, **1.674** en MySQL 8 · 33 suites |
| Puertas de calidad | **7**: formato, análisis estático, fronteras, pruebas, vigencias, nombres entre capas y **las suites** (8.11) |
| Verificadores fuera de PHPUnit | 4: fixturas, periodos, nombres entre capas y **mensajes de la base** (nuevo en 8.1) |
| Decisiones registradas | hasta `DEC-173` |

### Lo que se puede hacer hoy por pantalla

Dar de alta y gestionar **creadores** (solicitud, aprobación, activación con seis
requisitos, identidad, redes sociales verificadas, perfil comercial y tarifas,
perfil fiscal aprobado por dos personas distintas, medios de pago verificados) y
**clientes** (organización, marcas, contactos con su principal, identidad fiscal
por país con vigencia, y la sociedad que les factura según cobertura).

Y desde 7.1 y 7.2, **campañas**: alta con la sociedad que factura resuelta a la
fecha de inicio y congelada al confirmar, grafo de estados con su permiso por
transición, y un brief que dice qué hay que entregar y a qué precio — con el
cero declarado, porque «regalada» y «sin precio» no son lo mismo.

Y desde 7.3, en qué países corre cada campaña, con su cupo de creadores y con un
brief que se puede especializar por mercado.

Y desde 7.4, buscar a quién invitar: el buscador aplica solo los mercados, los
formatos del brief, la edad mínima y las categorías de la marca, y la lista corta
veta a quien no cumple `BR-CREATOR-006`.

Y desde 7.5, el dinero: presupuesto de creadores, veto de sobrecosto con
autorización auditada, y monto acordado congelado al aceptar.

Y desde `F4.9` el correo —plantillas versionadas, registro auditable,
reintentos— y desde 4.13 su primer uso real: **el creador recibe un aviso cuando
alguien toca sus datos fiscales o su medio de pago**, mientras el cambio todavía
se puede parar. Eso cerró `T-10` y la mitad que faltaba de `BR-CREATOR-007`.

Y desde `5.9` + `4.1`, **la contraseña**: aprobar a un creador le crea su cuenta
y le manda un enlace de 72 h para elegirla —nadie más la ve nunca— y cualquiera
puede recuperar la suya desde `/recuperar`, con una hora de plazo y la misma
respuesta exista o no el correo. Es la primera vez que entra al sistema alguien
que no es del equipo, y eso destapó que la portada le enseñaba los totales
internos a cualquier autenticado.

Y desde `7.6`, **la conversación con el creador**: se le manda una invitación con
su importe dentro, la contesta él mismo desde un enlace de un solo uso, y si no
contesta el plazo la cierra sola y devuelve el dinero al presupuesto. Rechazar no
cierra la puerta: se puede volver a preguntar con otra oferta, y quedan las dos
rondas.

Y desde `7.6b`, los tres cabos sueltos que quedaban: **ninguna contraseña se
dicta ya por teléfono** —tampoco la del equipo—, el creador **puede preguntar**
antes de decidir, y a quien invitó **le llega un correo** cuando el creador
contesta.

Y desde `7.7`, **el panel de seguimiento**: qué hay que atender hoy, por dónde va
cada creador, qué mercado va corto y cuánto queda de presupuesto. Es la pantalla
que el roadmap llama «la más usada del sistema».

Y desde `8.1`, **el creador entra a trabajar**: al aceptar se le crean solos sus
entregables —del brief del **mercado** que le toca, no del general—, y en
`/mis-entregas` manda su enlace. Si al texto le faltan los hashtags del brief, no
se envía y se le dice cuáles. Cada corrección es una **versión nueva**: nunca se
pisa la anterior.

Y desde `8.3`, **el ciclo se cierra**: lo entregado cae en una cola, alguien lo
mira, y o queda aprobado o vuelve con lo que hay que cambiar —y el creador recibe
ese texto por correo—. Las «2 rondas incluidas en el precio» dejan de ser una
frase del contrato: son un contador por pieza que **bloquea**, y pasarse exige
decir si se le cobra al cliente o lo asumimos nosotros, firmado.

Y desde `8.2`, el sistema sabe **cuál es la buena**: aprobar apunta a una versión
concreta, y esa versión no se puede borrar ni sustituir por debajo. Volver atrás
sobre algo aprobado deja de exigir tocar la base a mano: se reabre con motivo y
firma, y la aprobación anterior se queda donde estaba.

Y desde `8.6`, **el ciclo del creador se cierra**: acepta, entrega, corrige, se
le aprueba, publica y pega el enlace — y ahí termina su parte. Sólo se registra
lo aprobado, la red se comprueba contra la que pedía el brief, y el mismo post no
se puede reclamar desde dos entregables.

Y desde `8.7`, **existe la prueba**: alguien abre el post, comprueba que está y
sube la captura, que queda archivada y no se borra nunca. Si no está, el
entregable vuelve al creador con el motivo. Es lo que convierte
`BR-CONTENT-004` en algo real y lo que el pago va a mirar.

Y desde `8.5`, **el cliente ve su pieza y da el visto bueno**: un enlace de un
solo uso, sin portal ni contraseña, donde ve la campaña, el formato y el
contenido — y ni un solo importe. Lo que conteste queda registrado con su hora y
su IP, y lo cierra una persona: su corrección gasta ronda, y una ronda de más
exige una firma que él no puede poner contra sí mismo.

Y desde `8.4`, **el techo de rondas lo impone la base**: sólo la corrección del
cliente gasta ronda, una ronda de más no se cuela sin declararse —y esa columna
es la que se factura— y el contador no baja. Lo que hasta entonces protegía un
`if` de PHP, justo antes de que `8.5` empiece a escribir revisiones desde un
enlace firmado que no pasa por ahí.

Y desde `8.8`, **el post se vigila hasta su fecha**: una bandeja con lo que hay
que mirar, un histórico de comprobaciones que no se edita ni se borra, y un
comando diario que cierra las ventanas cumplidas —que es lo que habilita el
pago—. Si el post desaparece, alguien lo firma con la captura de lo que ve, el
pago se para y el creador se entera por correo. Nada de eso lo decide una
máquina, y eso es la iteración entera.

Y desde `8.12`, la ficha de campaña **explica quién factura y por qué** —«CTS
Perú factura a Perú desde el … (sociedad local)»— en vez de dar un nombre a
secas, y dice que esa sociedad **paga a todos los creadores de la campaña**, sean
del país que sean (`BR-LE-009`, `DEC-156`). No hay desplegable de sociedad
porque no hay nada que elegir: el esquema garantiza como mucho una por país y
fecha. Al ir a enseñarlo salió `T-58` — la pantalla llevaba desde 7.1 imprimiendo
la sociedad que tocaría **hoy** bajo el rótulo de la guardada.

Y desde `9.8`, **el creador ve su dinero**: saldo por moneda, qué falta para
cobrar cada cosa —con las palabras de las cinco condiciones— y la fecha prevista
en cuanto es pagable. Sin un solo botón, y sin el motivo interno de una
retención.

Y desde `9.4`, **aceptar una campaña deja el dinero anotado solo**: nadie pulsa
nada, y si el listener alguna vez falla el barrido lo rescata y avisa de que
hubo que rescatarlo.

Y desde `9.3`, **el libro mayor del creador está vivo**: se devenga lo pactado
—una sola vez por participación, y lo garantiza la base—, un barrido diario pasa
a pagable lo que cumple las cinco condiciones, un post caído retiene el asiento
en vez de anularlo, y un pagado no vuelve. El saldo es una suma, nunca una
columna.

Y desde `9.2`, **las tasas llegan solas**: un cron a las 05:30 las trae de
Decolecta, una pantalla enseña qué fuente manda, qué hay y si el cron sigue vivo,
y la credencial se configura sin entrar por SSH — cifrada, firmada, y sin
reenseñarse nunca.

Y desde `9.1`, **el sistema sabe convertir dinero** — y sabe cuándo no debe.
Quién publica el tipo de cambio de cada par se declara con periodos, así que el
histórico se sigue explicando con la fuente de entonces; compra y venta caben sin
pisarse; una tasa publicada no se reescribe; y un domingo se convierte con la
tasa del viernes **guardando el viernes**. Lo que todavía no hace es traerlas:
eso es `9.2`.

Eso completa **7.0 a 7.7 del roadmap**, más `F4.9`, `5.9`, `4.1`, `8.1`, `8.2`,
`8.3`, `8.6`, `8.7`, `8.8`, `8.12` y `9.1`.

> **Y por primera vez el sistema se ha usado.** Tres fallos salieron de ahí en una
> tarde: un 500 al repetir un formato en el brief de un mercado, la bitácora
> entera caída por una fila con una lista dentro, y una herramienta que no sabía
> distinguir un fixture inválido a propósito. Los tres están cerrados
> (`T-40`, `T-41`, `T-42`). **Ninguno lo habría encontrado yo solo**, y los dos
> primeros eran de pantalla: la regla existía y estaba probada; lo que faltaba era
> que alguien la supiera contar.

---

## 2. Lo que bloquea, y a quién le toca

Esto es lo importante de este documento. **La cola de trabajo de ingeniería está
vacía**: no queda ninguna tarea técnica que yo pueda hacer sin una decisión tuya
o sin abrir un módulo nuevo.

| # | Qué está parado | Quién lo desbloquea | Qué pasa mientras tanto |
|---|---|---|---|
| `T-09` | Publicar la **primera versión real de los términos del creador** | **Tu abogado** | 🔴 **Ningún creador puede activarse.** La pantalla lo dice explícitamente |
| `Q-40` | Con qué **tasa** se retiene a un creador no domiciliado. **Desde `DEC-156` son dos tablas**: «CTS Perú paga» —contador peruano— y «CTS Colombia paga» —contador colombiano—, porque quien paga lo decide la campaña y no el país del creador | **Tu contador** | Un perfil fiscal con retención sin decidir no se puede aprobar (`DEC-048`) |
| `DEC-085` | Ejecutar los dos `GRANT` en el servidor de producción | **Tú, al desplegar** | La bitácora es truncable por la aplicación hasta que se haga. **Pasos en `docs/18-RUNBOOK-DESPLIEGUE.md` §3.1** |
| `Q-44` | ¿Los servicios a un cliente **no domiciliado** son exportación de servicios (sin IGV) o van al 18 %? | **Tu contador** | El modelo admite las cuatro opciones y no fuerza ninguna |

Los tres primeros son de verdad urgentes. `T-09` es el más caro de todos: **todo
el trabajo de adquisición de creadores está construido y probado, y no se puede
usar con un creador real hasta que exista ese texto.**

---

## 3. Decisiones de negocio abiertas

Ninguna bloquea código hoy; todas bloquean una iteración futura concreta.

| # | Pregunta | Cuándo hace falta |
|---|---|---|
| `Q-46` | Al publicar términos nuevos, ¿qué pasa con los creadores **ya activos**? | Antes de la 2ª versión de los términos |
| `Q-47` | El periodo de gracia de 30 días, ¿global o por creador? | Iteración de rechazo de creadores |
| `Q-50` | ¿`campaign_creators` y compañía son **evidencia** (no se borran)? | **Al construir campañas — o sea, ahora** |
| `Q-56` | ¿`deliverable_versions` y `content_reviews` son **evidencia**? Hoy se pueden borrar, y son lo que el cliente aprueba y lo que justifica un cargo | **Antes de facturar (F9)** |
| `Q-57` | ¿Dónde acaba la línea del **cargo por una ronda de más**? Hoy la decisión queda en `content_reviews` y la pantalla la enseña; no hay factura todavía | **Al construir facturación (F9)** |
| `Q-58` | ¿Se puede **reabrir** un entregable ya publicado o verificado? Hoy no, y hoy da igual porque `publications` se llena en 8.6 | **Al construir publicación (8.6)** |
| `Q-52` | ¿Un cliente debería exigir contacto de facturación antes de estar `active`? | Facturación (F9) |
| `Q-53` | ¿El mismo correo repetido en el mismo cliente y tipo es un error? | Importación de clientes |
| `Q-54` | ¿Se puede corregir un periodo fiscal ya **cerrado**? | Primera corrección real |
| `Q-55` | ¿Se valida el formato del documento fiscal por país? | Alta de clientes en el 2º país |
| `Q-34` | Colombia: ¿DIAN directo o proveedor certificado? *(recomendé proveedor, contra lo que dijiste — revísalo)* | F12 |
| `Q-38` | ¿Cuántos desarrolladores? Con uno solo, las estimaciones ×1,7 | Todo el plan |
| `Q-62` | Si la fuente **corrige** un tipo de cambio ya publicado, ¿qué se hace? Hoy `tg_fx_inmutable` no deja tocarlo, y hoy da igual porque la tabla está vacía | Antes de la primera corrección real |
| `Q-63` | ¿Qué lado del tipo de cambio aplica a cada operación —**compra o venta**— y si depende de si es ingreso o egreso? Es contable, no técnica. Mientras no esté, el buscador de creadores sigue sin convertir tarifas | **Antes del primer pago o factura en otra moneda** |
| `Q-64` | ¿De dónde salen los tipos de cambio que **SUNAT no publica** (`COP`, `MXN`, `CLP`, `EUR`)? Decolecta sólo trae `USD → PEN`. Hoy se teclean a mano con fuente `manual`, que funciona y no escala | Al pagar al primer creador de fuera de Perú |

---

## 4. Lo que propongo como siguiente iteración

Lo que queda de `F9` **empieza a necesitar a tu contador**:

| | Qué falta | Quién |
|---|---|---|
| `9.5` | Requisitos documentales por país y régimen | tu contador (`Q-40` + la matriz de documentos) |
| `9.6` | Lotes de pago con doble aprobación y exportación bancaria | nadie — y aquí se paga `DEC-157` |
| `9.7` | Registro del pago, comprobante, aviso al creador | nadie |

**Propongo `9.6`**, que es el siguiente sin bloqueo y el que cierra la deuda de
`DEC-157`: la comprobación de que **la sociedad que paga es la de la campaña**
(`BR-LE-009`, 🔴) se adelantó a la iteración que estrene `payout_batches`, y esa
es ésta. El lote ya tiene su `legal_entity_id` y el asiento ya sabe de qué
campaña viene; lo que falta es la restricción entre los dos.

Alternativa sin código nuevo: **bajar el trinquete de las 297 aserciones
ciegas**, que sigue en 297 desde que `DEC-161` dio a `porque()` la alternancia.


## 5. Deuda de documentación reconocida

Cinco iteraciones de la Fase 3 no tienen su documento, mientras todas las demás
sí: **3.9** (tarifas), **3.11** (anulación), **3.12** (no borrar), **3.13**
(términos) y **3.14** (rotación de clave). El trabajo está hecho y verificado por
sus suites; lo que falta es el documento que explica **por qué**.

Se anota aquí en vez de en un comentario para que no pase lo de `T-12`.

---

## 6. Qué necesito de ti

Cuatro cosas, por orden de coste para el proyecto:

1. **Manda el texto de los términos del creador a tu abogado.** Es lo único que
   impide usar de verdad todo lo construido en las fases 3 y 4.
2. **Pregúntale a tu contador `Q-40` y `Q-44`.** Las dos tienen respuesta corta y
   las dos bloquean el dinero.
3. **Consigue la clave de Decolecta y cárgala** — y pon el cron de Laravel en el
   servidor si no está. Sin esa línea `cambio:traer` no corre nunca, y la
   pantalla lo dirá con esas palabras. Los pasos están en
   `docs/fase-9/9.2-TRAIDA-AUTOMATICA.md` §9.
4. **La cuenta de SMTP (`Q-20`) sube de prioridad.** Ya no es sólo para los
   avisos: desde `5.9`, **sin correo saliente un creador aprobado no puede
   estrenar su cuenta** — su contraseña viaja en un correo y en ningún otro
   sitio. Hasta entonces el enlace se escribe en `storage/logs` y el flujo se
   puede probar entero, pero eso no sirve con una persona real.
