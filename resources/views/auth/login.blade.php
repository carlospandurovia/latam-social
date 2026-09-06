@extends('layouts.acceso')
@section('titulo', 'Entrar')

@section('contenido')
  <h1 class="text-xl font-semibold text-slate-900 mb-1">Entrar</h1>
  <p class="text-sm text-slate-500 mb-6">Accede con tu cuenta.</p>

  @if (session('exito'))
    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ $errors->first() }}
    </div>
  @endif

  <form method="POST" action="{{ route('entrar') }}" class="space-y-4">
    @csrf
    <div>
      <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Correo</label>
      <input id="email" name="email" type="email" required autofocus
             value="{{ old('email') }}"
             class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                    focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
    </div>
    <div>
      <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Contraseña</label>
      <input id="password" name="password" type="password" required
             class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                    focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
    </div>
    {{-- L-7: el area tactil de la fila entera, no la del cuadradito.
           A 13 px de alto, marcar «mantener la sesion» en un telefono es
           punteria. El `<label>` ya envuelve al control, asi que basta con que
           la fila mida lo que tiene que medir. --}}
    <label class="flex min-h-[2.25rem] items-center gap-2 py-1 text-sm text-slate-600">
      <input type="checkbox" name="remember" class="rounded border border-slate-300 text-marca-500 focus:ring-marca-200">
      Mantener la sesión
    </label>
    <button type="submit"
            class="w-full rounded-lg bg-marca-500 px-4 py-2.5 text-sm font-semibold text-white
                   hover:bg-marca-600 focus:ring-2 focus:ring-marca-300 focus:outline-none transition">
      Entrar
    </button>
  </form>

  {{-- `4.1`. Antes de esto, olvidar la contraseña era una llamada de teléfono y
       un comando de consola. --}}
  <p class="mt-5 text-center text-sm">
    <a href="{{ route('recuperar') }}" class="inline-block py-1.5 text-marca-600 hover:text-marca-700 hover:underline">
      He olvidado mi contraseña
    </a>
  </p>
@endsection
