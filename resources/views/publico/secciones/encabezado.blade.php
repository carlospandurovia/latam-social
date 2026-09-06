{{-- El encabezado de una franja (L-3).

     Los tres campos son opcionales y por eso está en un parcial: sin él, las
     cuatro plantillas de franja repetirían el mismo `@if` tres veces cada una, y
     el día que el sobretítulo cambie de color habría que acordarse de cuatro
     sitios. Es el defecto `V-8` —tres `<h2>` hermanos sin nada que los agrupe—
     resuelto de una vez para todas las franjas. --}}
@if ($s->eyebrow || $s->title || $s->subtitle)
  <div class="max-w-2xl {{ $centrado ?? false ? 'mx-auto text-center' : '' }}">
    @if ($s->eyebrow)
      <p class="text-xs font-semibold uppercase tracking-[0.18em] texto-degradado">{{ $s->eyebrow }}</p>
    @endif

    @if ($s->title)
      <h2 class="fuente-titulos mt-2 text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">
        {{ $s->title }}
      </h2>
    @endif

    @if ($s->subtitle)
      <p class="mt-3 text-base leading-relaxed text-slate-600">{{ $s->subtitle }}</p>
    @endif
  </div>
@endif
