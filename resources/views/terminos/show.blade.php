@extends('layouts.panel')
@section('titulo', 'v'.$version->version.' · '.$version->title)
@section('subtitulo', $esBorrador ? 'Borrador — se edita libremente' : 'Publicada — el texto ya no se toca')

@section('contenido')
  <div class="mb-5">
    <a href="{{ route('terminos.index', ['codigo' => $version->code]) }}"
       class="text-sm text-marca-700 hover:underline">← Todas las versiones</a>
  </div>

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
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-5">
      @if ($esBorrador)
        <form method="POST" action="{{ route('terminos.update', $version->uuid) }}" class="space-y-3">
          @csrf
          @method('PUT')
          <div>
            <label for="title" class="block text-xs text-slate-500 mb-1">Título</label>
            <input id="title" name="title" required maxlength="160" value="{{ old('title', $version->title) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label for="body" class="block text-xs text-slate-500 mb-1">
              Texto completo — es lo que el creador acepta
            </label>
            <textarea id="body" name="body" required rows="28"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs leading-relaxed"
            >{{ old('body', $version->body) }}</textarea>
          </div>
          <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Guardar borrador
          </button>
        </form>
      @else
        {{-- Una publicada NO se edita: hay aceptaciones que apuntan a ella con
             su huella, y cambiarle el texto por debajo dejaría esas firmas
             apuntando a algo que ya no dice lo que decía (`tg_terms_inmutable`). --}}
        <p class="mb-3 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-600">
          Publicada el {{ substr((string) $version->published_at, 0, 10) }}. El texto está congelado:
          {{ $aceptaciones }} {{ $aceptaciones === 1 ? 'persona la ha aceptado' : 'personas la han aceptado' }}
          y su huella tiene que seguir cuadrando. Para cambiarlo, crea la versión siguiente.
        </p>
        <pre class="whitespace-pre-wrap font-mono text-xs leading-relaxed text-slate-700">{{ $version->body }}</pre>
      @endif
    </div>

    <div class="space-y-5">
      @if ($esBorrador)
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-semibold mb-1">Publicar</h2>
          <p class="text-xs text-slate-400 mb-3">
            Cierra la versión anterior el día antes. A partir de ahí el texto no se toca.
          </p>
          <form method="POST" action="{{ route('terminos.publicar', $version->uuid) }}" class="space-y-3">
            @csrf
            <div>
              <label for="change_type" class="block text-xs text-slate-500 mb-1">Qué clase de cambio es</label>
              <select id="change_type" name="change_type"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($cambios as $codigo => $nombre)
                  <option value="{{ $codigo }}">{{ $nombre }}</option>
                @endforeach
              </select>
              <p class="mt-1 text-xs text-slate-400">
                Queda escrito quién lo declaró. «Menor» es para erratas y datos de contacto.
              </p>
            </div>
            <div>
              <label for="desde" class="block text-xs text-slate-500 mb-1">Desde</label>
              <input id="desde" name="desde" type="date" value="{{ now()->toDateString() }}"
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            {{-- 9.19: los plazos de Q-46. Vienen puestos con el valor de
                 partida y se cambian aquí; después de publicar son inmutables,
                 porque son parte de lo que se le comunicó a la gente. --}}
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label for="acceptance_days" class="block text-xs text-slate-500 mb-1">Días para aceptar</label>
                <input id="acceptance_days" name="acceptance_days" type="number" min="1" max="3650"
                       value="{{ $version->acceptance_days }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              </div>
              <div>
                <label for="readonly_days" class="block text-xs text-slate-500 mb-1">Después, sólo lectura</label>
                <input id="readonly_days" name="readonly_days" type="number" min="0" max="3650"
                       value="{{ $version->readonly_days }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              </div>
            </div>
            <p class="text-xs text-slate-400">
              Sólo cuentan si el cambio es <strong>de fondo</strong>. Cero días de sólo lectura
              significa que, pasado el plazo, hace falta aceptar para seguir.
            </p>
            <button class="w-full rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white hover:bg-marca-700">
              Publicar esta versión
            </button>
          </form>
        </div>
      @endif

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-1">Revisión legal</h2>
        {{-- Es un DATO sobre el texto, no una puerta. No impide publicar ni
             activar a nadie: informa de qué se está usando. --}}
        <p class="text-xs text-slate-400 mb-3">
          No bloquea nada. Sirve para saber qué texto es el que se le opone a un creador.
        </p>
        <form method="POST" action="{{ route('terminos.revision', $version->uuid) }}" class="space-y-3">
          @csrf
          <div>
            <label for="review_status" class="sr-only">Estado</label>
            <select id="review_status" name="review_status"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($revision as $codigo => $nombre)
                <option value="{{ $codigo }}" @selected($version->review_status === $codigo)>{{ $nombre }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="review_note" class="block text-xs text-slate-500 mb-1">Nota</label>
            <input id="review_note" name="review_note" maxlength="255"
                   value="{{ $version->review_note }}"
                   placeholder="Quién lo revisó, o qué falta"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
            Guardar estado
          </button>
        </form>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5 text-xs text-slate-500 space-y-1">
        <p class="text-sm font-semibold text-slate-700 mb-2">Ficha</p>
        <p>Código: <span class="font-mono">{{ $version->code }}</span></p>
        <p>Huella: <span class="font-mono">{{ substr((string) $version->content_sha256, 0, 16) }}…</span></p>
        <p>Vigente desde: {{ $version->effective_from }}</p>
        @if ($version->effective_to)<p>Cerrada el: {{ $version->effective_to }}</p>@endif
        <p>Aceptaciones: {{ $aceptaciones }}</p>
      </div>
    </div>
  </div>
@endsection
