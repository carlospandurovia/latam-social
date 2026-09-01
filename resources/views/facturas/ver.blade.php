@extends('layouts.panel')
@section('titulo', 'Comprobante')
@section('subtitulo', $factura->full_number ?? 'Borrador sin número')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Comprobante'])

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

  <div class="mb-4 flex flex-wrap items-center gap-3">
    <a href="{{ route('facturas.index') }}" class="text-sm text-marca-600 hover:underline">&larr; Comprobantes</a>
    <span class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">
      {{ $estados[$factura->status] ?? $factura->status }}
    </span>
    <span class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">
      {{ $regimenes[$factura->tax_regime] ?? $factura->tax_regime }}
    </span>
  </div>

  @if ($factura->status === 'voided')
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
      <strong>Anulada el {{ $factura->voided_at }}.</strong> {{ $factura->void_reason }}
      <span class="block text-xs mt-1">
        El número sigue siendo suyo: devolverlo a la serie sería emitir dos comprobantes con el mismo.
      </span>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      <div class="grid gap-5 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
          <p class="text-xs uppercase text-slate-400 mb-2">Emisor</p>
          <p class="font-semibold">{{ $factura->issuer_legal_name_snapshot }}</p>
          <p class="font-mono text-xs text-slate-500">{{ $factura->issuer_tax_id_snapshot }}</p>
          <p class="text-xs text-slate-500">{{ $factura->issuer_address_snapshot }}</p>
          <p class="text-xs text-slate-400">{{ $factura->issuer_country_snapshot }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
          <p class="text-xs uppercase text-slate-400 mb-2">Receptor</p>
          <p class="font-semibold">{{ $factura->receiver_legal_name_snapshot }}</p>
          <p class="font-mono text-xs text-slate-500">{{ $factura->receiver_tax_id_snapshot }}</p>
          <p class="text-xs text-slate-500">{{ $factura->receiver_address_snapshot }}</p>
          <p class="text-xs text-slate-400">{{ $factura->receiver_country_snapshot }}</p>
        </div>
      </div>

      <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div class="border-b border-slate-100 px-4 py-3 text-sm font-semibold">Qué se cobra</div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th class="px-4 py-2 text-left">Concepto</th>
                <th class="px-4 py-2 text-right">Cant.</th>
                <th class="px-4 py-2 text-right">Precio</th>
                <th class="px-4 py-2 text-right">Subtotal</th>
                <th class="px-4 py-2 text-right">Impuesto</th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($lineas as $l)
                <tr>
                  <td class="px-4 py-2">{{ $l->description }}</td>
                  <td class="px-4 py-2 text-right font-mono text-xs">{{ rtrim(rtrim($l->quantity, '0'), '.') }}</td>
                  <td class="px-4 py-2 text-right font-mono text-xs">{{ number_format((float) $l->unit_price, 2) }}</td>
                  <td class="px-4 py-2 text-right font-mono text-xs">{{ number_format((float) $l->line_subtotal, 2) }}</td>
                  <td class="px-4 py-2 text-right font-mono text-xs">{{ number_format((float) $l->line_tax, 2) }}</td>
                  <td class="px-4 py-2 text-right">
                    @if ($factura->status === 'draft' && $puedeEmitir && count($lineas) > 1)
                      <form method="POST" action="{{ route('facturas.linea.borrar', ['uuid' => $factura->uuid, 'linea' => $l->id]) }}">
                        @csrf @method('DELETE')
                        <button class="text-xs text-rose-600 hover:underline">quitar</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
            <tfoot class="bg-slate-50 text-sm">
              <tr>
                <td colspan="3" class="px-4 py-2 text-right text-xs uppercase text-slate-500">Subtotal</td>
                <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $factura->subtotal_amount, 2) }}</td>
                <td colspan="2"></td>
              </tr>
              <tr>
                <td colspan="3" class="px-4 py-2 text-right text-xs uppercase text-slate-500">
                  Impuesto
                  @if ($factura->tax_rate_snapshot)
                    ({{ rtrim(rtrim($factura->tax_rate_snapshot, '0'), '.') }} %)
                  @endif
                </td>
                <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $factura->tax_amount, 2) }}</td>
                <td colspan="2"></td>
              </tr>
              <tr class="font-semibold">
                <td colspan="3" class="px-4 py-2 text-right text-xs uppercase text-slate-500">Total</td>
                <td class="px-4 py-2 text-right font-mono">
                  {{ $factura->currency_code }} {{ number_format((float) $factura->total_amount, 2) }}
                </td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      @if ($factura->status === 'draft' && $puedeEmitir)
        <form method="POST" action="{{ route('facturas.linea', ['uuid' => $factura->uuid]) }}"
              class="rounded-xl border border-slate-200 bg-white p-5 space-y-3">
          @csrf
          <h2 class="text-sm font-semibold">Añadir una línea</h2>
          <label class="block text-xs text-slate-500">Concepto
            <input name="description" required maxlength="300" value="{{ old('description') }}"
                   class="mt-1 w-full rounded border-slate-300 text-sm">
          </label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-xs text-slate-500">Cantidad
              <input type="number" name="quantity" step="0.0001" min="0.0001" required value="{{ old('quantity', '1') }}"
                     class="mt-1 w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block text-xs text-slate-500">Precio unitario
              <input type="number" name="unit_price" step="0.0001" min="0" required value="{{ old('unit_price') }}"
                     class="mt-1 w-full rounded border-slate-300 text-sm">
            </label>
          </div>
          <button class="rounded bg-slate-800 px-3 py-2 text-sm text-white">Añadir</button>
        </form>
      @endif
    </div>

    <div class="space-y-4">
      <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm space-y-1">
        <p class="text-xs uppercase text-slate-400 mb-2">El documento</p>
        <p><span class="text-slate-500">Emitido:</span> {{ $factura->issue_date }}</p>
        <p><span class="text-slate-500">Vence:</span> {{ $factura->due_date }}</p>
        <p><span class="text-slate-500">Campaña:</span> {{ $factura->campana_codigo }} {{ $factura->campana }}</p>
        <p><span class="text-slate-500">Sociedad:</span> {{ $factura->sociedad_nombre }}</p>
        @if ($factura->full_number)
          <p class="font-mono">{{ $factura->full_number }}</p>
        @endif
      </div>

      @if ($factura->status === 'draft' && $puedeEmitir)
        <form method="POST" action="{{ route('facturas.emitir', ['uuid' => $factura->uuid]) }}"
              class="rounded-xl border border-slate-200 bg-white p-5 space-y-3">
          @csrf
          <h2 class="text-sm font-semibold">Emitir</h2>
          <p class="text-xs text-slate-500">
            Aquí se gasta el correlativo y se congelan las dos partes.
            <strong>No hay vuelta atrás</strong>: a partir de este momento sólo se puede anular.
          </p>
          <label class="block text-xs text-slate-500">Serie
            <select name="document_series_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
              @forelse ($series as $s)
                <option value="{{ $s->id }}">
                  {{ $s->series }} — {{ $s->tipo }} (siguiente: {{ $s->next_number }}, {{ $s->environment }})
                </option>
              @empty
                <option value="" disabled>Esa sociedad no tiene ninguna serie activa</option>
              @endforelse
            </select>
          </label>
          <button class="w-full rounded bg-marca-500 px-3 py-2 text-sm text-white"
                  {{ count($series) === 0 ? 'disabled' : '' }}>Emitir</button>
        </form>

        <form method="POST" action="{{ route('facturas.descartar', ['uuid' => $factura->uuid]) }}"
              class="rounded-xl border border-slate-200 bg-white p-5">
          @csrf @method('DELETE')
          <button class="text-xs text-rose-600 hover:underline">Descartar este borrador</button>
          <p class="mt-1 text-[11px] text-slate-400">No gastó número, así que no deja hueco.</p>
        </form>
      @endif

      @if ($puedeEmitir && !in_array($factura->status, ['draft', 'voided'], true))
        <form method="POST" action="{{ route('facturas.anular', ['uuid' => $factura->uuid]) }}"
              class="rounded-xl border border-rose-200 bg-white p-5 space-y-3">
          @csrf
          <h2 class="text-sm font-semibold text-rose-700">Anular</h2>
          <label class="block text-xs text-slate-500">Motivo
            <textarea name="motivo" required minlength="10" maxlength="255" rows="3"
                      class="mt-1 w-full rounded border-slate-300 text-sm">{{ old('motivo') }}</textarea>
            <span class="text-[11px] text-slate-400">Una anulación muda no se puede defender.</span>
          </label>
          <button class="w-full rounded bg-rose-600 px-3 py-2 text-sm text-white">Anular</button>
        </form>
      @endif
    </div>
  </div>
@endsection
