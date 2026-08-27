@extends('layouts.panel')
@section('titulo', 'Comprobar · '.$publicacion->creador)
@section('subtitulo', $publicacion->campana.' · pieza #'.$publicacion->sequence_number)

@section('contenido')
  <div class="space-y-5 max-w-3xl">

    @if (session('aviso'))
      <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
        {{ session('aviso') }}
      </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700 mb-2">El post que hay que comprobar</h2>
      <a href="{{ $publicacion->url }}" target="_blank" rel="noopener noreferrer"
         class="text-marca-600 hover:underline break-all">{{ $publicacion->url }}</a>
      <p class="mt-2 text-xs text-slate-500">
        {{ $publicacion->red ?? 'red desconocida' }} ·
        publicado el {{ \Illuminate\Support\Carbon::parse($publicacion->published_at)->format('d/m/Y') }} ·
        permanencia mínima {{ $publicacion->permanence_days }} días
      </p>
    </div>

    {{-- Se dice ANTES del formulario por qué esto no se comprueba solo. Es la
         primera pregunta que se hace cualquiera al ver una cola manual. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700">Cómo se comprueba</h2>
      <p class="mt-1 text-sm text-slate-600">
        Abre el post <strong>sin sesión iniciada</strong> —una ventana privada— y comprueba que
        se ve y que es el contenido aprobado. Sube la captura: eso es lo que queda archivado y
        <strong>no se borra nunca</strong>.
      </p>
      <p class="mt-2 text-xs text-slate-500">
        No se comprueba automáticamente porque no se puede: Instagram y TikTok devuelven lo mismo
        para un post vivo que para un bloqueo. Con las APIs oficiales (F12) sí se podrá.
      </p>
    </div>

    <form method="POST" action="{{ route('verificacion.verificar', $publicacion->uuid) }}"
          enctype="multipart/form-data"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @csrf

      <div>
        <label for="captura" class="block text-sm text-slate-600 mb-1">
          Captura del post <span class="text-slate-400">(obligatoria para dar por bueno)</span>
        </label>
        <input id="captura" name="captura" type="file" accept="image/*,application/pdf"
               class="w-full text-sm text-slate-600">
        @error('captura')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
      </div>

      <div>
        <label for="motivo" class="block text-sm text-slate-600 mb-1">
          Si no aparece, por qué <span class="text-slate-400">(sólo al rechazar)</span>
        </label>
        <select id="motivo" name="motivo"
                class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          <option value="">— elija —</option>
          @foreach ($motivos as $codigo => $texto)
            <option value="{{ $codigo }}" @selected(old('motivo') === $codigo)>{{ $texto }}</option>
          @endforeach
        </select>
        @error('motivo')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="nota" class="block text-sm text-slate-600 mb-1">
            Detalle <span class="text-slate-400">(opcional)</span>
          </label>
          <input id="nota" name="nota" maxlength="200" value="{{ old('nota') }}"
                 class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
        </div>
        <div>
          <label for="http_status" class="block text-sm text-slate-600 mb-1">
            Estado HTTP <span class="text-slate-400">(opcional, no decide)</span>
          </label>
          <input id="http_status" name="http_status" type="number" min="100" max="599"
                 value="{{ old('http_status') }}"
                 class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          @error('http_status')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
      </div>

      <div class="flex gap-3 pt-1">
        @if ($puedeVerificar)
          <button type="submit" name="veredicto" value="verified"
                  class="text-sm px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
            El post está ahí
          </button>
          <button type="submit" name="veredicto" value="rejected"
                  class="text-sm px-4 py-2 rounded-lg border border-amber-300 text-amber-800 hover:bg-amber-50">
            No aparece
          </button>
        @else
          <p class="text-xs text-slate-500">
            Verificar necesita el permiso <code>content.verify</code>: de esto cuelga el pago.
          </p>
        @endif
        <a href="{{ route('verificacion.cola') }}"
           class="text-sm px-4 py-2 text-slate-500 hover:underline">Volver a la cola</a>
      </div>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700 mb-3">Lo archivado</h2>
      @if ($evidencias->isEmpty())
        <p class="text-sm text-slate-400">Todavía no hay nada archivado de este post.</p>
      @else
        <ul class="space-y-2 text-sm">
          @foreach ($evidencias as $e)
            <li class="text-slate-600">
              <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">{{ $e->evidence_type }}</span>
              {{ \Illuminate\Support\Carbon::parse($e->captured_at)->format('d/m/Y H:i') }}
              @if ($e->archivo) · {{ $e->archivo }} @endif
              @if ($e->http_status) · HTTP {{ $e->http_status }} @endif
              @if ($e->capturado_por) · {{ $e->capturado_por }} @endif
            </li>
          @endforeach
        </ul>
        <p class="mt-3 text-xs text-slate-400">
          Nada de esto se borra: <code>publication_evidence</code> lleva <code>no_delete</code> desde 3.12.
        </p>
      @endif
    </div>
  </div>
@endsection
