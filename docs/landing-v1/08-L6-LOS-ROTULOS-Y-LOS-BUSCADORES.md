# L-6 — «Los rótulos fuera de la plantilla, y lo que leen los buscadores»

> Iteración cerrada. Landing comercial v1, sexta entrega.
> Base: `L-1` … `L-5`.

---

## 1. Lo que quedaba

| # | Qué |
|---|---|
| `R-2` | *«Los rótulos de la plantilla y el `lang="es"` están escritos en el Blade. Traducir hoy exige tocar plantillas. El §26 pide no dejarlo así»* |
| §20 | El `Organization` de los buscadores, lo último que faltaba del SEO |
| — | Rendimiento y accesibilidad |

---

## 2. `R-2` — los rótulos, y por qué esto no es «para cuando lleguemos a Colombia»

El texto de marketing ya vivía en la base desde la `L-3`. Lo que seguía escrito
en el `.blade.php` eran **las palabras de la plantilla**: «Entrar», «Correo»,
«Ir al contenido», las cuatro columnas del pie, las etiquetas de los dos
formularios. Traducir el sitio obligaba a abrir seis vistas y buscar frases.

Ahora viven en `lang/es/publico.php`. La regla para saber dónde va cada cosa se
dice en una frase:

> **Si cambiarlo es traducir, va al archivo de idioma. Si cambiarlo es escribir,
> va en la base.**

Un titular es contenido —lo escribe quien administra— y «Correo» es un rótulo.

### Y lo que hace que siga siendo verdad dentro de seis iteraciones

Sacarlos una vez es fácil. La próxima persona que añada un campo al formulario
escribirá «Teléfono» donde le toque, y **nadie lo notará**: el sitio sigue en
español y se ve igual.

Así que la entrega de verdad de esta parte no es el archivo: es
**`tools/verificar-rotulos.py`**. Lee las vistas públicas, quita comentarios,
`<script>`, `{{ … }}` y directivas, y lo que quede escrito entre etiquetas o en
un `alt`/`aria-label`/`placeholder` **pone el CI en rojo** — o se escribe en
`ROTULOS-CRUDOS` con su motivo, igual que `RUTAS-ABIERTAS` y
`SALIDAS-AL-MUNDO`. Hoy hay una sola excepción: `https://…`, que es un ejemplo
de dirección y se escribe igual en cualquier idioma.

**Y encontró cosas al primer intento**, que es lo que justifica escribirlo:

- `publico/gracias-marca.blade.php` entero —una vista que yo no había mirado— con
  tres frases sueltas.
- «Si prefieres escribir tú:» dentro del formulario de creadores.

### Lo que NO se hizo, y se dice

**No se traduce a un segundo idioma todavía.** Se podría añadir `lang/en/` en
diez minutos, y el resultado sería peor que lo que hay: los rótulos en inglés
sobre una portada cuyo **contenido** —titular, franjas, preguntas, páginas
legales— sigue en español, porque el contenido vive en la base y la base no tiene
idioma. Traducir de verdad es una columna `locale` en `landing_pages`,
`landing_sections`, `landing_blocks` y `content_pages`, con su editor. Es una
iteración propia y queda como **`T-95`**.

Lo que sí es verdad hoy, y era el encargo: **traducir ya no exige tocar
plantillas**.

---

## 3. §20 — el `Organization`

Un JSON-LD con nombre, logotipo, descripción, correo, teléfono, razón social,
identificador fiscal, domicilio y perfiles sociales. No es «SEO» en abstracto: es
lo que hace que, al buscar la marca, salga algo más que un enlace azul.

Ni una palabra escrita a mano: todo sale de «Sitio público» y de la sociedad
operadora, con el mismo motor de la `L-2b`. Se arma en PHP y se vuelca con
`json_encode` —un JSON escrito con `{{ }}` dentro de una plantilla es una comilla
suelta esperando a romperlo, y un JSON-LD roto no da ningún error: simplemente
deja de leerse—.

`sameAs` **no se pinta si no hay redes**. Declararle a un buscador un array vacío
es afirmar «no tenemos redes», que no es lo mismo que «todavía no están
configuradas».

### Y un defecto que salió mirando el JSON

El domicilio decía **«Por completar, Perú»** — el valor que siembra
`CimientosSeeder` para que la sociedad exista. En un documento legal eso al menos
le grita al operador que lo complete, y por eso allí se pinta y se avisa en
ámbar. Pero aquí se lo estábamos **declarando a un buscador**, que lo guarda y lo
enseña.

Mejor no decir la dirección que decir una que no es. Y de paso, la regla de «esto
todavía es el valor de fábrica» iba ya por **tres sitios** escrita a mano; ahora
es `Reemplazos::esDeFabrica()`. Una regla escrita en tres sitios es una regla que
el cuarto no tiene.

---

## 4. Rendimiento

- **La tipografía de titulares pide un solo peso.** Se usa siempre en negrita
  —las seis apariciones de `fuente-titulos` van con `font-bold`— así que pedir
  cuatro pesos eran **tres archivos que el navegador descargaba para no usarlos
  nunca**. La de interfaz sigue pidiendo los cuatro: `font-medium` aparece en
  cinco sitios, y quitarlo habría sido cambiar el diseño para ahorrar un archivo,
  que es el intercambio al revés.
- **`display=swap`**, que es lo que de verdad se nota. Sin él el navegador
  **esconde el texto** hasta que la fuente llega —el titular sale en blanco
  durante un instante— y con él lo pinta con la del sistema y lo cambia después.
  En una portada donde lo primero que hay que leer es el titular, eso es la
  diferencia entre «lento» y «roto».

Lo que **no** se hizo: servir las fuentes desde nuestro propio dominio. Quitaría
las dos peticiones externas y de paso resolvería la mitad de `T-94` —la IP del
visitante dejaría de salir hacia un tercero— pero hay que descargar los archivos
y este contenedor no alcanza el servidor de fuentes. Queda como parte de `T-94`,
que es donde ya estaba escrito.

---

## 5. Accesibilidad

`:focus-visible` con el color de la marca, y en blanco sobre el degradado.
Tailwind quita el contorno del navegador y pone el suyo, que en una instalación
white label no tiene por qué contrastar con nada. Quien navega con teclado
necesita ver **dónde está**, y `:focus-visible` no molesta a quien usa el ratón
porque no se dispara al hacer clic.

Con el salto al contenido de la `L-3` y el `lang` correcto, lo básico está.
Una revisión completa —contraste medido, lectores de pantalla, navegación entera
con teclado— es la `L-7`.

---

## 6. Decisiones

### `DEC-296` · Si cambiarlo es traducir va al archivo de idioma; si es escribir, va en la base

Y lo sostiene un verificador, no la buena voluntad. Sacar los rótulos una vez es
fácil; lo difícil es que sigan fuera dentro de seis iteraciones, cuando alguien
añada un campo y escriba la etiqueta donde le toque sin que nadie lo note.

### `DEC-297` · No se traduce a un segundo idioma hasta que el contenido también pueda traducirse

Rótulos en inglés sobre contenido en español es peor que todo en español. El
contenido vive en la base y la base no tiene idioma; eso es una columna `locale`
en cuatro tablas y su editor (`T-95`). Lo que el §26 pedía —que traducir no
exija tocar plantillas— está hecho.

### `DEC-298` · Lo que todavía es el valor de fábrica no se le declara a un buscador

En un documento legal un «Por completar» le grita al operador que lo complete; en
un `Organization` se lo estamos diciendo a Google, que lo guarda y lo enseña.
Mejor no decir la dirección que decir una que no es. Y la regla vive ahora en un
solo sitio.

---

## 7. Lo que queda

| Qué | Dónde |
|---|---|
| QA completo: escritorio, tableta, móvil, teclado, contraste, enlaces, correos, consola | **L-7** |
| Traducir el contenido, no sólo los rótulos | `T-95` |
| Fuentes propias y la política de privacidad al día | `T-94` (con `T-09`) |
| Fotografías reales | Cuando existan |

---

## 8. Al desplegar

1. `php artisan migrate` — **esta iteración no trae migración**: no hay cambio de
   esquema.
2. **Hay una carpeta nueva en la raíz: `lang/`.** Va al repositorio como
   cualquier otra.
3. `npm run build`.
4. Comprueba que `APP_LOCALE=es_PE` en el `.env`: de ahí sale el `lang` del
   documento y el idioma de los rótulos.

---

## 9. Comprobado

| | |
|---|---|
| Pruebas | 1 275 pruebas / 4 550 aserciones |
| SQL, MariaDB | 2 652 aserciones, 0 fallidas |
| SQL, MySQL 8 | 2 642 aserciones, 0 fallidas |
| Puertas | Las seis en verde |
| Verificadores | Diez, más `verificar-rotulos.py` — nuevo, y ya en `correr-todo.sh` |
| Mirado | El `<head>` servido de verdad: `lang="es-PE"`, JSON-LD válido y las dos peticiones de fuentes con sus pesos |
