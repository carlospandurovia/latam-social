@extends('layouts.panel')
@section('titulo', 'Gastos de la campaña')
@section('subtitulo', $campana->name.($campana->marca ? ' · '.$campana->marca : ''))

@section('contenido')
  {{-- Esta pantalla NO enseña `revenue_amount` ni margen. Quien lleva la
       campaña carga gastos y ve cuánto lleva gastado; cuánto se gana es otra
       pregunta y otro permiso (`campaign.view_margin`, DEC-181). --}}
  <div class="mb-5 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
    <strong>Lo que la campaña nos cuesta a nosotros:</strong> producto, envíos,
    producción, pauta, herramientas. No es lo que se le paga al creador —eso sale
    del libro mayor y aparece abajo aparte— ni lo que se le cobra al cliente.
    <br>
    Cada moneda va por su lado: sumarlas exige un tipo de cambio, y cuál se
    aplica es una decisión contable que sigue abierta.
  </div>

  @if (session('exito'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Gastos anotados</h2>
        </div>
        @forelse ($costos as $c)
          <div class="border-b border-slate-100 p-5 last:border-0 {{ $c->voided_at ? 'bg-slate-50' : '' }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-sm font-medium {{ $c->voided_at ? 'line-through text-slate-400' : '' }}">
                  {{ $c->description }}
                </p>
                <p class="text-xs text-slate-500">
                  {{ $tipos[$c->cost_type] ?? $c->cost_type }} ·
                  {{ substr((string) $c->incurred_on, 0, 10) }}
                  @if ($c->autor) · anotado por {{ $c->autor }} @endif
                  @if ($c->file_id)
                    ·
                    {{-- 9.15: hasta ahora esto decia «con comprobante» y no habia
                         forma de abrirlo. Una evidencia que nadie puede mirar no
                         es una evidencia. --}}
                    <a href="{{ route('archivos.ver', $c->archivo_uuid) }}" target="_blank"
                       rel="noopener" class="text-marca-700 hover:underline">ver comprobante</a>
                  @endif
                </p>
                {{-- El anulado se enseña tachado y con su motivo, no se esconde:
                     quien mira una cifra que no le cuadra necesita ver que hubo
                     una corrección. --}}
                @if ($c->voided_at)
                  <p class="mt-1 text-xs text-amber-700">
                    Anulado{{ $c->anulador ? ' por '.$c->anulador : '' }}:
                    {{ $c->voided_reason }}
                  </p>
                @endif
              </div>
              <p class="text-base font-semibold tabular-nums {{ $c->voided_at ? 'line-through text-slate-400' : '' }}">
                {{ number_format((float) $c->amount, 2) }}
                <span class="text-sm font-normal text-slate-500">{{ $c->currency_code }}</span>
              </p>
            </div>

            @can('finance.cost.manage')
              @if (! $c->voided_at)
                <form method="POST" action="{{ route('costos.anular', [$campana->uuid, $c->id]) }}"
                      class="mt-3 flex flex-wrap items-center gap-2">
                  @csrf
                  <label for="motivo-{{ $c->id }}" class="sr-only">Motivo de la anulación</label>
                  <input id="motivo-{{ $c->id }}" name="motivo" required minlength="5" maxlength="255"
                         placeholder="Por qué se anula (queda escrito)"
                         class="flex-1 min-w-[16rem] rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                    Anular
                  </button>
                </form>
              @endif
            @endcan
          </div>
        @empty
          <p class="p-5 text-sm text-slate-500">
            Todavía no hay gastos anotados en esta campaña.
          </p>
        @endforelse
      </div>

      @can('finance.cost.manage')
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-semibold mb-3">Anotar un gasto</h2>
          <form method="POST" action="{{ route('costos.store', $campana->uuid) }}"
                enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
            @csrf
            <div class="sm:col-span-2">
              <label for="description" class="block text-xs text-slate-500 mb-1">Qué se gastó</label>
              <input id="description" name="description" required minlength="3" maxlength="255"
                     value="{{ old('description') }}"
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
              <label for="cost_type" class="block text-xs text-slate-500 mb-1">Tipo</label>
              <select id="cost_type" name="cost_type"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($tipos as $codigo => $nombre)
                  <option value="{{ $codigo }}" @selected(old('cost_type') === $codigo)>{{ $nombre }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="incurred_on" class="block text-xs text-slate-500 mb-1">Cuándo se gastó</label>
              {{-- `max` acompaña a `tg_cco_fecha`: un gasto no se incurre mañana. --}}
              <input id="incurred_on" name="incurred_on" type="date" required max="{{ $hoy }}"
                     value="{{ old('incurred_on', $hoy) }}"
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
              <label for="amount" class="block text-xs text-slate-500 mb-1">Importe</label>
              <input id="amount" name="amount" type="number" step="0.01" min="0" required
                     value="{{ old('amount') }}"
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
              <label for="currency_code" class="block text-xs text-slate-500 mb-1">Moneda</label>
              <select id="currency_code" name="currency_code"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($monedas as $m)
                  <option value="{{ $m->code }}"
                    @selected(old('currency_code', $campana->currency_code) === $m->code)>{{ $m->code }}</option>
                @endforeach
              </select>
            </div>
            <div class="sm:col-span-2">
              <label for="comprobante" class="block text-xs text-slate-500 mb-1">
                Comprobante (opcional)
              </label>
              <input id="comprobante" name="comprobante" type="file"
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="sm:col-span-2">
              <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Anotar el gasto
              </button>
            </div>
          </form>
        </div>
      @endcan
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-3">Gasto operativo</h2>
        @forelse ($resumen as $moneda => $datos)
          <div class="mb-4 last:mb-0">
            <p class="text-lg font-semibold tabular-nums">
              {{ number_format($datos['total'], 2) }}
              <span class="text-sm font-normal text-slate-500">{{ $moneda }}</span>
            </p>
            <dl class="mt-1 text-xs text-slate-500 space-y-0.5">
              @foreach ($datos['tipos'] as $tipo => $total)
                <div class="flex justify-between gap-3">
                  <dt>{{ $tipos[$tipo] ?? $tipo }}</dt>
                  <dd class="tabular-nums">{{ number_format($total, 2) }}</dd>
                </div>
              @endforeach
            </dl>
          </div>
        @empty
          <p class="text-sm text-slate-500">Sin gastos vivos.</p>
        @endforelse
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-1">Comprometido con creadores</h2>
        {{-- Del libro mayor y no del importe pactado: un devengo anulado ya no
             se debe, y sumar lo pactado lo seguiría contando. --}}
        <p class="text-xs text-slate-400 mb-3">
          Del libro mayor, desde que cada creador acepta. Sin lo anulado.
        </p>
        @forelse ($creadores as $moneda => $total)
          <p class="text-lg font-semibold tabular-nums">
            {{ number_format($total, 2) }}
            <span class="text-sm font-normal text-slate-500">{{ $moneda }}</span>
          </p>
        @empty
          <p class="text-sm text-slate-500">Ningún creador ha aceptado todavía.</p>
        @endforelse
      </div>

      <p class="text-xs text-slate-400">
        La rentabilidad —esto puesto contra lo que se le cobra al cliente— es otra
        pantalla y otro permiso.
      </p>
    </div>
  </div>
@endsection
