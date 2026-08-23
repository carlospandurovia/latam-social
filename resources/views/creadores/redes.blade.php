@extends('layouts.panel')
@section('titulo', 'Redes de '.$creador->display_name)
@section('subtitulo', 'BR-CREATOR-003 · una cuenta verificada es de un solo creador')

@section('contenido')
<div class="max-w-5xl">

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

  @forelse ($cuentas as $c)
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-100">
        <div>
          <p class="font-semibold text-slate-900">
            {{ $c->red }} · {{ '@'.$c->handle }}
            @if ($c->is_primary)<span class="ml-1 text-xs text-slate-400">principal</span>@endif
          </p>
          <a href="{{ $c->profile_url }}" rel="noopener noreferrer" target="_blank"
             class="text-xs text-marca-600 hover:underline break-all">{{ $c->profile_url }}</a>
        </div>
        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium
          @class([
            'bg-emerald-50 text-emerald-700' => $c->verification_status === 'verified',
            'bg-amber-50 text-amber-800' => $c->verification_status === 'pending',
            'bg-rose-50 text-rose-700' => $c->verification_status === 'failed',
            'bg-slate-100 text-slate-600' => $c->verification_status === 'unverified',
          ])">
          {{ $c->verification_status }}
        </span>
      </div>

      <div class="px-6 py-4 border-b border-slate-100">
        @if ($c->verification_status === 'verified')
          {{-- H-05: el método y la persona son parte de la evidencia, no un adorno. --}}
          <p class="text-sm text-slate-600">
            Verificada por <strong>{{ $c->verificada_por ?: 'la plataforma' }}</strong>
            el {{ $c->verified_at }},
            método <code class="text-xs bg-slate-100 px-1 rounded">{{ $c->verification_method }}</code>.
          </p>
        @else
          @can('creator.verify')
            <form method="POST" action="{{ route('creadores.redes.verificar', [$creador->uuid, $c->id]) }}"
                  class="grid gap-3 sm:grid-cols-3 items-end">
              @csrf
              <div>
                <label class="block text-xs text-slate-600 mb-1" for="vm{{ $c->id }}">¿Cómo lo comprobaste?</label>
                <select id="vm{{ $c->id }}" name="verification_method" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  @foreach ([
                    'bio_code' => 'Código en la biografía',
                    'dm_challenge' => 'Mensaje directo desde la cuenta',
                    'post_mention' => 'Publicación mencionándonos',
                    'manual_review' => 'Revisión manual (más débil)',
                  ] as $v => $etiqueta)
                    <option value="{{ $v }}">{{ $etiqueta }}</option>
                  @endforeach
                </select>
              </div>
              <div class="sm:col-span-2">
                <label class="block text-xs text-slate-600 mb-1" for="nt{{ $c->id }}">Qué viste (opcional)</label>
                <input id="nt{{ $c->id }}" name="nota" maxlength="255"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div class="sm:col-span-3">
                <label class="flex gap-2 items-start text-xs text-slate-700 mb-3">
                  <input type="checkbox" name="confirma_comprobacion" value="1" class="mt-0.5" required>
                  <span>Confirmo que hice la comprobación yo y que la cuenta es de este creador.</span>
                </label>
                <button class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-medium hover:opacity-90">
                  Marcar como verificada
                </button>
              </div>
            </form>
          @else
            <p class="text-sm text-slate-500">Sin verificar. Hace falta permiso de verificación para comprobarla.</p>
          @endcan
        @endif
      </div>

      {{-- Histórico: nunca se sobrescribe (BR-CREATOR-005). --}}
      <div class="px-6 py-4">
        <h3 class="text-sm font-semibold text-slate-800 mb-1">Histórico de métricas</h3>
        <p class="text-xs text-slate-500 mb-3">
          Cada captura es una fila nueva. Una métrica equivocada se arregla capturando la buena encima.
        </p>

        @forelse ($historico[$c->id] ?? [] as $s)
          <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm border-b border-slate-100 last:border-0 py-2">
            <span class="tabular-nums text-slate-800 font-medium">
              {{ $s->followers !== null ? number_format($s->followers).' seguidores' : 'sin seguidores' }}
            </span>
            <span class="text-slate-500 text-xs">
              {{ $s->engagement_rate !== null ? rtrim(rtrim(number_format($s->engagement_rate, 4), '0'), '.').' % engagement' : '' }}
            </span>
            <span class="text-xs text-slate-400">{{ $s->source }} · {{ $s->captured_at }}</span>
            <span class="ml-auto inline-flex px-2 py-0.5 rounded-full text-xs font-medium
              @class([
                'bg-emerald-50 text-emerald-700' => $s->coherence_status === 'clean',
                'bg-amber-50 text-amber-800' => $s->coherence_status === 'anomalous',
                'bg-slate-100 text-slate-600' => $s->coherence_status === 'pending_review',
              ])">
              {{ $s->coherence_status === 'pending_review' ? 'sin revisar' : ($s->coherence_status === 'clean' ? 'coherente' : 'a revisar') }}
            </span>
            @if ($s->anomaly_note)
              <p class="w-full text-xs text-amber-700">{{ $s->anomaly_note }}</p>
            @endif
          </div>
        @empty
          <p class="text-sm text-slate-400">Todavía no hay ninguna captura.</p>
        @endforelse

        @can('creator.manage')
          <form method="POST" action="{{ route('creadores.redes.metrica', [$creador->uuid, $c->id]) }}"
                class="mt-4 grid gap-3 sm:grid-cols-4 items-end border-t border-slate-100 pt-4">
            @csrf
            <div>
              <label class="block text-xs text-slate-600 mb-1" for="sr{{ $c->id }}">Fuente</label>
              <select id="sr{{ $c->id }}" name="source" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                @foreach (['self_declared' => 'La declaró el creador', 'manual_review' => 'La miramos nosotros', 'api' => 'API de la red', 'import' => 'Importada'] as $v => $etiqueta)
                  <option value="{{ $v }}">{{ $etiqueta }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label class="block text-xs text-slate-600 mb-1" for="ca{{ $c->id }}">Cuándo</label>
              <input id="ca{{ $c->id }}" type="datetime-local" name="captured_at" required
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
              <label class="block text-xs text-slate-600 mb-1" for="fo{{ $c->id }}">Seguidores</label>
              <input id="fo{{ $c->id }}" type="number" min="0" name="followers"
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
              <label class="block text-xs text-slate-600 mb-1" for="er{{ $c->id }}">Engagement %</label>
              <input id="er{{ $c->id }}" type="number" step="0.0001" min="0" max="100" name="engagement_rate"
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-4">
              <button class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">
                Guardar captura
              </button>
              <span class="ml-3 text-xs text-slate-500">
                El estado de coherencia lo calcula el sistema, no se elige. Nada se rechaza: se marca.
              </span>
            </div>
          </form>
        @endcan
      </div>
    </div>
  @empty
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6">
      <p class="text-sm text-slate-400">
        Este creador no tiene ninguna cuenta registrada. Sin al menos una verificada no se puede activar.
      </p>
    </div>
  @endforelse

  @can('creator.manage')
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6">
      <h2 class="text-sm font-semibold text-slate-800">Añadir una cuenta</h2>
      <p class="text-xs text-slate-500 mt-1 mb-4">
        Nace <strong>sin verificar</strong>: que el creador diga que es suya no la hace suya.
      </p>
      <form method="POST" action="{{ route('creadores.redes.store', $creador->uuid) }}" class="grid gap-4 sm:grid-cols-3">
        @csrf
        <div>
          <label class="block text-sm text-slate-600 mb-1" for="platform_id">Red</label>
          <select id="platform_id" name="platform_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            @foreach ($redes as $r)
              <option value="{{ $r->id }}" @selected((int) old('platform_id') === (int) $r->id)>{{ $r->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1" for="handle">Identificador (sin arroba)</label>
          <input id="handle" name="handle" maxlength="120" value="{{ old('handle') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1" for="external_id">Id en la red (opcional)</label>
          <input id="external_id" name="external_id" maxlength="120" value="{{ old('external_id') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-3">
          <label class="block text-sm text-slate-600 mb-1" for="profile_url">Enlace al perfil</label>
          <input id="profile_url" name="profile_url" maxlength="500" value="{{ old('profile_url') }}"
                 placeholder="https://" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-3">
          <button class="px-5 py-2.5 rounded-xl bg-marca-500 text-white text-sm font-medium hover:opacity-90">
            Registrar cuenta
          </button>
        </div>
      </form>
    </div>
  @endcan
</div>
@endsection
