@extends('layouts.panel')
@section('titulo', 'Términos y condiciones')
@section('subtitulo', 'El texto que acepta el creador, y sus versiones')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Términos'])

  {{-- Los avisos NO bloquean: informan y se ordenan por prioridad. Es el
       criterio de 9.16 — una configuración se rellena con un valor de partida y
       se avisa de que conviene revisarlo, no se convierte en una puerta. --}}
  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso['nivel'] === 'rojo'
          ? 'bg-rose-50 border-rose-200 text-rose-900'
          : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso['nivel'] === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso['nivel'] === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso['texto'] }}
    </div>
  @endforeach

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
      <ul class="list-disc pl-5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="px-5 py-3 border-b border-slate-100 flex items-baseline justify-between gap-3">
        <h2 class="text-sm font-semibold">Versiones de «{{ $codigo }}»</h2>
        <span class="text-xs text-slate-400">La vigente es la que hay que aceptar</span>
      </div>

      @forelse ($versiones as $v)
        <div class="border-b border-slate-100 p-5 last:border-0">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-sm font-medium">
                <a href="{{ route('terminos.show', $v->uuid) }}" class="text-marca-700 hover:underline">
                  v{{ $v->version }} · {{ $v->title }}
                </a>
              </p>
              <p class="text-xs text-slate-500">
                {{ $audiencias[$v->audience] ?? $v->audience }} ·
                @if ($v->published_at === null)
                  borrador, sin publicar
                @else
                  vigente desde {{ $v->effective_from }}
                  @if ($v->effective_to) hasta {{ $v->effective_to }} @endif
                  @if ($v->publicador) · publicó {{ $v->publicador }} @endif
                @endif
                · {{ $v->aceptaciones }} {{ $v->aceptaciones === 1 ? 'aceptación' : 'aceptaciones' }}
              </p>
              @if ($v->change_type)
                <p class="text-xs text-slate-400 mt-0.5">{{ $cambios[$v->change_type] ?? $v->change_type }}</p>
              @endif
            </div>
            <div class="flex flex-col items-end gap-1">
              @if ($v->published_at === null)
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Borrador</span>
              @elseif ($v->effective_to === null)
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Vigente</span>
              @else
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">Cerrada</span>
              @endif

              {{-- El badge de revisión legal: rojo si nadie la ha mirado. --}}
              <span class="rounded px-2 py-0.5 text-xs
                {{ $v->review_status === 'revisado' ? 'bg-emerald-100 text-emerald-800'
                   : ($v->review_status === 'en_revision' ? 'bg-amber-100 text-amber-800'
                   : 'bg-rose-100 text-rose-800') }}">
                {{ $revision[$v->review_status] ?? $v->review_status }}
              </span>
            </div>
          </div>
        </div>
      @empty
        <p class="p-5 text-sm text-slate-500">Todavía no hay ninguna versión de este documento.</p>
      @endforelse
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-1">Nueva versión</h2>
        <p class="text-xs text-slate-400 mb-3">
          Se crea como borrador. Nada cambia para nadie hasta que la publiques.
        </p>
        <form method="POST" action="{{ route('terminos.store') }}" class="space-y-3">
          @csrf
          <input type="hidden" name="code" value="{{ $codigo }}">
          <input type="hidden" name="audience" value="creator">
          @if ($vigente)
            {{-- Partir de la vigente es el caso normal: casi nadie escribe unos
                 términos desde cero, se corrige el texto que ya hay. --}}
            <input type="hidden" name="desde_uuid" value="{{ $vigente->uuid }}">
            <p class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-600">
              Se copiará el texto de la v{{ $vigente->version }} para que lo edites.
            </p>
          @endif
          <div>
            <label for="version" class="block text-xs text-slate-500 mb-1">Etiqueta de versión</label>
            <input id="version" name="version" required maxlength="20" placeholder="2026.2"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label for="title" class="block text-xs text-slate-500 mb-1">Título</label>
            <input id="title" name="title" required maxlength="160"
                   value="{{ $vigente->title ?? 'Términos y Condiciones para Creadores' }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <button class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Crear borrador
          </button>
        </form>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5 text-xs text-slate-500 space-y-2">
        <p class="text-sm font-semibold text-slate-700">Cómo funciona</p>
        <p>Un <strong>borrador</strong> se edita cuantas veces quieras.</p>
        <p>Al <strong>publicar</strong> se congela y cierra la anterior el día antes: nunca hay dos vigentes a la vez.</p>
        <p>Al publicar declaras si el cambio es <strong>de fondo</strong> —todos vuelven a aceptar— o
          <strong>menor</strong> —una errata, un teléfono: quien ya aceptó sigue en regla—.</p>
      </div>
    </div>
  </div>
@endsection
