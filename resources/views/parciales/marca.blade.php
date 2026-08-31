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
     puede contener ni comillas ni `;`. --}}
@php
    $familia = $marca['tipografia'];
    $fuenteUrl = 'https://fonts.bunny.net/css?family='
        .rawurlencode(str_replace(' ', '-', mb_strtolower($familia))).':400,500,600,700';
@endphp
<link rel="icon" href="{{ $marca['favicon'] }}">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="{{ $fuenteUrl }}" rel="stylesheet">
@vite('resources/css/app.css')
<style>
  :root {
    --marca: {{ $marca['color'] }};
    --marca-2: {{ $marca['color2'] }};
    --barra: {{ $marca['barra'] }};
    /* Tailwind resuelve `font-sans` contra esta variable, asi que cambiarla
       aqui cambia la tipografia de toda la aplicacion sin recompilar nada. */
    --font-sans: '{{ $familia }}', ui-sans-serif, system-ui, sans-serif;
  }
  .bg-navy { background-color: var(--barra); }
  .degradado-marca { background-image: linear-gradient(135deg, var(--marca), var(--marca-2)); }
  .text-marca-700 { color: var(--marca); }
  .text-marca-800 { color: color-mix(in srgb, var(--marca) 80%, black); }
  .bg-marca-50 { background-color: color-mix(in srgb, var(--marca) 8%, white); }
  .border-marca-200 { border-color: color-mix(in srgb, var(--marca) 25%, white); }
  .bg-marca-500 { background-color: var(--marca); }
</style>
