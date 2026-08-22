# 14 — Marca y sistema de diseño

> Versión 0.1 — 2026-08-21. Basado en el kit recibido `03_Isotipo_Completo.zip` (LATAM Social — Brand Kit Final) y en `10_Manual_Basico_de_Marca_CORREGIDO.pdf` v1.1.
> Este documento traduce **una identidad de marca** en **un sistema de diseño de producto**, que no es lo mismo. Y de paso audita el kit, porque encontré cinco cosas que hay que corregir antes de construir.

---

## 1. Qué recibí

Un kit correcto y bien pensado: wordmark con dos gestos propios —la **A** de LATAM como triángulo de reproducción y la **O** de SOCIAL como ojo—, degradado naranja→magenta→morado, base navy, y once versiones (horizontal, vertical, isotipo completo y simplificado, monocromáticos, fondo oscuro, favicon, PDF vectorial).

El concepto es sólido: el triángulo de *play* y el ojo dicen "contenido" y "audiencia" sin necesidad de explicarlo, y funcionan tanto juntos como por separado. El manual define paleta, área de seguridad, tamaño mínimo y usos incorrectos.

**Lo que el manual no define y un sitio web necesita:** tipografía, colores funcionales, colores semánticos, neutrales, escala, densidad y comportamiento en tema oscuro. Eso es lo que añade este documento.

---

## 2. Auditoría del kit — cinco defectos a corregir

No son objeciones al diseño: son problemas técnicos que se manifestarían en producción.

### D-01 · Los SVG llevan texto vivo con una fuente que casi nadie tiene 🔴

Los tres logotipos con wordmark declaran `font-family="DejaVu Sans"` y el texto está **como texto**, no como contornos. DejaVu Sans es una fuente de sistemas Linux: **no está en Windows ni en macOS**. Cualquiera que abra ese SVG en un navegador o en Illustrator verá el logotipo compuesto con otra tipografía — con otras proporciones, otro grosor y otro ancho.

Dicho de otro modo: **el logotipo cambia de forma según quién lo abra.** Es el defecto más grave del kit.

> **Corregido.** Convertí el texto a contornos vectoriales en `marca/derivados/`. Verifiqué la conversión renderizando original y versión contornada al mismo tamaño y comparando píxel a píxel: la diferencia es solo antialiasing de bordes. Los archivos entregados renderizan idénticos en cualquier equipo, sin depender de ninguna fuente instalada.

### D-02 · El favicon perdió la pupila del ojo 🔴

En el isotipo completo la construcción es: aro con degradado → **aro blanco** → pupila navy → brillo blanco. En `08_Favicon.svg` alguien cambió el aro blanco a navy (`#070A2B`), y como la pupila también es navy, **desaparece**. El resultado es una rosquilla de degradado con un agujero oscuro y una mota blanca suelta en el centro: deja de leerse como un ojo.

Se ve con claridad comparando el favicon original con el corregido a 16, 32, 48 y 64 px. Afecta a los seis PNG derivados y al `.ico`.

> **Corregido.** `marca/derivados/favicon.svg` restaura el aro blanco, y regeneré los PNG en todos los tamaños.

### D-03 · El descriptor no es legible sobre fondo oscuro 🟠

"CREATOR MARKETING PLATFORM" va en morado `#6635D8`. Sobre navy `#070A2B` eso da **2,82:1** de contraste. El mínimo de la norma WCAG AA es 4,5:1 para texto normal y 3:1 incluso para texto grande — **no llega ni al umbral de texto grande**, y aquí es texto pequeño y muy espaciado, que es el caso más difícil de leer.

Se aprecia en la imagen Open Graph generada: el descriptor casi desaparece.

> **Corregido.** Para fondos oscuros, el descriptor usa `#A78BFA` (7,10:1). Token: `--purple-on-dark`.

### D-04 · La mitad del lienzo del logotipo está vacía 🟠

`01_Logo_Horizontal.svg` declara un lienzo de 1800×650, pero el contenido ocupa 1122×530 y está pegado a la izquierda. **El 49% del archivo es aire.** El vertical tiene un 40%.

Consecuencia práctica: al colocar el SVG en una cabecera con `width: 200px`, el logotipo se ve pequeño y descentrado, y el espacio en blanco rompe cualquier alineación. Es el clásico problema que luego se "arregla" con márgenes negativos.

> **Corregido.** Recorté el `viewBox` al contenido real más un margen del 6% —coherente con el área de seguridad que pide el manual, la altura de la O— midiendo la caja sobre el render, no a ojo.

### D-05 · Faltan los assets que exige una PWA 🟡

El portal del creador es una **PWA instalable** (`DEC-003`). Eso necesita, además de los favicon: icono *maskable* con zona segura, icono monocromo para notificaciones de Android, imagen Open Graph y `site.webmanifest`. El icono actual, usado como maskable, se recortaría por las esquinas en Android.

> **Corregido.** Generé los cinco. El maskable reduce el ojo al 78% del ancho para caber en la zona segura circular.

---

## 3. Identidad ≠ paleta de producto

Este es el punto conceptual más importante del documento.

La paleta de marca tiene cuatro colores. Un producto necesita entre veinticinco y cuarenta: neutrales para superficies y bordes, un primario funcional, y colores semánticos para éxito, aviso, error e información. Y aquí aparece una colisión concreta:

> **El naranja de marca ocupa el mismo espacio visual que un "aviso", y el magenta el de un "error".** Si el botón principal es naranja y la alerta de aviso también, nadie distingue una cosa de la otra.

### La regla que lo resuelve

**El naranja y el magenta existen únicamente dentro del degradado.** En la interfaz, el único color de marca plano es el **morado**. Con eso, los colores semánticos quedan libres y no compiten con la identidad.

No es una limitación: es lo que hace que el degradado siga siendo especial. Un color de marca que aparece en cada botón deja de significar nada.

### Contraste de la paleta de marca (medido, WCAG 2.1)

| Combinación | Ratio | AA texto | AA grande | Uso permitido |
|---|---:|---|---|---|
| Navy sobre blanco | 19,31:1 | ✅ | ✅ | Texto principal |
| Blanco sobre navy | 19,31:1 | ✅ | ✅ | Texto en oscuro |
| **Morado sobre blanco** | **6,85:1** | ✅ | ✅ | **Primario funcional: enlaces, botones, foco** |
| Blanco sobre morado | 6,85:1 | ✅ | ✅ | Texto en botón primario |
| Magenta sobre blanco | 4,48:1 | ❌ | ✅ | Solo texto grande o decoración |
| Naranja sobre blanco | 2,68:1 | ❌ | ❌ | **Nunca texto.** Solo degradado |
| Navy sobre naranja | 7,21:1 | ✅ | ✅ | Aceptable si el naranja es fondo grande |
| **Morado sobre navy** | **2,82:1** | ❌ | ❌ | **Prohibido** — usar `--purple-on-dark` |

El morado es el único color de marca que aguanta texto pequeño sobre blanco. Por eso es el primario.

---

## 4. Tokens del sistema

Entregados y listos para usar en `design/tokens.css`. Resumen de decisiones:

**Neutrales con sesgo elegido.** Construidos sobre el matiz del navy (232°) con 14% de saturación, en lugar de grises puros. Un gris neutro al lado de esta paleta se ve muerto; estos acompañan.

**Semánticos que no colisionan.** Éxito `#15803D`, aviso `#B45309`, error `#B3261E`, información `#1D4ED8`. Los cuatro superan 5:1 sobre blanco, y ninguno se confunde con el naranja o el magenta de marca — precisamente porque esos dos ya no aparecen planos.

**Tema oscuro completo**, con los tres estados que exige el navegador: preferencia del sistema, claro forzado y oscuro forzado. El fondo oscuro es el navy de marca, con una rampa de superficies derivada de él (`#0F1338`, `#171B45`) en lugar de un único valor plano. Los semánticos se aclaran: los cuatro superan 9:1 sobre navy.

**Densidad por portal.** Misma identidad, distinta sensación, tal como pide `docs/03 §F3.6`: el portal del creador con radios amplios y más aire; el backoffice denso y de esquinas más duras; el de marca en el medio. Es un token, no tres sistemas de diseño.

---

## 5. Tipografía — lo que el manual no define

El manual no menciona ninguna tipografía, y el kit usa DejaVu Sans, que es una fuente de sistema, no una elección de marca. Hay que decidirlo, y decidirlo ahora: cambiar de tipografía después de construir tres portales es caro.

Lo que pide este producto en concreto: personalidad en las landings, **legibilidad a 13 px en tablas densas** en el backoffice, y numerales tabulares porque el sistema está lleno de importes, correlativos, RUC y métricas en columnas.

### Propuesta (recomendada)

| Rol | Familia | Por qué |
|---|---|---|
| **Display** — landings, títulos, cifras destacadas | **Sora** | Geométrica con carácter, hereda bien la geometría circular del isotipo, y aguanta el trackeo negativo en tamaños grandes |
| **Interfaz** — todo el producto | **Plus Jakarta Sans** | Cálida y muy legible en tamaños pequeños, con numerales tabulares. Menos ubicua que las opciones obvias |
| **Datos** — IDs, importes, series, credenciales | **IBM Plex Mono** | El sistema está lleno de identificadores (`DEC-005`, `F001-00347`, RUC). Un monoespaciado los hace escaneables |

Las tres son de código abierto y están en Google Fonts, así que no hay licencias que gestionar ni coste por dominio.

**Alternativa más conservadora:** una sola familia, `Plus Jakarta Sans`, para display e interfaz, más el monoespaciado. Menos peso de carga y menos decisiones; a cambio, las landings pierden algo de personalidad.

> **Nota sobre el wordmark.** El trackeo amplísimo de "SOCIAL" (0,42em) es un rasgo propio de la marca. Reutilizarlo en etiquetas y microtítulos —no en texto corrido— es la forma más barata de que toda la interfaz se sienta de LATAM Social sin repetir el logotipo por todas partes. Token: `--tracking-brand`.

---

## 6. Reglas del degradado

El degradado es el activo visual más reconocible de la marca. Como todo lo memorable, se gasta con el uso.

| Sí | No |
|---|---|
| Fondo de héroe en landings | Fondo de tablas o formularios |
| El isotipo y sus derivados | Relleno de botones secundarios |
| Una barra de acento fina (2–4 px) | Texto de cuerpo con degradado |
| Estados de celebración: nivel alcanzado, campaña completada | Iconos pequeños (se convierte en una mancha) |
| Ilustración y gráficos de marketing | Series de datos en gráficas |

**Ángulo canónico: 45°**, naranja abajo-izquierda → morado arriba-derecha, tal como está construido en los SVG originales. No inventar otros ángulos.

En gráficas y visualizaciones, el degradado **no se usa para codificar datos**: las series usan la paleta categórica que se definirá en la iteración 3.2, derivada del morado y los neutrales.

---

## 7. Uso del logotipo por portal

| Superficie | Versión | Nota |
|---|---|---|
| Landings públicas | Horizontal, con descriptor | Es donde la marca tiene que explicarse |
| Backoffice | Isotipo + "LATAM Social" en texto | El descriptor sobra; el equipo ya sabe dónde está |
| Portal del creador (PWA) | Isotipo en la cabecera, horizontal en el arranque | En móvil el horizontal no cabe con dignidad |
| Portal de marca | Horizontal sin descriptor | Contexto ejecutivo |
| Documentos fiscales | **Ninguno de estos** | Una factura la emite la sociedad, no la marca: lleva la identidad de la `LegalEntity` (`DEC-023`, `BR-LE-010`) |
| Correo transaccional | Horizontal, ≥120 px | Por debajo, isotipo |

La última fila importa: `docs/11` y `docs/12` establecieron que lo fiscal va con identidad de sociedad y lo operativo con identidad de marca. La marca no aparece en una factura.

---

## 8. Assets entregados

En `marca/derivados/`:

| Archivo | Qué es |
|---|---|
| `logo-horizontal.svg` · `logo-vertical.svg` | Contornados y con lienzo recortado |
| `logo-horizontal-dark.svg` | Para fondos oscuros, con el descriptor legible |
| `isotipo.svg` | El ojo, con el aro blanco intacto |
| `favicon.svg` + PNG 16/32/48/180/192/512 | **Con la pupila corregida** |
| `icon-maskable.svg` + PNG 192/512 | Zona segura de Android al 78% |
| `icon-monochrome.svg` | Silueta para notificaciones |
| `og-image.png` | 1200×630 para redes y WhatsApp |
| `site.webmanifest` | PWA del portal del creador |
| `*_outlined.svg` | Los originales contornados, sin recortar, por si se prefiere conservar el lienzo |

Y en `design/tokens.css`: el sistema completo de variables, listo para la Fase 3.

Los archivos originales del kit quedan intactos en `marca/`. Nada se sobrescribió.

---

## 9. Impacto en el roadmap

**La Fase 3 ya no arranca en blanco.** Las iteraciones 3.1 y 3.2 se reducen: la paleta, los neutrales, los semánticos, el tema oscuro y la tipografía están decididos y documentados. Lo que queda es aplicarlos a componentes y pantallas.

| Iteración | Antes | Ahora |
|---|---|---|
| 3.2 Tokens | Definir desde cero | ✅ Entregado — solo validar |
| 3.3 Componentes | Sin base visual | Parte de tokens reales |
| 3.6 Identidad por portal | Por definir | Resuelto con densidad por portal |

**Ahorro estimado: 3 a 5 días de la Fase 3.** El resto de la fase (journeys, wireframes, estados) no cambia.

---

## 10. Preguntas abiertas

| # | Pregunta | Bloquea |
|---|---|---|
| **Q-29** | ¿Se aprueba la propuesta tipográfica, o hay una tipografía corporativa ya comprada? | Iteración 3.2 |
| **Q-30** | ¿Existe versión editable del wordmark (AI/Figma) con la tipografía real que usó el diseñador? Si la hay, mis contornos deberían sustituirse por los suyos. | Calidad del logotipo |
| **Q-31** | ¿Se corrige el kit original con estas versiones, o conviven? Recomiendo sustituir: mantener dos favicon distintos garantiza que alguien use el roto. | Gobernanza de marca |
| **Q-32** | ¿"LATAM Social" está registrada, y a nombre de qué sociedad? (`Q-18`, ya abierta) | Textos legales |
