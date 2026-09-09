{{-- D-6: qué campañas hay que mirar hoy, y por qué.

     Tres cosas que esta tabla hace a propósito:

     1. NO se recorta por periodo. Los filtros de cliente, país, sociedad y
        campaña sí; el periodo no. Una campaña retrasada lo está hoy, y
        esconderla porque terminó fuera de «los últimos 30 días» sería esconder
        justo el caso que esta tabla existe para enseñar (`DEC-320`).
     2. Cada semáforo lleva su MOTIVO escrito. Un color sin explicación obliga a
        abrir la campaña para saber qué mira, y a la tercera vez deja de mirarse.
     3. Los umbrales que la colorean se ven en la cabecera y se enlazan a donde
        se cambian. Un número que decide un color y no se puede ni encontrar ni
        tocar es exactamente lo que `DEC-190` prohíbe. --}}

@php
  $base = $filtros->comoConsulta();
  $chips = $seguimiento['niveles'];
  $puntos = [
    'roja' => 'bg-rose-500', 'ambar' => 'bg-amber-500', 'verde' => 'bg-emerald-500',
  ];
  $visibles = array_slice($seguimiento['filas'], 0, 25);
@endphp

<section class="mt-8 bg-white rounded-xl border border-slate-200 overflow-hidden"
         aria-labelledby="t-seguimiento">

  <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 id="t-seguimiento" class="font-semibold text-slate-900">Campañas que requieren seguimiento</h2>
      <p class="text-sm text-slate-500 mt-0.5">
        Las que están vivas hoy. Este bloque <strong>no</strong> se recorta por periodo;
        los demás filtros sí se le aplican.
      </p>
    </div>

    @can('campaign.manage')
      <a href="{{ route('umbrales.index') }}"
         class="shrink-0 text-xs text-slate-500 underline decoration-dotted underline-offset-2
                hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 rounded">
        Ámbar a {{ $seguimiento['umbrales']['dias'] }} días del cierre bajo el
        {{ $seguimiento['umbrales']['avance'] }} % · cambiar umbrales
      </a>
    @endcan
  </div>

  {{-- Los contadores son también el filtro. Un número que se puede pulsar
       ahorra el desplegable de al lado y dice lo mismo. --}}
  <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap gap-2">
    @foreach ($chips as $clave => $etiqueta)
      @php($cuantas = $clave === 'todas' ? $seguimiento['total'] : ($seguimiento['conteo'][$clave] ?? 0))
      <a href="{{ route('panel', $clave === 'todas' ? $base : $base + ['semaforo' => $clave]) }}"
         aria-current="{{ $semaforo === $clave ? 'true' : 'false' }}"
         class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition
                focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2
                {{ $semaforo === $clave
                     ? 'border-slate-800 bg-slate-800 text-white'
                     : 'border-slate-200 text-slate-600 hover:bg-slate-50' }}">
        @if ($clave !== 'todas')
          <span class="inline-block w-2 h-2 rounded-full {{ $puntos[$clave] }}"></span>
        @endif
        {{ $etiqueta }}
        <span class="tabular-nums {{ $semaforo === $clave ? 'text-white/70' : 'text-slate-400' }}">
          {{ $cuantas }}
        </span>
      </a>
    @endforeach
  </div>

  @if ($seguimiento['filas'] === [])
    <p class="px-5 py-8 text-center text-sm text-slate-400">
      @if ($seguimiento['total'] === 0)
        No hay campañas vivas con los filtros puestos.
      @else
        Ninguna campaña en ese estado. Es una buena noticia, no un fallo.
      @endif
    </p>
  @else
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" class="px-5 py-2.5 text-left font-medium">Campaña</th>
            <th scope="col" class="px-3 py-2.5 text-left font-medium hidden lg:table-cell">Cliente</th>
            <th scope="col" class="px-3 py-2.5 text-left font-medium hidden sm:table-cell">Cierre</th>
            <th scope="col" class="px-3 py-2.5 text-right font-medium hidden md:table-cell">Creadores</th>
            <th scope="col" class="px-3 py-2.5 text-right font-medium">Avance</th>
            <th scope="col" class="px-5 py-2.5 text-left font-medium">Estado</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @foreach ($visibles as $fila)
            <tr class="align-top hover:bg-slate-50/60">
              <td class="px-5 py-3">
                <a href="{{ route('campanas.show', $fila['id']) }}"
                   class="font-medium text-slate-900 hover:text-marca-700
                          focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 rounded">
                  {{ $fila['nombre'] }}
                </a>
                <p class="text-xs text-slate-400 tabular-nums">{{ $fila['codigo'] }}</p>
                {{-- En movil, el cliente y el cierre no caben en su columna:
                     se dicen aqui en vez de desaparecer. --}}
                <p class="mt-0.5 text-xs text-slate-500 lg:hidden">{{ $fila['cliente'] }}</p>
              </td>

              <td class="px-3 py-3 text-slate-600 hidden lg:table-cell">{{ $fila['cliente'] }}</td>

              <td class="px-3 py-3 hidden sm:table-cell whitespace-nowrap">
                @if ($fila['fin'] === null)
                  <span class="text-slate-400">sin fecha</span>
                @else
                  <span class="text-slate-700 tabular-nums">{{ $fila['fin_texto'] }}</span>
                  <p class="text-xs {{ $fila['dias'] < 0 ? 'text-rose-600' : 'text-slate-400' }} tabular-nums">
                    @if ($fila['dias'] < 0)
                      hace {{ abs($fila['dias']) }} d
                    @elseif ($fila['dias'] === 0)
                      hoy
                    @else
                      en {{ $fila['dias'] }} d
                    @endif
                  </p>
                @endif
              </td>

              <td class="px-3 py-3 text-right hidden md:table-cell whitespace-nowrap tabular-nums">
                @if ($fila['objetivo'] > 0)
                  <span class="{{ $fila['aceptados'] < $fila['objetivo'] ? 'text-slate-900' : 'text-slate-500' }}">
                    {{ $fila['aceptados'] }}/{{ $fila['objetivo'] }}
                  </span>
                @else
                  <span class="text-slate-500">{{ $fila['aceptados'] }}</span>
                  <p class="text-xs text-slate-400">sin cupo</p>
                @endif
              </td>

              <td class="px-3 py-3 text-right whitespace-nowrap">
                @if ($fila['avance'] === null)
                  <span class="text-slate-400 text-xs">no medible</span>
                @else
                  <span class="tabular-nums text-slate-900">{{ $fila['avance'] }} %</span>
                  <div class="mt-1 h-1.5 w-16 ml-auto rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full {{ $puntos[$fila['nivel']] }}"
                         style="width: {{ max(2, (int) round($fila['avance'])) }}%"></div>
                  </div>
                  <p class="text-xs text-slate-400 tabular-nums">
                    {{ $fila['logrados'] }}/{{ $fila['entregables'] }}
                  </p>
                @endif
              </td>

              <td class="px-5 py-3">
                <span class="inline-flex items-center gap-2">
                  <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0 {{ $puntos[$fila['nivel']] }}"></span>
                  <span class="text-slate-800 font-medium">{{ $fila['nivel_texto'] }}</span>
                </span>
                <p class="mt-0.5 text-xs text-slate-500 max-w-md">{{ $fila['motivo'] }}</p>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    @if (count($seguimiento['filas']) > count($visibles))
      <p class="px-5 py-3 border-t border-slate-100 text-xs text-slate-500">
        Se enseñan {{ count($visibles) }} de {{ count($seguimiento['filas']) }}.
        <a href="{{ route('campanas.index') }}" class="underline decoration-dotted underline-offset-2">
          Ver todas las campañas
        </a>
      </p>
    @endif
  @endif

  @if ($seguimiento['techo'])
    <p class="px-5 py-3 border-t border-amber-100 bg-amber-50 text-xs text-amber-800">
      Hay más campañas vivas de las que este bloque puede ordenar de una vez
      ({{ $seguimiento['tope'] }}). Lo que se ve es correcto, pero no está
      completo: acota con los filtros de arriba.
    </p>
  @endif
</section>
