@extends('layouts.panel')
@section('titulo', 'Impuestos')
@section('subtitulo', 'Cuánto era la tasa, y desde cuándo')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Impuestos'])

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

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-800'
         : 'bg-amber-50 border-amber-200 text-amber-800' }}">
      {{ $aviso->texto }}
    </div>
  @endforeach

  <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Una tasa no se corrige: se publica la siguiente</p>
    <p>
      Corregir el 18&nbsp;% de una fila que ya explicó el impuesto de cien facturas es reescribir
      el pasado. Lo que se hace es <strong>cerrar la que rige y abrir la nueva desde el día que
      toque</strong> — que es lo que de verdad pasa cuando un país sube un impuesto. La cerrada se
      queda: es lo que explica el importe de lo ya emitido.
    </p>
  </div>

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-4 py-2 text-left">País</th>
              <th class="px-4 py-2 text-left">Impuesto</th>
              <th class="px-4 py-2 text-right">Tasa</th>
              <th class="px-4 py-2 text-left">Desde</th>
              <th class="px-4 py-2 text-left">Hasta</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse ($tasas as $t)
              <tr class="{{ $t->valid_to === null ? '' : 'text-slate-400' }}">
                <td class="px-4 py-2">{{ $t->pais }}</td>
                <td class="px-4 py-2">
                  {{ $t->code }}
                  <span class="text-xs text-slate-400">{{ $t->name }}</span>
                  @if ($t->official_code)
                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-mono">{{ $t->official_code }}</span>
                  @endif
                  @if ($t->sales_tax_code === $t->code)
                    <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[11px] text-sky-800">en la factura</span>
                  @endif
                </td>
                <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim($t->rate, '0'), '.') }} %</td>
                <td class="px-4 py-2 text-xs">{{ $t->valid_from }}</td>
                <td class="px-4 py-2 text-xs">
                  {{ $t->valid_to ?? '—' }}
                  @if ($t->valid_to === null)
                    <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-800">vigente</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-6 text-sm text-slate-500">
                Todavía no hay ninguna tasa. Sin ella, el impuesto de una factura saldría en cero.
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <form method="POST" action="{{ route('impuestos.publicar') }}"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      @csrf
      <h2 class="text-sm font-semibold">Publicar una tasa</h2>

      <label class="block text-xs text-slate-500">País
        <select name="country_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
          @foreach ($paises as $p)
            <option value="{{ $p->id }}">{{ $p->name }}</option>
          @endforeach
        </select>
      </label>

      <label class="block text-xs text-slate-500">Código
        <input name="code" required maxlength="20" placeholder="IGV" value="{{ old('code') }}"
               class="mt-1 w-full rounded border-slate-300 text-sm font-mono">
        <span class="text-[11px] text-slate-400">Es lo que sale impreso en el comprobante.</span>
      </label>

      <label class="block text-xs text-slate-500">Nombre
        <input name="name" required maxlength="80" placeholder="Impuesto General a las Ventas"
               value="{{ old('name') }}" class="mt-1 w-full rounded border-slate-300 text-sm">
      </label>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-xs text-slate-500">Tasa (%)
          <input type="number" name="rate" step="0.0001" min="0" max="99.9999" required
                 placeholder="18" value="{{ old('rate') }}"
                 class="mt-1 w-full rounded border-slate-300 text-sm">
        </label>

        <label class="block text-xs text-slate-500">Código oficial
          <input name="official_code" maxlength="10" placeholder="1000" value="{{ old('official_code') }}"
                 class="mt-1 w-full rounded border-slate-300 text-sm font-mono">
        </label>
      </div>

      <label class="block text-xs text-slate-500">Rige desde
        <input type="date" name="valid_from" required value="{{ old('valid_from') }}"
               class="mt-1 w-full rounded border-slate-300 text-sm">
        <span class="text-[11px] text-slate-400">La anterior se cierra el día antes.</span>
      </label>

      <label class="block text-xs text-slate-500">Nota
        <input name="note" maxlength="255" placeholder="La norma que la cambió"
               value="{{ old('note') }}" class="mt-1 w-full rounded border-slate-300 text-sm">
      </label>

      <label class="flex items-start gap-2 text-xs text-slate-600">
        <input type="checkbox" name="es_de_venta" value="1" class="mt-0.5 rounded border-slate-300">
        <span>
          Es el impuesto que va en las <strong>facturas de venta</strong> de ese país.
          <span class="block text-[11px] text-slate-400">
            Sin esto el sistema no sabe cuál de los impuestos del país usar al facturar, y saldría en cero.
          </span>
        </span>
      </label>

      <button class="w-full rounded bg-marca-500 px-3 py-2 text-sm text-white">Publicar</button>
    </form>
  </div>
@endsection
