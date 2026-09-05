# L-7 — «El barrido: usar el sitio, no leerlo»

> Iteración cerrada. Landing comercial v1, séptima y última entrega.

---

## 1. Cómo se hizo

No se leyó el código. Se levantó el sitio con datos completos —WhatsApp, correo,
teléfono, dirección, tres redes, las dos páginas legales publicadas— y se **usó**:

- Las cinco páginas públicas a **1440, 834 y 390 px**, con la consola abierta.
- Cada enlace de cada página, comprobado.
- **Los dos formularios enviados de verdad**, incluidos el caso inválido, el
  duplicado y el campo trampa.
- El teclado, tabulando desde cero.
- El contraste, **medido**, no mirado.
- `robots.txt`, `sitemap.xml`, un 404 y una URL muerta.

Es la misma decisión que abrió la auditoría: en `9.17i` el hallazgo más caro del
proyecto —130 controles sin borde visible— **no lo encontró ninguna prueba**, lo
encontró mirar la pantalla. Esta vez tampoco.

---

## 2. Lo que salió, en orden de gravedad

### 🔴 2.1 · El sitio entero pintaba las claves de traducción

Con **`APP_LOCALE=es_PE`** —que es exactamente lo que la `L-6` le pide poner al
operador— la cabecera decía `publico.entrar` y el pie `publico.pie.para_marcas`.
En todas las páginas públicas. **Con un 200 y sin un solo error en consola.**

Laravel busca `lang/es_PE/`, no lo encuentra, cae en el respaldo `en` —que este
proyecto no tiene— y devuelve la clave. Antes de la `L-6` no había carpeta
`lang/` y por eso no pasaba: **la `L-6` lo introdujo y su propia prueba no lo
vio**, porque comparaba la página con `__('publico.entrar')`, que devuelve la
misma clave cuando falta la traducción. Una tautología en verde. **Sexta vez en
este proyecto que una aserción pasa por el motivo equivocado.**

Corregido en el arranque —no en el `.env`, porque un `.env` se olvida y lo que se
olvidaría es la única línea que impide que el sitio salga así— y sólo cuando el
respaldo configurado no existe: quien ponga `APP_FALLBACK_LOCALE=pt` a propósito
y tenga `lang/pt/`, manda. La prueba nueva se rompió a propósito para comprobar
que caza el defecto.

### 🔴 2.2 · Los mensajes de error decían `validation.email`

El mismo agujero, en el sitio donde más duele: **en la cara de quien intenta
escribirnos**. Antes de la `L-6`, sin `lang/`, Laravel usaba sus traducciones
internas y el mensaje salía en inglés pero era una frase. Al crear la carpeta, el
traductor pasó a buscar ahí y empezó a devolver la clave.

Lo encontró **enviar el formulario con un correo mal escrito**, que es una cosa
que ninguna prueba hacía.

Ahora hay `lang/es/validation.php`, `auth.php` y `passwords.php`, con las reglas
que este proyecto usa de verdad —no las noventa de Laravel: lo que no se usa no
se puede comprobar— y con `attributes`, para que el mensaje diga *«Falta la
empresa o marca»* y no *«Falta company_name»*, que es el nombre de una columna y
no el del hueco que la persona está mirando.

### 🟠 2.3 · Los campos del formulario público no tenían relleno

`class="mt-1 w-full rounded-lg border border-slate-300"` — **sin `px` ni `py`**.
Once controles, en las dos portadas. En un teléfono medían **22 px de alto**:
difíciles de acertar y apretados de leer. Los formularios del panel sí lo
llevaban.

Es exactamente la misma familia que el hallazgo de `9.17i`: una clase de
utilidades a la que le falta una, y como el borde sí está, **parece un campo
terminado**.

### 🟠 2.4 · Contraste por debajo del mínimo en el pie, medido

| Qué | Ratio | Mínimo |
|---|---|---|
| «Plataforma», «Contacto», «Legal» (12 px) | 2.51 : 1 | 4.5 : 1 |
| La línea con la razón social y el RUC (12 px) | 2.51 : 1 | 4.5 : 1 |
| «(opcional)» y el «+» de las preguntas | 2.63 : 1 | 4.5 : 1 |
| El pie legal de la pantalla de acceso | 4.05 : 1 | 4.5 : 1 |

La segunda fila es la que más molesta: **la línea que hay obligación de poder
leer era la menos legible de la página**.

Y aquí hubo que corregir la herramienta antes que el producto: la primera
medición daba números absurdos porque Tailwind 4 devuelve los colores en
`oklch()` y el lector los troceaba como si fueran `rgb()`. Se resolvió pintando
cada color en un lienzo de 1×1 y leyendo el píxel: el navegador hace la
conversión. **Una medición que miente es peor que no medir.**

### 🟡 2.5 · Objetivos táctiles por debajo de 24 px en móvil

Los sumarios de las preguntas (20 px), los enlaces del pie (16 px), los iconos de
redes (20 px), el pliegue «Añadir teléfono y web» (20 px) y la fila «Mantener la
sesión» de la pantalla de acceso (13 px, que es el cuadradito).

Todos corregidos con relleno o con un área mínima. **Se quedan dos, y se dice por
qué:** «Háblanos por WhatsApp» y «política de privacidad» son enlaces **dentro de
una frase**, y ahí agrandar el área rompería el renglón; la norma los exime por
eso mismo.

### 🟡 2.6 · El mapa del sitio no incluía las páginas legales

La política de privacidad y los términos son páginas públicas, con su URL y su
contenido, y no estaban en `sitemap.xml`. Ahora salen las que tienen versión
vigente: **si sale en el pie, un buscador tiene que poder encontrarla.**

---

## 3. Lo que se comprobó y estaba bien

- **Los dos formularios llegan a la base** con los campos correctos, país incluido
  (Perú, sin que nadie lo elija).
- **El campo trampa funciona**: responde «gracias» y **no escribe nada**.
- **El anti-duplicado funciona**, y lo dice con una frase amable en vez de con un
  error.
- **El pliegue de los campos opcionales vuelve abierto** cuando la validación
  rebota con algo escrito dentro.
- **Ninguna página desborda a lo ancho** en ninguno de los tres tamaños.
- **El foco se ve en los ocho primeros saltos de tabulación**, y el primero es el
  salto al contenido.
- `robots.txt` dice `Disallow: /` porque esta instalación **no es producción**.
- Un 404 es un 404, y `/panel` —una URL muerta desde `9.21a`— también.
- **Ni un error de JavaScript** en ninguna página.

---

## 4. Lo que NO se pudo comprobar, y se dice

- **Que las tipografías cargan.** Este contenedor no alcanza `fonts.bunny.net`, así
  que en todas las páginas hay un `ERR_TUNNEL_CONNECTION_FAILED` que en producción
  no debería estar. Lo que sí está comprobado es que la página **se ve bien sin
  ellas**: con `display=swap` sale con la tipografía del sistema y no en blanco.
- **El correo.** La portada no manda ninguno; el que sí sale —el aviso a los
  creadores al publicar términos— vive en `9.19b` y su barrera es de `9.22b`.
- **La medición de verdad.** Aquí no se emite por diseño (`DEC-294`), así que lo
  que se comprobó es que **no** se emite, no que Google la reciba.
- **Un lector de pantalla.** Hay `lang`, salto al contenido, foco visible,
  encabezados en orden y `aria-hidden` donde toca, pero eso es lo que se puede
  afirmar leyendo el HTML. Oírlo es otra cosa, y no se ha hecho.

---

## 5. Decisiones

### `DEC-299` · El idioma de reserva es uno que exista, y se decide en el arranque

Laravel trae `en` de fábrica y este proyecto no lo tiene. Poner
`APP_FALLBACK_LOCALE=es` en el `.env` habría funcionado y habría sido la peor
solución posible: un `.env` se olvida, y lo que se olvidaría es la única línea que
impide que el sitio salga en clave —sin dar ningún error—.

### `DEC-300` · Una aserción no se compara con la misma función que se está probando

`assertSee(__('publico.entrar'))` pasaba en verde con el sitio entero en clave,
porque `__()` devuelve la clave cuando falta la traducción. Se afirma **la
palabra**. Es la sexta vez que aparece este patrón y la primera en que se afirma
como regla: *una prueba que usa el mecanismo que está probando no prueba nada.*

### `DEC-301` · El contraste se mide, no se mira; y la medición se comprueba antes de creerla

La primera pasada denunció doce textos y **los doce eran falsos**: Tailwind 4
devuelve `oklch()` y el lector los troceaba como `rgb()`. Un verificador que grita
por nada enseña a ignorarlo. Se resolvió pintando cada color en un lienzo y
leyendo el píxel.

---

## 6. Lo que queda abierto

| # | Qué | Quién |
|---|---|---|
| `T-09` | Que un abogado revise los términos | Tu abogado |
| `T-94` | Que la política declare la tipografía servida por un tercero y la medición | Tu abogado |
| `T-95` | Traducir el contenido, no sólo los rótulos | Desarrollo, cuando haya un segundo país |
| `T-96` | La pantalla de acceso da por supuesto que la barra es **oscura** | Desarrollo |
| — | Fotografías reales | Cuando existan |
| — | Un lector de pantalla de verdad | Antes de presumir de accesible |

---

## 7. Al desplegar

1. `php artisan migrate` — **esta iteración no trae migración**.
2. `npm run build` — hay CSS nuevo (relleno de campos, áreas táctiles, contraste).
3. `.env`: `APP_LOCALE=es_PE` y `APP_URL=https://latamsocial.com`. Con esto el
   `robots.txt` pasa a permitir la indexación **sólo si `APP_ENV=production`**.
4. Configura **Sitio público** y completa el domicilio en **Entidades legales**.
5. Lee, ajusta y **publica** las dos páginas legales: se siembran como borrador a
   propósito, porque publicar es un acto con un responsable.

---

## 8. Comprobado

| | |
|---|---|
| Pruebas | 1 280 pruebas / 4 569 aserciones |
| SQL, MariaDB | 2 652 aserciones, 0 fallidas |
| SQL, MySQL 8 | 2 642 aserciones, 0 fallidas |
| Puertas | Las seis en verde |
| Páginas × tamaños | 5 × 3, con la consola abierta |
| Formularios | Los dos, enviados de verdad; inválido, duplicado y trampa |
| Contraste | Medido en las cuatro páginas: todo por encima del mínimo |
| Los dos arreglos rojos | **Comprobados rompiéndolos**: sin ellos, las pruebas se ponen rojas |
