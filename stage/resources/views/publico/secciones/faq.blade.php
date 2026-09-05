{{-- Las preguntas (L-3).

     `<details>` y no un acordeón de JavaScript: se abre y se cierra sin una
     línea de script, el navegador ya sabe hacerlo con el teclado, y —lo que
     importa aquí— **el texto de la respuesta está en el HTML aunque esté
     cerrado**, así que un buscador lo lee.

     Van ANTES del formulario, y eso es una corrección: en `/creadores` salían
     detrás, o sea la sección que quita objeciones puesta después del punto de
     conversión. Ahora el orden es un dato (`sort_order`) y se arregla desde el
     panel, sin desplegar. --}}
{{-- La columna es estrecha --una pregunta y su respuesta no se leen a 1100
     px-- pero va dentro del mismo contenedor de 6xl que las tarjetas y con el
     mismo margen izquierdo. Centrarla sobre la pagina le daba a la franja un
     borde izquierdo distinto al de las de arriba, y eso se lee como un error de
     maquetacion aunque cada franja por separado este bien. Se vio mirando la
     pantalla, que es donde se ven estas cosas. --}}
<section id="{{ $s->code }}" class="mx-auto max-w-6xl px-6 py-16 sm:py-20">
  <div class="max-w-3xl">
  @include('publico.secciones.encabezado', ['s' => $s])

  @if ($s->bloques->isNotEmpty())
    <div class="{{ ($s->eyebrow || $s->title || $s->subtitle) ? 'mt-8' : '' }} divide-y divide-slate-200 border-y border-slate-200">
      @foreach ($s->bloques as $b)
        <details class="group py-4">
          <summary class="flex min-h-[2.75rem] cursor-pointer list-none items-center justify-between gap-4 py-1 text-sm font-semibold text-slate-900">
            {{ $b->heading }}
            <span class="shrink-0 text-slate-500 transition-transform group-open:rotate-45" aria-hidden="true">+</span>
          </summary>
          @if ($b->body)
            <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $b->body }}</p>
          @endif
        </details>
      @endforeach
    </div>
  @endif

  @include('publico.secciones.cta', ['s' => $s])
  </div>
</section>
