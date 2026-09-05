{{-- La marca, en el `<head>` de las tres plantillas que la llevaban escrita (9.17).

     ### Qué hace aquí una hoja de estilo

     `bg-navy`, `degradado-marca`, `text-marca-700`, `bg-marca-50` y
     `border-marca-200` se usaban en las plantillas desde la Fase 4 y **no
     estaban definidas en ninguna parte**: `app.css` sólo declara la tipografía.
     Tailwind no genera lo que no conoce, así que la barra lateral no tenía color
     y los avisos de marca salían en blanco sobre blanco. Salió al buscar dónde
     cambiar el color, que es la clase de cosa que sólo se encuentra cuando se
     va a tocar (`T-72`).

     No pueden vivir en `app.css` como clases fijas: el color viene de la base y
     Tailwind se compila en el despliegue. Los tonos intermedios se calculan con
     `color-mix()` a partir del color de marca, así que un color nuevo arrastra
     su familia entera sin que nadie escriba cinco valores.

     ### Y por qué se puede escribir aquí sin miedo

     `Marca::datos()` devuelve los colores ya comprobados contra `#RRGGBB` y la
     tipografía contra letras, números y espacios. Lo que llega a este archivo no
     puede contener ni comillas ni `;`.

     ### L-1

     - `--degradado` llega **ya armado** desde el servicio. Componer una regla
       CSS con valores de la base es código, no maquetación; y el ángulo es un
       dato de marca (`docs/14 §6`: 45° es el canónico), no una constante que le
       toque decidir a una plantilla.
     - `--font-sans` la resuelve Tailwind para `font-sans`, así que cambiarla
       aquí cambia la tipografía de toda la aplicación sin recompilar nada.
       `--font-display` es la de titulares (`docs/14 §5`).
     - `.texto-degradado` pinta el degradado **como texto**. `docs/14 §6` lo
       prohíbe en texto de cuerpo —se convierte en una mancha— y lo permite en
       titulares, que es donde la marca tiene que explicarse.

     ### L-3

     El sistema visual: la cabecera pegada, el botón de marca, la tarjeta, el
     número de paso, el panel móvil y la entrada de las tarjetas al aparecer.

     Están **aquí** y no en `app.css` por lo mismo que los colores: todo esto usa
     `var(--degradado)` y `var(--marca)`, que salen de la base, y Tailwind se
     compila en el despliegue —no puede saber de qué color es una marca que
     todavía no existe—.

     `.aparece` sólo esconde algo cuando la clase `js` está puesta en el `<html>`,
     y esa clase **la pone el propio script**. Sin JavaScript, o si el script
     falla, la página se ve entera. Una animación de entrada que puede dejar la
     portada en blanco es peor que no tener animación.

     ### Y por qué aquí no hay ni un comentario `/* */`

     Porque **un comentario dentro de `<style>` se manda al navegador en cada
     petición**. No es sólo peso: le enseña a un visitante el porqué interno de
     nuestras decisiones. Se descubrió al ponerse roja una prueba de `9.20` que
     comprueba que quien lleva finanzas **no ve el área de Marca**: el nombre de
     una clase, escrito en un comentario de CSS, salía en la página. --}}
@php
    $familia = $marca['tipografia'];
    $titulares = $marca['tipografiaTitulos'];

    // L-1: dos familias, no una. `docs/14 §5` separa la tipografia de TITULARES
    // --con caracter, para las landings-- de la de INTERFAZ --legible a 13 px en
    // tablas densas--.
    //
    // Un `<link>` por familia y no una peticion con `|`: el separador multiple
    // del API es el de Google Fonts v1 y bunny lo replica, pero desde aqui no se
    // puede comprobar --el contenedor no alcanza el servidor de fuentes-- y una
    // URL que falla en silencio deja la pagina con la letra del sistema sin que
    // nadie se entere. Dos peticiones funcionan seguro, y con `preconnect`
    // cuestan lo mismo. Si son la misma familia, una sola.
    $familias = array_values(array_unique([$titulares, $familia]));
@endphp
<link rel="icon" href="{{ $marca['favicon'] }}">
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
{{-- L-6: cada familia pide SOLO los pesos que usa, y con `display=swap`.

     La de titulares se usa **siempre en negrita** --se comprobo buscando
     `fuente-titulos` una por una: las seis salen con `font-bold`-- asi que pedir
     cuatro pesos para ella eran tres archivos que el navegador descargaba para
     no usarlos nunca. La de interfaz si usa los cuatro: `font-medium` aparece en
     cinco sitios, y quitarlo habria sido cambiar el diseño para ahorrar un
     archivo, que es el intercambio al reves.

     `display=swap` es lo que de verdad se nota: sin él, el navegador **esconde
     el texto** hasta que la fuente llega —el titular sale en blanco durante un
     instante— y con él lo pinta con la del sistema y lo cambia después. En una
     portada donde lo primero que hay que leer es el titular, eso es la
     diferencia entre «lento» y «roto». --}}
@foreach ($familias as $f)
  <link rel="stylesheet"
        href="https://fonts.bunny.net/css?family={{ rawurlencode(str_replace(' ', '-', mb_strtolower($f))) }}:{{ $f === $titulares && $f !== $familia ? '700' : '400,500,600,700' }}&amp;display=swap">
@endforeach
<meta name="theme-color" content="{{ $marca['barra'] }}">
@vite('resources/css/app.css')
<style>
  :root {
    --marca: {{ $marca['color'] }};
    --marca-2: {{ $marca['color2'] }};
    --barra: {{ $marca['barra'] }};
    --degradado: {{ $marca['degradado'] }};
    --font-sans: '{{ $familia }}', ui-sans-serif, system-ui, sans-serif;
    --font-display: '{{ $titulares }}', '{{ $familia }}', ui-sans-serif, system-ui, sans-serif;
  }
  .bg-navy { background-color: var(--barra); }
  .degradado-marca { background-image: var(--degradado); }
  .texto-degradado {
    background-image: var(--degradado);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }
  .fuente-titulos { font-family: var(--font-display); }
  .text-marca-700 { color: var(--marca); }
  .text-marca-800 { color: color-mix(in srgb, var(--marca) 80%, black); }
  .bg-marca-50 { background-color: color-mix(in srgb, var(--marca) 8%, white); }
  .border-marca-200 { border-color: color-mix(in srgb, var(--marca) 25%, white); }
  .bg-marca-500 { background-color: var(--marca); }
  .prosa { color: #334155; line-height: 1.7; }
  .prosa h2 { font-family: var(--font-display); font-size: 1.375rem; font-weight: 700;
              color: #0f172a; margin: 2.25rem 0 .75rem; line-height: 1.3; }
  .prosa h3 { font-size: 1.05rem; font-weight: 600; color: #0f172a; margin: 1.75rem 0 .5rem; }
  .prosa p { margin: 0 0 1rem; }
  .prosa ul, .prosa ol { margin: 0 0 1rem 1.25rem; }
  .prosa ul { list-style: disc; }
  .prosa ol { list-style: decimal; }
  .prosa li { margin: .35rem 0; }
  .prosa a { color: var(--marca); text-decoration: underline; }
  .prosa strong { color: #0f172a; font-weight: 600; }
  .prosa hr { border: 0; border-top: 1px solid #e2e8f0; margin: 2rem 0; }
  .prosa table { width: 100%; border-collapse: collapse; margin: 0 0 1rem; font-size: .9em; }
  .prosa th, .prosa td { border: 1px solid #e2e8f0; padding: .5rem .75rem; text-align: left; }
  .prosa th { background: #f8fafc; font-weight: 600; color: #0f172a; }
  .prosa blockquote { border-left: 3px solid var(--marca); padding-left: 1rem; color: #475569; margin: 0 0 1rem; }
  .prosa code { background: #f1f5f9; padding: .1rem .3rem; border-radius: .25rem; font-size: .9em; }

  {{-- L-6: el foco, visible y con el color de la marca.

       Tailwind quita el contorno del navegador y pone el suyo, que en una
       instalacion white label no tiene por que contrastar con nada. Quien
       navega con teclado --y quien usa un lector de pantalla-- necesita ver
       DONDE esta, y `:focus-visible` no molesta a quien usa el raton porque no
       se dispara al hacer clic. --}}
  :focus-visible {
    outline: 2px solid var(--marca);
    outline-offset: 2px;
    border-radius: .25rem;
  }
  .degradado-marca :focus-visible,
  .cabecera-pegada .boton-marca:focus-visible { outline-color: #fff; }

  .salto-al-contenido {
    position: absolute; left: -9999px; top: 0; z-index: 60;
    background: var(--marca); color: #fff; padding: .625rem 1rem;
    border-radius: 0 0 .5rem 0; font-size: .875rem; font-weight: 600;
  }
  .salto-al-contenido:focus { left: 0; }

  .cabecera-pegada {
    position: sticky; top: 0; z-index: 50;
    background: rgb(255 255 255 / .82);
    backdrop-filter: saturate(180%) blur(12px);
    -webkit-backdrop-filter: saturate(180%) blur(12px);
    border-bottom: 1px solid #f1f5f9;
  }

  .enlace-nav { position: relative; color: #475569; padding-bottom: .25rem; }
  .enlace-nav::after {
    content: ''; position: absolute; left: 0; bottom: 0; height: 2px; width: 0;
    background-image: var(--degradado); border-radius: 2px;
    transition: width .22s ease;
  }
  .enlace-nav:hover { color: #0f172a; }
  .enlace-nav:hover::after { width: 100%; }

  .boton-marca {
    display: inline-flex; align-items: center; gap: .5rem;
    background-image: var(--degradado); color: #fff;
    padding: .75rem 1.25rem; border-radius: .75rem;
    font-size: .875rem; font-weight: 600; line-height: 1;
    box-shadow: 0 8px 20px -8px color-mix(in srgb, var(--marca) 65%, transparent);
    transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
  }
  .boton-marca:hover {
    transform: translateY(-1px); filter: saturate(1.08);
    box-shadow: 0 12px 26px -8px color-mix(in srgb, var(--marca) 70%, transparent);
  }
  .boton-marca-sm { padding: .5rem .9rem; }

  .boton-fantasma {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .75rem 1.25rem; border-radius: .75rem;
    font-size: .875rem; font-weight: 600; line-height: 1;
    border: 1px solid rgb(255 255 255 / .45); color: #fff;
    transition: background-color .18s ease, border-color .18s ease;
  }
  .boton-fantasma:hover { background: rgb(255 255 255 / .12); border-color: rgb(255 255 255 / .8); }

  .tarjeta {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem;
    transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
  }
  .tarjeta:hover {
    transform: translateY(-3px);
    border-color: color-mix(in srgb, var(--marca) 30%, white);
    box-shadow: 0 14px 30px -14px rgb(15 23 42 / .28);
  }

  .marco-icono {
    display: inline-flex; align-items: center; justify-content: center;
    height: 2.5rem; width: 2.5rem; border-radius: .75rem;
    background: color-mix(in srgb, var(--marca) 10%, white);
    color: var(--marca);
  }

  .numero-paso {
    display: inline-flex; align-items: center; justify-content: center;
    height: 2rem; width: 2rem; border-radius: 999px;
    background-image: var(--degradado); color: #fff;
    font-size: .8125rem; font-weight: 700;
  }

  .panel-movil > summary::-webkit-details-marker { display: none; }
  .panel-movil .icono-cerrar { display: none; }
  .panel-movil[open] .icono-abrir { display: none; }
  .panel-movil[open] .icono-cerrar { display: block; }
  .cajon-movil {
    position: absolute; left: 0; right: 0; top: 100%;
    background: #fff; border-bottom: 1px solid #e2e8f0;
    padding: .5rem 1.5rem 1.5rem;
    box-shadow: 0 18px 30px -20px rgb(15 23 42 / .35);
  }

  .js .aparece { opacity: 0; transform: translateY(14px); }
  .js .aparece.visible {
    opacity: 1; transform: none;
    transition: opacity .5s ease var(--retraso, 0ms), transform .5s ease var(--retraso, 0ms);
  }

  .voz { animation: flotar 9s ease-in-out infinite; animation-delay: var(--retraso, 0ms);
         transform-box: fill-box; transform-origin: center; }
  @keyframes flotar {
    0%, 100% { transform: translateY(0); opacity: .85; }
    50%      { transform: translateY(-6px); opacity: 1; }
  }

  @media (prefers-reduced-motion: reduce) {
    .voz { animation: none; }
    .js .aparece, .js .aparece.visible { opacity: 1; transform: none; transition: none; }
    .tarjeta, .boton-marca, .enlace-nav::after { transition: none; }
    .tarjeta:hover, .boton-marca:hover { transform: none; }
  }
</style>
