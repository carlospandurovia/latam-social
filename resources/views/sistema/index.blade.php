@extends('layouts.panel')
@section('titulo', 'Sistema')
@section('subtitulo', 'Información del sistema')

@section('contenido')
  @if ($avisos !== [])
    <div class="mb-6 space-y-2">
      @foreach ($avisos as $aviso)
        <div class="rounded-lg border px-4 py-3 text-sm
          {{ $aviso->nivel === 'rojo'
             ? 'border-rose-300 bg-rose-50 text-rose-900'
             : 'border-amber-300 bg-amber-50 text-amber-900' }}">
          {{ $aviso->texto }}
        </div>
      @endforeach
    </div>
  @endif

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Esta instalación</h2>
      <p class="text-sm text-slate-500 mb-4">
        Qué máquina es ésta y qué se le deja hacer de verdad.
      </p>
      <dl class="space-y-2.5 text-sm">
        <div class="flex items-start justify-between gap-4">
          <dt class="text-slate-600">Entorno</dt>
          <dd class="flex items-center gap-2 shrink-0">
            <span class="text-xs text-slate-500">{{ $entorno['nombre'] }}</span>
            <span class="inline-block w-2 h-2 rounded-full
              {{ $entorno['es_produccion'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
          </dd>
        </div>
        <div class="flex items-start justify-between gap-4">
          <dt class="text-slate-600">Conexiones de producción</dt>
          <dd class="flex items-center gap-2 shrink-0">
            <span class="text-xs text-slate-500">
              {{ $entorno['barrera_abierta'] ? 'PERMITIDAS' : 'bloqueadas' }}
            </span>
            <span class="inline-block w-2 h-2 rounded-full
              {{ $entorno['barrera_abierta'] ? 'bg-rose-500' : 'bg-emerald-500' }}"></span>
          </dd>
        </div>
        @foreach ($aplicacion as $etiqueta => $valor)
          <div class="flex items-start justify-between gap-4">
            <dt class="text-slate-600">{{ $etiqueta }}</dt>
            <dd class="text-xs text-slate-500 shrink-0 text-right">{{ $valor }}</dd>
          </div>
        @endforeach
      </dl>
      @unless ($entorno['es_produccion'])
        <p class="mt-4 text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
          Mientras el entorno no sea «Producción», nada de lo que se haga aquí sale
          a servicios reales y los buscadores no indexan el sitio público.
        </p>
      @endunless
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Motor de base de datos</h2>
      <p class="text-sm text-slate-500 mb-4">
        Medido con consultas, no leído del número de versión.
      </p>
      <dl class="space-y-2.5 text-sm">
        @foreach ($motor as $etiqueta => [$ok, $detalle])
          <div class="flex items-start justify-between gap-4">
            <dt class="text-slate-600">{{ $etiqueta }}</dt>
            <dd class="flex items-center gap-2 shrink-0">
              <span class="text-xs text-slate-500">{{ $detalle }}</span>
              <span class="inline-block w-2 h-2 rounded-full
                {{ $ok ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
            </dd>
          </div>
        @endforeach
      </dl>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Cómo están impuestas las reglas</h2>
      <p class="text-sm text-slate-500 mb-4">
        Lo que quedó registrado al instalar cada una, no una suposición.
      </p>
      <div class="grid grid-cols-3 gap-4 mb-4">
        <div>
          <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($reglas['total']) }}</p>
          <p class="text-xs text-slate-400">restricciones</p>
        </div>
        <div>
          <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($reglas['tablas']) }}</p>
          <p class="text-xs text-slate-400">tablas</p>
        </div>
        <div>
          <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($reglas['disparadores']) }}</p>
          <p class="text-xs text-slate-400">disparadores</p>
        </div>
      </div>
      @if ($reglas['por_mecanismo'] === [])
        <p class="text-sm text-slate-400">Sin restricciones registradas todavía.</p>
      @else
        <dl class="space-y-2 text-sm border-t border-slate-100 pt-3">
          @foreach ($reglas['por_mecanismo'] as $mecanismo => $cuantas)
            <div class="flex items-center justify-between gap-4">
              <dt class="text-slate-600">
                {{ $mecanismo === 'check' ? 'Con CHECK nativo' : 'Con disparador' }}
              </dt>
              <dd class="text-xs text-slate-500 tabular-nums">{{ number_format($cuantas) }}</dd>
            </div>
          @endforeach
        </dl>
        @if (($reglas['por_mecanismo']['trigger'] ?? 0) > 0)
          <p class="mt-4 text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
            Este motor no aplica <code>CHECK</code>, así que esas reglas se imponen con
            disparadores. No es una carencia: funcionan igual y está verificado.
          </p>
        @endif
      @endif
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Sociedades que facturan</h2>
      <p class="text-sm text-slate-500 mb-4">Una sola vigente por país, sin empates posibles.</p>
      @if ($cobertura->isEmpty())
        <p class="text-sm text-slate-400">Sin cobertura configurada todavía.</p>
      @else
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wider text-slate-400 border-b border-slate-200">
                <th class="pb-2 font-medium">País</th>
                <th class="pb-2 font-medium">Factura</th>
                <th class="pb-2 font-medium">Motivo</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($cobertura as $fila)
                <tr>
                  <td class="py-2 text-slate-700">{{ $fila->pais }}</td>
                  <td class="py-2 text-slate-600">{{ $fila->sociedad }}</td>
                  <td class="py-2 text-slate-400 text-xs">{{ $fila->motivo }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>

  </div>
@endsection
