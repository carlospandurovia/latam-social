@extends('layouts.panel')
@section('titulo', 'Seguimiento · '.$campana->name)
@section('subtitulo', $campana->code.' · '.($campana->marca ?? $campana->cliente ?? ''))

@section('contenido')
  <div class="space-y-5">

    {{-- LO QUE HAY QUE ATENDER HOY.

         Arriba del todo y antes que ningún número. Una alerta que hay que ir a
         buscar debajo de tres tablas es una alerta que nadie ve. --}}
    @if ($alertas !== [])
      <div class="space-y-2">
        @foreach ($alertas as $a)
          <div class="rounded-xl border px-4 py-3
                      {{ $a['nivel'] === 'rojo'
                          ? 'border-rose-200 bg-rose-50'
                          : 'border-amber-200 bg-amber-50' }}">
            <p class="text-sm font-medium {{ $a['nivel'] === 'rojo' ? 'text-rose-900' : 'text-amber-900' }}">
              {{ $a['titulo'] }}
            </p>
            {{-- Cada alerta dice QUÉ HACER. Una que sólo dice que hay un
                 problema obliga a buscarlo, y entonces deja de leerse. --}}
            <p class="mt-0.5 text-xs {{ $a['nivel'] === 'rojo' ? 'text-rose-700' : 'text-amber-800' }}">
              {{ $a['detalle'] }}
            </p>
          </div>
        @endforeach
      </div>
    @else
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
        <p class="text-sm text-emerald-800">Nada que atender ahora mismo en esta campaña.</p>
      </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">

      {{-- EL EMBUDO --}}
      <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-baseline justify-between mb-4">
          <h2 class="text-sm font-medium text-slate-700">Por dónde va cada uno</h2>
          <span class="text-xs text-slate-400">
            {{ $embudo['vivos'] }} en juego · {{ $embudo['total'] }} en total
          </span>
        </div>

        @if ($embudo['total'] === 0)
          <p class="text-sm text-slate-400">
            Todavía no hay nadie en esta campaña.
            <a href="{{ route('campanas.candidatos', $campana->uuid) }}"
               class="text-marca-600 hover:underline">Buscar creadores</a>.
          </p>
        @else
          {{-- Se pintan TODOS los pasos, también los que están a cero: un embudo
               que esconde los ceros enseña dónde llegó la gente, no dónde se
               atasca. --}}
          <ul class="space-y-1.5">
            @foreach ($nombresEmbudo as $codigo => $nombre)
              @php $n = $embudo['pasos'][$codigo]; @endphp
              <li class="flex items-center gap-3">
                <span class="w-32 shrink-0 text-xs {{ $n > 0 ? 'text-slate-600' : 'text-slate-300' }}">
                  {{ $nombre }}
                </span>
                <div class="flex-1 h-5 rounded bg-slate-100 overflow-hidden">
                  @if ($n > 0)
                    <div class="h-full bg-marca-400"
                         style="width: {{ max(4, round($n / max(1, $embudo['vivos']) * 100)) }}%"></div>
                  @endif
                </div>
                <span class="w-8 text-right text-xs tabular-nums
                             {{ $n > 0 ? 'font-medium text-slate-800' : 'text-slate-300' }}">{{ $n }}</span>
              </li>
            @endforeach
          </ul>

          {{-- Las salidas van aparte, y en gris: no son un paso del embudo, son
               gente que ya no está. Sumarlas al embudo haría que el total
               pareciera progreso. --}}
          @if (array_sum($embudo['salidas']) > 0)
            <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap gap-x-4 gap-y-1">
              @foreach ($nombresSalidas as $codigo => $nombre)
                @if ($embudo['salidas'][$codigo] > 0)
                  <span class="text-xs text-slate-400">
                    {{ $nombre }}: <span class="tabular-nums">{{ $embudo['salidas'][$codigo] }}</span>
                  </span>
                @endif
              @endforeach
            </div>
          @endif
        @endif
      </div>

      {{-- EL DINERO --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">El dinero</h2>

        <dl class="space-y-2 text-sm">
          <div class="flex justify-between">
            <dt class="text-slate-500">Presupuesto</dt>
            <dd class="tabular-nums">{{ number_format($dinero['presupuesto'], 2) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-slate-500">Comprometido</dt>
            <dd class="tabular-nums">{{ number_format($dinero['comprometido'], 2) }}</dd>
          </div>
          <div class="flex justify-between pt-2 border-t border-slate-100">
            <dt class="text-slate-600 font-medium">Disponible</dt>
            {{-- Se enseña NEGATIVO cuando lo es. Redondearlo a cero escondería
                 justo el caso que hay que ver. --}}
            <dd class="tabular-nums font-medium
                       {{ $dinero['disponible'] < 0 ? 'text-rose-600' : 'text-slate-900' }}">
              {{ number_format($dinero['disponible'], 2) }}
            </dd>
          </div>
        </dl>

        @if ($dinero['autorizado'])
          <p class="mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-900">
            Sobrecosto autorizado: {{ $dinero['motivo'] }}
          </p>
        @endif

        {{-- EL MARGEN.

             `$margen` es `null` cuando quien mira no puede verlo, y entonces el
             dato ni siquiera ha llegado hasta aquí: no se calcula y se esconde,
             no se calcula (`BR-SEC-001`, 🔴). --}}
        @if ($margen !== null)
          <div class="mt-4 pt-3 border-t border-slate-100">
            <p class="text-xs text-slate-400 mb-2">Sólo para quien puede ver el margen</p>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between">
                <dt class="text-slate-500">Ingreso</dt>
                <dd class="tabular-nums">{{ number_format($margen['ingreso'], 2) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-slate-600 font-medium">Margen</dt>
                <dd class="tabular-nums font-medium">
                  {{ number_format($margen['margen'], 2) }}
                  @if ($margen['porcentaje'] !== null)
                    <span class="text-xs text-slate-400">({{ number_format($margen['porcentaje'], 1) }}%)</span>
                  @endif
                </dd>
              </div>
            </dl>
          </div>
        @endif
      </div>
    </div>

    {{-- LOS CUPOS --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="flex items-baseline justify-between mb-3">
        <h2 class="text-sm font-medium text-slate-700">Cupo por mercado</h2>
        <span class="text-xs text-slate-400">
          @if ($dias === null)
            sin fecha de inicio
          @elseif ($dias < 0)
            empezó hace {{ abs($dias) }} día(s)
          @elseif ($dias === 0)
            empieza hoy
          @else
            empieza en {{ $dias }} día(s)
          @endif
        </span>
      </div>

      @if ($cupos->isEmpty())
        <p class="text-sm text-slate-400">Esta campaña no tiene mercados declarados.</p>
      @else
        <table class="w-full text-sm">
          <thead class="text-slate-500 text-xs">
            <tr>
              <th class="text-left font-medium pb-2">País</th>
              <th class="text-right font-medium pb-2">Cupo</th>
              {{-- «Cubiertos» es aceptados o más allá, NO invitados: una
                   invitación sin contestar es una plaza esperando, y contarla
                   como cubierta es cómo se llega al arranque a medias. --}}
              <th class="text-right font-medium pb-2">Cubiertos</th>
              <th class="text-right font-medium pb-2">Invitados</th>
              <th class="text-right font-medium pb-2">Faltan</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($cupos as $m)
              <tr>
                <td class="py-2">{{ $m->pais }}</td>
                <td class="py-2 text-right tabular-nums text-slate-500">
                  {{ $m->target_creators ?? '—' }}
                </td>
                <td class="py-2 text-right tabular-nums">{{ $m->cubiertos }}</td>
                <td class="py-2 text-right tabular-nums text-slate-500">{{ $m->invitados }}</td>
                <td class="py-2 text-right tabular-nums">
                  @if ($m->faltan === null)
                    <span class="text-slate-300">sin cupo</span>
                  @elseif ($m->faltan === 0)
                    <span class="text-emerald-600">completo</span>
                  @else
                    <span class="font-medium text-amber-700">{{ $m->faltan }}</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>

    {{-- QUIÉN ES QUIÉN --}}
    @if ($participantes->isNotEmpty())
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-baseline justify-between mb-3">
          <h2 class="text-sm font-medium text-slate-700">Quién es quién</h2>
          <div class="flex gap-3">
            @if ($verEntregables)
              {{-- 8.1: el conteo NO está en esta pantalla, y no por diseño de
                   producto: `Campaign` no puede conocer `Content`. Lo que hay es
                   el enlace, que es un nombre de ruta y no una clase. --}}
              <a href="{{ route('campanas.entregables', $campana->uuid) }}"
                 class="text-xs text-marca-600 hover:underline">Ver entregables</a>
            @endif
            <a href="{{ route('campanas.candidatos', $campana->uuid) }}"
               class="text-xs text-marca-600 hover:underline">Buscar más creadores</a>
          </div>
        </div>

        <table class="w-full text-sm">
          <thead class="text-slate-500 text-xs">
            <tr>
              <th class="text-left font-medium pb-2">Creador</th>
              <th class="text-left font-medium pb-2">Mercado</th>
              <th class="text-left font-medium pb-2">Estado</th>
              <th class="text-right font-medium pb-2">Importe</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($participantes as $p)
              <tr>
                <td class="py-2">
                  <a href="{{ route('creadores.show', $p->creador_uuid) }}"
                     class="text-marca-600 hover:underline">{{ $p->display_name }}</a>
                </td>
                <td class="py-2 text-slate-500">{{ $p->mercado ?? '—' }}</td>
                <td class="py-2">
                  <span class="rounded px-1.5 py-0.5 text-xs
                               {{ array_key_exists($p->status, $nombresSalidas)
                                   ? 'bg-slate-100 text-slate-400'
                                   : 'bg-slate-100 text-slate-600' }}">
                    {{ $nombresEmbudo[$p->status] ?? $nombresSalidas[$p->status] ?? $p->status }}
                  </span>
                </td>
                <td class="py-2 text-right tabular-nums">
                  {{ number_format((float) $p->agreed_amount, 2) }}
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
@endsection
