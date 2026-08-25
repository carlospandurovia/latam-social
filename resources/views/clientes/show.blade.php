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
        <div class="flex items-start justify-between">
          <h2 class="text-sm font-medium text-slate-700">Marcas</h2>
          @can('client.manage')
            <a href="{{ route('marcas.create', $cliente->uuid) }}" class="text-xs text-marca-700 hover:underline">Añadir marca</a>
          @endcan
        </div>
        <p class="mt-1 text-xs text-slate-500">Una campaña se hace para una marca, no para el cliente.</p>
        @forelse ($marcas as $m)
          <div class="mt-3 flex items-start justify-between border-t border-slate-100 pt-3 text-sm">
            <div>
              <div class="text-slate-800">
                @can('client.manage')
                  <a href="{{ route('marcas.edit', [$cliente->uuid, $m->uuid]) }}" class="text-marca-700 hover:underline">{{ $m->name }}</a>
                @else
                  {{ $m->name }}
                @endcan
              </div>
              <div class="text-xs text-slate-400">{{ $m->slug }}</div>
              {{-- Sin categorías la detección de conflictos (BR-CAMPAIGN-007)
                   no puede hacerse para esta marca. Se avisa donde se ve, no
                   solo en el formulario de edición. --}}
              @if ($m->categorias === 0)
                <div class="mt-1 text-xs text-amber-700">Sin categorías: no se detectarán conflictos de marca.</div>
              @else
                <div class="mt-1 text-xs text-slate-500">{{ $m->categorias }} {{ $m->categorias === 1 ? 'categoría' : 'categorías' }}</div>
              @endif
            </div>
            <span class="text-xs text-slate-500">{{ $m->status }}</span>
          </div>
        @empty
          <p class="mt-3 text-sm text-slate-400">Todavía no tiene marcas.</p>
        @endforelse
      </div>
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-start justify-between">
          <h2 class="text-sm font-medium text-slate-700">Perfiles fiscales</h2>
          @can('client.tax.manage')
            <a href="{{ route('clientes.fiscal.create', $cliente->uuid) }}" class="text-xs text-marca-700 hover:underline">Nueva identidad</a>
          @endcan
        </div>
        <p class="mt-1 text-xs text-slate-500">Uno vigente por país: la razón social y el identificador con los que se emite la factura.</p>
        @forelse ($fiscales as $f)
          <div class="mt-3 border-t border-slate-100 pt-3 text-sm">
            <div class="flex items-start justify-between">
              <div class="text-slate-800">{{ $f->legal_name }}</div>
              {{-- Sólo el vigente se corrige. Un periodo cerrado explica una
                   factura ya emitida, así que está congelado (DEC-078) y ni
                   siquiera se ofrece el enlace. --}}
              @if (!$f->valid_to)
                @can('client.tax.manage')
                  <a href="{{ route('clientes.fiscal.edit', [$cliente->uuid, $f->id]) }}" class="text-xs text-marca-700 hover:underline">Corregir</a>
                @endcan
              @endif
            </div>
            <div class="text-xs text-slate-500">{{ $f->pais }} · {{ $f->tax_id_type }} {{ $f->tax_id_number }}</div>
            <div class="text-xs text-slate-400">
              @if ($f->valid_to)
                del {{ $f->valid_from }} al {{ $f->valid_to }} · cerrado
              @else
                desde {{ $f->valid_from }} · <span class="text-emerald-700">vigente</span> · paga a {{ $f->payment_term_days }} días
              @endif
            </div>
          </div>
        @empty
          <p class="mt-3 text-sm text-slate-400">
            Ninguno todavía. <strong>Sin identidad fiscal no se le puede emitir una factura.</strong>
          </p>
        @endforelse
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-start justify-between">
          <h2 class="text-sm font-medium text-slate-700">Contactos</h2>
          @can('client.manage')
            <a href="{{ route('contactos.create', $cliente->uuid) }}" class="text-xs text-marca-700 hover:underline">Añadir contacto</a>
          @endcan
        </div>
        <p class="mt-1 text-xs text-slate-500">Un principal activo por tipo: es a quien se le escribe.</p>

        {{-- No es un error —la base no lo exige— pero sí es deuda: cuando llegue
             la facturación habrá que saber a quién se le manda la factura. Se
             marca igual que una marca sin categorías (Q-52). --}}
        @if ($tiposSinPrincipal !== [])
          <div class="mt-3 rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
            Con contactos pero sin principal:
            {{ implode(', ', array_map(fn (string $t): string => $tiposContacto[$t] ?? $t, $tiposSinPrincipal)) }}.
          </div>
        @endif

        @forelse ($contactos as $c)
          <div class="mt-3 flex items-start justify-between border-t border-slate-100 pt-3 text-sm">
            <div>
              <div class="text-slate-800">
                @can('client.manage')
                  <a href="{{ route('contactos.edit', [$cliente->uuid, $c->uuid]) }}" class="text-marca-700 hover:underline">{{ $c->full_name }}</a>
                @else
                  {{ $c->full_name }}
                @endcan
                @if ($c->is_primary && $c->status === 'active')
                  <span class="ml-1 rounded bg-marca-50 px-1.5 py-0.5 text-xs text-marca-700">principal</span>
                @endif
              </div>
              <div class="text-xs text-slate-500">{{ $tiposContacto[$c->contact_type] ?? $c->contact_type }}@if ($c->position) · {{ $c->position }}@endif</div>
              <div class="text-xs text-slate-400">{{ $c->contact_email }}@if ($c->phone) · {{ $c->phone }}@endif</div>
            </div>
            @if ($c->status !== 'active')
              <span class="text-xs text-slate-400">inactivo</span>
            @endif
          </div>
        @empty
          <p class="mt-3 text-sm text-slate-400">Todavía no tiene contactos.</p>
        @endforelse
      </div>
    </div>
  </div>
@endsection
