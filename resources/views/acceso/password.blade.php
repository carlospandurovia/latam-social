@extends('layouts.panel')
@section('titulo', 'Cambiar contraseña')
@section('subtitulo', $obligatorio ? 'Hay que hacerlo antes de seguir' : 'Tu contraseña de acceso')

@section('contenido')
  <div class="max-w-xl">
    {{-- Cuando es obligatorio se explica POR QUÉ. Una pantalla que bloquea sin
         decir el motivo se percibe como una molestia burocrática, y entonces la
         gente teclea lo primero que se le ocurre. --}}
    @if ($obligatorio)
      <div class="mb-5 rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
        <p class="font-medium">Tu contraseña la conoce quien te creó la cuenta.</p>
        <p class="mt-1">
          Mientras siga siendo la misma, tu usuario y el suyo no son dos personas distintas
          para el sistema. Y de eso dependen las reglas que exigen <strong>dos personas</strong>
          para aprobar un perfil fiscal o verificar un medio de pago
          (<code>BR-FIN-005</code>). Por eso no se puede seguir sin cambiarla.
        </p>
      </div>
    @endif

    <form method="POST" action="{{ route('contrasena.cambiar') }}"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @csrf
      @method('PUT')

      <div>
        <label for="actual" class="block text-sm font-medium text-slate-700 mb-1">Contraseña actual</label>
        <input id="actual" name="actual" type="password" autocomplete="current-password"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        {{-- Se pide aunque el cambio sea obligatorio: «entró con ella» y «sigue
             delante» no son lo mismo. Una sesión abierta y desatendida bastaría
             para dejar fuera al dueño de la cuenta. --}}
        <p class="mt-1 text-xs text-slate-500">Se pide para que una sesión abierta y desatendida no baste para dejarte fuera.</p>
        @error('actual') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Contraseña nueva</label>
        <input id="password" name="password" type="password" autocomplete="new-password"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="mt-1 text-xs text-slate-500">
          Al menos 12 caracteres, con letras, números y símbolos. Se comprueba además
          que no aparezca en filtraciones públicas conocidas.
        </p>
        @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div>
        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1">Repite la nueva</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>

      <div class="flex gap-3 pt-1">
        <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
          Cambiar contraseña
        </button>
        {{-- Sin «Cancelar» cuando es obligatorio: el middleware devolvería aquí
             de todas formas, y un botón que no lleva a ningún sitio es peor que
             no tenerlo. --}}
        @unless ($obligatorio)
          <a href="{{ route('panel') }}"
             class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
            Cancelar
          </a>
        @endunless
      </div>
    </form>
  </div>
@endsection
