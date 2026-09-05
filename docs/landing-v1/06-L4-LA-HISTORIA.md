# L-4 — «La historia que cuenta la portada de marcas»

> Iteración cerrada. Landing comercial v1, cuarta entrega.
> Base: `L-1` (la marca), `L-2a` (los datos del sitio), `L-2b` (las páginas), `L-3` (la cabecera y las franjas).

---

## 1. Qué se pidió

La auditoría lo dejó escrito en su §1.8, y es el diagnóstico que abrió esta
iteración:

> La estructura actual es **héroe → 3 ventajas → 4 pasos → formulario**: justo la
> forma que el §11 rechaza por su nombre. No hay problema, no hay tensión, no hay
> por qué. Se entra sabiendo qué se vende y se sale sin haber entendido por qué
> importa. Y falta lo esencial: **la palabra «muchos» no aparece por ningún lado.**

La `L-3` convirtió las franjas en datos. Esta escribe la historia.

---

## 2. El titular

Se aplica el recomendado en la auditoría, **A**:

> # Muchas voces. Una sola campaña.
> ### Activamos decenas de creadores reales en una campaña coordinada: elegimos, producimos, publicamos y te entregamos cada publicación con su evidencia. Tú hablas con una sola persona.

El anterior —«Campañas con creadores, de principio a fin y con todo a la vista»—
es honesto y se entiende, pero habla de **proceso**, «de principio a fin» lo dice
cualquier agencia, y sobre todo **no dice «muchas»**: el modelo entero quedaba
fuera del titular.

**No está aprobado por ti**, y por eso la migración lo cambia **sólo si sigue
siendo el de fábrica** —el mismo criterio que la `L-1` usó con los colores— y el
titular se edita desde el panel en diez segundos. Un `UPDATE` a secas sobre una
portada que alguien hubiera redactado sería la peor clase de migración: la que
borra trabajo ajeno sin preguntar. Si prefieres **B** o **C**, se cambia en
Portada pública sin desplegar nada.

---

## 3. Las nueve franjas

Cada una con su trabajo. La columna «conversión» dice qué se le pide al
visitante ahí, no qué se le cuenta.

| # | Franja | Forma | En el menú | Qué hace |
|---|---|---|---|---|
| 1 | **El problema** | `plain` | no | *«Diez creadores no deberían ser diez conversaciones.»* Nombra el caos que el lector ya vive. Aquí no se pide nada: se gana la lectura |
| 2 | **Una marca. Muchas voces.** | `claim` | no | El giro. Por qué muchas comunidades pequeñas hacen algo que una grande no hace. **El único sitio, aparte del héroe, donde manda el degradado** |
| 3 | **Cómo funciona** | `steps` | sí | Los cuatro pasos, con un CTA intermedio |
| 4 | **Qué recibes** | `cards` | sí | Seis entregables concretos, incluido el comprobante electrónico |
| 5 | **Tipos de campaña** | `cards` | sí | Lanzamiento, prueba de producto, cobertura de local, UGC, temporada. Micro-CTA: «esto es lo mío» |
| 6 | **Cómo elegimos a los creadores** | `steps` | no | Responde la primera objeción real de un gerente de marketing: *«¿y si me ponen a cualquiera?»* |
| 7 | **Por qué puedes confiarnos una campaña** | `cards` | no | Seis cosas comprobables. **Ni una métrica** |
| 8 | **Preguntas** | `faq` | sí | Ocho, de las que se hacen de verdad. Van **antes** del formulario |
| 9 | **¿Eres creador?** | `plain` | no | Deriva al otro público sin competir |

Cuatro entradas en el menú y no nueve: un menú con nueve anclas no es un menú.

---

## 4. Lo que NO dice la portada

El §12 prohíbe inventar creadores, campañas, clientes, países activos, ROI,
testimonios, casos de éxito, premios, métricas y años de experiencia. Se cumple,
y **hay una prueba que lo afirma**: `test_la_portada_no_presume_de_numeros_que_no_existen`
recorre todos los textos sembrados y se pone roja si aparece «creadores activos»,
«campañas realizadas», «clientes satisfechos», «años de experiencia», «de ROI» o
«millones de».

No es una prueba de estilo. Es la única forma de que una cifra inventada no entre
por descuido en una revisión de copy dentro de seis meses.

La franja de confianza lo dice en su propia bajada, que era la alternativa
honesta a fingir tracción:

> *Aquí no vas a encontrar cifras de vanidad ni logos de clientes: no vamos a
> inventar los que todavía no tenemos. Lo que sigue se puede comprobar hoy.*

Y tampoco promete plataforma self-serve (§27, `R-1`): en ningún sitio se insinúa
«entra y lanza tu campaña». Lo que se promete es **campaña gestionada con la
trazabilidad de una plataforma**, que es lo que se puede cumplir el primer día.

---

## 5. La razón social no está escrita en el texto

La franja «por qué confiar» dice la razón social de la empresa y su
identificador fiscal. Escribirlos dentro del texto sería `DEC-190` roto en el
peor sitio: el día que la empresa cambie de nombre habría que **editar la portada
a mano buscando dónde se nombra a la empresa**.

Así que el texto sembrado dice, literalmente:

> `{{ empresa.razon_social }}, con {{ empresa.documento }} público.`

Es el mismo motor de marcadores que la `L-2b` construyó para los documentos
legales (`Reemplazos`), aplicado ahora a todo lo que se lee en la portada:
titular, bajada, encabezados de franja, bloques y el cierre. Y hay dos pruebas:
una comprueba que el marcador se resuelve, y **la otra cambia la razón social y
exige que cambie la portada**.

Lo que no se puede resolver sale en la calle como una raya `—` y en el panel
**en rojo**, con el nombre del marcador que falta.

Nota de rendimiento, porque importa: la tabla de valores se resuelve **una vez
por página** y se pasa a cada sustitución. `aplicar()` la habría reconstruido en
cada texto, y son sesenta: sesenta consultas por la sociedad operadora para
pintar una página que mira quien todavía no es cliente. Se añadió
`Reemplazos::conValores()` en vez de una memoria estática, precisamente por
`T-90`.

---

## 6. El héroe deja de estar medio vacío

`V-2`: en escritorio el héroe era un titular sobre 800 px de degradado liso.

**No se pone una fotografía**, y conviene decir por qué antes de que parezca un
descuido: no tenemos ninguna que sea nuestra. Poner caras de banco de imágenes
junto a «creadores reales» es la misma clase de mentira que el §12 prohíbe —no es
una métrica inventada, pero se lee igual de falso, y lo nota cualquiera que haya
visto esa foto en otro sitio—.

Lo que hay es una composición que **explica el modelo**: muchos puntos pequeños
—comunidades— unidos por líneas finas a uno solo. Es el titular hecho dibujo. Pesa
menos de 2 KB, es parte del HTML, no pide ninguna petición más, flota muy despacio
y se queda quieta con `prefers-reduced-motion`. En móvil no sale: ahí el titular y
el botón son lo único que tiene que trabajar.

El día que haya fotografías de campañas de verdad, esto se sustituye por ellas.

---

## 7. El cierre deja de repetir el botón

`C-3`: la misma frase salía **tres veces** —botón del héroe, título de la sección
de cierre y botón de enviar— porque los tres leían `cta_label`. Lee como una
plantilla rellenada, no como una página escrita.

El formulario es **código** —tiene su validación, su campo trampa y su
`throttle`, y eso no puede ser un dato— pero **sus palabras no lo son**. Dos
columnas nuevas: `form_heading` y `form_intro`. Vacías, se sigue usando el botón:
nada bloquea (`DEC-190`).

Y al lado, el WhatsApp: quien no quiere rellenar siete campos tiene el canal de
menor fricción de LATAM a un clic. Sale de «Sitio público»; sin configurar, no se
pinta.

---

## 8. Decisiones

### `DEC-288` · El titular de fábrica se corrige; el que alguien escribió, no

La migración compara el titular **entero** con el que sembró `9.21b` y sólo
entonces lo sustituye. Es el criterio de `L-1` con los colores, y la razón es la
misma: una migración que pisa el trabajo de quien administra es peor que una
migración que no hace nada, porque el daño se descubre tarde y no se puede
deshacer. El titular nuevo **no está aprobado**: es la recomendación de la
auditoría, y se cambia desde el panel sin desplegar.

### `DEC-289` · Lo que la portada dice de la empresa sale de la configuración

La razón social y el identificador fiscal viajan como marcadores, no como texto.
El motor es el mismo de los documentos legales de la `L-2b`, y el criterio
también: escribir un dato de la empresa dentro de un texto largo es garantizar
que el día que cambie, alguien tenga que buscarlo a mano. Un marcador sin valor
sale como una raya en la calle y **en rojo** en el panel.

### `DEC-290` · Antes que una fotografía falsa, un dibujo que explica el modelo

No hay fotografías propias, y las de banco de imágenes junto a «creadores reales»
se leen tan falsas como una métrica inventada (§12). La composición del héroe no
decora: dibuja lo que dice el titular. Se sustituye por fotografía real el día que
la haya, y el esquema ya admite imágenes por bloque (`image_file_id`, `L-3`).

### `DEC-291` · Las palabras del formulario son datos; el formulario no

`form_heading` y `form_intro` en `landing_pages`. La validación, el campo trampa
y el `throttle` siguen siendo código —eso no se configura desde un panel— pero el
encabezado del cierre no puede ser el texto del botón, que es lo que producía la
misma frase tres veces.

---

## 9. Lo que se aprendió

**Renombrar contenido rompe las pruebas del contenido.** Tres pruebas de la `L-3`
se pusieron rojas al sembrar la narrativa nueva: dos buscaban la franja `por-que`
—que en marcas ya no existe— y una creaba una franja «Por qué confiar» que ahora
**choca** con la sembrada. Ninguna era un defecto del producto, pero enseñan algo
real: una prueba que se apoya en el **contenido de fábrica** se rompe cada vez que
el contenido cambia. Las tres se reescribieron para apoyarse en la *forma* —que
el menú de una página no ofrece las anclas de la otra— en vez de en un ancla
concreta, y de paso quedaron mejores: ahora comprueban **las dos direcciones**,
porque con una sola un menú que siempre usara la portada de creadores también
habría pasado.

**Un 302 donde se esperaba un 200 dijo algo del producto.** La prueba del editor
enviaba el formulario sin la casilla «publicada», y `Landing::guardar()` la
interpreta —correctamente— como «apagada», así que `/` redirigía al acceso. En la
pantalla real la casilla siempre viaja, pero queda anotado: **guardar la portada
sin esa casilla la despublica**, y eso es exactamente lo que tiene que pasar.

---

## 10. Lo que NO se hizo, y por qué

| Qué | Dónde va |
|---|---|
| Fotografías reales en las franjas y en el héroe (`V-1`) | Cuando existan. El esquema ya las admite; falta una **puerta pública** para servirlas, porque `/archivos/{uuid}` exige `file.view` y la portada la mira quien no tiene cuenta |
| El formulario corto (`C-7`) y el país por defecto Perú (`C-2`) | **L-5** |
| Enganchar un proveedor de analítica a los `data-evento` | **L-5** |
| Los rótulos de la plantilla a archivos de idioma (`R-2`) | **L-6** |
| La portada de creadores con su propia narrativa | Después de la `L-7`. Esta iteración es de **marcas**, que es el lado que paga |

---

## 11. Lo que hay que hacer al desplegar

1. `php artisan migrate` — añade las dos columnas del cierre y **corrige el
   titular sólo si sigue siendo el de fábrica**.
2. `php artisan db:seed --class=CimientosSeeder` — siembra las nueve franjas.
   Sólo lo que falta (`T-77`): no devuelve nada a los valores de fábrica.
3. `npm run build`.
4. En **Entidades legales**, comprobar que la razón social y el RUC están
   completos: la franja «por qué confiar» los nombra, y si faltan salen como una
   raya (el panel lo avisa en rojo).
5. Leer la portada entera y cambiar lo que no te suene tuyo. Todo se edita desde
   Portada pública.

---

## 12. Comprobado

| | |
|---|---|
| Pruebas | 1 249 pruebas / 4 393 aserciones |
| SQL, MariaDB | 2 632 aserciones, 0 fallidas |
| SQL, MySQL 8 | 2 622 aserciones, 0 fallidas |
| Puertas | Las seis en verde |
| Migración con datos antiguos | Probada: titular de fábrica corregido, titular propio intacto |
| Mirado en pantalla | 1440 px y 390 px, la portada entera, la consola limpia |
