# L-5 — «El formulario corto, el país que no es Chile y la medición»

> Iteración cerrada. Landing comercial v1, quinta entrega.
> Base: `L-1` … `L-4`.

---

## 1. Los tres defectos que cierra

| # | Qué decía la auditoría |
|---|---|
| `C-2` | **El país por defecto es Chile** (primero por orden alfabético). *«Un negocio que arranca en Perú etiqueta mal sus propios leads, en silencio, desde el primer día»* |
| `C-7` | **7 campos + área de texto**, todos juntos y sin explicar para qué. *«Se puede calificar igual con menos»* |
| §21 | Hooks de evento **sin proveedor atado**. Los `data-evento` están puestos desde la `L-3`; faltaba por dónde salen |

---

## 2. `C-2` — el país por defecto, y por qué no dice «Perú»

El desplegable no traía **ninguna** opción marcada, así que el navegador elegía
la primera de la lista. La lista va por nombre, y la primera resultaba ser
**Chile**. Nadie lo eligió: salió así.

Y el país de un lead no es un adorno: decide el mercado, la moneda y qué
comprobante se emite. Etiquetarlo mal **no da ningún error**.

La tentación era escribir «Perú» en el código. Eso es `DEC-190` roto: el sistema
es white label y el segundo operador puede estar en Colombia. Así que es un
ajuste —**Sitio público → País que sale marcado**— con una regla de reserva que
**no es una constante**:

> Cuando no se ha elegido ninguno, el país por defecto es **el de la sociedad
> operadora**.

Eso es un dato que ya existe, que ya está bien y que ya se administra. Hay una
prueba que **cambia el país de la sociedad y exige que cambie el del formulario**:
si alguien escribiera «Perú» en el código, se pondría roja.

Y la lista sale **con ese país primero**, no sólo marcado: un desplegable que
abre en el país correcto pero lo tiene en la posición catorce sigue invitando a
que alguien elija otro sin querer.

---

## 3. `C-7` — el formulario corto

Antes: empresa, nombre, correo, teléfono, país, web, y el área de texto. Siete
huecos antes de que nadie haya prometido nada.

Ahora quedan a la vista **los cuatro que hacen falta para contestar** —con quién
hablamos, de qué marca, a dónde escribimos y en qué país— y el país **ya viene
marcado**, así que son tres que teclear. El teléfono y la web no cambian la
respuesta, así que van detrás de un `<details>`: «Añadir teléfono y web
(opcional)».

**Acortar es esconder lo que no hace falta para contestar, no dejar de
recogerlo.** `client_leads` no cambia y `Prospectos` tampoco —el §6 pide no crear
soluciones paralelas— y hay una prueba que rellena los campos plegados y
comprueba que llegan a la base tal cual.

Si la validación rebota con teléfono o web escritos, el `<details>` vuelve
abierto: un campo que se esconde con un error dentro es un formulario que dice
«algo está mal» y no enseña dónde.

---

## 4. §21 — la medición, sin proveedor atado

Cuatro proveedores conocidos —**GA4, Tag Manager, Meta Pixel y Plausible**— y el
identificador, los dos configurables en «Sitio público». Cambiar de Google a Meta
es un desplegable, no un despliegue.

El puente de eventos son **nueve líneas sin librería**: lee el `data-evento` del
elemento pulsado y se lo pasa a lo que haya cargado. Si no hay nada cargado no
hace nada y no falla — medir nunca puede impedir que un botón funcione.

### Lo que de verdad importa de esta parte

**La medición no se emite fuera de producción.** No es una precaución teórica: es
literalmente el agujero que `9.22b` cerró para el correo. Se restaura un volcado
de producción en el servidor de pruebas —cosa que se hace todas las semanas— y
ese volcado **trae dentro el identificador bueno de la propiedad**, así que cada
clic de una prueba se cuenta como una visita real. No rompe nada, y por eso nadie
lo nota: los números simplemente dejan de significar algo.

Lo decide `Sitio::medicion()` y no la plantilla: una vista que pregunta por la
máquina en la que corre es una regla escondida en la maquetación, y la siguiente
vista no se acordaría. La pantalla lo dice con todas las letras, en verde o en
ámbar, según la máquina.

Y hay una prueba que se rompió a propósito para comprobar que sirve: quitando el
`Instalacion::esProduccion()` de una línea, se pone roja. Es la quinta vez que en
este proyecto se comprueba que una aserción falla por su motivo, y las cuatro
anteriores enseñaron por qué hace falta.

### El identificador entra dentro de un `<script>`

Ahí una comilla no es una errata: es una inyección en **todas** las páginas
públicas. Se comprueba en las dos puertas —el formulario del panel y
`ck_ss_medidor_id` en la base, con `COLLATE utf8mb4_bin`— y aun así se escapa al
pintarlo. La defensa que se salta «esta vez» es la que falta el día que alguien
cambie la regla de arriba.

---

## 5. El hallazgo de la iteración: había una salida al mundo que nadie miraba

`verificar-salidas.py` (`9.22b`) reconoce a quien puede hablar con el exterior
por **pedir la dirección o la clave de una conexión**. La medición no pide
ninguna de las dos —su identificador es público— y sin embargo carga un
`<script>` de un tercero en todas las páginas de la calle y le manda la IP de
cada visitante.

**El verificador no la habría visto nunca.** Es exactamente el mismo tipo de
hueco que el propio `DEC-283` describe: el consumidor que no se detecta es el que
el verificador existe para detectar.

Así que ahora mira **también en las plantillas**: toda vista que cargue algo de un
dominio ajeno —un `src`, un `<link href>`, o una dirección dentro de un
`<script>`— tiene que consultar la barrera o estar escrita en `SALIDAS-AL-MUNDO`
con su motivo. Un `<a href>` no cuenta, y la primera versión se equivocaba justo
ahí: denunciaba cinco pantallas del panel que **enlazan** a SUNAT o a la ayuda de
Google. Un enlace no manda nada a nadie hasta que una persona decide pulsarlo.

### Y al correrlo salió algo que nadie había mirado

> **La tipografía viaja a un tercero, en todas las páginas, incluida la de acceso.**

`parciales/marca.blade.php` sirve las fuentes desde `fonts.bunny.net`. No pasa por
la barrera de entorno —una máquina de pruebas también necesita verse bien, y no
viaja ningún dato **nuestro**— pero **sí sale la IP del visitante hacia un
tercero**, y eso lo tiene que decir la política de privacidad. Queda escrito en la
lista con su motivo y como `T-94`, con la alternativa real al lado: servir las
fuentes desde nuestro propio dominio.

Esto no lo pedía nadie y no lo habría encontrado ninguna prueba. Lo encontró
**ampliar el verificador al sitio donde acababa de aparecer un agujero nuevo**.

---

## 5 bis. Y dos avisos que escribí mal

Los dos primeros avisos que puse sobre la medición estaban equivocados, y lo dijo
una prueba de la `L-2a` que se puso roja: **«con todo puesto no queda ningún
aviso»**.

- *No medir* no es una configuración a medias: es una decisión legítima, y
  ponerle un ámbar es regañar por una elección.
- Y la nota de privacidad de cuando **sí** se mide tampoco puede vivir ahí,
  porque sería un ámbar que no se apaga nunca — exactamente lo que `DEC-282`
  corrigió en el correo: *un aviso permanente acaba tapando los que sí hay que
  leer*.

Las dos cosas se dicen ahora **en su sitio**, dentro de la sección de medición,
donde se leen cuando importan.

Y de paso salió otra: **`L2a-sitio.sh` no se podía correr dos veces seguidas**.
Fallaba en la segunda pasada con `uq_sl_red` porque dejaba las redes escritas. En
la pasada completa no se notaba —`correr-todo.sh` rehace las bases primero— así
que era una suite que sólo funcionaba dentro de su rutina. Una prueba que no se
puede repetir a mano es una prueba que nadie repite a mano cuando la necesita.
Ahora se limpia al empezar, y se comprobó corriéndola tres veces seguidas.

---

## 6. Decisiones

### `DEC-292` · El país por defecto es un ajuste, y su reserva es un dato, no una constante

Ni «el primero de la lista» —que era Chile— ni «Perú» escrito en el código. El que
se configure; si no, el de la sociedad operadora. Y la lista sale con él delante,
porque marcarlo sin subirlo sigue invitando a elegir otro sin querer.

### `DEC-293` · Acortar el formulario es esconder, no dejar de recoger

Quedan a la vista los cuatro campos que hacen falta para **contestar**. El
teléfono y la web se siguen guardando si alguien los escribe: `client_leads` no
cambia y `Prospectos` tampoco (§6). Y si la validación rebota con algo escrito
dentro, el pliegue vuelve abierto.

### `DEC-294` · La medición se configura, y NO se emite fuera de producción

Cuatro proveedores conocidos y su identificador, los dos en «Sitio público».
El identificador va comprobado en las dos puertas porque entra dentro de un
`<script>`. Y la barrera de `9.22a` la decide el servicio, no la plantilla: un
volcado de producción en pruebas mandaría visitas falsas a la propiedad de verdad
sin dar ningún error.

### `DEC-295` · Una plantilla que carga algo de fuera también es una salida al mundo

`verificar-salidas.py` reconocía al consumidor por pedir una credencial. Un
`<script>` de terceros no pide ninguna y manda la IP de cada visitante. Ahora se
mira también en las vistas, con el mismo trato: no se prohíbe, se exige que esté
escrita. Un `<a href>` no cuenta.

---

## 7. Lo que queda dicho y no resuelto (§56)

- **`T-94`** — la política de privacidad tiene que declarar la tipografía servida
  desde un tercero, y la medición cuando se active. El aviso ámbar de la pantalla
  lo recuerda al configurarla, pero **el texto no se ha escrito**: lo escribe
  quien revise el documento, que es lo mismo que dice `T-09`.
- **Consentimiento.** Si se activa GA4 o Meta Pixel, puede hacer falta un banner
  de consentimiento antes de cargarlos. Aquí no se ha construido ninguno, y se
  dice en vez de darlo por supuesto: no es un dictamen y no lo firma nadie.

---

## 8. Lo que NO se hizo

| Qué | Dónde va |
|---|---|
| Los rótulos de la plantilla a archivos de idioma (`R-2`) | **L-6** |
| Rendimiento y el resto del SEO | **L-6** |
| Banner de consentimiento | Cuando el abogado diga si hace falta |
| La portada de creadores con su propia narrativa | Después de la `L-7` |

---

## 9. Al desplegar

1. `php artisan migrate`.
2. En **Sitio público** ya no hace falta tocar el país: sale el de la sociedad
   operadora. Se cambia sólo si tu mercado principal es otro.
3. Si quieres medir, elige proveedor e identificador ahí mismo. **En tu máquina
   de pruebas no se emitirá**, y la pantalla te lo dirá.

---

## 10. Comprobado

| | |
|---|---|
| Pruebas | 1 263 pruebas / 4 432 aserciones |
| SQL, MariaDB | 2 652 aserciones, 0 fallidas |
| SQL, MySQL 8 | 2 642 aserciones, 0 fallidas |
| Puertas | Las seis en verde |
| Barrera de entorno | **Comprobada rompiéndola**: sin `esProduccion()`, la prueba se pone roja |
| Mirado en pantalla | El formulario a 1440 px, con Perú marcado y la consola limpia |
