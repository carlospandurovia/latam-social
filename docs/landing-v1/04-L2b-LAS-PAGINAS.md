# L-2b / L-2c — Páginas, y los dos documentos legales

> *«en el admin debe haber una sección de "pages" donde se agreguen estas páginas
> por base de datos… lo harás pensando en altos estándares de la industria y será
> un modelo profesional y listo para usar, sólo reemplazado con los valores que se
> leerán desde el admin»*

---

## 1. Lo primero: qué NO se duplica

`terms_versions` existe desde `9.16` y ya es un sistema de documentos legales
completo: versión, huella `SHA-256`, inmutabilidad al publicar, vigencia, estado
de revisión jurídica y tipo de cambio. Es **el contrato que un creador acepta con
un clic registrado**, y `terms_acceptances` apunta a una versión concreta.

**Eso no se duplica.** Si «términos y condiciones» viviera también en la tabla
nueva habría dos verdades sobre lo mismo y ninguna forma de saber cuál rige.

Lo que faltaba y es esto: las **páginas públicas del sitio** —privacidad, aviso
legal, «sobre nosotros», cookies—. Nadie las acepta con un clic; se publican y se
leen.

---

## 2. Qué hay ahora

**Configuración → Páginas** (`/backoffice/paginas`):

- Crear cuantas páginas quieras, cada una con su dirección, su título y su
  descripción para buscadores.
- Un **editor** con el texto en Markdown, la **vista previa con los marcadores ya
  sustituidos** —lo que va a ver un visitante, no el código fuente— y la lista de
  marcadores con su valor de hoy.
- **Publicar** con fecha de vigencia. Publicar la siguiente cierra la anterior el
  día antes.
- **Anotar la revisión jurídica**: sin revisar / en revisión / revisado, con nota.
- El **historial** de versiones.

Y en la calle: `latamsocial.com/politica-de-privacidad`, más la columna **Legal**
del pie con las páginas publicadas.

---

## 3. Los marcadores

El texto legal **no lleva escrita la razón social**. Lleva `{{empresa.razon_social}}`,
y el valor sale de donde ya vive.

| Prefijo | De dónde sale |
|---|---|
| `marca.` | `platform_brands` |
| `empresa.` | La **sociedad operadora** declarada en Sitio público |
| `sitio.` | `site_settings` |
| `pagina.` | La propia página |

Escribir «Soluciones Tecnológicas a Medida S.A.C.» dentro del cuerpo sería
`DEC-190` roto en el peor sitio posible: el día que cambie la razón social —o el
día que otra marca use esta plataforma— habría que editar a mano un documento
legal buscando dónde se nombra a la empresa.

**Un marcador que no se resuelve NO sale entre llaves.** Un
`{{empresa.razon_social}}` visible en `latamsocial.com/politica-de-privacidad`
dice que el documento está a medio hacer. Sale una raya, y **el área avisa en
rojo** nombrando el marcador que falta.

Y esto **no es una plantilla de Blade**: pasar contenido de la base por el motor
de plantillas sería ejecución de código desde la base de datos. Aquí sólo se
sustituye texto por texto: no hay condicionales, ni bucles, ni forma de que un
marcador ejecute nada.

---

## 4. Seguridad: el cuerpo es Markdown, no HTML

Un documento legal necesita títulos, listas y tablas, así que texto plano no vale.
Y **HTML crudo editable desde el panel es XSS almacenado en la página más pública
del sitio**: quien edite —o quien le robe la sesión a quien edite— escribiría
`<script>` en `latamsocial.com`.

`Marcado::aHtml()` convierte con `league/commonmark` —ya instalado, cero
dependencias nuevas— con `html_input: escape` y `allow_unsafe_links: false`. El
HTML que venga dentro **se enseña como texto**. Hay pruebas que lo afirman con un
`<script>` y con un enlace `javascript:`.

---

## 5. El versionado, y por qué lo lleva una página

Porque de una política de privacidad hay que poder contestar **«¿cuál estaba
vigente el día que esta persona nos dio sus datos?»**, y esa pregunta no se
contesta con «la de ahora».

- **Una versión publicada no se reescribe** (`tg_cpv_inmutable`): es el texto que
  alguien pudo haber leído. Se publica la siguiente.
- **Una sola vigente** por página — columna puerta **36**, índice único.
- **Y el histórico no se solapa** (`cpv_sin_solape`): dos versiones tapando el
  mismo día son dos respuestas a la pregunta de arriba, y dos respuestas es
  ninguna.

La revisión jurídica **sí** se puede anotar sobre una publicada: el disparador
protege el **texto**, no el estado de la revisión. Al revés sería absurdo — un
abogado revisa justamente lo que ya está publicado.

---

## 6. Una página no puede tapar una pantalla

Si alguien crea la página `creadores`, la portada de creadores deja de abrirse.

La lista de direcciones prohibidas **no está escrita a mano**: se calcula
preguntándole al enrutador cuáles son sus primeros segmentos. Una lista escrita se
queda vieja el día que se añade una ruta, y el fallo aparece meses después con la
forma de «una portada dejó de funcionar».

La ruta comodín `/{slug}` va **la última del archivo**, y hay una prueba que
comprueba que `/creadores`, `/entrar` y `/robots.txt` siguen resolviéndose.

---

## 7. Los dos documentos

Escritos a estándar de industria: la política siguiendo la **Ley N.º 29733** de
Protección de Datos Personales del Perú y su Reglamento (D.S. 003-2013-JUS).

**La política de privacidad** cubre: responsable, qué datos y de quién —marcas,
creadores, **menores de edad con autorización de su tutor**, datos técnicos—, para
qué y con qué legitimación (en tabla), con quién se comparten, transferencias
fuera del país, plazos de conservación —**incluido que lo fiscal no se puede
borrar aunque lo pidas**—, derechos ARCO y cómo ejercerlos, seguridad, cookies,
cambios y contacto.

**Los términos** cubren: quién opera, qué se acepta, **qué hacemos y qué no** —con
un apartado explícito de «no garantizamos resultados» y «no somos autoservicio»
(§27)—, uso del sitio, cuentas, propiedad intelectual del contenido del creador,
precios y comprobantes, responsabilidad, enlaces de terceros, cambios, ley
aplicable y separabilidad.

### §56 al pie de la letra

**Ninguno de los dos lo ha revisado un abogado, y los dos lo dicen en su primera
línea.** Se siembran con `review_status = 'sin_revisar'` y el área avisa en ámbar
mientras siga así, con estas palabras: *«es un texto de partida escrito a estándar
de industria, no un dictamen»*.

No es un trámite: es que un supuesto legal se identifica explícitamente. **Cierra
la mitad de `T-09` que se podía cerrar** —había un texto que revisar— y deja la
otra mitad donde estaba: hace falta el abogado.

### Y se siembran **como borrador**, nunca publicados

`ck_cpv_publicada` dice que publicar es un acto con responsable, y al sembrar no
hay ninguno. Atribuírselo al usuario de id más bajo sería poner el nombre de una
persona al pie de un documento legal que no ha leído.

**Que la primera publicación de la política de privacidad la haga una persona, con
un clic, no es un rodeo: es la parte del proceso que importa.** El área lo pide en
rojo hasta que ocurra, y mientras tanto el pie no enseña un enlace roto.

---

## 8. Cuatro cosas que salieron construyéndolo

### 8.1 La tabla del documento salía rota, y se vio mirando

**CommonMark no lleva tablas**: son una extensión de GitHub, no del estándar. La
tabla de «para qué usamos los datos y con qué legitimación» salía como un párrafo
lleno de barras verticales. Un documento legal con una tabla rota se lee como un
documento a medio hacer, y ésa es justo la tabla que alguien consulta.

Se añade **sólo** `TableExtension`, no el paquete entero de GitHub: los enlaces
automáticos y las menciones de `@usuario` no pintan nada en una página legal, y
cada extensión es superficie.

### 8.2 Y el domicilio decía «Por completar»

También mirando la página publicada. La sociedad se siembra con
`address_line1 = 'Por completar'` para que exista, y eso salía tal cual en el
documento. **Un marcador que se resuelve no es lo mismo que un marcador que se
resuelve bien**, y esto lo lee un tercero. Ahora hay un aviso ámbar que lo dice.

### 8.3 `verificar-periodos.py` hizo una pregunta de diseño

Avisó de que `content_page_versions` tiene forma de periodo y ninguna regla de
solape. Tenía razón, y la pregunta es exactamente la que importa aquí: *«¿qué
política regía el día que esta persona nos dio sus datos?»*. Se añadió
`cpv_sin_solape`.

De ahí salió otra: **el disparador de solape dispara antes que el índice único**,
así que la aserción que decía esperar `uq_cpv_vigente` afirmaba algo que ya no
pasaba. Se cambió al mensaje que **realmente** sale. La columna puerta no sobra
por eso: un disparador **lee otras filas**, y dos transacciones simultáneas pueden
leer las dos antes de que ninguna escriba. El índice único es lo único que aguanta
esa carrera.

### 8.4 El trinquete de cobertura sube 2, con el motivo escrito

`verificar-cobertura-sql.py` reconoce una regla **por su nombre dentro del error**,
y un `SIGNAL` sólo devuelve su mensaje. Por eso **las 16 reglas de solape del
proyecto** están en su lista, incluidas diez que llevan meses comprobadas. Las dos
mías están preguntadas —hay una aserción por cada mitad, alta y modificación— y el
techo sube de 139 a 141 con la explicación escrita en el propio verificador.

No se arregla poniendo el nombre del disparador dentro del mensaje: los mensajes
los lee una persona, y `tg_cpv_sin_solape_ins` no le dice nada a nadie.

---

## 9. Comprobación

`tests/Feature/PaginasDelSitioTest.php` — **22 pruebas**.
`tools/pruebas/L2b-paginas.sh` — **23 aserciones SQL**.

Totales: **1 221 pruebas / 4 282 aserciones**; **2 568 aserciones SQL en MariaDB**
y **2 558 en MySQL 8**; **las seis puertas en verde**.

### Y una prueba de otra iteración se puso roja, con toda la razón

**`AterrizajeTest` — «una dirección guardada que ya no existe no manda a un
404».** La ruta comodín `/{slug}` **resucitó todas las direcciones muertas de un
solo segmento**: `/panel` volvía a «resolver» —contra el comodín— y la puerta de
entrada lo daba por bueno. El síntoma habría sido idéntico al de `9.21a`: entrar
con la contraseña correcta y aterrizar en un 404, **a todo el mundo, el día del
despliegue**.

Es la clase de daño que hace una ruta comodín: no rompe nada de lo suyo, revive
lo ajeno. Ahora `sigueExistiendo()` descuenta la ruta `pagina`.

Lo encontró la prueba que existe justamente por aquel fallo. Sin ella esto se
habría entregado.

---

## 10. Qué hay que hacer en el servidor

1. `git pull`, `php artisan migrate`, `php artisan db:seed --class=CimientosSeeder`
2. `npm run build`
3. **Configuración → Sitio público**: declarar la sociedad operadora y poner el
   correo de contacto. Sin eso, los documentos no nombran a nadie.
4. **Completar el domicilio de la sociedad** en Entidades legales: hoy dice «Por
   completar» y eso sale en la política.
5. **Configuración → Páginas**: leer los dos textos, cambiar lo que no encaje, y
   **publicarlos**.
6. Llevarlos a un abogado, y anotar la revisión aquí cuando la haga.
