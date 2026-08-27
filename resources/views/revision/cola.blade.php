@extends('layouts.panel')
@section('titulo', 'Revisión')
@section('subtitulo', $cola->count().' '.\Illuminate\Support\Str::plural('entrega', $cola->count()).' esperando veredicto')

@section('contenido')
  <div class="space-y-5">

    {{-- Bandeja GLOBAL: revisar es trabajo por lotes. El filtro por campaña
         existe para acotar, no para tener que elegir una antes de ver nada. --}}
    <form method="GET" class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end">
      <div>
        <label for="campana" class="block text-xs text-slate-500 mb-1">Campaña</label>
        <select id="campana" name="campana"
                class="text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          <option value="">Todas</option>
          @foreach ($campanas as $c)
            <option value="{{ $c->id }}" @selected($filtros['campana'] === (int) $c->id)>{{ $c->name }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="dias" class="block text-xs text-slate-500 mb-1">Esperando desde hace</label>
        <select id="dias" name="dias"
                class="text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          <option value="">Cualquier tiempo</option>
          @foreach ([1 => 'Más de 1 día', 3 => 'Más de 3 días', 7 => 'Más de una semana'] as $d => $texto)
            <option value="{{ $d }}" @selected($filtros['desde_dias'] === $d)>{{ $texto }}</option>
          @endforeach
        </select>
      </div>
      <button type="submit"
              class="text-sm px-3 py-2 rounded-lg bg-marca-500 text-white hover:bg-marca-600">Filtrar</button>
      @if ($filtros['campana'] || $filtros['desde_dias'])
        <a href="{{ route('revision.cola') }}" class="text-xs text-slate-500 hover:underline">Quitar filtros</a>
      @endif
    </form>

    @if (session('exito'))
      <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
        {{ session('exito') }}
      </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      @if ($cola->isEmpty())
        <p class="p-5 text-sm text-slate-400">
          No hay nada esperando revisión.
          @if ($filtros['campana'] || $filtros['desde_dias']) Con estos filtros, al menos. @endif
        </p>
      @else
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th class="text-left font-medium px-4 py-2">Entregado</th>
                <th class="text-left font-medium px-4 py-2">Creador</th>
                <th class="text-left font-medium px-4 py-2">Campaña</th>
                <th class="text-left font-medium px-4 py-2">Pieza</th>
                <th class="text-left font-medium px-4 py-2">Rondas</th>
                <th class="text-left font-medium px-4 py-2">Vence</th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($cola as $e)
                @php
                  $espera = $e->submitted_at ? \Illuminate\Support\Carbon::parse($e->submitted_at)->diffInDays() : 0;
                  $quedan = max(0, (int) $e->included_revision_rounds - (int) $e->revision_rounds_used);
                @endphp
                <tr class="hover:bg-slate-50">
                  {{-- Lo que lleva más esperando va primero y se dice con
                       números: «hace 6 días» mueve a alguien, una fecha no. --}}
                  <td class="px-4 py-2 whitespace-nowrap {{ $espera >= 3 ? 'text-rose-600 font-medium' : 'text-slate-500' }}">
                    {{ $espera === 0 ? 'hoy' : 'hace '.$espera.' d' }}
                  </td>
                  <td class="px-4 py-2 text-slate-700">{{ $e->creador }}</td>
                  <td class="px-4 py-2 text-slate-500">
                    {{ $e->campana }}@if ($e->marca) <span class="text-slate-300">· {{ $e->marca }}</span>@endif
                  </td>
                  <td class="px-4 py-2 text-slate-500">{{ $e->formato }} #{{ $e->sequence_number }}</td>
                  <td class="px-4 py-2 tabular-nums {{ $quedan === 0 ? 'text-amber-600' : 'text-slate-500' }}">
                    {{ $quedan }}/{{ $e->included_revision_rounds }}
                  </td>
                  <td class="px-4 py-2 text-slate-500 whitespace-nowrap">
                    {{ \Illuminate\Support\Carbon::parse($e->due_on)->format('d/m') }}
                  </td>
                  <td class="px-4 py-2 text-right">
                    <a href="{{ route('revision.ver', $e->uuid) }}"
                       class="text-marca-600 hover:underline">Revisar</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>

    @unless ($puedeAprobar)
      <p class="text-xs text-slate-400">
        Puede pedir cambios. Dar el visto bueno necesita el permiso de aprobación.
      </p>
    @endunless
  </div>
@endsection
