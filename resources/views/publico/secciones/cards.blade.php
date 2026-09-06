{{-- Ventajas en tarjetas (L-3).

     Antes eran texto desnudo en una rejilla de tres —defecto `V-3`: «leen como
     una ficha técnica»—. Ahora cada una es una tarjeta con su icono, su borde y
     su elevación al pasar por encima.

     La rejilla es `sm:2 / lg:3` y no `sm:3`: a 640 px tres columnas dejan
     renglones de dos palabras. --}}
<section id="{{ $s->code }}" class="mx-auto max-w-6xl px-6 py-16 sm:py-20">
  @include('publico.secciones.encabezado', ['s' => $s])

  @if ($s->bloques->isNotEmpty())
    <div class="{{ ($s->eyebrow || $s->title || $s->subtitle) ? 'mt-10' : '' }} grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      @foreach ($s->bloques as $b)
        <div class="tarjeta aparece p-6" style="--retraso: {{ $loop->index * 70 }}ms">
          <span class="marco-icono">
            @include('parciales.icono', ['icono' => $b->icon, 'clase' => 'h-5 w-5'])
          </span>

          <h3 class="mt-4 text-base font-semibold text-slate-900">{{ $b->heading }}</h3>

          @if ($b->body)
            <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $b->body }}</p>
          @endif

          @if ($b->cta_label)
            <a href="{{ $b->cta_url ?: '#empezar' }}"
               @if (str_starts_with((string) $b->cta_url, 'https://')) target="_blank" rel="noopener" @endif
               data-evento="cta_bloque"
               class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-marca-700 hover:gap-2 transition-all">
              {{ $b->cta_label }} <span aria-hidden="true">→</span>
            </a>
          @endif
        </div>
      @endforeach
    </div>
  @endif

  @include('publico.secciones.cta', ['s' => $s])
</section>
