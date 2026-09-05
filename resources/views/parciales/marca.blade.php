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
@foreach ($familias as $f)
  <link rel="stylesheet"
        href="https://fonts.bunny.net/css?family={{ rawurlencode(str_replace(' ', '-', mb_strtolower($f))) }}:400,500,600,700">
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
</style>
