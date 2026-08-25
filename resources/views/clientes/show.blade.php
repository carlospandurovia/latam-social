@extends('layouts.panel')
@section('titulo', $cliente->commercial_name)
@section('subtitulo', $cliente->client_code.' · '.$cliente->pais)

@section('contenido')
  {{-- Quién le factura, arriba del todo y siempre: es el dato del que depende
       que se le pueda cobrar, y no debe descubrirse el día de la factura. --}}
  <div @class([
    'mb-6 rounded-xl border px-4 py-3 text-sm',
    'border-emerald-200 bg-emerald-50 text-emerald-900' => $cobertura->hay(),
    'border-amber-300 bg-amber-50 text-amber-900' => !$cobertura->hay(),
  ])>
    <div class="font-medium">Facturación</div>
    <p class="mt-1">{{ $cobertura->explicacion }}</p>
  </div>

  <div class="grid grid-cols-3 gap-5">
    <div class="col-span-2 space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-start justify-between">
          <h2 class="text-sm font-medium text-slate-700">Datos</h2>
          @can('client.manage')
            <a href="{{ route('clientes.edit', $cliente->uuid) }}" class="text-xs text-marca-700 hover:underline">Editar</a>
          @endcan
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
          <div><dt class="text-xs text-slate-500">Estado</dt><dd class="text-slate-800">{{ $cliente->status }}</dd></div>
          <div><dt class="text-xs text-slate-500">País</dt><dd class="text-slate-800">{{ $cliente->pais }}</dd></div>
          <div><dt class="text-xs text-slate-500">Ejecutivo</dt><dd class="text-slate-800">{{ $cliente->ejecutivo ?: '—' }}</dd></div>
          <div><dt class="text-xs text-slate-500">Web</dt><dd class="text-slate-800">
            @if ($cliente->website)<a href="{{ $cliente->website }}" class="text-marca-700 hover:underline" rel="noopener noreferrer" target="_blank">{{ $cliente->website }}</a>@else — @endif
          </dd></div>
        </dl>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Marcas</h2>
        @forelse ($marcas as $m)
          <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-sm">
            <div>
              <div class="text-slate-800">{{ $m->name }}</div>
              <div class="text-xs text-slate-400">{{ $m->slug }}</div>
            </div>
            <span class="text-xs text-slate-500">{{ $m->status }}</span>
          </div>
        @empty
          <p class="mt-3 text-sm text-slate-400">
            Todavía no tiene marcas. Una campaña se hace <em>para una marca</em>, no para el cliente,
            así que hará falta al menos una.
          </p>
        @endforelse
      </div>
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Perfiles fiscales</h2>
        <p class="mt-1 text-xs text-slate-500">Uno por país: la razón social y el identificador con los que se emite la factura.</p>
        @forelse ($fiscales as $f)
          <div class="mt-3 border-t border-slate-100 pt-3 text-sm">
            <div class="text-slate-800">{{ $f->legal_name }}</div>
            <div class="text-xs text-slate-500">{{ $f->pais }} · {{ $f->tax_id_type }} {{ $f->tax_id_number }}</div>
            <div class="text-xs text-slate-400">
              desde {{ $f->valid_from }}@if ($f->valid_to) hasta {{ $f->valid_to }}@else (vigente)@endif
            </div>
          </div>
        @empty
          <p class="mt-3 text-sm text-slate-400">Ninguno todavía.</p>
        @endforelse
      </div>
    </div>
  </div>
@endsection
