{{-- D-5: en qué estado están las campañas, y el embudo operativo.

     Los ocho estados salen SIEMPRE, también los que están a cero: un estado que
     desaparece al vaciarse obliga a recordar cuáles existen para notar que falta
     uno, y «ninguna en revisión» es una respuesta tan útil como «cuatro». --}}

<div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">

  <section class="bg-white rounded-xl border border-slate-200 p-6" aria-labelledby="t-estados">
    <h2 id="t-estados" class="font-semibold text-slate-900 mb-1">Estado de las campañas</h2>
    <p class="text-sm text-slate-500 mb-4">
      Las que tocan el periodo elegido, con lo que se le cobra al cliente.
    </p>

    @php($maximo = max(1, max(array_column($estados, 'cantidad'))))

    <dl class="space-y-2.5">
      @foreach ($estados as $fila)
        <div>
          <div class="flex items-baseline justify-between gap-3 text-sm">
            <dt class="{{ $fila['cantidad'] === 0 ? 'text-slate-400' : 'text-slate-700' }}">
              {{ $fila['nombre'] }}
            </dt>
            <dd class="shrink-0 flex items-baseline gap-3">
              @if ($fila['importe'] > 0)
                <span class="text-xs text-slate-400 tabular-nums">
                  {{ number_format($fila['importe'], 0) }}
                </span>
              @endif
              <span class="tabular-nums font-medium
                {{ $fila['cantidad'] === 0 ? 'text-slate-300' : 'text-slate-900' }}">
                {{ $fila['cantidad'] }}
              </span>
            </dd>
          </div>
          <div class="mt-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full bg-marca-500"
                 style="width: {{ $fila['cantidad'] === 0 ? 0 : max(3, round($fila['cantidad'] / $maximo * 100)) }}%"></div>
          </div>
        </div>
      @endforeach
    </dl>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 p-6" aria-labelledby="t-embudo">
    <h2 id="t-embudo" class="font-semibold text-slate-900 mb-1">Embudo operativo</h2>
    <p class="text-sm text-slate-500 mb-4">
      Cada peldaño cuenta su propia unidad, así que no hay porcentajes entre
      ellos: dividir publicaciones entre campañas daría un número con pinta de
      tasa y ningún significado.
    </p>

    @php($tope = max(1, max(array_column($embudo, 'cantidad'))))

    <ol class="space-y-3">
      @foreach ($embudo as $paso)
        <li>
          <div class="flex items-baseline justify-between gap-3 text-sm">
            <span class="{{ $paso['cantidad'] === 0 ? 'text-slate-400' : 'text-slate-700' }}">
              {{ $paso['etiqueta'] }}
            </span>
            <span class="shrink-0 flex items-baseline gap-2">
              <span class="tabular-nums font-semibold
                {{ $paso['cantidad'] === 0 ? 'text-slate-300' : 'text-slate-900' }}">
                {{ number_format($paso['cantidad']) }}
              </span>
              <span class="text-xs text-slate-400">{{ $paso['unidad'] }}</span>
            </span>
          </div>
          <div class="mt-1 h-2 rounded bg-slate-100 overflow-hidden">
            <div class="h-full rounded bg-marca-400"
                 style="width: {{ $paso['cantidad'] === 0 ? 0 : max(3, round($paso['cantidad'] / $tope * 100)) }}%"></div>
          </div>
        </li>
      @endforeach
    </ol>
  </section>

</div>
