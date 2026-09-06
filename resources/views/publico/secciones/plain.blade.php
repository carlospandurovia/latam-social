{{-- Sólo el encabezado y la bajada (L-3).

     Existe para las franjas que son una frase y nada más —«el problema», el
     cierre— y para que una franja mal configurada tenga siempre algo que
     dibujar. Es el destino de reserva del despachador de `publico.landing`. --}}
<section id="{{ $s->code }}" class="mx-auto max-w-6xl px-6 py-16 sm:py-20">
  <div class="max-w-3xl">
  {{-- Alineado a la izquierda como todo lo demas, y NO centrado.

       Estaba centrado, y mirando la pantalla se vio lo que eso produce: en «el
       problema» el encabezado salia centrado y sus tres bloques debajo a la
       izquierda, o sea dos alineaciones dentro de la misma franja. Es el mismo
       defecto que la `L-3` arreglo entre franjas --un solo borde izquierdo-- y
       aqui estaba DENTRO de una. --}}
  @include('publico.secciones.encabezado', ['s' => $s])

  @if ($s->bloques->isNotEmpty())
    <div class="mt-8 space-y-5">
      @foreach ($s->bloques as $b)
        <div class="aparece" style="--retraso: {{ $loop->index * 70 }}ms">
          <h3 class="text-base font-semibold text-slate-900">{{ $b->heading }}</h3>
          @if ($b->body)
            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $b->body }}</p>
          @endif
        </div>
      @endforeach
    </div>
  @endif

  @include('publico.secciones.cta', ['s' => $s])
  </div>
</section>
