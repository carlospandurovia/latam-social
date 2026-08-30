@extends('layouts.panel')
@section('titulo', 'Rentabilidad de la campaña')
@section('subtitulo', $campana->name.($campana->marca ? ' · '.$campana->marca : ''))

@section('contenido')
  <div class="mb-5 flex flex-wrap justify-end gap-2">
    <a href="{{ route('rentabilidad.index') }}"
       class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm hover:bg-slate-50">
      Todas las campañas
    </a>
    @can('finance.cost.manage')
      <a href="{{ route('costos.index', $campana->uuid) }}"
         class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm hover:bg-slate-50">
        Anotar un gasto
      </a>
    @endcan
  </div>

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      @foreach ($cuenta['monedas'] as $moneda => $fila)
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-semibold mb-3">En {{ $moneda }}</h2>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between">
              <dt class="text-slate-500">Ingreso declarado</dt>
              <dd class="tabular-nums">{{ number_format($fila['ingreso'], 2) }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-slate-500">Costo de creadores</dt>
              <dd class="tabular-nums">− {{ number_format($fila['creadores'], 2) }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-slate-500">Gasto operativo</dt>
              <dd class="tabular-nums">− {{ number_format($fila['gasto'], 2) }}</dd>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-2">
              <dt class="font-medium">Margen</dt>
              <dd class="tabular-nums font-semibold {{ $fila['margen'] < 0 ? 'text-rose-700' : '' }}">
                {{ number_format($fila['margen'], 2) }} {{ $moneda }}
                @if ($cuenta['porcentaje'] !== null)
                  <span class="text-xs font-normal text-slate-400">
                    ({{ number_format($cuenta['porcentaje'], 1) }}%)
                  </span>
                @endif
              </dd>
            </div>
          </dl>
        </div>
      @endforeach

      {{-- Cuando falta el porcentaje se dice POR QUÉ. Quien mira una pantalla
           donde falta un número necesita saber si falta porque no lo hay o
           porque no se puede calcular: son dos conversaciones distintas. --}}
      @if ($cuenta['veto_porcentaje'] !== null)
        <p class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
          <strong>Sin porcentaje.</strong> {{ $cuenta['veto_porcentaje'] }}
        </p>
      @endif

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">De qué está hecho el gasto operativo</h2>
        </div>
        @forelse ($gastos as $g)
          <div class="border-b border-slate-100 px-5 py-3 last:border-0 flex flex-wrap justify-between gap-3">
            <div>
              <p class="text-sm {{ $g->voided_at ? 'line-through text-slate-400' : '' }}">
                {{ $g->description }}
              </p>
              <p class="text-xs text-slate-500">
                {{ $tipos[$g->cost_type] ?? $g->cost_type }} ·
                {{ substr((string) $g->incurred_on, 0, 10) }}
                @if ($g->voided_at) · anulado: {{ $g->voided_reason }} @endif
              </p>
            </div>
            <p class="tabular-nums text-sm {{ $g->voided_at ? 'line-through text-slate-400' : '' }}">
              {{ number_format((float) $g->amount, 2) }} {{ $g->currency_code }}
            </p>
          </div>
        @empty
          <p class="px-5 py-4 text-sm text-slate-500">
            Sin gastos anotados. Si la campaña costó producto o envíos y no está
            aquí, el margen sale más alto de lo que es.
          </p>
        @endforelse
      </div>
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5 text-sm">
        <h2 class="text-sm font-semibold mb-3">La campaña</h2>
        <dl class="space-y-2 text-slate-600">
          <div class="flex justify-between gap-3"><dt class="text-slate-400">Código</dt><dd>{{ $campana->code }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-400">Estado</dt><dd>{{ $campana->status }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-400">Moneda</dt><dd>{{ $campana->currency_code }}</dd></div>
          @if ($campana->sociedad)
            <div class="flex justify-between gap-3"><dt class="text-slate-400">Factura</dt><dd class="text-right">{{ $campana->sociedad }}</dd></div>
          @endif
        </dl>
        @if ($cuenta['canje'])
          <p class="mt-3 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-600">
            <strong>Es un canje.</strong> El ingreso es cero por decisión, así que el
            margen es negativo por diseño. No entra en ningún total — pero lo que
            costó es real, y por eso se enseña.
          </p>
        @endif
      </div>

      <p class="text-xs text-slate-400">
        Ingreso declarado en la campaña, costo de creadores del libro mayor desde
        que cada uno acepta, y gasto operativo anotado a mano. Ninguna de las tres
        se convierte de moneda.
      </p>
    </div>
  </div>
@endsection
