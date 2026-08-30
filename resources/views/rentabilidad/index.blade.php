@extends('layouts.panel')
@section('titulo', 'Rentabilidad')
@section('subtitulo', 'Qué deja cada campaña, y cuáles no dejan nada')

@section('contenido')
  {{-- `BR-SEC-001` (🔴): esto no se enseña a un cliente ni a un creador, y desde
       9.10a tampoco a quien lleva la campaña. La pantalla entera es de
       `campaign.view_margin` y el dato no se calcula si no se puede ver. --}}
  <div class="mb-5 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
    <strong>El ingreso es el declarado, no el facturado.</strong> Es lo que se dijo que
    se le cobra al cliente cuando se creó la campaña; todavía no hay facturación,
    así que no es lo facturado ni lo cobrado.
    <br>
    Por eso aquí <strong>no hay total por sociedad</strong>: sumar precios declarados y
    presentarlos por sociedad se parece a un estado de resultados y no lo es.
    <br>
    Cada moneda va por su lado. Comparar un margen en soles con uno en dólares
    exige convertirlos, y qué tasa se aplica sigue sin decidirse.
  </div>

  <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
    <div>
      <label for="estado" class="block text-xs text-slate-500 mb-1">Estado</label>
      <select id="estado" name="estado"
              class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Todos</option>
        @foreach ($estados as $e)
          <option value="{{ $e }}" @selected($estado === $e)>{{ $e }}</option>
        @endforeach
      </select>
    </div>
    <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
      Filtrar
    </button>
  </form>

  @forelse ($grupos as $moneda => $grupo)
    <div class="mb-6 bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-3">
        <h2 class="text-sm font-semibold">Campañas en {{ $moneda }}</h2>
        <div class="text-right">
          <p class="text-sm">
            <span class="text-slate-500">Margen del grupo:</span>
            <strong class="tabular-nums {{ $grupo['total']['margen'] < 0 ? 'text-rose-700' : '' }}">
              {{ number_format($grupo['total']['margen'], 2) }} {{ $moneda }}
            </strong>
          </p>
          {{-- Lo que queda fuera se DICE. Un total que excluye filas sin
               contarlas es un total que engaña por omisión. --}}
          @if ($grupo['fuera'] > 0)
            <p class="text-xs text-amber-700">
              {{ $grupo['fuera'] }}
              {{ $grupo['fuera'] === 1 ? 'campaña queda fuera' : 'campañas quedan fuera' }}
              del total: son canjes o tienen gastos en otra moneda.
            </p>
          @endif
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500">
            <tr>
              <th class="px-4 py-2 text-left font-medium">Campaña</th>
              <th class="px-4 py-2 text-right font-medium">Ingreso</th>
              <th class="px-4 py-2 text-right font-medium">Creadores</th>
              <th class="px-4 py-2 text-right font-medium">Gasto</th>
              <th class="px-4 py-2 text-right font-medium">Margen</th>
            </tr>
          </thead>
          <tbody>
            {{-- De peor a mejor: la pregunta por la que se abre esta pantalla es
                 cuáles pierden dinero, y ésas tienen que estar arriba sin que
                 nadie ordene nada. --}}
            @foreach ($grupo['campanas'] as $c)
              <tr class="border-t border-slate-100">
                <td class="px-4 py-3">
                  <a href="{{ route('rentabilidad.show', $c['uuid']) }}"
                     class="font-medium text-marca-700 hover:underline">{{ $c['name'] }}</a>
                  <p class="text-xs text-slate-500">
                    {{ $c['code'] }}@if ($c['marca']) · {{ $c['marca'] }}@endif · {{ $c['status'] }}
                  </p>
                  @if ($c['canje'])
                    <span class="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                      canje · fuera del total
                    </span>
                  @endif
                  @if ($c['otras_monedas'] !== [])
                    <p class="mt-1 text-xs text-amber-700">
                      Tiene importes en {{ implode(', ', $c['otras_monedas']) }}:
                      este margen no los incluye.
                    </p>
                  @endif
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($c['ingreso'], 2) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($c['creadores'], 2) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($c['gasto'], 2) }}</td>
                <td class="px-4 py-3 text-right tabular-nums font-medium {{ $c['margen'] < 0 ? 'text-rose-700' : '' }}">
                  {{ number_format($c['margen'], 2) }}
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @empty
    <p class="rounded-xl bg-white border border-slate-200 p-5 text-sm text-slate-500">
      No hay campañas que mirar con ese filtro.
    </p>
  @endforelse
@endsection
