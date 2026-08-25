@extends('layouts.panel')
@section('titulo', $campana->name)
@section('subtitulo', $campana->code)

@section('contenido')
  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Datos</h2>
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div><dt class="text-slate-500">Estado</dt>
            <dd class="font-medium">{{ $estados[$campana->status] ?? $campana->status }}</dd></div>
          <div><dt class="text-slate-500">Objetivo</dt>
            <dd>{{ $objetivos[$campana->objective] ?? $campana->objective }}</dd></div>
          <div><dt class="text-slate-500">Fechas</dt>
            <dd>{{ $campana->starts_on }} → {{ $campana->ends_on }}</dd></div>
          <div><dt class="text-slate-500">Ingreso</dt>
            <dd>{{ number_format((float) $campana->revenue_amount, 2) }} {{ $campana->currency_code }}</dd></div>
          <div><dt class="text-slate-500">Rondas incluidas</dt>
            <dd>{{ $campana->included_revision_rounds }}</dd></div>
          <div><dt class="text-slate-500">Edad mínima</dt>
            <dd>{{ $campana->min_creator_age > 0 ? $campana->min_creator_age.' años' : 'sin restricción' }}</dd></div>
        </dl>
      </div>

      @if ($campana->briefing)
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700 mb-3">Briefing</h2>
          <p class="text-sm text-slate-600 whitespace-pre-line">{{ $campana->briefing }}</p>
        </div>
      @endif
    </div>

    <div class="space-y-5">
      {{-- QUIÉN FACTURA, y de dónde sale.
           Se enseña el dato GUARDADO, no el que devolvería la cobertura hoy:
           `BR-LE-001` dice que nunca se deduce de la configuración vigente en el
           momento de la consulta. Cuando los dos no coinciden se dice, porque
           entonces hay algo que mirar. --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Quién la factura</h2>

        @if ($campana->billing_legal_entity_id)
          <p class="text-sm font-medium">
            {{ $cobertura->hay() ? $cobertura->entidad->legal_name : 'Sociedad #'.$campana->billing_legal_entity_id }}
          </p>
          <p class="mt-1 text-xs text-slate-500">
            Resuelto al {{ $campana->starts_on }}, la fecha en que empieza el servicio
            (<code>BR-LE-003</code>).
          </p>

          @if ($campana->confirmed_at)
            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
              Congelada: la campaña se confirmó el {{ $campana->confirmed_at }} y su sociedad
              ya no se puede cambiar (<code>BR-LE-002</code>). Corregirla exige anular la
              campaña y crear otra.
            </p>
          @endif
        @else
          <p class="text-sm text-amber-800">{{ $cobertura->explicacion }}</p>
          <p class="mt-2 text-xs text-slate-500">
            Hasta que exista esa cobertura, la campaña no puede salir de borrador.
          </p>
        @endif
      </div>

      @if ($transiciones !== [])
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700 mb-3">Mover a</h2>
          {{-- Sólo lo que ESTE usuario puede hacer: un botón que va a dar 403
               es peor que no tenerlo. --}}
          <div class="flex flex-wrap gap-2">
            @foreach ($transiciones as $destino => $nombre)
              <form method="POST" action="{{ route('campanas.estado', $campana->uuid) }}">
                @csrf
                <input type="hidden" name="estado" value="{{ $destino }}">
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                  {{ $nombre }}
                </button>
              </form>
            @endforeach
          </div>
        </div>
      @endif

      @can('campaign.manage')
        @if (in_array($campana->status, ['draft', 'pending_approval', 'cancelled'], true))
          <a href="{{ route('campanas.edit', $campana->uuid) }}"
             class="block text-center rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
            Editar
          </a>
        @endif
      @endcan
    </div>
  </div>
@endsection
