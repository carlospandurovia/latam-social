{{-- D-7: de dónde viene el negocio.

     Dos cosas que este bloque hace a propósito y conviene no deshacer:

     1. Los cuatro indicadores son FLUJO. Lo pendiente --prospectos sin atender,
        solicitudes sin resolver-- ya lo dice el bloque de alertas, y allí mira el
        presente sin recortarse (`DEC-311`). Repetirlo aquí recortado por periodo
        daría dos números distintos para la misma pregunta en la misma pantalla.
     2. El reparto de prospectos es una DISTRIBUCIÓN, no un embudo. `client_leads`
        guarda un solo estado --el de hoy-- y no hay historia, así que dibujar
        cinco conteos como un embudo enseñaría una caída entre pasos que nunca
        ocurrió (`T-113`). --}}

<h2 class="mt-8 mb-1 text-sm font-semibold text-slate-500">Comercial</h2>
<p class="mb-3 text-xs text-slate-400">
  De los filtros de arriba, a este bloque sólo le aplica el de <strong>país</strong>
  —y el de cliente a los clientes nuevos—: un prospecto que aún no es cliente no
  tiene sociedad que le facture ni campaña.
</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
  @foreach ($comercial as $kpi)
    @include('panel.parciales.tarjeta-kpi', ['kpi' => $kpi])
  @endforeach
</div>

<section class="mt-5 bg-white rounded-xl border border-slate-200 p-6" aria-labelledby="t-prospectos">
  <div class="flex flex-wrap items-baseline justify-between gap-3 mb-1">
    <h3 id="t-prospectos" class="font-semibold text-slate-900">En qué quedaron los prospectos del periodo</h3>
    @if ($prospectos['conversion'] !== null)
      <p class="text-sm text-slate-600">
        Conversión
        <strong class="tabular-nums text-slate-900">{{ $prospectos['conversion'] }} %</strong>
        <span class="text-slate-400">
          ({{ $prospectos['convertidos'] }} de {{ $prospectos['total'] }})
        </span>
      </p>
    @endif
  </div>
  <p class="text-sm text-slate-500 mb-4">
    Los que llegaron dentro del periodo, por el estado en que están <strong>hoy</strong>.
    No es un embudo: no se guarda por dónde pasó cada uno.
  </p>

  @if ($prospectos['total'] === 0)
    <p class="py-6 text-center text-sm text-slate-400">
      No llegó ningún prospecto en el periodo elegido.
    </p>
  @else
    @php($maximo = max(1, max(array_column($prospectos['filas'], 'cantidad'))))

    <dl class="space-y-2.5">
      @foreach ($prospectos['filas'] as $fila)
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
              {{ $fila['clave'] === 'converted' ? 'bg-emerald-500'
                 : ($fila['clave'] === 'discarded' ? 'bg-slate-300' : 'bg-marca-500') }}"
                 style="width: {{ $fila['cantidad'] === 0 ? 0 : max(3, round($fila['cantidad'] / $maximo * 100)) }}%"></div>
          </div>
        </div>
      @endforeach
    </dl>
  @endif
</section>
