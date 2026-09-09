@extends('layouts.panel')
@section('titulo', 'Resumen de la operación')

@section('contenido')

  @include('panel.parciales.alertas')

  {{-- D-2: los filtros. Un formulario GET y no un fetch: la pantalla queda en la
       URL, se comparte por enlace, el boton de atras funciona y no hace falta
       una linea de JavaScript. Lo que se elige aqui manda sobre TODO lo que se
       pinte debajo. --}}
  <form method="GET" action="{{ route('panel') }}"
        class="mb-6 rounded-xl border border-slate-200 bg-white p-4">
    <div class="flex flex-wrap items-end gap-3">

      <div class="min-w-[10rem]">
        <label for="f-periodo" class="block text-xs font-medium text-slate-500 mb-1">Periodo</label>
        <select id="f-periodo" name="periodo"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @foreach ($periodos as $clave => $nombre)
            <option value="{{ $clave }}" @selected($filtros->periodo === $clave)>{{ $nombre }}</option>
          @endforeach
        </select>
      </div>

      <div>
        <label for="f-desde" class="block text-xs font-medium text-slate-500 mb-1">Desde</label>
        <input id="f-desde" type="date" name="desde" value="{{ $filtros->desde->toDateString() }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>

      <div>
        <label for="f-hasta" class="block text-xs font-medium text-slate-500 mb-1">Hasta</label>
        <input id="f-hasta" type="date" name="hasta" value="{{ $filtros->hasta->toDateString() }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>

      @foreach ([
        ['pais', 'País', 'paises', $filtros->paisId],
        ['sociedad', 'Sociedad', 'sociedades', $filtros->sociedadId],
        ['cliente', 'Cliente', 'clientes', $filtros->clienteId],
        ['campana', 'Campaña', 'campanas', $filtros->campanaId],
      ] as [$campo, $etiqueta, $lista, $elegido])
        <div class="min-w-[9rem]">
          <label for="f-{{ $campo }}" class="block text-xs font-medium text-slate-500 mb-1">
            {{ $etiqueta }}
          </label>
          <select id="f-{{ $campo }}" name="{{ $campo }}"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                         disabled:bg-slate-50 disabled:text-slate-400"
                  @disabled($opciones[$lista] === [])>
            <option value="">{{ $opciones[$lista] === [] ? 'Sin datos todavía' : 'Todos' }}</option>
            @foreach ($opciones[$lista] as $opcion)
              <option value="{{ $opcion->id }}" @selected($elegido === (int) $opcion->id)>
                {{ $opcion->nombre }}
              </option>
            @endforeach
          </select>
        </div>
      @endforeach

      <div class="flex items-center gap-2 ml-auto">
        <button type="submit"
                class="rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white
                       transition hover:bg-marca-700
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2">
          Actualizar
        </button>
        @if ($filtros->hayRecorte())
          <a href="{{ route('panel') }}"
             class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600
                    transition hover:bg-slate-50
                    focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2">
            Limpiar
          </a>
        @endif
      </div>
    </div>

    <p class="mt-3 text-xs text-slate-400">
      {{ $filtros->nombreDelPeriodo() }}:
      {{ $filtros->desde->format('d/m/Y') }} — {{ $filtros->hasta->format('d/m/Y') }}
      · actualizado {{ $actualizado->format('d/m/Y H:i') }} UTC
    </p>
  </form>

  {{-- Los indicadores de operación. La tarjeta vive en su propio parcial desde
       `D-7`, porque comercial usa exactamente la misma. --}}
  <h2 class="mb-3 text-sm font-semibold text-slate-500">Operación</h2>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
    @foreach ($kpis as $kpi)
      @include('panel.parciales.tarjeta-kpi', ['kpi' => $kpi])
    @endforeach
  </div>

  @include('panel.parciales.comercial')

  {{-- D-8: el dinero. `null` cuando el usuario no tiene `finance.view`, y en ese
       caso el bloque no existe --ni se consultó--. --}}
  @if ($financiero !== null)
    @include('panel.parciales.financiero')
  @endif

  {{-- D-14: publicaciones y permanencia. `null` sin `campaign.view`. --}}
  @if ($publicaciones !== null)
    @include('panel.parciales.publicaciones')
  @endif

  {{-- D-13: el margen. `null` sin `campaign.view_margin` --que no tiene quien
       lleva campanas--, y entonces ni se pinta ni se consulto. --}}
  @if ($margen !== null)
    @include('panel.parciales.margen')
  @endif

  {{-- D-9: la red. `null` sin `creator.view`: no se pinta y no se consulta. --}}
  @if ($red !== null)
    @include('panel.parciales.creadores')
  @endif

  @include('panel.parciales.estado-campanas')

  {{-- D-6: el semaforo. `null` cuando el usuario no tiene `campaign.view`, y en
       ese caso el bloque no existe --ni se consulto--, en vez de existir vacio.
       Quien lleva finanzas no tiene por que ver la cartera de campanas. --}}
  @if ($seguimiento !== null)
    @include('panel.parciales.seguimiento')
  @endif

  {{-- Los contadores de siempre, que no dependen del periodo: son el tamaño de
       la operación, no su actividad. Se dice para que nadie los lea como si el
       filtro no funcionara. --}}
  <h2 class="mt-8 mb-3 text-sm font-semibold text-slate-500">Tamaño de la operación</h2>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    @foreach ($tarjetas as $t)
      <a href="{{ route($t['ruta']) }}"
         class="block bg-white rounded-xl border border-slate-200 p-5 transition
                hover:border-slate-300 hover:shadow-sm
                focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2">
        <p class="text-sm text-slate-500">{{ $t['titulo'] }}</p>
        <p class="mt-1 text-3xl font-bold text-slate-900 tabular-nums">{{ number_format($t['valor']) }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ $t['nota'] }} · en total, sin filtrar</p>
      </a>
    @endforeach
  </div>

  {{-- D-10: la actividad. `null` sin `audit.view`. --}}
  @if ($actividad !== null)
    @include('panel.parciales.actividad')
  @endif

  <div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-6 text-center">
    <p class="text-sm font-medium text-slate-700">Lo que este panel todavía no puede decir</p>
    <p class="mt-1 text-sm text-slate-500">
      El <strong>margen</strong> —lo que queda después de pagar a creadores y gastos—
      todavía no está en esta pantalla: se ve por campaña en Rentabilidad.
      El <strong>rendimiento</strong> de las publicaciones —alcance, interacciones—
      necesita las APIs de cada red: no hay fuente, y un número inventado sería peor
      que el hueco.
    </p>
  </div>
@endsection
