@extends('layouts.acceso')
@section('titulo', $inicial ? 'Elegir contraseña' : 'Contraseña nueva')

@section('contenido')
  <h1 class="text-xl font-semibold text-slate-900 mb-1">
    {{ $inicial ? 'Elige tu contraseña' : 'Pon una contraseña nueva' }}
  </h1>

  {{-- A qué cuenta afecta. Quien lleva dos cuentas y abre el correo equivocado
       tiene que poder darse cuenta ANTES de teclear. --}}
  <p class="text-sm text-slate-500 mb-6">
    Para <strong class="text-slate-700">{{ $correo }}</strong>.
  </p>

  @if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ $errors->first() }}
    </div>
  @endif

  <form method="POST" action="{{ route('recuperar.fijar') }}" class="space-y-4">
    @csrf
    {{-- Sin campo oculto con el token: vive en la sesión desde que se abrió el
         enlace, y así no vuelve a viajar ni queda en el HTML de la página. --}}
    <div>
      <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Contraseña</label>
      <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
             class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                    focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
      <p class="mt-1 text-xs text-slate-500">
        Al menos 12 caracteres, con letras, números y símbolos.
      </p>
    </div>
    <div>
      <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Repítela</label>
      <input id="password_confirmation" name="password_confirmation" type="password" required
             autocomplete="new-password"
             class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                    focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
    </div>
    <button type="submit"
            class="w-full rounded-lg bg-marca-500 px-4 py-2.5 text-sm font-semibold text-white
                   hover:bg-marca-600 focus:ring-2 focus:ring-marca-300 focus:outline-none transition">
      Guardar contraseña
    </button>
  </form>

  @unless ($inicial)
    <p class="mt-5 text-xs text-slate-500">
      Al guardarla se cerrarán todas las sesiones abiertas de esta cuenta, aquí y en
      cualquier otro dispositivo.
    </p>
  @endunless
@endsection
