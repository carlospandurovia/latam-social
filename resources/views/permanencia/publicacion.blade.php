@extends('layouts.panel')
@section('titulo', 'Permanencia del post')
@section('subtitulo', $publicacion->campana.' · '.$publicacion->creador)

@section('contenido')
  <div class="space-y-5 max-w-3xl">

    @if (session('aviso'))
      <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
        {{ session('aviso') }}
      </div>
    @endif
    @if (session('exito'))
      <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
        {{ session('exito') }}
      </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs text-slate-500">Entregable #{{ $publicacion->sequence_number }} · {{ $publicacion->red ?? '—' }}</p>
          <a href="{{ $publicacion->url }}" target="_blank" rel="noopener noreferrer"
             class="text-marca-600 hover:underline break-all text-sm">{{ $publicacion->url }}</a>
        </div>
        @if ($publicacion->status === \App\Modules\Content\Services\Permanencia::CAIDA)
          <span class="text-xs px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 whitespace-nowrap">Caído</span>
        @elseif ($publicacion->status === \App\Modules\Content\Services\Permanencia::CUMPLIDA)
          <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 whitespace-nowrap">Cumplido</span>
        @else
          <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 whitespace-nowrap">Vigilado</span>
        @endif
      </div>

      <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm pt-2 border-t border-slate-100">
        <div><dt class="text-xs text-slate-500">Publicado</dt><dd class="text-slate-700">{{ \Illuminate\Support\Carbon::parse($publicacion->published_at)->format('d/m/Y') }}</dd></div>
        <div><dt class="text-xs text-slate-500">Debe seguir hasta</dt><dd class="text-slate-700">{{ $publicacion->permanence_until ?? '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Exigido</dt><dd class="text-slate-700">{{ $publicacion->permanence_days }} días</dd></div>
        <div>
          <dt class="text-xs text-slate-500">Quedan</dt>
          <dd class="{{ $diasRestantes !== null && $diasRestantes < 0 ? 'text-slate-400' : 'text-slate-700' }}">
            {{ $diasRestantes === null ? '—' : ($diasRestantes < 0 ? 'ventana cerrada' : $diasRestantes.' d') }}
          </dd>
        </div>
      </dl>

      @if ($publicacion->removed_reason)
        <p class="text-sm text-rose-800 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
          Dado por caído: {{ $publicacion->removed_reason }}
        </p>
      @endif
    </div>

    {{-- El historial. Append-only: una comprobación no se edita, se anota otra. --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <h2 class="text-sm font-medium text-slate-700 px-5 pt-4">Lo que se ha mirado</h2>
      @if ($comprobaciones->isEmpty())
        <p class="p-5 text-sm text-slate-400">Todavía no lo ha mirado nadie.</p>
      @else
        <ul class="divide-y divide-slate-100 mt-3">
          @foreach ($comprobaciones as $c)
            <li class="px-5 py-3 text-sm flex items-start gap-3">
              <span class="mt-0.5 text-xs px-2 py-0.5 rounded-full whitespace-nowrap
                    {{ (int) $c->is_live === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                {{ (int) $c->is_live === 1 ? 'estaba' : 'no estaba' }}
              </span>
              <div class="min-w-0">
                <p class="text-slate-600">
                  {{ \Illuminate\Support\Carbon::parse($c->checked_at)->format('d/m/Y H:i') }}
                  · {{ $c->source === 'probe' ? 'sonda' : ($c->miro ?? 'alguien') }}
                  @if ($c->http_status)<span class="text-slate-400">· HTTP {{ $c->http_status }}</span>@endif
                </p>
                @if ($c->notes)<p class="text-slate-500 text-xs mt-0.5">{{ $c->notes }}</p>@endif
              </div>
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    @if (!$puedeFirmar)
      <p class="text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3">
        Puede mirar esta ficha, pero no anotar ni firmar: hace falta el permiso de
        verificación, porque de esto cuelga el pago.
      </p>
    @elseif ($publicacion->status === \App\Modules\Content\Services\Permanencia::CUMPLIDA)
      <p class="text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3">
        La ventana se cerró el {{ \Illuminate\Support\Carbon::parse($publicacion->fulfilled_at)->format('d/m/Y') }}
        con el post en pie. Ya no hay nada que vigilar.
      </p>
    @else
      <form method="POST" action="{{ route('permanencia.comprobar', $publicacion->uuid) }}"
            enctype="multipart/form-data"
            class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        @csrf

        @if ($publicacion->status === \App\Modules\Content\Services\Permanencia::VIGILANDO)
          <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-slate-700">Anotar lo que ve ahora</legend>
            <p class="text-xs text-slate-500">
              Esto no cambia nada por sí solo: queda archivado y no se borra.
            </p>
            <div class="flex flex-wrap gap-3 items-end">
              <div>
                <label for="viva" class="block text-xs text-slate-500 mb-1">¿El post sigue ahí?</label>
                <select id="viva" name="viva"
                        class="text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
                  <option value="1" @selected(old('viva') === '1')>Sí, sigue publicado</option>
                  <option value="0" @selected(old('viva') === '0')>No lo encuentro</option>
                </select>
              </div>
              <div>
                <label for="http_status" class="block text-xs text-slate-500 mb-1">Estado HTTP (opcional)</label>
                <input type="number" id="http_status" name="http_status" min="100" max="599"
                       value="{{ old('http_status') }}"
                       class="w-28 text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
              </div>
              <button type="submit" name="accion" value="anotar"
                      class="text-sm px-3 py-2 rounded-lg bg-slate-700 text-white hover:bg-slate-800">
                Anotar
              </button>
            </div>
          </fieldset>
        @endif

        <div class="pt-4 border-t border-slate-100 space-y-3">
          <div>
            <label for="nota" class="block text-xs text-slate-500 mb-1">Nota (qué vio)</label>
            <textarea id="nota" name="nota" rows="2" maxlength="200"
                      class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">{{ old('nota') }}</textarea>
          </div>

          <div>
            <label for="captura" class="block text-xs text-slate-500 mb-1">Captura de pantalla</label>
            <input type="file" id="captura" name="captura" class="text-sm text-slate-600">
            <p class="text-xs text-slate-400 mt-1">
              Obligatoria para firmar. La que probó que el post existía no prueba que ya no esté.
            </p>
          </div>
        </div>

        @if ($publicacion->status === \App\Modules\Content\Services\Permanencia::VIGILANDO)
          <div class="pt-4 border-t border-slate-100 space-y-3">
            <label for="motivo" class="block text-sm font-medium text-slate-700">Dar el post por caído</label>
            <p class="text-xs text-slate-500">
              Esto <strong>para el pago</strong> de ese entregable y avisa al creador.
              No descuenta nada: la decisión de qué se le paga la toma una persona.
            </p>
            <select id="motivo" name="motivo"
                    class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
              <option value="">— elija un motivo —</option>
              @foreach ($motivos as $codigo => $texto)
                <option value="{{ $codigo }}" @selected(old('motivo') === $codigo)>{{ $texto }}</option>
              @endforeach
            </select>
            <button type="submit" name="accion" value="caida"
                    class="text-sm px-4 py-2 rounded-lg bg-rose-600 text-white hover:bg-rose-700">
              Firmar la caída
            </button>
          </div>
        @else
          <div class="pt-4 border-t border-slate-100 space-y-2">
            <p class="text-sm font-medium text-slate-700">¿Ya está repuesto, o fue un falso positivo?</p>
            <p class="text-xs text-slate-500">
              Vuelve a estar vigilado. La fecha de permanencia <strong>no cambia</strong>: si debería
              alargarse por los días que estuvo caído está abierto como <code>Q-59</code>.
            </p>
            <button type="submit" name="accion" value="reponer"
                    class="text-sm px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
              Volver a vigilarlo
            </button>
          </div>
        @endif
      </form>
    @endif

    <a href="{{ route('permanencia.bandeja') }}" class="text-sm text-slate-500 hover:underline">← Volver a la bandeja</a>
  </div>
@endsection
