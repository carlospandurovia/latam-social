@extends('layouts.acceso')
@section('titulo', 'Invitación a una campaña')

@section('contenido')
  {{-- Lo que ve un creador y NADA MÁS: su campaña, su marca, sus fechas, SU
       importe y su plazo de pago.

       Ni el ingreso del cliente, ni lo que cobran los demás creadores, ni el
       presupuesto, ni el margen. `BR-SEC-001` es 🔴 y dice exactamente eso: la
       audiencia de creador no puede recibir campos de la audiencia interna. --}}
  <h1 class="text-xl font-semibold text-slate-900 mb-1">Hola, {{ $i->display_name }}</h1>
  <p class="text-sm text-slate-500 mb-6">Te queremos invitar a una campaña.</p>

  <dl class="rounded-xl border border-slate-200 divide-y divide-slate-100 text-sm">
    <div class="flex justify-between gap-4 px-4 py-3">
      <dt class="text-slate-500">Campaña</dt>
      <dd class="font-medium text-slate-900 text-right">{{ $i->campana }}</dd>
    </div>
    @if ($i->marca)
      <div class="flex justify-between gap-4 px-4 py-3">
        <dt class="text-slate-500">Marca</dt>
        <dd class="font-medium text-slate-900 text-right">{{ $i->marca }}</dd>
      </div>
    @endif
    <div class="flex justify-between gap-4 px-4 py-3">
      <dt class="text-slate-500">Fechas</dt>
      <dd class="font-medium text-slate-900 text-right">
        {{ \Illuminate\Support\Carbon::parse($i->starts_on)->format('d/m/Y') }}
        —
        {{ \Illuminate\Support\Carbon::parse($i->ends_on)->format('d/m/Y') }}
      </dd>
    </div>
    <div class="flex justify-between gap-4 px-4 py-3 bg-slate-50">
      <dt class="text-slate-500">Lo que cobras</dt>
      <dd class="font-semibold text-slate-900 text-right">
        {{ number_format((float) $i->amount_snapshot, 2) }} {{ $i->currency_snapshot }}
      </dd>
    </div>
    <div class="flex justify-between gap-4 px-4 py-3">
      <dt class="text-slate-500">Plazo de pago</dt>
      <dd class="font-medium text-slate-900 text-right">{{ $i->payment_term_days_snapshot }} días</dd>
    </div>
  </dl>

  <p class="mt-3 text-xs text-slate-500">
    Tienes hasta el
    <strong>{{ \Illuminate\Support\Carbon::parse($i->expires_at)->format('d/m/Y H:i') }}</strong>
    para contestar. Pasado ese plazo la invitación caduca sola.
  </p>

  @if ($errors->any())
    <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ $errors->first() }}
    </div>
  @endif

  <form method="POST" action="{{ route('invitacion.aceptar') }}" class="mt-6">
    @csrf
    <button class="w-full rounded-lg bg-marca-500 px-4 py-2.5 text-sm font-semibold text-white
                   hover:bg-marca-600 focus:ring-2 focus:ring-marca-300 focus:outline-none transition">
      Acepto la invitación
    </button>
  </form>

  <p class="mt-2 text-xs text-slate-500">
    Al aceptar, el importe de arriba queda cerrado por las dos partes.
  </p>

  {{-- El rechazo va con motivo, y el motivo es de lista cerrada. Sin él no se
       puede contestar la única pregunta útil que sale de aquí: ¿por qué nos
       dicen que no? --}}
  <details class="mt-6 group">
    <summary class="cursor-pointer text-sm text-slate-500 hover:text-slate-700 list-none">
      <span class="group-open:hidden">No puedo esta vez</span>
      <span class="hidden group-open:inline">No puedo esta vez</span>
    </summary>

    <form method="POST" action="{{ route('invitacion.rechazar') }}" class="mt-4 space-y-3">
      @csrf
      <div>
        <label for="motivo" class="block text-sm font-medium text-slate-700 mb-1.5">¿Por qué?</label>
        <select id="motivo" name="motivo" required
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                       focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
          <option value="">Elige un motivo…</option>
          @foreach ($motivos as $clave => $texto)
            <option value="{{ $clave }}" @selected(old('motivo') === $clave)>{{ $texto }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="nota" class="block text-sm font-medium text-slate-700 mb-1.5">
          ¿Quieres contarnos algo más? <span class="text-slate-400 font-normal">(opcional)</span>
        </label>
        <textarea id="nota" name="nota" rows="2" maxlength="255"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                         focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">{{ old('nota') }}</textarea>
      </div>
      <button class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50">
        Rechazar la invitación
      </button>
    </form>
  </details>
@endsection
