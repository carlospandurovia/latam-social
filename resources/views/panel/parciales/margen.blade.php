{{-- D-13: el margen, sólo para quien tiene `campaign.view_margin`.

     Es la cifra más delicada del panel: `BR-SEC-001` (🔴) prohíbe enseñarla a
     un cliente o a un creador, y desde `9.10a` tampoco la ve quien lleva la
     campaña. Por eso el bloque no se pinta cuando el permiso falta —y tampoco
     se consulta, que es la mitad que de verdad protege—. --}}

<h2 class="mt-8 mb-1 text-sm font-semibold text-slate-500">Margen</h2>
<p class="mb-3 text-xs text-slate-400">
  Ingreso declarado menos lo devengado a creadores menos el gasto operativo, de las campañas
  <strong>confirmadas</strong> cuya ventana toca el periodo. Borradores, pendientes de aprobar y
  canceladas quedan fuera.
</p>

<div class="rounded-xl border border-slate-200 bg-white overflow-hidden">

  @if ($margen['lineas'] === [])
    <p class="px-5 py-8 text-center text-sm text-slate-400">
      No hay campañas confirmadas en este recorte.
      @if ($margen['fuera'] > 0)
        Las {{ $margen['fuera'] }} que hay quedaron fuera del total: canje o gastos en otra moneda.
      @endif
    </p>
  @else
    {{-- El consolidado arriba, con sus tres piezas: sin el ingreso y el costo
         al lado, un margen a secas no se puede juzgar. --}}
    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-baseline gap-x-8 gap-y-2">
      <div>
        <p class="text-xs text-slate-500">Margen del periodo</p>
        <p class="text-2xl font-bold tabular-nums
          {{ ($margen['consolidado']['margen'] ?? 0) < 0 ? 'text-rose-600' : 'text-slate-900' }}">
          @if ($margen['consolidado']['margen'] === null)
            —
          @else
            {{ number_format($margen['consolidado']['margen'], 2, ',', '.') }}
            <span class="text-xs font-medium text-slate-400">{{ $margen['consolidado']['moneda'] }}</span>
          @endif
        </p>
      </div>

      @if ($margen['porcentaje'] !== null)
        <div>
          <p class="text-xs text-slate-500">Sobre el ingreso</p>
          <p class="text-2xl font-bold text-slate-900 tabular-nums">
            {{ number_format($margen['porcentaje'], 1, ',', '.') }} %
          </p>
        </div>
      @endif

      <div class="text-xs text-slate-500 space-y-0.5">
        <p>
          Ingreso
          <strong class="tabular-nums text-slate-700">
            {{ $margen['consolidado']['ingreso'] === null
                ? '—' : number_format($margen['consolidado']['ingreso'], 2, ',', '.') }}
          </strong>
        </p>
        <p>
          Costos
          <strong class="tabular-nums text-slate-700">
            {{ $margen['consolidado']['costos'] === null
                ? '—' : number_format($margen['consolidado']['costos'], 2, ',', '.') }}
          </strong>
        </p>
      </div>
    </div>

    {{-- Y el desglose por moneda, que es lo que de verdad hay guardado. --}}
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
        <tr>
          <th class="px-5 py-2 text-left font-medium">Moneda</th>
          <th class="px-5 py-2 text-right font-medium">Ingreso</th>
          <th class="px-5 py-2 text-right font-medium">Creadores</th>
          <th class="px-5 py-2 text-right font-medium">Gasto</th>
          <th class="px-5 py-2 text-right font-medium">Margen</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @foreach ($margen['lineas'] as $linea)
          <tr>
            <td class="px-5 py-2 font-medium text-slate-500">{{ $linea['moneda'] }}</td>
            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($linea['ingreso'], 2, ',', '.') }}</td>
            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($linea['creadores'], 2, ',', '.') }}</td>
            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($linea['gasto'], 2, ',', '.') }}</td>
            <td class="px-5 py-2 text-right tabular-nums font-semibold
              {{ $linea['margen'] < 0 ? 'text-rose-600' : 'text-slate-900' }}">
              {{ number_format($linea['margen'], 2, ',', '.') }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 text-xs text-slate-500 space-y-1">
    @if ($margen['veto'] !== null)
      <p class="text-amber-700">{{ $margen['veto'] }}</p>
    @endif
    @if ($margen['fuera'] > 0 && $margen['lineas'] !== [])
      <p>
        {{ $margen['fuera'] }}
        {{ $margen['fuera'] === 1 ? 'campaña queda' : 'campañas quedan' }}
        fuera del total: un canje tiene ingreso cero por decisión, y una campaña con gastos en otra
        moneda tendría el margen incompleto.
      </p>
    @endif
    @if ($margen['consolidado']['fecha'] !== null)
      <p>Convertido con la tasa del {{ $margen['consolidado']['fecha'] }}.</p>
    @endif
    <p>
      El ingreso es el <strong>declarado</strong> en la campaña, no lo facturado ni lo cobrado.
      Campaña a campaña se ve en <a href="{{ route('rentabilidad.index') }}"
        class="text-marca-700 underline">Rentabilidad</a>.
    </p>
  </div>
</div>
