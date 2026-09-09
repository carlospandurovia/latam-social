{{-- D-10: lo último que ha pasado.

     Dice QUÉ, QUIÉN y CUÁNDO — y no el detalle del cambio. `audit_logs.changes`
     lleva el antes y el después de cada campo, y ahí viaja información que no
     tiene por qué salir en una portada: un correo, un domicilio, un importe. El
     detalle está a un clic, en la bitácora, que es donde ya tiene su pantalla y
     su permiso. --}}

<section class="mt-8 bg-white rounded-xl border border-slate-200 overflow-hidden"
         aria-labelledby="t-actividad">
  <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-3">
    <div>
      <h2 id="t-actividad" class="font-semibold text-slate-900">Lo último que ha pasado</h2>
      <p class="text-sm text-slate-500 mt-0.5">
        Del periodo elegido. Los demás filtros no se le aplican: una entrada de
        bitácora no cuelga de una campaña.
      </p>
    </div>
    <a href="{{ route('bitacora') }}"
       class="shrink-0 text-xs text-slate-500 underline decoration-dotted underline-offset-2
              hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 rounded">
      Ver la bitácora completa
    </a>
  </div>

  @if ($actividad === [])
    <p class="px-5 py-8 text-center text-sm text-slate-400">
      No se registró ninguna actividad en el periodo elegido.
    </p>
  @else
    <ol class="divide-y divide-slate-100">
      @foreach ($actividad as $linea)
        <li class="px-5 py-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
          <span class="font-mono text-xs text-slate-400 tabular-nums shrink-0">{{ $linea['cuando'] }}</span>
          <span class="font-medium text-slate-800">{{ $linea['accion'] }}</span>
          <span class="text-slate-500">{{ $linea['entidad'] }}</span>
          <span class="ml-auto text-xs text-slate-400">{{ $linea['quien'] }}</span>
        </li>
      @endforeach
    </ol>
  @endif
</section>
