@extends('layouts.acceso')
@section('titulo', 'Recuperar contraseña')

@section('contenido')
  @if (session('fallo'))
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      {{ session('fallo') }}
    </div>
  @endif

  @if (session('enviado'))
    {{-- El MISMO texto exista el correo o no. Si aquí dijera «no tenemos ese
         correo», esta pantalla sería un buscador de cuentas dadas de alta. --}}
    <h1 class="text-xl font-semibold text-slate-900 mb-2">Mira tu correo</h1>
    <p class="text-sm text-slate-600">
      Si esa dirección tiene una cuenta activa, le acaba de salir un enlace para poner
      una contraseña nueva. Vale <strong>una hora</strong> y sólo se puede usar una vez.
    </p>
    <p class="mt-3 text-sm text-slate-500">
      ¿No llega en unos minutos? Mira en la carpeta de correo no deseado y comprueba que
      la dirección esté bien escrita. Si sigue sin llegar, escribe a administración.
    </p>
    <a href="{{ route('acceso') }}"
       class="mt-6 block w-full rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm
              text-slate-600 hover:bg-slate-50">
      Volver a entrar
    </a>
  @else
    <h1 class="text-xl font-semibold text-slate-900 mb-1">Recuperar contraseña</h1>
    <p class="text-sm text-slate-500 mb-6">Te mandamos un enlace para poner una nueva.</p>

    @if ($errors->any())
      <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="{{ route('recuperar.enviar') }}" class="space-y-4">
      @csrf
      <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Tu correo</label>
        <input id="email" name="email" type="email" required autofocus
               value="{{ old('email') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                      focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
      </div>
      <button type="submit"
              class="w-full rounded-lg bg-marca-500 px-4 py-2.5 text-sm font-semibold text-white
                     hover:bg-marca-600 focus:ring-2 focus:ring-marca-300 focus:outline-none transition">
        Mandarme el enlace
      </button>
    </form>

    <p class="mt-5 text-center text-sm">
      <a href="{{ route('acceso') }}" class="text-slate-500 hover:text-slate-700 hover:underline">
        Volver a entrar
      </a>
    </p>
  @endif
@endsection
