@extends('layouts.panel')
@section('titulo', $lote->code)
@section('subtitulo', $sociedad.' · '.$lote->currency_code)

@section('contenido')
  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-400">
            <tr class="text-left">
              <th class="px-5 py-3">Creador</th><th>Cuenta</th>
              <th class="text-right">Importe</th><th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($pagos as $p)
              <tr @class(['opacity-50' => $p->status === 'cancelled'])>
                <td class="px-5 py-3">
                  <p class="font-medium">{{ $p->creador }}</p>
                  <p class="text-xs text-slate-500">{{ $p->beneficiary_name_snapshot }}</p>
                </td>
                <td class="text-slate-500">{{ $p->account_masked_snapshot }}</td>
                <td class="text-right tabular-nums">
                  {{ number_format((float) $p->amount, 2) }}
                  @if ($p->status === 'cancelled')
                    <span class="block text-xs text-amber-700">sacado del lote</span>
                  @endif
                </td>
                <td class="px-5 text-right">
                  {{-- Sacar un pago sólo mientras el dinero no ha salido. Después
                       eso se corrige con una devolución, no sacándolo. --}}
                  @if ($p->status === 'pending' && $lote->status !== 'executed')
                    @can('finance.payout.create')
                      <details>
                        <summary class="cursor-pointer text-xs text-slate-500">Sacar</summary>
                        <form method="POST" action="{{ route('lotes.sacar', [$lote->uuid, $p->id]) }}"
                              class="mt-2 space-y-1">
                          @csrf
                          <input name="motivo" placeholder="Por qué sale del lote"
                                 class="w-56 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                          <button class="rounded-lg border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                            Sacar del lote
                          </button>
                        </form>
                      </details>
                    @endcan
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
          <tfoot class="bg-slate-50">
            <tr>
              <td class="px-5 py-3 font-medium" colspan="2">Total a pagar</td>
              <td class="text-right font-semibold tabular-nums">
                {{ number_format((float) $total, 2) }} {{ $lote->currency_code }}
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      @error('motivo') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Estado</h2>
        <p class="mt-1 text-sm">{{ $estados[$lote->status] ?? $lote->status }}</p>

        @if ($lote->approved_at)
          <p class="mt-2 text-xs text-slate-500">Firmado el {{ substr((string) $lote->approved_at, 0, 16) }}.</p>
        @endif
        @if ($lote->executed_at)
          <p class="text-xs text-slate-500">Ejecutado el {{ substr((string) $lote->executed_at, 0, 16) }}.</p>
        @endif

        {{-- El veto se dice ANTES de que nadie pulse. Descubrir al pulsar que no
             puedes firmar tu propio lote es enterarse tarde. --}}
        @if ($veto)
          <p class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">{{ $veto }}</p>
        @endif

        @if (!$veto)
          @can('finance.payout.approve')
            <form method="POST" action="{{ route('lotes.aprobar', $lote->uuid) }}" class="mt-3">
              @csrf
              <button class="w-full rounded-lg bg-marca-500 px-3 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
                Firmar este lote
              </button>
            </form>
          @endcan
        @endif

        @if ($lote->status === 'approved')
          @can('finance.payout.create')
            <form method="POST" action="{{ route('lotes.ejecutar', $lote->uuid) }}" class="mt-2">
              @csrf
              <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 transition">
                Ejecutar: el dinero sale
              </button>
            </form>
          @endcan
        @endif

        <a href="{{ route('lotes.csv', $lote->uuid) }}"
           class="mt-2 block rounded-lg border border-slate-300 px-3 py-2 text-center text-sm hover:bg-slate-50 transition">
          Descargar CSV
        </a>
        <p class="mt-2 text-xs text-slate-500">
          El CSV es legible por una persona, <strong>no es el archivo del banco</strong>:
          ese formato sale del manual de cada entidad y hay que construirlo con su
          especificación delante.
        </p>
      </div>

      <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 text-xs text-slate-600">
        <p class="font-medium text-slate-700">Por qué hacen falta dos firmas</p>
        <p class="mt-1">
          <code>BR-FIN-005</code>. Quien arma un lote no puede aprobarlo, y eso no lo
          comprueba esta pantalla: lo impide la base. Siempre, sea cual sea el importe.
        </p>
      </div>
    </div>
  </div>
@endsection
