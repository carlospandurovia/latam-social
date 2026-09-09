{{-- Una tarjeta de indicador. Vive en un solo sitio desde `D-7`: hasta entonces
     el marcado estaba escrito dentro del bucle de la portada, y comercial
     necesitaba exactamente el mismo. Dos copias del mismo marcado divergen a la
     segunda vez que alguien toca una. --}}
<a href="{{ route($kpi['ruta']) }}"
   title="{{ $kpi['tooltip'] }}"
   class="block bg-white rounded-xl border border-slate-200 p-5 transition
          hover:border-slate-300 hover:shadow-sm
          focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2">
  <p class="text-sm text-slate-500">{{ $kpi['titulo'] }}</p>
  <p class="mt-1 text-3xl font-bold text-slate-900 tabular-nums">{{ number_format($kpi['valor']) }}</p>
  <p class="mt-1 text-xs
    {{ $kpi['sentido'] === 'sube' ? 'text-emerald-600'
       : ($kpi['sentido'] === 'baja' ? 'text-rose-600' : 'text-slate-400') }}">
    @if ($kpi['variacion'] === null)
      sin periodo anterior con el que comparar
    @else
      {{ $kpi['sentido'] === 'baja' ? '' : '+' }}{{ $kpi['variacion'] }}%
      frente a {{ number_format($kpi['anterior']) }} del periodo anterior
    @endif
  </p>
</a>
