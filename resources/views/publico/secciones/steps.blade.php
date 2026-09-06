{{-- Los pasos, en orden (L-3).

     `<ol>` y no `<div>`: son pasos numerados y un lector de pantalla tiene que
     poder decir «3 de 4». El número lo pone la plantilla a partir del orden, y
     no se escribe dentro del título: hasta hoy los bloques sembrados se llamaban
     «1. Postulas», y ese «1.» hacía que insertar un paso nuevo en medio obligara
     a renumerar cuatro títulos a mano. --}}
<section id="{{ $s->code }}" class="border-y border-slate-100 bg-slate-50/70">
  <div class="mx-auto max-w-6xl px-6 py-16 sm:py-20">
    @include('publico.secciones.encabezado', ['s' => $s])

    @if ($s->bloques->isNotEmpty())
      <ol class="{{ ($s->eyebrow || $s->title || $s->subtitle) ? 'mt-10' : '' }} grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($s->bloques as $b)
          <li class="tarjeta aparece relative p-6" style="--retraso: {{ $loop->index * 70 }}ms">
            <span class="numero-paso" aria-hidden="true">{{ $loop->iteration }}</span>

            <h3 class="mt-4 text-sm font-semibold text-slate-900">{{ $b->heading }}</h3>

            @if ($b->body)
              <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $b->body }}</p>
            @endif
          </li>
        @endforeach
      </ol>
    @endif

    @include('publico.secciones.cta', ['s' => $s])
  </div>
</section>
