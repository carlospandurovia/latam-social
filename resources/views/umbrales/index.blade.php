@extends('layouts.panel')
@section('titulo', 'Semáforo de campañas')
@section('subtitulo', 'Cuándo una campaña está retrasada y cuándo está en riesgo')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Semáforo de campañas'])

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-900'
                                  : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso->nivel === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso->nivel === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso->texto }}
    </div>
  @endforeach

  @if (session('mensaje'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('mensaje') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">

      {{-- Las reglas, escritas. Un formulario con tres números y sin la frase
           que forman no se puede revisar: hay que acordarse de qué compara cada
           uno, y a la segunda vez nadie se acuerda. --}}
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Con estos números, hoy</h2>
        </div>
        <dl class="divide-y divide-slate-100 text-sm">
          <div class="px-5 py-4 flex gap-3">
            <dt class="shrink-0"><span class="inline-block w-3 h-3 rounded-full bg-rose-500 mt-1"></span></dt>
            <dd>
              <p class="font-medium text-slate-900">Retrasada</p>
              <p class="text-slate-600 mt-0.5">
                Se pasó la fecha de cierre y la campaña sigue abierta; o se pasó la fecha límite
                de publicación y quedan entregables sin publicación verificada.
              </p>
              <p class="text-xs text-slate-400 mt-1">Esta regla no tiene umbral: una fecha comprometida se pasó o no.</p>
            </dd>
          </div>
          <div class="px-5 py-4 flex gap-3">
            <dt class="shrink-0"><span class="inline-block w-3 h-3 rounded-full bg-amber-500 mt-1"></span></dt>
            <dd>
              <p class="font-medium text-slate-900">En riesgo</p>
              <p class="text-slate-600 mt-0.5">
                Faltan <strong class="tabular-nums">{{ $umbrales['dias'] }}</strong> días o menos para
                el cierre y el avance de contenido va por debajo del
                <strong class="tabular-nums">{{ $umbrales['avance'] }} %</strong>;
                o la campaña sigue en convocatoria a
                <strong class="tabular-nums">{{ $umbrales['convocatoria'] }}</strong> días o menos
                del arranque con menos creadores aceptados que el objetivo de sus mercados.
              </p>
            </dd>
          </div>
          <div class="px-5 py-4 flex gap-3">
            <dt class="shrink-0"><span class="inline-block w-3 h-3 rounded-full bg-emerald-500 mt-1"></span></dt>
            <dd>
              <p class="font-medium text-slate-900">En plazo</p>
              <p class="text-slate-600 mt-0.5">Todas las demás.</p>
            </dd>
          </div>
        </dl>
        <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 text-xs text-slate-500 space-y-1">
          <p>
            <strong>Avance de contenido</strong> = entregables aprobados, publicados o verificados
            ÷ entregables no cancelados. Una campaña ya arrancada y sin ningún entregable creado
            cuenta como 0 %; una que aún no arranca no se mide.
          </p>
          <p>
            <strong>Objetivo de creadores</strong> = suma de los cupos declarados en los mercados de
            la campaña. Sin cupos declarados, esa regla no se le aplica.
          </p>
        </div>
      </div>
    </div>

    {{-- El formulario. Tres campos y nada más: si algún día hay un cuarto
         umbral, entra aquí y la frase de arriba lo recoge sola. --}}
    <div class="space-y-5">
      <form method="POST" action="{{ route('umbrales.update') }}"
            class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        @csrf
        @method('PUT')

        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Los umbrales</h2>
        </div>

        <div class="p-5 space-y-4">
          @foreach ([
            ['risk_days_before_end', 'Días antes del cierre', $umbrales['dias'], 365,
             'A partir de aquí se mira el avance de la campaña.'],
            ['min_progress_pct', 'Avance mínimo (%)', $umbrales['avance'], 100,
             'Por debajo de esto, dentro de esos días, es ámbar.'],
            ['recruiting_days_before_start', 'Días antes del arranque', $umbrales['convocatoria'], 365,
             'A partir de aquí, una convocatoria incompleta es ámbar.'],
          ] as [$campo, $etiqueta, $valor, $techo, $ayuda])
            <div>
              <label for="u-{{ $campo }}" class="block text-xs font-medium text-slate-600 mb-1">
                {{ $etiqueta }}
              </label>
              <input id="u-{{ $campo }}" type="number" name="{{ $campo }}"
                     value="{{ old($campo, $valor) }}" min="0" max="{{ $techo }}" step="1" required
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums
                            focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500">
              <p class="mt-1 text-xs text-slate-400">{{ $ayuda }}</p>
            </div>
          @endforeach
        </div>

        <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between gap-3">
          <p class="text-xs text-slate-400">
            De partida: {{ $partida['dias'] }} · {{ $partida['avance'] }} % · {{ $partida['convocatoria'] }}
          </p>
          <button type="submit"
                  class="rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white
                         transition hover:bg-marca-700
                         focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2">
            Guardar
          </button>
        </div>
      </form>

      <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs text-slate-500 space-y-2">
        <p class="text-sm font-semibold text-slate-700">Qué hace este ajuste</p>
        <p>
          Colorea la tabla de seguimiento del panel. No cambia ningún estado, no manda ningún
          correo y no bloquea nada: es el criterio con el que el equipo mira su propio trabajo.
        </p>
        @if ($modificado)
          <p class="text-slate-400">Última modificación: {{ $modificado }} UTC.</p>
        @endif
      </div>
    </div>
  </div>
@endsection
