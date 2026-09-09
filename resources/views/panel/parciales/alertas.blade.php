{{-- D-3: «Requiere atención».

     Va ANTES de los indicadores. Un panel de control se abre para saber qué
     hacer, no para saber cuánto se vendió; lo segundo se consulta, lo primero
     interrumpe.

     Cada alerta lleva a su cola con un clic. Una lista de avisos que no lleva a
     ninguna parte es decoración, y la decoración se deja de mirar. --}}

<section class="mb-6" aria-labelledby="titulo-atencion">
  <div class="flex items-baseline justify-between gap-4 mb-3">
    <h2 id="titulo-atencion" class="text-sm font-semibold text-slate-700">Requiere atención</h2>
    <p class="text-xs text-slate-400">No depende del periodo: es lo que está pendiente hoy.</p>
  </div>

  @if ($alertas === [])
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-6 text-center">
      <p class="text-sm font-medium text-emerald-900">No hay nada pendiente</p>
      <p class="mt-1 text-sm text-emerald-700">
        Ni colas atrasadas, ni cobros vencidos, ni pagos devueltos.
      </p>
    </div>
  @else
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      @foreach ($alertas as $alerta)
        <a href="{{ route($alerta['ruta']) }}"
           class="block rounded-xl border px-4 py-3 transition
                  focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2
                  {{ $alerta['nivel'] === 'rojo'
                     ? 'border-rose-200 bg-rose-50 hover:border-rose-300 focus-visible:ring-rose-500'
                     : 'border-amber-200 bg-amber-50 hover:border-amber-300 focus-visible:ring-amber-500' }}">
          <div class="flex items-start gap-3">
            <span class="mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full
              {{ $alerta['nivel'] === 'rojo' ? 'bg-rose-500' : 'bg-amber-500' }}"></span>
            <div class="min-w-0 flex-1">
              <p class="flex items-baseline gap-2">
                <span class="text-2xl font-bold tabular-nums
                  {{ $alerta['nivel'] === 'rojo' ? 'text-rose-900' : 'text-amber-900' }}">
                  {{ number_format($alerta['cantidad']) }}
                </span>
                <span class="text-sm font-medium
                  {{ $alerta['nivel'] === 'rojo' ? 'text-rose-900' : 'text-amber-900' }}">
                  {{ $alerta['titulo'] }}
                </span>
              </p>
              <p class="mt-0.5 text-xs {{ $alerta['nivel'] === 'rojo' ? 'text-rose-700' : 'text-amber-700' }}">
                {{ $alerta['detalle'] }}
              </p>
              @if ($alerta['antiguedad'] !== null)
                <p class="mt-1 text-xs {{ $alerta['nivel'] === 'rojo' ? 'text-rose-600' : 'text-amber-600' }}">
                  @if ($alerta['antiguedad'] === 0)
                    el más antiguo, de hoy
                  @else
                    el más antiguo espera {{ $alerta['antiguedad'] }}
                    {{ $alerta['antiguedad'] === 1 ? 'día' : 'días' }}
                  @endif
                </p>
              @endif
            </div>
          </div>
        </a>
      @endforeach
    </div>
  @endif
</section>
