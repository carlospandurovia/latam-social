@extends('layouts.panel')
@section('titulo', 'Campañas')
@section('subtitulo', 'Lo que se le está vendiendo a cada marca')

@section('contenido')
  {{-- Arriba lo que impide trabajar, no lo bonito. Una campaña sin sociedad no
       puede salir de borrador, y descubrirlo campaña por campaña al intentar
       moverlas es la peor forma de enterarse. --}}
  @if ($sinSociedad > 0)
    <div class="mb-5 rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
      <p class="font-medium">{{ $sinSociedad }} campaña(s) sin sociedad que las facture.</p>
      <p class="mt-1">
        No van a poder salir de borrador: ninguna sociedad del grupo cubre la facturación
        del país de su cliente en su fecha de inicio (<code>BR-LE-004</code>). Se arregla
        declarando la cobertura en <a href="{{ route('entidades.index') }}" class="underline">Entidades legales</a>.
      </p>
    </div>
  @endif

  <div class="flex items-center justify-between mb-4">
    <form method="GET" class="flex items-center gap-2">
      <label for="estado" class="text-sm text-slate-600">Estado</label>
      <select id="estado" name="estado" onchange="this.form.submit()"
              class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Todos</option>
        @foreach ($estados as $codigo => $nombre)
          <option value="{{ $codigo }}" @selected($estado === $codigo)>{{ $nombre }}</option>
        @endforeach
      </select>
    </form>

    @can('campaign.manage')
      <a href="{{ route('campanas.create') }}"
         class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        Nueva campaña
      </a>
    @endcan
  </div>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          <th class="text-left px-4 py-3 font-medium">Código</th>
          <th class="text-left px-4 py-3 font-medium">Campaña</th>
          <th class="text-left px-4 py-3 font-medium">Cliente / marca</th>
          <th class="text-left px-4 py-3 font-medium">Fechas</th>
          <th class="text-left px-4 py-3 font-medium">Factura</th>
          <th class="text-left px-4 py-3 font-medium">Estado</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($campanas as $c)
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $c->code }}</td>
            <td class="px-4 py-3">
              <a href="{{ route($c->confirmed_at !== null ? 'campanas.seguimiento' : 'campanas.show', $c->uuid) }}"
                 class="font-medium text-marca-600 hover:underline">
                {{ $c->name }}
              </a>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ $c->cliente }} · {{ $c->marca }}</td>
            <td class="px-4 py-3 text-slate-600">{{ $c->starts_on }} → {{ $c->ends_on }}</td>
            <td class="px-4 py-3">
              @if ($c->sociedad)
                <span class="font-mono text-xs">{{ $c->sociedad }}</span>
              @else
                <span class="text-amber-700 text-xs">sin cobertura</span>
              @endif
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $estados[$c->status] ?? $c->status }}</span>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">
            No hay campañas{{ $estado ? ' en ese estado' : '' }}.
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
@endsection
