{{-- D-14: lo que SÍ se puede medir de la «sección 7».

     El rendimiento —alcance, interacciones— sigue sin fuente y sigue fuera del
     panel: un número inventado sería peor que el hueco. Estas tres cifras no
     dicen cómo funcionó el contenido; dicen si el trato se cumplió. --}}

<h2 class="mt-8 mb-1 text-sm font-semibold text-slate-500">Publicaciones</h2>
<p class="mb-3 text-xs text-slate-400">
  Las tres son del periodo, cada una por la fecha que no cambia. El cumplimiento mira
  <strong>ventanas cerradas</strong>, y lo que se está vigilando es a día de hoy.
</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
  @foreach ($publicaciones['tarjetas'] as $tarjeta)
    @include('panel.parciales.tarjeta-kpi', ['kpi' => $tarjeta])
  @endforeach

  <section class="bg-white rounded-xl border border-slate-200 p-5" aria-labelledby="t-permanencia">
    <p id="t-permanencia" class="text-sm text-slate-500">Permanencia cumplida</p>

    @if ($publicaciones['permanencia']['porcentaje'] === null)
      <p class="mt-1 text-3xl font-bold text-slate-300 tabular-nums">—</p>
      <p class="mt-1 text-xs text-slate-400">
        Ninguna ventana de permanencia se cerró en el periodo. Sin ventanas cerradas no hay
        porcentaje: un 100 % de cero se leería como «todo bien».
      </p>
    @else
      <p class="mt-1 text-3xl font-bold tabular-nums
        {{ $publicaciones['permanencia']['porcentaje'] < 100 ? 'text-amber-600' : 'text-slate-900' }}">
        {{ number_format($publicaciones['permanencia']['porcentaje'], 1, ',', '.') }} %
      </p>
      <p class="mt-1 text-xs text-slate-400">
        {{ $publicaciones['permanencia']['cumplidas'] }} cumplidas y
        {{ $publicaciones['permanencia']['caidas'] }} caídas, de las ventanas cerradas en el periodo.
      </p>
    @endif

    <p class="mt-2 text-xs text-slate-500">
      {{ $publicaciones['permanencia']['vigilando'] }} en vigilancia hoy.
    </p>
  </section>
</div>
