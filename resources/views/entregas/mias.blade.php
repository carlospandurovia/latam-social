{{-- El portal del creador. Extiende `layouts.acceso` y NO `layouts.panel`: ese
     trae el menú del back-office, y aunque todas sus secciones estén protegidas
     por permiso, enseñárselas sería un mapa de lo que hay dentro.

     Es la misma decisión que `panel/espera.blade.php` en 5.9. --}}
@extends('layouts.acceso')
@section('titulo', 'Mis entregas')

@section('contenido')
  {{-- 9.8: el creador entra aqui a trabajar y se pregunta por su dinero en la
       misma visita. Un enlace y no un menu: el portal tiene dos pantallas. --}}
  <div class="mb-5 text-right">
    <a href="{{ route('ingresos.mios') }}"
       class="text-sm text-marca-600 hover:text-marca-700 underline underline-offset-2">
      Ver mis ingresos
    </a>
  </div>

  @if (session('exito'))
    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif

  @if (session('aviso'))
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      {{ session('aviso') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ $errors->first() }}
    </div>
  @endif

  <h1 class="text-xl font-semibold text-slate-900 mb-1">Mis entregas</h1>

  @if ($participaciones->isEmpty())
    <p class="text-sm text-slate-600">
      Ahora mismo no tienes nada pendiente. Cuando aceptes una campaña aparecerá aquí
      lo que hay que entregar y para cuándo.
    </p>
  @else
    <p class="text-sm text-slate-500 mb-6">Lo que has aceptado y lo que falta por mandar.</p>

    <div class="space-y-6">
      @foreach ($participaciones as $p)
        <section>
          <h2 class="text-sm font-semibold text-slate-800">{{ $p->campana }}</h2>
          <p class="text-xs text-slate-500 mb-3">
            @if ($p->marca) {{ $p->marca }} · @endif
            {{ number_format((float) $p->agreed_amount, 2) }} {{ $p->currency_code }}
            · pago a {{ $p->payment_term_days_snapshot }} días
          </p>

          @if ($p->entregables->isEmpty())
            <p class="text-sm text-slate-400">
              Todavía no hay nada asignado. El equipo lo está preparando.
            </p>
          @endif

          <div class="space-y-3">
            @foreach ($p->entregables as $e)
              @php
                $vencido = $e->submitted_at === null
                    && \Illuminate\Support\Carbon::parse($e->due_on)->isBefore(now()->startOfDay());
              @endphp
              <div class="rounded-xl border p-4
                          {{ $e->submitted_at !== null
                              ? 'border-emerald-200 bg-emerald-50/40'
                              : ($vencido ? 'border-rose-200 bg-rose-50/40' : 'border-slate-200') }}">
                <div class="flex items-baseline justify-between gap-3">
                  <p class="text-sm font-medium text-slate-800">
                    {{ $e->red ? $e->red.' · ' : '' }}{{ $e->formato }}
                    @if ($e->quantity > 1)
                      <span class="text-slate-400">#{{ $e->sequence_number }}</span>
                    @endif
                  </p>
                  <p class="text-xs {{ $vencido ? 'text-rose-700 font-medium' : 'text-slate-500' }}">
                    @if ($e->submitted_at !== null)
                      entregado
                    @else
                      para el {{ \Illuminate\Support\Carbon::parse($e->due_on)->format('d/m/Y') }}
                    @endif
                  </p>
                </div>

                @if ($e->notes)
                  <p class="mt-1 text-xs text-slate-600">{{ $e->notes }}</p>
                @endif

                {{-- Las etiquetas que el brief exige, ARRIBA y antes del
                     formulario. Enterarse de que faltan al pulsar «enviar» es
                     una vuelta que se ahorra diciéndolo antes. --}}
                @if ($e->hashtags || $e->mentions)
                  <p class="mt-2 text-xs text-slate-600">
                    Tu texto tiene que incluir:
                    <span class="font-medium text-slate-800">{{ trim($e->hashtags.' '.$e->mentions) }}</span>
                  </p>
                @endif

                @if (in_array($e->status, \App\Modules\Content\Services\Entregables::ABIERTOS, true))
                  <form method="POST"
                        action="{{ route('entregas.entregar', $e->uuid) }}"
                        enctype="multipart/form-data"
                        class="mt-3 space-y-3">
                    @csrf
                    <div>
                      <label for="url-{{ $e->uuid }}" class="block text-xs font-medium text-slate-700 mb-1">
                        Enlace a tu contenido
                      </label>
                      <input id="url-{{ $e->uuid }}" name="external_url" type="url"
                             inputmode="url" placeholder="https://…"
                             value="{{ old('external_url') }}"
                             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                                    focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
                    </div>
                    <div>
                      <label for="cap-{{ $e->uuid }}" class="block text-xs font-medium text-slate-700 mb-1">
                        Texto de la publicación
                      </label>
                      <textarea id="cap-{{ $e->uuid }}" name="caption" rows="3"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                                       focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">{{ old('caption') }}</textarea>
                    </div>
                    <div>
                      <label for="file-{{ $e->uuid }}" class="block text-xs font-medium text-slate-700 mb-1">
                        Imagen de referencia <span class="font-normal text-slate-400">(opcional)</span>
                      </label>
                      {{-- `accept` con `capture` para que en el móvil abra la
                           cámara o el carrete directamente. --}}
                      <input id="file-{{ $e->uuid }}" name="archivo" type="file"
                             accept="image/*,application/pdf"
                             class="w-full text-xs text-slate-600">
                    </div>
                    <div>
                      <label for="nota-{{ $e->uuid }}" class="block text-xs font-medium text-slate-700 mb-1">
                        ¿Algo que contarnos? <span class="font-normal text-slate-400">(opcional)</span>
                      </label>
                      <input id="nota-{{ $e->uuid }}" name="creator_notes" maxlength="500"
                             value="{{ old('creator_notes') }}"
                             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <button class="w-full rounded-lg bg-marca-500 px-4 py-2.5 text-sm font-semibold text-white
                                   hover:bg-marca-600 transition">
                      {{ $e->submitted_at !== null ? 'Mandar una versión nueva' : 'Entregar' }}
                    </button>
                  </form>
                @endif

                {{-- 8.6: aprobado, lo que toca es publicarlo y pegar el enlace.
                     El formulario aparece SOLO cuando está aprobado: enseñarlo
                     antes invita a publicar algo que el cliente todavía no ha
                     visto, y `tg_pub_version_aprobada` lo rechazaría igual. --}}
                @if ($e->status === 'approved')
                  <div class="mt-3 rounded-lg bg-emerald-50 border border-emerald-200 p-3">
                    <p class="text-xs font-medium text-emerald-900">
                      Aprobado. Publícalo y pega aquí el enlace.
                    </p>
                    <form method="POST" action="{{ route('entregas.publicar', $e->uuid) }}"
                          class="mt-2 space-y-2">
                      @csrf
                      <input name="url" type="url" inputmode="url" required
                             placeholder="https://instagram.com/p/…"
                             value="{{ old('url') }}"
                             class="w-full rounded-lg border border-emerald-300 px-3 py-2 text-sm
                                    focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 focus:outline-none">
                      <p class="text-xs text-slate-500">
                        Pega el enlace público del post, tal cual. Da igual si lleva parámetros al final.
                      </p>
                      <button class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white
                                     hover:bg-emerald-700 transition">
                        Ya está publicado
                      </button>
                    </form>
                  </div>
                @elseif ($e->status === 'published')
                  <p class="mt-3 text-xs text-slate-500">
                    Publicado y registrado. El equipo lo comprueba y ahí termina tu parte.
                  </p>
                @endif
              </div>
            @endforeach
          </div>
        </section>
      @endforeach
    </div>
  @endif

  <form method="POST" action="{{ route('salir') }}" class="mt-8">
    @csrf
    <button class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50">
      Cerrar sesión
    </button>
  </form>
@endsection
