# L-1 — La marca de verdad

> El sitio dejaba de parecer una demo de Tailwind y empieza a parecer
> LATAM Social. Sin tocar ni una sección.

---

## 1. El hallazgo

Existe un sistema de diseño aprobado —`design/tokens.css` y
`docs/14-BRAND-AND-DESIGN-SYSTEM.md`— con esta regla escrita:

> *El naranja y el magenta de marca existen SOLO dentro del degradado. En
> interfaz, el único color de marca plano es el morado.*

Y el degradado canónico es `#FF7447 → #D73382 → #6635D8` **a 45°**.

**Lo que estaba publicado no era eso:**

| | Antes | Ahora |
|---|---|---|
| Color 1 | `#7C3AED` — **violeta 600 de Tailwind** | `#6635D8` |
| Color 2 | `#22D3EE` — **cian 400 de Tailwind** | `#D73382` + `#FF7447` |
| Degradado | morado → cian, a 135° | naranja → magenta → morado, a 45° |
| Titulares | *no existía* | Sora |
| Logotipo | un cuadrado de color | el logotipo |

El héroe que veía un visitante era **el degradado por defecto del framework**.

---

## 2. Tres paradas, y sólo una columna nueva

El degradado de marca tiene tres paradas y el esquema guardaba dos colores.
Pintarlo con dos se salta el magenta, que es el tercio central.

La tercera no hace falta inventarla: **el degradado termina en el morado, que es
el mismo color plano de la marca**. Así que:

| Columna | Papel |
|---|---|
| `gradient_from` *(nueva)* | El naranja. Sólo vive en el degradado |
| `secondary_color` | El magenta. La parada de en medio |
| `primary_color` | El morado. Final del degradado **y** color plano de la interfaz |

Una columna en vez de tres, y los nombres siguen diciendo la verdad. Más
`gradient_angle` —porque 45° es un dato de marca, no una constante que le toque
decidir a una plantilla— y `display_font_family`, porque `docs/14 §5` separa la
letra de **titulares** de la de **interfaz** y una sola familia no puede
expresarlo.

### La corrección no pisa lo que alguien haya decidido

Los valores se corrigen **sólo si siguen siendo exactamente el par de fábrica**
`#7C3AED` / `#22D3EE`. Quien ya puso sus colores —esto es white label— se los
queda. Es `sembrarSiFalta` de `T-77` aplicado a una corrección.

---

## 3. El logotipo llevaba en el repositorio desde agosto

`public/img/brand/logo-horizontal.svg`, desde el 22 de agosto, **sin que lo
referenciara nadie**.

`9.17` decidió —con razón— no pintar un `<img>` a una ruta que devolvería 404, y
dibujaba un cuadrado de degradado en su lugar. El razonamiento era correcto y la
premisa era falsa: **sí había un logotipo**.

Y son **dos** variantes, que tampoco es estética: el horizontal mide 1122×530, así
que metido en un hueco cuadrado queda del alto de un sello. `docs/14 §7` reparte:
horizontal en las landings —«es donde la marca tiene que explicarse»— e isotipo en
el back-office, junto al nombre en texto.

El aviso de «no hay logotipo» **baja de rojo a ámbar**, porque cambió el hecho: ya
no hay nada roto que enseñar, sólo conviene subir el propio en una instalación de
otra marca.

---

## 4. Lo que lee un buscador

`og-image.png` existía —92 KB, hecha a propósito— y **no la referenciaba nadie**.
Compartir el enlace por WhatsApp o LinkedIn producía **una tarjeta sin imagen**, y
este sitio va a recibir su tráfico justamente de ahí: una fuga de clics en el
primer metro.

Ahora el `<head>` lleva `canonical`, `og:url`, `og:site_name`, `og:locale`,
`og:image` con sus medidas, tarjeta de Twitter/X, `theme-color` y el manifiesto.

**`robots.txt` y `sitemap.xml` son rutas, no archivos**, porque los dos dependen
de la configuración:

- El mapa lista **sólo las portadas publicadas**. Apagar una desde el admin la
  quita del mapa.
- `robots.txt` dice **`Disallow: /`** en una instalación que no es producción
  (`9.22a`). Un servidor de pruebas indexado compite en Google con el de verdad y
  le roba las visitas, y eso se descubre meses después.

Y el manifiesto estaba roto: apuntaba a `/img/favicon-192.png` cuando los archivos
están en `/img/brand/`, y declaraba `start_url: /creators/` — una ruta en inglés
que no existe.

---

## 5. Cuatro cosas que salieron al construirlo

### 5.1 El `https` de producción

Detrás de un proxy que termina el TLS, Laravel ve la petición como `http` y
`url()->current()` devuelve `http://…`. Eso va directo al `canonical`, al
`og:url` y al `sitemap.xml`: **el sitio se declara a sí mismo en la URL
equivocada** ante Google y ante quien comparte el enlace.

No da ningún error. Se descubre meses después, cuando alguien pregunta por qué el
dominio no posiciona. Se fuerza el esquema desde `APP_URL`, que es donde ya está
escrito cómo se sirve esto.

### 5.2 Una página en español declarada en inglés

`APP_LOCALE` no viene puesto de fábrica: el valor por defecto de Laravel es `en`.
Una instalación a la que se le olvide publica `<html lang="en">` sobre texto en
español. Lo leen un buscador y un lector de pantalla, **ninguno de los dos avisa**,
y no se nota mirando la página. Ahora hay un aviso ámbar que lo dice.

### 5.3 `is_published` vivía en un solo consumidor

La regla «una portada apagada no se sirve» estaba escrita **dentro de
`PortadaController`**, el único que la había necesitado. Al escribir el
`sitemap.xml` salió lo que eso significa: **el mapa ofrecía a un buscador una
portada apagada.** La regla se muda a `Landing::portada()`, que es donde el
segundo consumidor la hereda sin volver a escribirla.

### 5.4 El recolector de esquema se tragaba tipos de columna en silencio (`T-89`)

`tools/recolectar-esquema.php` no conocía `uuid()` ni `mediumText()`, y su
`__call` los **devolvía sin registrar la columna**. Resultado:
`verificar-migraciones.py` llevaba desde `9.9d` denunciando tres columnas «que la
migración no crea» **y que la migración sí crea**.

Un verificador que miente es peor que no tenerlo: se deja de mirar, y el día que
dice algo cierto tampoco se mira. Se añaden los tipos que faltaban y **`__call`
ahora revienta con un mensaje que dice qué hacer**, en vez de tragar.

Quedan de 4 discrepancias a **1**, y la que queda es real y ajena: MariaDB
implementa `JSON` como `LONGTEXT`, así que `email_templates.variables` sale
distinta en los dos sitios diciendo lo mismo.

---

## 6. Y dos cosas que sólo se vieron mirando la pantalla

Compilé Tailwind, sembré, levanté la aplicación y miré, en 1440 y en 390 px:

- **El logotipo salía a 59 px de ancho y no se leía.** Es un lockup de dos líneas
  (relación 2,1:1), así que `h-7` no basta. Ahora `h-8` en móvil y `h-10` en
  escritorio — porque a `h-10` en 390 px ocupaba media cabecera y apretaba contra
  «Entrar».
- **La columna «Contacto» del pie salía con el encabezado y nada debajo**, porque
  no hay correo ni WhatsApp configurados todavía. Ahora la columna entera
  desaparece: un encabezado sobre un hueco promete algo que no está.

Ninguna prueba habría visto ninguna de las dos. Es la lección de `9.17i`.

---

## 7. Comprobación

`tests/Feature/MarcaDeVerdadTest.php` — **16 pruebas**.
`tools/pruebas/L1-marca.sh` — **10 aserciones SQL**.

Totales: **1 199 pruebas / 4 184 aserciones**; **2 524 aserciones SQL en MariaDB**
y **2 514 en MySQL 8**; **las seis puertas en verde**.

### Y tres pruebas se pusieron rojas solas, que es para lo que están

- **`NavegacionTest`** — «todo lo que exige sesión vive bajo `/backoffice`». Las
  dos rutas nuevas del buscador tuvieron que escribirse en su lista con el
  motivo. Dejar algo fuera de `/backoffice` tiene que ser una decisión.
- **`MarcaPlataformaTest`** — el aviso del logotipo bajó de rojo a ámbar y el
  color de partida cambió. Las dos aserciones dijeron exactamente lo que había
  cambiado.
- **`ConfiguracionTest`** — «quien lleva finanzas no ve el área de Marca». Se
  puso roja por un motivo que no esperaba: el nombre de una clase escrito en un
  **comentario de CSS** salía en la página. De ahí salió el arreglo de peso: los
  comentarios `/* */` dentro de `<style>` **se mandan al navegador en cada
  petición**. Ahora son comentarios de Blade, que se quedan en el servidor.

---

## 8. Qué hay que hacer en el servidor

1. `git pull` y `php artisan migrate` — la migración corrige los colores sola.
2. `npm run build` — hay clases nuevas (`texto-degradado`, `fuente-titulos`).
3. Comprobar que `.env` tiene **`APP_LOCALE=es_PE`** y **`APP_URL=https://latamsocial.com`**.
   Sin el segundo, el `canonical` sale en `http`.

Nada más. El logotipo, el favicon y la imagen para compartir ya están en el
repositorio.
