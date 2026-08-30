@extends('layouts.panel')
@section('titulo', 'Lotes de pago')
@section('subtitulo', 'Todo pago pertenece a un lote, y todo lote lo firma alguien distinto de quien lo armó')

@section('contenido')
  <div class="mb-5 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
    Un lote es de <strong>una sociedad y una moneda</strong>. La sociedad sale de la
    <strong>campaña</strong> de cada devengo, no del país del creador
    (<code>BR-LE-009</code>): un creador colombiano en una campaña de CTS Perú
    cobra de CTS Perú. La base no deja mezclarlas.
    <br>
    Y siempre hacen falta <strong>dos firmas</strong>, sea cual sea el importe.
  </div>

  {{-- Se enseña lo que se pagaría ANTES de crear nada: un lote no se borra. --}}
  <form method="GET" action="{{ route('lotes.index') }}"
        class="mb-5 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-5">
    <div>
      <label for="entidad" class="block text-xs text-slate-500 mb-1">Sociedad que paga</label>
      <select id="entidad" name="entidad" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">—</option>
        @foreach ($entidades as $e)
          <option value="{{ $e->id }}" @selected($entidadId === (int) $e->id)>{{ $e->code }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="moneda" class="block text-xs text-slate-500 mb-1">Moneda</label>
      <select id="moneda" name="moneda" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">—</option>
        @foreach ($monedas as $m)
          <option value="{{ $m->code }}" @selected($moneda === $m->code)>{{ $m->code }}</option>
        @endforeach
      </select>
    </div>
    <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 transition">
      Ver qué se pagaría
    </button>
  </form>

  @if ($entidadId > 0 && $moneda !== '')
    <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5">
      <h2 class="text-sm font-medium text-slate-700">Pagable ahora mismo</h2>

      @if ($pagables->isEmpty())
        <p class="mt-2 text-sm text-slate-500">
          No hay nada pagable de esa sociedad en esa moneda. Un devengo llega aquí
          cuando cumple las cinco condiciones de <code>BR-FIN-003</code>.
        </p>
      @else
        <table class="mt-3 w-full text-sm">
          <thead class="text-xs uppercase text-slate-400">
            <tr class="text-left"><th class="py-2">Creador</th><th>Campaña</th><th class="text-right">Importe</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($pagables as $p)
              <tr>
                <td class="py-2">{{ $p->creador }}</td>
                <td class="text-slate-500">{{ $p->campana }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $p->amount, 2) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-200">
              <td class="pt-2 font-medium" colspan="2">
                {{ $pagables->count() }} {{ $pagables->count() === 1 ? 'devengo' : 'devengos' }}
              </td>
              <td class="pt-2 text-right font-semibold tabular-nums">
                {{ number_format((float) $pagables->sum('amount'), 2) }} {{ $moneda }}
              </td>
            </tr>
          </tfoot>
        </table>

        @can('finance.payout.create')
          <form method="POST" action="{{ route('lotes.store') }}" class="mt-4">
            @csrf
            <input type="hidden" name="legal_entity_id" value="{{ $entidadId }}">
            <input type="hidden" name="currency_code" value="{{ $moneda }}">
            <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
              Armar el lote
            </button>
          </form>
        @endcan
      @endif
    </div>
  @endif

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-xs uppercase text-slate-400">
        <tr class="text-left">
          <th class="px-5 py-3">Lote</th><th>Sociedad</th><th>Estado</th>
          <th>Armó</th><th>Firmó</th><th></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($lotes as $l)
          <tr>
            <td class="px-5 py-3 font-medium">{{ $l->code }}</td>
            <td class="text-slate-500">{{ $l->sociedad }} · {{ $l->currency_code }}</td>
            <td>
              <span @class([
                'rounded-full px-2 py-0.5 text-xs',
                'bg-emerald-50 text-emerald-700' => $l->status === 'executed',
                'bg-sky-50 text-sky-700' => $l->status === 'approved',
                'bg-slate-100 text-slate-600' => in_array($l->status, ['draft', 'pending_approval'], true),
                'bg-amber-50 text-amber-800' => $l->status === 'cancelled',
              ])>{{ $estados[$l->status] ?? $l->status }}</span>
            </td>
            <td class="text-slate-500">{{ $l->autor ?? '—' }}</td>
            <td class="text-slate-500">{{ $l->aprobador ?? '—' }}</td>
            <td class="px-5 text-right">
              <a href="{{ route('lotes.show', $l->uuid) }}"
                 class="text-marca-600 hover:text-marca-700">Ver</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-5 py-6 text-slate-500">
            Todavía no se armó ningún lote.
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
@endsection
