# Landing comercial v1 — Auditoría y arquitectura propuesta

> **Iteración 1 y 2 del encargo. Nada implementado todavía.**
> Fecha: 2026-09-02 · Backoffice en pausa a partir de `9.22b`.

---

## 0. Cómo se hizo esta auditoría

No se leyó sólo el código. Se **compiló Tailwind, se sembró una base limpia, se
levantó la aplicación y se miraron las dos portadas** en 1440 px y en 390 px, con
la consola del navegador abierta.

Es deliberado: en `9.17i` el hallazgo más caro del proyecto —130 controles de
formulario sin borde visible— **no lo encontró ninguna prueba**, lo encontró
mirar la pantalla. Una landing es exactamente el sitio donde eso vuelve a pasar.

---

## 1. Diagnóstico

### 1.1 Lo que está bien, y que la v1 NO debe romper

Esto no es cortesía: son cuatro decisiones acertadas que una reescritura
descuidada destruye sin darse cuenta.

| Qué | Por qué importa |
|---|---|
| **Todo el texto vive en la base** (`landing_pages` + `landing_blocks`), editable desde `/backoffice/landing` sin desplegar | Es `DEC-190` aplicado. Una landing nueva con los titulares escritos en la plantilla sería un **retroceso**, por bonita que quedara |
| **El formulario ya alimenta `client_leads`** — con `throttle`, campo trampa, sin CAPTCHA, y una puerta única (`uq_clead_abierto`) que impide que quien escribe tres veces porque nadie le contesta aparezca como tres marcas | El §6 del encargo pide no crear soluciones paralelas. **No hace falta ninguna**: el ciclo Lead → Customer ya existe |
| **La marca es un dato** (`platform_brands`): nombre, colores, tipografía, pie legal | White label real, y la v1 se apoya en ello |
| **El copy actual no miente**. No hay una sola métrica inventada, ni un testimonio, ni un logo de cliente | Cumple el §12 desde hoy. Es un suelo mejor del que suele encontrarse |

### 1.2 El hallazgo principal: **el sitio no está vestido de LATAM Social**

Existe un sistema de diseño aprobado —`design/tokens.css` y
`docs/14-BRAND-AND-DESIGN-SYSTEM.md`— con esta regla de oro escrita:

> *El naranja y el magenta de marca existen SOLO dentro del degradado. En
> interfaz, el único color de marca plano es el morado.*

Y el degradado canónico es `#FF7447 → #D73382 → #6635D8` a 45°.

**Lo que está publicado no es eso.** La instalación arranca con:

| | Sembrado hoy | Marca aprobada |
|---|---|---|
| Color 1 | `#7C3AED` — violeta 600 de Tailwind | `#6635D8` |
| Color 2 | `#22D3EE` — cian 400 de Tailwind | `#D73382` + `#FF7447` |
| Degradado | morado → cian | naranja → magenta → morado |
| Display | *no existe* | Sora |

El héroe que se ve hoy es **el degradado por defecto de Tailwind**, no el de
LATAM Social. Cualquier persona que haya visto el manual de marca lo nota en dos
segundos. Es el defecto de mayor impacto de toda la auditoría **y el más barato
de corregir: es un cambio de datos, no de código.**

### 1.3 El logotipo existe en el repositorio y no se publica nunca

`public/img/brand/logo-horizontal.svg` está ahí desde el 22 de agosto.
`Marca::datos()` sólo devuelve un logotipo si alguien **sube un archivo** a
`files`; si no, la plantilla dibuja un cuadrado con degradado.

Resultado: el visitante ve un cuadradito de color junto al texto «LATAM Social».
El logotipo real no ha salido nunca a la calle. Y `docs/14 §7` dice
explícitamente que en las landings públicas va **el horizontal con descriptor**.

### 1.4 Assets creados y jamás enganchados

| Archivo | Estado |
|---|---|
| `public/img/brand/og-image.png` (92 KB, hecho a propósito) | **Nadie lo referencia.** No hay `og:image` |
| `public/img/brand/site.webmanifest` | **Nadie lo referencia.** Y además apunta a `/img/favicon-192.png` cuando el archivo está en `/img/brand/…`, y declara `start_url: /creators/`, una ruta en inglés **que no existe** (las rutas son `/creadores`) |

Compartir hoy `latamsocial.com` por WhatsApp o LinkedIn produce una tarjeta **sin
imagen**. Para un sitio cuyo tráfico va a venir de redes, eso es una fuga de
clics en el primer metro.

### 1.5 SEO: por debajo del mínimo para estrenar un dominio

Presente: `title`, `description`, `og:title`, `og:description`, `og:type`.

Ausente: `canonical`, `og:image`, `og:url`, `og:site_name`, `og:locale`,
Twitter/X card, `robots.txt`, `sitemap.xml`, JSON-LD de `Organization`,
`<link rel="manifest">`, `theme-color`.

### 1.6 Conversión

| # | Problema | Efecto |
|---|---|---|
| C-1 | **El header no tiene el CTA comercial.** Sus dos únicas acciones son «Soy creador» (público secundario) y «Entrar» (quien ya es cliente) | Quien llega y hace scroll sin decidirse **no tiene a dónde volver**. El §7 pide justo lo contrario: que lo secundario no compita |
| C-2 | **El país por defecto es Chile** (primero por orden alfabético) | Un negocio que arranca en Perú etiqueta mal sus propios leads, en silencio, desde el primer día |
| C-3 | **La misma frase tres veces**: botón del héroe, título de sección y botón de envío dicen «Quiero lanzar una campaña» | Lee como plantilla, no como página escrita |
| C-4 | **La portada de marcas no responde ni una objeción.** No tiene bloques `faq` sembrados; y en `/creadores` las preguntas salen **después** del formulario | La sección que quita objeciones está detrás del punto de conversión, o no está |
| C-5 | **No hay WhatsApp** en ninguna parte | El canal de contacto de menor fricción en LATAM, ausente |
| C-6 | **Un solo punto de conversión**, al final de un scroll largo | Sin CTA intermedio, quien se convence en el minuto uno tiene que seguir bajando |
| C-7 | 7 campos + área de texto, todos juntos y sin explicar para qué | Se puede calificar igual con menos |

### 1.7 Diseño visual y UX

| # | Problema |
|---|---|
| V-1 | **Cero imágenes.** Ni una persona, ni una pantalla, ni un vídeo. Es literalmente *degradado + texto + tarjetas blancas*, que es lo que el §14 dice que no quiere |
| V-2 | **El héroe tiene la mitad derecha vacía** en escritorio: un titular de 5xl sobre 800 px de degradado liso |
| V-3 | Las tres ventajas son **texto desnudo**: sin icono, sin tarjeta, sin ritmo. Leen como una ficha técnica |
| V-4 | **Ninguna sección tiene imagen posible.** `landing_blocks` no tiene columna de imagen: el esquema no puede expresar una landing con fotografía |
| V-5 | **Sin movimiento**: ni un `hover` propio, ni una entrada, ni un `scroll reveal` |
| V-6 | **Móvil, 390 px:** «Soy creador» parte en dos líneas y aprieta contra «Entrar». No hay menú, ni anclas, ni CTA |
| V-7 | El pie es **una sola línea**. Sin privacidad, sin términos —que existen y están publicados desde `9.16`—, sin contacto, sin redes |
| V-8 | Tres `<h2>` hermanos sin título de sección que los agrupe |

### 1.8 Narrativa

La estructura actual es **héroe → 3 ventajas → 4 pasos → formulario**: justo la
forma que el §11 rechaza por su nombre. No hay problema, no hay tensión, no hay
por qué. Se entra sabiendo qué se vende y se sale sin haber entendido por qué
importa.

Y falta lo esencial: **la palabra «muchos» no aparece por ningún lado.** El
modelo entero —decenas de microcreadores coordinados como una sola operación— no
está contado. Se vende «campañas con creadores», que es lo que vende cualquier
agencia.

### 1.9 Dos riesgos que hay que decir antes de escribir una línea

**R-1 · No prometer plataforma self-serve (§27).** Hoy la operación es
**gestionada**: la marca habla con una persona y el sistema es la trastienda que
lo hace trazable. Una landing que insinúe «entra y lanza tu campaña» promete algo
que no se puede cumplir el primer día. Lo que sí se puede decir, y es cierto:
*campaña gestionada, con la trazabilidad de una plataforma.*

**R-2 · La internacionalización no está preparada.** El texto de marketing sí
vive en la base, pero los rótulos de la plantilla («Cómo funciona»,
«Preguntas», cada etiqueta del formulario, «Sólo lo usamos para responderte») y
el `lang="es"` están **escritos en el Blade**. Traducir hoy exige tocar
plantillas. El §26 pide no dejarlo así.

---

## 2. Qué se conserva, qué se transforma, qué se elimina

### Se conserva tal cual
- `client_leads`, `Prospectos::recibir()`, el `throttle`, el campo trampa y la puerta anti-duplicado. **Cero soluciones paralelas.**
- `landing_pages` / `landing_blocks` como origen del texto, y el editor de `/backoffice/landing`.
- `Marca::datos()` y el sistema de color por variables CSS.
- La separación `layouts.publico` ≠ `layouts.panel`.
- `/` para marcas y `/creadores` para creadores (`DEC-238`).
- El tono honesto del copy: sin métricas, sin testimonios, sin logos.

### Se transforma
| Hoy | v1 |
|---|---|
| Una plantilla que sirve a las dos portadas con `@if ($esDeCreadores)` | Dos plantillas que comparten parciales. La de marcas es una página comercial; la de creadores, una de captación. Hoy comparten forma y **eso es lo que las hace genéricas a las dos** |
| Header con dos enlaces | Header con anclas + «Soy creador» + «Entrar» + **CTA comercial**; en móvil, un panel |
| Pie de una línea | Pie de cuatro columnas, con legal y contacto |
| Tres tipos de bloque | Bloques con **imagen, icono y CTA propio** — requiere migración |
| Colores por defecto de Tailwind | Degradado y morado de marca |

### Se elimina
- El cuadrado de degradado como sustituto del logotipo.
- La repetición del `cta_label` como título de sección.
- El orden `formulario → preguntas` de `/creadores`.
- El país por defecto por orden alfabético.

---

## 3. Arquitectura propuesta de la portada de marcas

Cada sección con su trabajo. La columna «conversión» dice **qué se le pide al
visitante ahí**, no qué se le cuenta.

| # | Sección | Qué hace | Conversión |
|---|---|---|---|
| 0 | **Header** | Marca, anclas, «Soy creador», «Entrar», CTA | Ancla permanente. Se pega al hacer scroll |
| 1 | **Héroe** | Qué es esto, en 5 segundos. Titular + subtítulo + CTA + WhatsApp. A la derecha, **una rejilla de vídeo vertical en movimiento lento**: se ve creator economy antes de leer nada | CTA primario |
| 2 | **El problema** | *«Diez creadores son diez conversaciones de WhatsApp, diez precios, diez fechas y ninguna evidencia junta.»* Nombrar el caos que ya vive el lector | Ninguna. Aquí se gana la lectura |
| 3 | **Una marca. Muchas voces.** | El giro: por qué muchas comunidades pequeñas hacen algo que una grande no hace. **Aquí vive el claim**, a página completa, con el degradado de marca | Ninguna. Es el momento de la idea |
| 4 | **Cómo funciona** | Los 4 pasos, en horizontal, con lo que hace la marca y lo que hacemos nosotros en cada uno | CTA secundario en línea |
| 5 | **Qué recibes** | Entregables concretos: contenido, publicaciones verificadas, evidencias, métricas, **y comprobante electrónico válido** | Ninguna |
| 6 | **Tipos de campaña** | Lanzamiento, prueba de producto, cobertura de local, contenido para pauta (UGC), temporada. Cada uno con un ejemplo de una línea | Micro-CTA: «esto es lo mío» |
| 7 | **Cómo elegimos a los creadores** | Verificación de identidad y cuentas, métricas comprobadas, términos aceptados. Responde la primera objeción real de un brand manager: *«¿y si me ponen a cualquiera?»* | Ninguna |
| 8 | **Por qué confiar** | Empresa real con RUC, comprobantes electrónicos, términos publicados y versionados, evidencia por publicación, un interlocutor. **Ni una métrica** | Ninguna |
| 9 | **Preguntas** | 6–8, de las que se preguntan de verdad: presupuesto mínimo, plazos, exclusividad, derechos de uso del contenido, facturación, qué pasa si una publicación no sale | Ninguna. Quita frenos |
| 10 | **Cierre + formulario** | «Hablemos de tu próxima campaña.» Formulario corto + WhatsApp al lado | **Conversión principal** |
| 11 | **Franja de creadores** | Discreta, al final: «¿Eres creador? Esta puerta es la tuya» | Deriva sin competir |
| 12 | **Pie** | Navegación, legal, contacto, redes, CTS donde corresponde | Última red |

**Por qué este orden.** Las secciones 2 y 3 son las que hoy no existen y son las
que convierten una lista de características en un argumento. La 7 y la 8 van
**antes** de las preguntas porque construyen la confianza que las preguntas
terminan de cerrar; y las preguntas van **antes** del formulario, que es el error
que hoy tiene `/creadores`.

---

## 4. Propuesta de héroe

### Análisis del actual

> **«Campañas con creadores, de principio a fin y con todo a la vista.»**

Es honesto y se entiende. Pero:

1. Habla de **proceso**, no de resultado.
2. «De principio a fin» es lo que dice **cualquier agencia**. No diferencia.
3. **No dice «muchos».** El modelo entero está fuera del titular.
4. No nombra a quién le habla.

Se salva «con todo a la vista» —esa parte sí es nuestra— y merece sobrevivir más
abajo, no en el titular.

### Recomendado

> # Muchas voces. Una sola campaña.
> ### Activa decenas de creadores reales en una campaña coordinada: nosotros elegimos, producimos, publicamos y te entregamos cada publicación con su evidencia. Tú hablas con una sola persona.
>
> **[ Quiero lanzar una campaña ]**  ·  Escríbenos por WhatsApp

**Por qué.** El titular es el modelo en cuatro palabras y usa el claim del §9
donde más rinde. El subtítulo hace el trabajo que el titular no debe hacer:
*decenas* (escala), *reales* (autenticidad), *coordinada* (simplicidad
operativa), *evidencia* (control), *una sola persona* (el dolor que se quita).
Y no promete ninguna automatización que hoy no exista (§27, R-1).

### Alternativas, por si prefieres otro registro

**B — el dolor primero, más directo:**
> # Diez creadores no deberían ser diez conversaciones.
> ### Coordinamos campañas con decenas de microcreadores y te las entregamos como una sola operación.

**C — el beneficio primero, más comercial:**
> # Tu marca, en decenas de comunidades reales.
> ### Una campaña con muchos creadores, gestionada de principio a fin y entregada con evidencia de cada publicación.

Mi recomendación es **A**, con **B** como titular de la sección 2 (el problema).
Las tres funcionan; A es la que se recuerda.

---

## 5. Confianza sin inventar nada (§12 y §13)

Todo esto es **verdad hoy** y es verificable. Nada de ello es una métrica de
vanidad:

- **Empresa real:** Soluciones Tecnológicas a Medida S.A.C., RUC 20603203896 — comprobable en SUNAT.
- **Facturamos con comprobante electrónico válido.** Acabamos de cerrarlo (`9.9e`). Para un gerente de marketing esto no es un detalle técnico: es si puede o no rendir el gasto.
- **Términos publicados y versionados**, con fecha (`9.16`).
- **Creadores verificados**: identidad y cuentas comprobadas antes de proponer a nadie.
- **Evidencia por publicación**, con fecha.
- **Pago a cada creador con su comprobante.**
- **Un interlocutor.**

Y una decisión de honestidad: **el sitio no lleva contador de nada** hasta que
haya algo real que contar. Se diseñan los huecos —casos, marcas, métricas— y se
publican vacíos, no rellenos.

---

## 6. Trabajo de backend estrictamente necesario

Sólo lo que la landing exige. Nada más (§29).

| Qué | Por qué | Migración |
|---|---|---|
| `landing_blocks`: `image_file_id`, `icon`, `cta_label`, `cta_url`, y más tipos (`problem`, `claim`, `deliverable`, `campaign_type`, `trust`) | Sin esto el esquema **no puede expresar** la landing propuesta, y volveríamos a escribir texto en la plantilla — que es romper `DEC-190` | Sí |
| `platform_brands`: `whatsapp_phone`, `whatsapp_message` | §23: el número **configurable**, no escrito en un componente | Sí |
| `platform_brands`: redes sociales | Pie | Sí |
| Colores y tipografía de marca corregidos a los aprobados | Es dato, no código | No — semilla |
| Logotipo horizontal servido desde `public/img/brand/` cuando no hay archivo subido | Que el logotipo salga a la calle | No |
| `og:image`, `canonical`, `og:url`, Twitter card, JSON-LD, `robots.txt`, `sitemap.xml`, manifiesto arreglado | §20 | No |
| Rótulos de plantilla a archivos de idioma | §26 | No |
| Hooks de evento (`data-evento="cta_campana"`, etc.) sin proveedor atado | §21 | No |
| País por defecto = Perú | C-2 | No |

**Cero cambios en:** facturación, campañas, creadores, pagos, integraciones,
permisos. La barrera de entorno de `9.22a`/`9.22b` sigue exactamente igual.

---

## 7. Orden de trabajo propuesto

| Iteración | Qué |
|---|---|
| **L-1** | La marca de verdad: colores, degradado, logotipo, tipografía, favicon, manifiesto, `og:image`. **Sin tocar una sola sección.** Se ve el cambio de inmediato y no depende de nada |
| **L-2** | Esquema: bloques con imagen y CTA, WhatsApp y redes en la marca, editor al día |
| **L-3** | Cabecera, pie y sistema visual: tarjetas, ritmo, movimiento |
| **L-4** | Las secciones nuevas: problema, claim, qué recibes, tipos de campaña, cómo elegimos, confianza, preguntas |
| **L-5** | Formulario corto, WhatsApp, eventos de analítica |
| **L-6** | SEO, rendimiento, accesibilidad, i18n |
| **L-7** | QA: escritorio, tableta, móvil, consola, enlaces, correos, producción |

Cada una entregable y desplegable por separado. Si hay que parar en la L-3, lo
que quede publicado **ya es mejor que lo de hoy**.

---

## 8. Lo que necesito de ti antes de empezar

1. **¿Apruebas el titular A**, o prefieres B / C / otro?
2. **Número de WhatsApp** y si el mensaje que propongo te sirve.
3. **Fotografía:** ¿tienes o puedes conseguir fotos reales de creadores, o arranco con un tratamiento visual que no dependa de fotos (composición gráfica, vídeo vertical de relleno) y las metemos después?
4. **Redes sociales de LATAM Social:** ¿cuáles existen ya?
5. **Correo de contacto** público, y si quieres teléfono en el pie.
6. **Privacidad:** los términos de creador existen (`9.16`); no hay política de privacidad. Para publicar en un dominio hace falta. ¿La redacto como borrador para que la revise tu abogado (`T-09`)?
7. **Presupuesto mínimo por campaña:** ¿lo decimos en las preguntas? Filtra muchísimo, en los dos sentidos.
