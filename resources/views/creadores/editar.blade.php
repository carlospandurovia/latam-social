@extends('layouts.panel')
@section('titulo', 'Editar creador')

@section('contenido')
<div class="max-w-3xl">

  <div class="mb-6">
    <a href="{{ route('creadores.show', $creador->uuid) }}" class="text-sm text-slate-500 hover:text-slate-800">← Volver a la ficha</a>
    <h1 class="text-2xl font-semibold text-slate-900 mt-2">{{ $creador->display_name }}</h1>
    <p class="text-slate-500 text-sm">{{ $creador->email }}</p>
  </div>

  {{-- Se dice en la propia pantalla qué NO se puede cambiar aquí. Un formulario
       que simplemente omite campos deja al operador buscándolos. --}}
  <div class="bg-slate-100 border border-slate-200 rounded-xl p-4 mb-6 text-sm text-slate-600">
    <p class="font-medium text-slate-800 mb-1">Aquí no se edita la identidad</p>
    <p>
      Nombre legal, fecha de nacimiento, documento, correo y estado tienen su
      propio flujo, con evidencia y aprobación. Esta pantalla es para contacto y
      preferencias comerciales.
    </p>
  </div>

  @if ($errors->any())
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 mb-6 text-sm">
      <p class="font-medium mb-1">Revisa estos campos:</p>
      <ul class="list-disc list-inside space-y-0.5">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <form method="POST" action="{{ route('creadores.update', $creador->uuid) }}"
        class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-5">
    @csrf
    @method('PUT')

    <div>
      <label for="display_name" class="block text-sm font-medium text-slate-700 mb-1">Nombre público</label>
      <input id="display_name" name="display_name" type="text" required maxlength="120"
             value="{{ old('display_name', $creador->display_name) }}"
             class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
      <div>
        <label for="phone" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
        <input id="phone" name="phone" type="text" maxlength="30"
               value="{{ old('phone', $creador->phone) }}"
               class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
      </div>
      <div>
        <label for="city" class="block text-sm font-medium text-slate-700 mb-1">Ciudad</label>
        <input id="city" name="city" type="text" maxlength="100"
               value="{{ old('city', $creador->city) }}"
               class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
      <div>
        <label for="payment_term_days" class="block text-sm font-medium text-slate-700 mb-1">Plazo de pago (días)</label>
        <input id="payment_term_days" name="payment_term_days" type="number" min="0" max="180" required
               value="{{ old('payment_term_days', $creador->payment_term_days) }}"
               class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
        <p class="text-xs text-slate-400 mt-1">Visible para el creador (BR-FIN-012).</p>
      </div>
      <div>
        <label for="preferred_currency_code" class="block text-sm font-medium text-slate-700 mb-1">Moneda preferida</label>
        <select id="preferred_currency_code" name="preferred_currency_code" required
                class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
          @foreach ($monedas as $moneda)
            <option value="{{ $moneda->code }}"
              @selected(old('preferred_currency_code', $creador->preferred_currency_code) === $moneda->code)>
              {{ $moneda->code }} — {{ $moneda->name }}
            </option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="locale" class="block text-sm font-medium text-slate-700 mb-1">Idioma</label>
        <select id="locale" name="locale" required
                class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
          @foreach ($idiomas as $idioma)
            <option value="{{ $idioma->code }}" @selected(old('locale', $creador->locale) === $idioma->code)>
              {{ $idioma->name }}
            </option>
          @endforeach
        </select>
      </div>
    </div>

    <div>
      <label for="timezone" class="block text-sm font-medium text-slate-700 mb-1">Zona horaria</label>
      <input id="timezone" name="timezone" type="text" required maxlength="64"
             value="{{ old('timezone', $creador->timezone) }}"
             class="w-full rounded-xl border border-slate-300 focus:border-marca-500 focus:ring-marca-500">
      <p class="text-xs text-slate-400 mt-1">Identificador IANA, por ejemplo <code>America/Lima</code>.</p>
    </div>

    <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
      <button type="submit"
              class="px-5 py-2.5 rounded-xl bg-marca-500 text-white text-sm font-medium hover:opacity-90">
        Guardar cambios
      </button>
      <a href="{{ route('creadores.show', $creador->uuid) }}"
         class="px-5 py-2.5 rounded-xl text-sm text-slate-600 hover:bg-slate-100">Cancelar</a>
      <span class="text-xs text-slate-400 ml-auto">Todo cambio queda registrado en la bitácora.</span>
    </div>
  </form>
</div>
@endsection
