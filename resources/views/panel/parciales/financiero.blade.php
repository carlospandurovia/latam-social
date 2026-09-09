{{-- D-8 + D-12: el dinero, por moneda Y consolidado.

     `D-8` lo dejó sin total a propósito, con esta frase: «sumar soles y dólares
     en una cifra es la mentira más cara que puede contar un panel». Sigue siendo
     verdad, y por eso el total de `D-12` no sustituye a las líneas por moneda:
     va ENCIMA de ellas, dice con qué tasa se hizo, y cuando una moneda no se
     pudo convertir lo dice en vez de callarse un total de menos. --}}

<h2 class="mt-8 mb-1 text-sm font-semibold text-slate-500">Financiero</h2>
<p class="mb-3 text-xs text-slate-400">
  Totales consolidados en <strong>{{ $financiero['ajustes']['moneda'] }}</strong>, y debajo cada
  moneda por separado. De los filtros de arriba sólo se aplica el de <strong>cliente</strong>.
</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
  @foreach ($financiero['bloques'] as $bloque)
    <a href="{{ route($bloque['ruta']) }}"
       title="{{ $bloque['nota'] }}"
       class="block bg-white rounded-xl border border-slate-200 p-5 transition
              hover:border-slate-300 hover:shadow-sm
              focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2">
      <p class="text-sm text-slate-500">{{ $bloque['titulo'] }}</p>

      @if ($bloque['lineas'] === [])
        <p class="mt-1 text-2xl font-bold text-slate-300 tabular-nums">—</p>
        <p class="mt-1 text-xs text-slate-400">sin importes en este recorte</p>
      @else
        {{-- El consolidado primero: es la cifra que se lee de un vistazo. --}}
        @if ($bloque['consolidado']['total'] !== null)
          <p class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">
            {{ number_format($bloque['consolidado']['total'], 2, ',', '.') }}
            <span class="text-xs font-medium text-slate-400">{{ $bloque['consolidado']['moneda'] }}</span>
          </p>
        @else
          <p class="mt-1 text-2xl font-bold text-slate-300 tabular-nums">—</p>
        @endif

        {{-- Y el desglose, que es lo que de verdad hay guardado. --}}
        <dl class="mt-2 space-y-1 border-t border-slate-100 pt-2">
          @foreach ($bloque['lineas'] as $linea)
            <div class="flex items-baseline justify-between gap-2">
              <dt class="text-xs font-medium text-slate-400">{{ $linea['moneda'] }}</dt>
              <dd class="text-sm font-semibold text-slate-700 tabular-nums">
                {{ number_format($linea['importe'], 2, ',', '.') }}
              </dd>
            </div>
          @endforeach
        </dl>

        @if ($bloque['consolidado']['parcial'])
          <p class="mt-2 text-xs text-amber-700">
            Total parcial: queda fuera
            @foreach ($bloque['consolidado']['fuera'] as $suelto)
              {{ number_format($suelto['importe'], 2, ',', '.') }} {{ $suelto['moneda'] }}@if (!$loop->last), @endif
            @endforeach
            — sin tipo de cambio aplicable.
          </p>
        @elseif ($bloque['consolidado']['fecha'] !== null)
          <p class="mt-2 text-xs text-slate-400">
            Convertido con la tasa del {{ $bloque['consolidado']['fecha'] }}.
          </p>
        @endif
      @endif
    </a>
  @endforeach
</div>

@if (count($financiero['monedas']) > 1)
  <p class="mt-3 text-xs text-slate-500">
    Hay importes en {{ count($financiero['monedas']) }} monedas
    ({{ implode(' · ', $financiero['monedas']) }}), convertidos a
    {{ $financiero['ajustes']['moneda'] }} sólo para el total. Cada cifra guardada sigue en la suya.
  </p>
@endif

@unless ($financiero['ajustes']['confirmado'])
  <p class="mt-2 text-xs text-amber-700">
    Nadie ha confirmado todavía que {{ $financiero['ajustes']['moneda'] }} sea la moneda del
    negocio: es el valor de partida. Se revisa en Configuración → Moneda de consolidación.
  </p>
@endunless
