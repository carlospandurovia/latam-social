@extends('layouts.panel')
@section('titulo', 'Activación de '.$creador->display_name)
@section('subtitulo', 'BR-CREATOR-006 · completitud operativa mínima')

@section('contenido')
<div class="max-w-4xl">

  <a href="{{ route('creadores.show', $creador->uuid) }}" class="text-sm text-slate-500 hover:text-slate-800">← Volver a la ficha</a>

  @if (session('exito'))
    <div class="mt-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">{{ session('exito') }}</div>
  @endif
  @if (session('aviso'))
    <div class="mt-4 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 text-sm">{{ session('aviso') }}</div>
  @endif
  @if ($errors->any())
    <div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm">
      <ul class="list-disc list-inside space-y-0.5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  {{-- La lista, que es el contenido de verdad de esta pantalla. Un "no cumple"
       sin decir QUÉ falta obliga al operador a adivinar. --}}
  <div class="mt-5 bg-white rounded-2xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-4">
      <div>
        <h2 class="text-sm font-semibold text-slate-800">Qué hace falta para activar</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Estado actual: <span class="font-medium text-slate-700">{{ $creador->status }}</span>
        </p>
      </div>
      <span class="text-xs px-2.5 py-1 rounded-full font-medium
        {{ $completo ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">
        {{ collect($requisitos)->where('cumple', true)->count() }} de {{ count($requisitos) }}
      </span>
    </div>

    <ul class="divide-y divide-slate-100">
      @foreach ($requisitos as $r)
        <li class="px-6 py-4 flex gap-4">
          <span class="shrink-0 mt-0.5 w-5 h-5 rounded-full grid place-items-center text-xs font-bold
            {{ $r->cumple ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
            {{ $r->cumple ? '✓' : '!' }}
          </span>
          <div class="min-w-0">
            <p class="text-sm font-medium text-slate-800">{{ $r->titulo }}</p>
            <p class="text-sm {{ $r->cumple ? 'text-slate-500' : 'text-rose-700' }}">{{ $r->detalle }}</p>
            <p class="text-xs text-slate-400 mt-0.5">
              {{ $r->regla }}
              {{-- Cuando falta algo que SE PUEDE resolver desde el panel, se
                   enlaza. Un requisito sin camino a resolverlo es la razón de
                   que la 3.4 dejara a todo el mundo en `pending`. --}}
              @if (!$r->cumple && $r->codigo === 'fiscal')
                @can('creator.view_sensitive')
                  <span class="text-slate-300">·</span>
                  <a href="{{ route('creadores.fiscal', $creador->uuid) }}" class="text-marca-600 hover:underline">resolver</a>
                @endcan
              @elseif (!$r->cumple && $r->codigo === 'red_social')
                <span class="text-slate-300">·</span>
                <a href="{{ route('creadores.redes', $creador->uuid) }}" class="text-marca-600 hover:underline">resolver</a>
              @endif
            </p>
          </div>
        </li>
      @endforeach
    </ul>
  </div>

  {{-- El botón. Deshabilitado es cortesía; la puerta está en el servidor. --}}
  @can('creator.activate')
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6">
      @if ($creador->status !== 'pending')
        <p class="text-sm text-slate-500">
          Esta pantalla activa creadores en «pendiente». Este está en
          «{{ $creador->status }}», así que aquí no hay nada que hacer.
        </p>
      @else
        <form method="POST" action="{{ route('creadores.activar', $creador->uuid) }}">
          @csrf
          <label class="block text-sm text-slate-600 mb-1" for="motivo">Nota para el histórico (opcional)</label>
          <input id="motivo" name="motivo" maxlength="255" value="{{ old('motivo') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm mb-4"
                 placeholder="Queda en status_transitions junto a la fecha y el usuario.">

          <button type="submit"
                  @disabled(! $completo)
                  class="px-5 py-2.5 rounded-xl text-sm font-medium text-white
                         {{ $completo ? 'bg-emerald-600 hover:opacity-90' : 'bg-slate-300 cursor-not-allowed' }}">
            Activar creador
          </button>
          @unless ($completo)
            <span class="ml-3 text-xs text-slate-500">Faltan requisitos de la lista de arriba.</span>
          @endunless
        </form>
      @endif
    </div>
  @endcan

  {{-- ---------------------------------------------------- captura de evidencia --}}
  @can('creator.verify')
    <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-5">

      {{-- Identidad: marca del revisor + documento adjunto (DEC-058) --}}
      <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-sm font-semibold text-slate-800">Verificar identidad</h2>
        <p class="text-xs text-slate-500 mt-1 mb-4">
          Coteja el documento contra los datos de la ficha y adjúntalo. Quedan
          registrados quién, cuándo y con qué documento: las tres cosas o ninguna.
        </p>

        @if ($creador->identity_verified_at)
          <p class="text-sm text-emerald-700 mb-3">
            Ya verificada el {{ $creador->identity_verified_at }}.
            Volver a enviarla sustituye el documento y queda en la bitácora.
          </p>
        @endif

        <form method="POST" action="{{ route('creadores.identidad', $creador->uuid) }}" enctype="multipart/form-data">
          @csrf
          <label class="block text-sm text-slate-600 mb-1" for="documento">Documento (PDF o imagen)</label>
          <input id="documento" type="file" name="documento" required
                 accept=".pdf,.jpg,.jpeg,.png,.webp"
                 class="w-full text-sm mb-3">

          <label class="block text-sm text-slate-600 mb-1" for="nota">Qué cotejaste (opcional)</label>
          <input id="nota" name="nota" maxlength="255" value="{{ old('nota') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm mb-3">

          <label class="flex gap-2 items-start text-sm text-slate-700 mb-4">
            <input type="checkbox" name="confirma_cotejo" value="1" class="mt-0.5" required>
            <span>Confirmo que cotejé este documento contra los datos del creador.</span>
          </label>

          <button type="submit" class="px-4 py-2 rounded-xl bg-marca-500 text-white text-sm font-medium hover:opacity-90">
            Registrar verificación
          </button>
        </form>
      </div>

      {{-- Términos: tabla versionada, el revisor registra la aceptación (DEC-059) --}}
      <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-sm font-semibold text-slate-800">Registrar aceptación de términos</h2>

        @if ($terminos === null)
          <p class="text-sm text-rose-700 mt-2">
            No hay ninguna versión vigente publicada. Publícala con
            <code class="text-xs bg-slate-100 px-1 rounded">php artisan terminos:publicar</code>
            antes de registrar aceptaciones. No se puede aceptar un documento que no existe.
          </p>
        @else
          <p class="text-xs text-slate-500 mt-1 mb-4">
            Vigente: <span class="font-medium text-slate-700">{{ $terminos->title }} · v{{ $terminos->version }}</span>
            (desde {{ $terminos->effective_from }}). Mientras no exista el portal del creador,
            la aceptación llega por correo o WhatsApp y tú la archivas aquí.
          </p>

          <form method="POST" action="{{ route('creadores.terminos', $creador->uuid) }}" enctype="multipart/form-data">
            @csrf
            <label class="block text-sm text-slate-600 mb-1" for="channel">Por dónde la dio</label>
            <select id="channel" name="channel" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm mb-3">
              @foreach (['email' => 'Correo', 'whatsapp' => 'WhatsApp', 'paper' => 'Papel firmado', 'phone' => 'Teléfono'] as $v => $etiqueta)
                <option value="{{ $v }}" @selected(old('channel') === $v)>{{ $etiqueta }}</option>
              @endforeach
            </select>

            <label class="block text-sm text-slate-600 mb-1" for="accepted_at">Cuándo la dio</label>
            <input id="accepted_at" type="datetime-local" name="accepted_at" required
                   value="{{ old('accepted_at') }}"
                   class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm mb-3">

            <label class="block text-sm text-slate-600 mb-1" for="evidencia">Evidencia (correo, captura o documento)</label>
            <input id="evidencia" type="file" name="evidencia" required
                   accept=".pdf,.jpg,.jpeg,.png,.webp"
                   class="w-full text-sm mb-3">

            <label class="block text-sm text-slate-600 mb-1" for="evidence_note">Nota (opcional)</label>
            <input id="evidence_note" name="evidence_note" maxlength="255" value="{{ old('evidence_note') }}"
                   class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm mb-3">

            <label class="flex gap-2 items-start text-sm text-slate-700 mb-4">
              <input type="checkbox" name="confirma_revision" value="1" class="mt-0.5" required>
              <span>Confirmo que la evidencia adjunta es de este creador y de esta versión.</span>
            </label>

            <button type="submit" class="px-4 py-2 rounded-xl bg-marca-500 text-white text-sm font-medium hover:opacity-90">
              Registrar aceptación
            </button>
          </form>
        @endif
      </div>
    </div>
  @endcan

  {{-- El histórico. No es la bitácora: esto es por dónde pasó el creador. --}}
  <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6">
    <h2 class="text-sm font-semibold text-slate-800 mb-1">Histórico de estados</h2>
    <p class="text-xs text-slate-500 mb-4">
      De aquí salen los tiempos del embudo. La columna <code class="text-xs">status</code> es solo la última fila de esta lista.
    </p>
    @forelse ($historico as $t)
      <div class="flex items-baseline justify-between gap-4 text-sm border-b border-slate-100 last:border-0 py-2">
        <span class="text-slate-700">{{ $t->from_status ?? '—' }} → <strong>{{ $t->to_status }}</strong></span>
        <span class="text-slate-500 text-xs flex-1">{{ $t->reason }}</span>
        <span class="tabular-nums text-xs text-slate-400">{{ $t->occurred_at }}</span>
      </div>
    @empty
      <p class="text-sm text-slate-400">Todavía no ha cambiado de estado.</p>
    @endforelse
  </div>
</div>
@endsection
