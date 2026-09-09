{{-- D-9: la red de creadores.

     Dos huecos que se dicen en vez de disimularse (`DEC-190`):
     el reparto por rango de seguidores necesita que los rangos sean
     configurables y decidir qué instantánea vale, y el «nivel de desempeño» no
     existe —no hay tabla de evaluación—: componerlo sería definir un indicador
     nuevo, no leer uno. --}}

<h2 class="mt-8 mb-1 text-sm font-semibold text-slate-500">Red de creadores</h2>
<p class="mb-3 text-xs text-slate-400">
  Los tres indicadores son del periodo. El reparto por estado es <strong>a hoy</strong>:
  un creador no tiene ventana de fechas.
</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
  @foreach ($creadores as $kpi)
    @include('panel.parciales.tarjeta-kpi', ['kpi' => $kpi])
  @endforeach

  <section class="bg-white rounded-xl border border-slate-200 p-5" aria-labelledby="t-verificados">
    <p id="t-verificados" class="text-sm text-slate-500">Identidad verificada</p>
    <p class="mt-1 text-3xl font-bold text-slate-900 tabular-nums">
      {{ number_format($red['verificados']) }}
      <span class="text-lg font-medium text-slate-400">/ {{ number_format($red['activos']) }}</span>
    </p>
    <p class="mt-1 text-xs text-slate-400">
      de los creadores activos. Sin verificar no se puede cobrar.
    </p>
  </section>
</div>

<section class="mt-5 bg-white rounded-xl border border-slate-200 p-6" aria-labelledby="t-red">
  <div class="flex flex-wrap items-baseline justify-between gap-3 mb-1">
    <h3 id="t-red" class="font-semibold text-slate-900">En qué estado está la red</h3>
    @if ($red['aceptacion'] !== null)
      <p class="text-sm text-slate-600">
        Aceptación de invitaciones
        <strong class="tabular-nums text-slate-900">{{ $red['aceptacion'] }} %</strong>
      </p>
    @endif
  </div>
  <p class="text-sm text-slate-500 mb-4">
    Todos los creadores registrados, por su estado de hoy. Los anonimizados no cuentan.
  </p>

  @if ($red['total'] === 0)
    <p class="py-6 text-center text-sm text-slate-400">Todavía no hay creadores registrados.</p>
  @else
    @php($maximo = max(1, max(array_column($red['filas'], 'cantidad'))))

    <dl class="space-y-2.5">
      @foreach ($red['filas'] as $fila)
        <div>
          <div class="flex items-baseline justify-between gap-3 text-sm">
            <dt class="{{ $fila['cantidad'] === 0 ? 'text-slate-400' : 'text-slate-700' }}">
              {{ $fila['nombre'] }}
            </dt>
            <dd class="tabular-nums font-medium
              {{ $fila['cantidad'] === 0 ? 'text-slate-300' : 'text-slate-900' }}">
              {{ $fila['cantidad'] }}
            </dd>
          </div>
          <div class="mt-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full
              {{ $fila['clave'] === 'active' ? 'bg-emerald-500'
                 : (in_array($fila['clave'], ['blacklisted', 'rejected'], true) ? 'bg-rose-400'
                 : ($fila['clave'] === 'pending' ? 'bg-amber-500' : 'bg-slate-300')) }}"
                 style="width: {{ $fila['cantidad'] === 0 ? 0 : max(3, round($fila['cantidad'] / $maximo * 100)) }}%"></div>
          </div>
        </div>
      @endforeach
    </dl>

    <p class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-400">
      Faltan aquí el reparto por rango de seguidores —los rangos tienen que ser
      configurables— y el nivel de desempeño, que no existe: no hay tabla de evaluación,
      y componerlo sería inventar un indicador, no leer uno.
    </p>
  @endif
</section>
