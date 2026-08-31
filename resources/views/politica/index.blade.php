@extends('layouts.panel')
@section('titulo', 'Política de precios')
@section('subtitulo', 'La retención y el umbral de rentabilidad')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Política de precios'])

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-900'
                                  : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso->nivel === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso->nivel === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso->texto }}
    </div>
  @endforeach

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
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">

      {{-- El ejemplo con cifras. Un umbral en abstracto no se discute; «100 te
           cuesta 141,84 y el ingreso tendría que llegar a 170,21» sí. --}}
      @if ($ejemplo)
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div class="px-5 py-3 border-b border-slate-100">
            <h2 class="text-sm font-semibold">Con los números de hoy</h2>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-slate-100">
            @foreach ([
              ['Le pagas', $ejemplo['neto'], 'lo que el creador recibe'],
              ['Se retiene', $ejemplo['retenido'], $ejemplo['tasa'].' % de retención'],
              ['Te cuesta', $ejemplo['costo'], 'lo que la campaña provisiona'],
              ['Ingreso mínimo', $ejemplo['minimo'],
                $ejemplo['umbral'].' % sobre el '.($ejemplo['base'] === 'revenue' ? 'ingreso' : 'costo')],
            ] as $i => [$titulo, $valor, $nota])
              <div class="p-4 {{ $i === 3 ? 'bg-marca-50' : '' }}">
                <p class="text-xs text-slate-500">{{ $titulo }}</p>
                <p class="text-lg font-semibold {{ $i === 3 ? 'text-marca-800' : 'text-slate-900' }}">
                  {{ number_format($valor, 2, ',', '.') }}
                </p>
                <p class="text-[11px] text-slate-400 mt-0.5">{{ $nota }}</p>
              </div>
            @endforeach
          </div>
          <p class="px-5 py-3 text-xs text-slate-400 border-t border-slate-100">
            Sobre 100 de neto. La cuenta es la misma para cualquier importe.
          </p>
        </div>
      @endif

      {{-- Historial: lo que estuvo vigente cuando se pactó cada compromiso. --}}
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-baseline justify-between gap-3">
          <h2 class="text-sm font-semibold">Historial</h2>
          <span class="text-xs text-slate-400">Una política cerrada no se reescribe</span>
        </div>
        @forelse ($versiones as $v)
          <div class="border-b border-slate-100 p-5 last:border-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-medium">
                  Retención {{ rtrim(rtrim(number_format($v->withholding_rate, 4, ',', ''), '0'), ',') }} %
                  · umbral {{ rtrim(rtrim(number_format($v->min_margin_pct, 4, ',', ''), '0'), ',') }} %
                  sobre el {{ $v->margin_basis === 'revenue' ? 'ingreso' : 'costo' }}
                </p>
                <p class="text-xs text-slate-500">
                  desde {{ $v->valid_from }}
                  @if ($v->valid_to) hasta {{ $v->valid_to }} @endif
                  @if ($v->autor) · publicó {{ $v->autor }} @endif
                </p>
                @if ($v->note)
                  <p class="text-xs text-slate-400 mt-1">{{ $v->note }}</p>
                @endif
              </div>
              @if ($v->valid_to === null)
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Vigente</span>
              @else
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">Cerrada</span>
              @endif
            </div>
          </div>
        @empty
          <p class="p-5 text-sm text-slate-500">Todavía no hay ninguna política.</p>
        @endforelse
      </div>
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-1">Publicar una nueva</h2>
        <p class="text-xs text-slate-400 mb-3">
          La vigente se cierra el día antes. Lo ya pactado no cambia: cada participación guarda
          su propia copia de estos números.
        </p>
        <form method="POST" action="{{ route('politica.store') }}" class="space-y-3">
          @csrf
          <div>
            <label for="withholding_rate" class="block text-xs text-slate-500 mb-1">Retención (%)</label>
            <input id="withholding_rate" name="withholding_rate" required inputmode="decimal"
                   value="{{ old('withholding_rate', $vigente->withholding_rate ?? '29.5') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
            <p class="mt-1 text-xs text-slate-400">Al creador que no emite comprobante.</p>
          </div>
          <div>
            <label for="min_margin_pct" class="block text-xs text-slate-500 mb-1">Umbral de rentabilidad (%)</label>
            <input id="min_margin_pct" name="min_margin_pct" required inputmode="decimal"
                   value="{{ old('min_margin_pct', $vigente->min_margin_pct ?? '20') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
          </div>
          <div>
            <label for="margin_basis" class="block text-xs text-slate-500 mb-1">Calculado sobre</label>
            <select id="margin_basis" name="margin_basis"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($bases as $codigo => $texto)
                <option value="{{ $codigo }}"
                  @selected(old('margin_basis', $vigente->margin_basis ?? 'cost') === $codigo)>
                  {{ $texto }}
                </option>
              @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-400">
              No es lo mismo: con 141,84 de costo y 20 %, sobre el costo da 170,21 y sobre el
              ingreso 177,30.
            </p>
          </div>
          <div>
            <label for="note" class="block text-xs text-slate-500 mb-1">Por qué estos números</label>
            <textarea id="note" name="note" rows="3" maxlength="255"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('note') }}</textarea>
          </div>
          <div>
            <label for="valid_from" class="block text-xs text-slate-500 mb-1">Vigente desde</label>
            <input id="valid_from" name="valid_from" type="date" required
                   value="{{ old('valid_from', $hoy) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <button class="w-full rounded-lg bg-navy px-4 py-2.5 text-sm font-medium text-white hover:opacity-90">
            Publicar
          </button>
        </form>
      </div>
    </div>
  </div>
@endsection
