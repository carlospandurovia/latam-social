# 09 — Estado del proyecto y siguiente iteración

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
| Tablas | 69 |
| Migraciones | 54, verdes desde cero en MySQL 8 y con vuelta atrás completa |
| Pruebas de PHPUnit | **638**, 2.006 aserciones |
| Aserciones de restricción (SQL) | **1.398** en MariaDB, **1.388** en MySQL 8 |
| Puertas de calidad | 6: formato, análisis estático, fronteras, pruebas, vigencias, nombres entre capas |
| Verificadores fuera de PHPUnit | 4: fixturas, periodos, nombres entre capas y **mensajes de la base** (nuevo en 8.1) |
| Decisiones registradas | hasta `DEC-141` |

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

Eso completa **7.0 a 7.7 del roadmap**, más `F4.9`, `5.9`, `4.1`, `8.1`, `8.2`,
`8.3` y `8.6`.

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
| `Q-40` | Con qué **tasa** se retiene a un creador no domiciliado | **Tu contador** | Un perfil fiscal con retención sin decidir no se puede aprobar (`DEC-048`) |
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

---

## 4. Lo que propongo como siguiente iteración

Hay un post registrado y nadie ha comprobado que exista. Propongo **`8.7` —
verificación y evidencia archivada**, que es lo único que convierte
`BR-CONTENT-004` en algo real: *«una publicación no se considera verificada hasta
que existe evidencia archivada del post en vivo, con fecha y hora de captura»*.

Es también donde el dinero empieza a depender de un dato nuestro: hoy la fila
nace en `reported` y ahí se queda, y **de `verified` cuelga el pago**.

Trae tres cosas concretas:

- `publication_evidence` —diseñada en la Fase 2, con cero filas, y ya con
  `no_delete` desde `3.12`— empieza a llenarse.
- `permanence_until` se calcula al verificar, que es lo que `8.8` necesita para
  vigilar que el post siga vivo.
- Y aparece la pregunta que hay que decidir con datos delante: **cómo se
  captura**. Una comprobación HTTP es barata y demuestra poco; una captura de
  pantalla demuestra más y no se puede automatizar sin un navegador. El esquema
  ya admite las tres formas (`file_id`, `http_status`, `raw_payload`), así que la
  decisión es de operación, no de modelo.

### Y una cosa que no es una iteración pero bloquea igual

**El CI no ha corrido ni una vez desde `4.9`.** El workflow sólo se disparaba en
`push` a `main` y `develop`; el trabajo vive en `feat/7.6-invitaciones`, así que
empujar ahí no lanzaba ningún job, y `main` no recibía nada desde entonces.

Ocho iteraciones —`5.9` a `8.6`— se empujaron sin que Percona 5.7 las mirara, y
Percona es el motor de producción y lo único que el CI prueba y el contenedor no.
Un CI que no se dispara **no falla: no dice nada**.

Arreglado (el `on: push` incluye ahora `feat/**`), y los pasos de entrega están
en **`docs/19-PROTOCOLO-DE-ENTREGA.md`**, que también documenta que `main` lleva
ocho iteraciones de retraso y qué hacer con eso.

### Lo que sigue sin poder hacerse, y no es código

`T-09` —el texto de los términos— lleva **cinco días** bloqueando toda activación
de creadores. El ciclo entero está construido y probado de punta a punta, y **no
se puede usar con una persona real** hasta que exista ese texto.

Es, con diferencia, lo más caro que hay abierto.

---

## 5. Deuda de documentación reconocida

Cinco iteraciones de la Fase 3 no tienen su documento, mientras todas las demás
sí: **3.9** (tarifas), **3.11** (anulación), **3.12** (no borrar), **3.13**
(términos) y **3.14** (rotación de clave). El trabajo está hecho y verificado por
sus suites; lo que falta es el documento que explica **por qué**.

Se anota aquí en vez de en un comentario para que no pase lo de `T-12`.

---

## 6. Qué necesito de ti

Tres cosas, por orden de coste para el proyecto:

1. **Manda el texto de los términos del creador a tu abogado.** Es lo único que
   impide usar de verdad todo lo construido en las fases 3 y 4.
2. **Pregúntale a tu contador `Q-40` y `Q-44`.** Las dos tienen respuesta corta y
   las dos bloquean el dinero.
3. **La cuenta de SMTP (`Q-20`) sube de prioridad.** Ya no es sólo para los
   avisos: desde `5.9`, **sin correo saliente un creador aprobado no puede
   estrenar su cuenta** — su contraseña viaja en un correo y en ningún otro
   sitio. Hasta entonces el enlace se escribe en `storage/logs` y el flujo se
   puede probar entero, pero eso no sirve con una persona real.
