@extends('layouts.panel')
@section('titulo', $mercado->pais)
@section('subtitulo', $campana->code.' · '.$campana->name)

@section('contenido')
  <div class="max-w-3xl space-y-5">
    <a href="{{ route('campanas.show', $campana->uuid) }}"
       class="text-sm text-marca-600 hover:underline">← Volver a la campaña</a>

    {{-- Lo primero que hay que saber al mirar esta pantalla es SI lo que se ve
         es propio de este país o heredado, porque de eso depende qué pasa al
         editar el brief general. --}}
    <div class="rounded-xl border p-4 text-sm
                {{ $propio ? 'bg-slate-50 border-slate-200 text-slate-700' : 'bg-marca-50 border-marca-200 text-marca-900' }}">
      @if ($propio)
        <p class="font-medium">Este mercado tiene brief propio</p>
        <p class="mt-1 text-xs">
          Lo de abajo es lo que alguien escribió <strong>para {{ $mercado->pais }}</strong>.
          Los requisitos generales de la campaña <strong>no se suman</strong> a éstos: el brief de
          mercado reemplaza al general, no se mezcla con él (<code>N-03</code>). Si quiere que
          {{ $mercado->pais }} vuelva a seguir el general, quite estas filas.
        </p>
      @else
        <p class="font-medium">Este mercado sigue el brief general</p>
        <p class="mt-1 text-xs">
          Nadie ha escrito requisitos específicos para {{ $mercado->pais }}, así que le toca lo que
          vale para todos los mercados. En cuanto se añada uno solo para este país,
          <strong>dejará de heredar el general por completo</strong>.
        </p>
      @endif
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="flex items-baseline justify-between mb-3">
        <h2 class="text-sm font-medium text-slate-700">Qué hay que entregar en {{ $mercado->pais }}</h2>
        <span class="text-xs text-slate-500">
          Creadores objetivo: {{ $mercado->target_creators ?? 'sin fijar' }}
        </span>
      </div>

      @if ($brief->isEmpty())
        <p class="text-sm text-amber-800">
          Ni este mercado ni la campaña dicen todavía qué hay que entregar.
        </p>
      @else
        <table class="w-full text-sm">
          <thead class="text-slate-500">
            <tr>
              <th class="text-left font-medium pb-2">Formato</th>
              <th class="text-right font-medium pb-2">Cantidad</th>
              <th class="text-right font-medium pb-2">Entrega</th>
              <th class="text-right font-medium pb-2">Permanencia</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($brief as $r)
              <tr>
                <td class="py-2">
                  {{ $r->red ? $r->red.' · ' : '' }}{{ $r->formato }}
                  @if ($r->notes)
                    <p class="text-xs text-slate-500">{{ $r->notes }}</p>
                  @endif
                </td>
                <td class="py-2 text-right">{{ $r->quantity }}</td>
                <td class="py-2 text-right">{{ $r->deadline_offset_days }} d</td>
                <td class="py-2 text-right">{{ $r->permanence_days }} d</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
@endsection
