@extends('layouts.panel')
@section('titulo', 'Entregables · '.$campana->name)
@section('subtitulo', $campana->code.($campana->marca ? ' · '.$campana->marca : ''))

@section('contenido')
  <div class="space-y-5">

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="flex items-baseline justify-between">
        <h2 class="text-sm font-medium text-slate-700">Cómo va el trabajo</h2>
        <a href="{{ route('campanas.seguimiento', $campana->uuid) }}"
           class="text-xs text-marca-600 hover:underline">Ver el seguimiento</a>
      </div>

      <div class="mt-3 flex gap-6 text-sm">
        <div>
          <p class="text-2xl font-semibold tabular-nums text-slate-900">
            {{ $avance['enviados'] }}<span class="text-slate-300">/{{ $avance['total'] }}</span>
          </p>
          <p class="text-xs text-slate-500">entregados</p>
        </div>
        <div>
          {{-- Vencido es «sin entregar y con la fecha pasada». Uno entregado
               tarde NO cuenta: ya llegó, y contarlo dejaría la cifra en rojo
               para siempre. --}}
          <p class="text-2xl font-semibold tabular-nums {{ $avance['vencidos'] > 0 ? 'text-rose-600' : 'text-slate-300' }}">
            {{ $avance['vencidos'] }}
          </p>
          <p class="text-xs text-slate-500">vencidos sin entregar</p>
        </div>
      </div>
    </div>

    {{-- 8.3: las rondas cobradas de más. No hay tabla de cargos al cliente
         todavía (`Q-57`), así que esta lista es el registro — y va antes de las
         piezas porque es lo que alguien tiene que ver antes de facturar. --}}
    @if ($cargos->isNotEmpty())
      <div class="bg-white rounded-xl border border-amber-300 p-5">
        <h2 class="text-sm font-medium text-amber-900">
          {{ $cargos->count() }} {{ \Illuminate\Support\Str::plural('ronda', $cargos->count()) }}
          de corrección por encima de las incluidas, para cobrar
        </h2>
        <p class="mt-1 text-xs text-amber-800">
          Autorizadas y pendientes de facturar. No están en ningún otro sitio todavía.
        </p>
        <ul class="mt-3 space-y-2 text-sm">
          @foreach ($cargos as $c)
            <li class="flex flex-wrap gap-x-2 text-slate-700">
              <span class="text-slate-400 tabular-nums">
                {{ \Illuminate\Support\Carbon::parse($c->reviewed_at)->format('d/m/Y') }}
              </span>
              <span>{{ $c->creador }} · pieza #{{ $c->sequence_number }}</span>
              @if ($c->autorizador)<span class="text-slate-400">· autorizó {{ $c->autorizador }}</span>@endif
            </li>
          @endforeach
        </ul>
      </div>
    @endif

    @if ($participaciones->isEmpty())
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <p class="text-sm text-slate-400">Todavía no ha aceptado nadie esta campaña.</p>
      </div>
    @endif

    @foreach ($participaciones as $p)
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-baseline justify-between mb-3">
          <h2 class="text-sm font-medium text-slate-700">
            <a href="{{ route('creadores.show', $p->creador_uuid) }}"
               class="text-marca-600 hover:underline">{{ $p->display_name }}</a>
          </h2>
          <span class="text-xs text-slate-400">{{ $p->status }}</span>
        </div>

        @if ($p->entregables->isEmpty())
          {{-- Aceptó y no tiene nada asignado. Se AVISA: el evento que los crea
               puede haber fallado, y un creador aceptado sin trabajo es algo que
               nadie descubre hasta que la campaña termina sin su contenido. --}}
          <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm text-amber-900">
              Este creador aceptó y no tiene ningún entregable asignado.
            </p>
            <p class="mt-0.5 text-xs text-amber-800">
              Se crean solos al aceptar; si no están, algo falló. Puede crearlos ahora
              desde el brief de su mercado.
            </p>
            @can('content.deliverable.view')
              <form method="POST"
                    action="{{ route('campanas.entregables.generar', [$campana->uuid, $p->id]) }}"
                    class="mt-2">
                @csrf
                <button class="text-xs font-medium text-amber-800 hover:underline">
                  Crear sus entregables
                </button>
              </form>
            @endcan
          </div>
        @else
          <table class="w-full text-sm">
            <thead class="text-slate-500 text-xs">
              <tr>
                <th class="text-left font-medium pb-2">Qué</th>
                <th class="text-left font-medium pb-2">Para cuándo</th>
                <th class="text-left font-medium pb-2">Estado</th>
                <th class="text-left font-medium pb-2">Última versión</th>
                <th class="text-left font-medium pb-2">Publicado</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($p->entregables as $e)
                @php
                  $vencido = $e->submitted_at === null
                      && \Illuminate\Support\Carbon::parse($e->due_on)->isBefore(now()->startOfDay());
                  $ultima = $e->versiones->first();
                @endphp
                <tr class="align-top">
                  <td class="py-2">
                    {{ $e->red ? $e->red.' · ' : '' }}{{ $e->formato }}
                    @if ($e->quantity > 1)
                      <span class="text-slate-400">#{{ $e->sequence_number }}</span>
                    @endif
                  </td>
                  <td class="py-2 {{ $vencido ? 'text-rose-600 font-medium' : 'text-slate-500' }}">
                    {{ \Illuminate\Support\Carbon::parse($e->due_on)->format('d/m/Y') }}
                  </td>
                  <td class="py-2">
                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $e->status }}</span>
                  </td>
                  <td class="py-2">
                    @if ($ultima === null)
                      <span class="text-slate-300">sin entregar</span>
                    @else
                      <div class="text-xs">
                        <p class="text-slate-500">
                          v{{ $ultima->version_number }} ·
                          {{ \Illuminate\Support\Carbon::parse($ultima->submitted_at)->format('d/m H:i') }}
                          @if ($ultima->autor) · {{ $ultima->autor }} @endif
                        </p>
                        @if ($ultima->external_url)
                          {{-- `rel="noopener noreferrer"`: el enlace lo escribió
                               alguien de fuera, y no le regalamos ni la
                               referencia ni el acceso a esta ventana. --}}
                          <a href="{{ $ultima->external_url }}" target="_blank" rel="noopener noreferrer"
                             class="text-marca-600 hover:underline break-all">{{ $ultima->external_url }}</a>
                        @endif
                        @if ($ultima->archivo)
                          <p class="text-slate-500">adjunto: {{ $ultima->archivo }}</p>
                        @endif
                        @if ($ultima->caption)
                          <p class="mt-1 text-slate-600 whitespace-pre-line">{{ $ultima->caption }}</p>
                        @endif
                        @if ($ultima->creator_notes)
                          <p class="mt-1 text-slate-400">nota: {{ $ultima->creator_notes }}</p>
                        @endif
                        @if ($e->versiones->count() > 1)
                          <p class="mt-1 text-slate-400">{{ $e->versiones->count() }} versiones</p>
                        @endif
                      </div>
                    @endif
                  </td>
                  {{-- 8.6: el post. Se registra desde aquí sólo cuando el
                       entregable está aprobado — es el caso real de «el enlace
                       llegó por WhatsApp y el creador no entra a pegarlo». --}}
                  <td class="py-2">
                    @forelse ($e->publicaciones as $pub)
                      <div class="text-xs">
                        <a href="{{ $pub->url }}" target="_blank" rel="noopener noreferrer"
                           class="text-marca-600 hover:underline break-all">{{ $pub->red ?? 'enlace' }}</a>
                        <p class="text-slate-400">
                          {{ \Illuminate\Support\Carbon::parse($pub->published_at)->format('d/m/Y') }}
                          · v{{ $pub->version_number }} · {{ $pub->status }}
                        </p>
                      </div>
                    @empty
                      @if ($e->status === 'approved' && $puedePublicar)
                        <form method="POST"
                              action="{{ route('campanas.entregables.publicar', [$campana->uuid, $e->id]) }}"
                              class="flex gap-1">
                          @csrf
                          <input name="url" type="url" required placeholder="https://…"
                                 class="w-40 rounded border border-slate-300 px-2 py-1 text-xs">
                          <button class="rounded bg-slate-700 px-2 py-1 text-xs text-white hover:bg-slate-800">
                            Registrar
                          </button>
                        </form>
                      @else
                        <span class="text-slate-300">—</span>
                      @endif
                    @endforelse
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    @endforeach
  </div>
@endsection
