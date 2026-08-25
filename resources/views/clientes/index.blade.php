@extends('layouts.panel')
@section('titulo', 'Clientes')
@section('subtitulo', $clientes->total().' en total')

@section('contenido')
  {{-- La falta de cobertura se avisa ARRIBA y una vez, no repetida en cada
       fila: es un problema de configuración del grupo, no de cada cliente. --}}
  @if ($sinCobertura > 0)
    <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <strong>{{ $sinCobertura }}</strong>
      {{ $sinCobertura === 1 ? 'país de esta lista no tiene' : 'países de esta lista no tienen' }}
      ninguna sociedad que pueda facturarle (<code>BR-LE-004</code>). Se pueden
      registrar como prospectos, pero no activarse hasta declarar la cobertura en
      Entidades legales.
    </div>
  @endif

  <div class="mb-5 flex gap-3">
    <form method="GET" class="flex flex-1 gap-3">
      <input name="q" value="{{ $q }}" placeholder="Buscar por nombre o código…"
             class="flex-1 max-w-md rounded-lg border border-slate-300 px-3 py-2 text-sm
                    focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
      <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        Buscar
      </button>
      @if ($q)
        <a href="{{ route('clientes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
          Limpiar
        </a>
      @endif
    </form>
    @can('client.manage')
      <a href="{{ route('clientes.create') }}"
         class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        Nuevo cliente
      </a>
    @endcan
  </div>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 border-b border-slate-200">
        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
          <th class="px-4 py-3 font-medium">Cliente</th>
          <th class="px-4 py-3 font-medium">País</th>
          <th class="px-4 py-3 font-medium">Marcas</th>
          <th class="px-4 py-3 font-medium">Ejecutivo</th>
          <th class="px-4 py-3 font-medium">Estado</th>
          <th class="px-4 py-3 font-medium">Quién le factura</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($clientes as $c)
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <a href="{{ route('clientes.show', $c->uuid) }}" class="font-medium text-marca-700 hover:underline">
                {{ $c->commercial_name }}
              </a>
              <div class="text-xs text-slate-400">{{ $c->client_code }}</div>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ $c->pais }}</td>
            <td class="px-4 py-3 text-slate-600">{{ $c->marcas }}</td>
            <td class="px-4 py-3 text-slate-600">{{ $c->ejecutivo ?: '—' }}</td>
            <td class="px-4 py-3">
              <span @class([
                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                'bg-emerald-50 text-emerald-700' => $c->status === 'active',
                'bg-sky-50 text-sky-700' => $c->status === 'prospect',
                'bg-slate-100 text-slate-600' => $c->status === 'inactive',
                'bg-rose-100 text-rose-800' => $c->status === 'blacklisted',
              ])>{{ $c->status }}</span>
            </td>
            <td class="px-4 py-3">
              @if ($c->cobertura->hay())
                <span class="text-slate-600">{{ $c->cobertura->entidad->code }}</span>
              @else
                <span class="text-amber-700">nadie todavía</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">
            @if ($q) Ningún cliente coincide con «{{ $q }}». @else Todavía no hay clientes. @endif
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $clientes->links() }}</div>
@endsection
