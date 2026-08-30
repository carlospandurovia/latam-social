@extends('layouts.panel')
@section('titulo', 'Conciliación')
@section('subtitulo', 'Los pagos que se mandaron y todavía no se han comprobado contra el extracto')

@section('contenido')
  <div class="mb-5 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
    <strong>Enviado no es llegado.</strong> Entre las dos cosas está el banco, y ahí un
    pago se rechaza porque la cuenta estaba mal, porque el titular no coincide o
    porque el archivo iba mal. Mientras nadie mire el extracto, el sistema no sabe
    cuál de las dos pasó — y el creador tampoco.
    <br>
    Al <strong>confirmar</strong> y al <strong>devolver</strong> se le escribe al creador. Al enviar no:
    sería avisarle de una intención.
  </div>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    @forelse ($pagos as $p)
      <div class="border-b border-slate-100 p-5 last:border-0">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium">{{ $p->creador }}</p>
            <p class="text-xs text-slate-500">
              {{ $p->beneficiary_name_snapshot }} · {{ $p->account_masked_snapshot }}
            </p>
            <p class="mt-1 text-xs text-slate-400">
              Lote {{ $p->lote }} · {{ $p->sociedad }} · enviado el
              {{ substr((string) $p->sent_at, 0, 10) }}
            </p>
          </div>
          <p class="text-lg font-semibold tabular-nums">
            {{ number_format((float) $p->amount, 2) }}
            <span class="text-sm font-normal text-slate-500">{{ $p->currency_code }}</span>
          </p>
        </div>

        @can('finance.payout.create')
          <div class="mt-4 grid gap-4 lg:grid-cols-2">
            {{-- Confirmar exige referencia y fecha valor. No es burocracia: sin
                 ellas «confirmado» es la palabra de quien lo marcó, y conciliar
                 un extracto dentro de seis meses se vuelve imposible. --}}
            <form method="POST" action="{{ route('pagos.confirmar', $p->id) }}"
                  enctype="multipart/form-data"
                  class="rounded-lg border border-slate-200 p-4">
              @csrf
              <p class="text-xs font-medium text-slate-700">Llegó</p>
              <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <div>
                  <label for="ref-{{ $p->id }}" class="block text-xs text-slate-500 mb-1">
                    Referencia del banco
                  </label>
                  <input id="ref-{{ $p->id }}" name="bank_reference"
                         class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div>
                  <label for="val-{{ $p->id }}" class="block text-xs text-slate-500 mb-1">
                    Fecha valor
                  </label>
                  <input id="val-{{ $p->id }}" name="value_date" type="date" max="{{ $hoy }}"
                         value="{{ $hoy }}"
                         class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
              </div>
              <label for="pdf-{{ $p->id }}" class="mt-2 block text-xs text-slate-500">
                Comprobante <span class="text-slate-400">(opcional)</span>
              </label>
              <input id="pdf-{{ $p->id }}" name="comprobante" type="file"
                     class="w-full text-xs">
              <button class="mt-3 w-full rounded-lg bg-marca-500 px-3 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
                Confirmar el pago
              </button>
            </form>

            {{-- Devolver exige motivo: un pago que vuelve sin decir por qué se
                 reintenta a ciegas contra la misma cuenta equivocada. --}}
            <form method="POST" action="{{ route('pagos.devolver', $p->id) }}"
                  class="rounded-lg border border-slate-200 p-4">
              @csrf
              <p class="text-xs font-medium text-slate-700">El banco lo devolvió</p>
              <label for="mot-{{ $p->id }}" class="mt-2 block text-xs text-slate-500 mb-1">
                Por qué lo devolvieron
              </label>
              <input id="mot-{{ $p->id }}" name="motivo"
                     placeholder="Cuenta cerrada, titular no coincide…"
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              <p class="mt-2 text-xs text-slate-500">
                El importe se le vuelve a deber y entra en el próximo lote. Al creador
                se le pide que revise su cuenta.
              </p>
              <button class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 transition">
                Registrar la devolución
              </button>
            </form>
          </div>
        @endcan
      </div>
    @empty
      <p class="p-6 text-sm text-slate-500">
        No hay ningún pago esperando conciliación. Aquí aparecen los pagos en cuanto
        se ejecuta un lote.
      </p>
    @endforelse
  </div>

  @error('bank_reference') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
  @error('value_date') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
  @error('motivo') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
  @error('comprobante') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
@endsection
