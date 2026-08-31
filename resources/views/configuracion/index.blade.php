@extends('layouts.panel')
@section('titulo', 'Configuración')
@section('subtitulo', 'Qué falta por configurar, y con qué prioridad')

@section('contenido')
  {{-- El encabezado contesta la pregunta de un vistazo. Si hay que leerse la
       pantalla entera para saber si hay algo urgente, la pantalla no sirve. --}}
  <div class="mb-5 rounded-xl border p-5
    {{ $recuento['rojo'] > 0 ? 'bg-rose-50 border-rose-200'
       : ($recuento['ambar'] > 0 ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200') }}">
    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
      @if ($recuento['rojo'] > 0)
        <p class="text-sm font-semibold text-rose-900">
          {{ $recuento['rojo'] }} {{ $recuento['rojo'] === 1 ? 'cosa' : 'cosas' }} que atender
        </p>
      @elseif ($recuento['ambar'] > 0)
        <p class="text-sm font-semibold text-amber-900">Nada urgente</p>
      @else
        <p class="text-sm font-semibold text-emerald-900">Todo configurado</p>
      @endif

      @if ($recuento['ambar'] > 0)
        <p class="text-sm text-amber-800">
          {{ $recuento['ambar'] }} para revisar cuando se pueda
        </p>
      @endif

      <p class="text-xs text-slate-500">
        {{ $recuento['listas'] }} de {{ $recuento['areas'] }}
        {{ $recuento['areas'] === 1 ? 'área' : 'áreas' }} sin nada pendiente
      </p>
    </div>

    {{-- La frase que hace falta que se lea, y la razón de que exista esta
         pantalla. DEC-190 con todas las letras. --}}
    <p class="mt-2 text-xs {{ $recuento['rojo'] > 0 ? 'text-rose-800' : 'text-slate-500' }}">
      Nada de esto impide operar. Son prioridades: rojo es lo que alguien de fuera va a notar,
      ámbar es lo que conviene y de momento se sostiene con el valor de partida.
    </p>
  </div>

  {{-- 9.20: por grupos. El orden de los grupos lo decide `Preparacion` y no
       esta plantilla, porque es una decision y no una presentacion: aqui las
       areas llegan ordenadas por urgencia, asi que «Fiscal» saldria antes o
       despues segun que falte hoy. Un sitio que cambia de forma segun el dia no
       se aprende. --}}
  @foreach ($grupos as $bloque)
  <p class="mt-6 first:mt-0 mb-2 text-[11px] uppercase tracking-wider text-slate-500">
    {{ $bloque['grupo'] }}
  </p>
  <div class="space-y-4">
    @foreach ($bloque['areas'] as $area)
      <div class="bg-white rounded-xl border overflow-hidden
        {{ $area['nivel'] === 'rojo' ? 'border-rose-200'
           : ($area['nivel'] === 'ambar' ? 'border-amber-200' : 'border-slate-200') }}">

        <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-slate-100">
          <div class="flex items-center gap-3">
            <span class="inline-block w-2.5 h-2.5 rounded-full
              {{ $area['nivel'] === 'rojo' ? 'bg-rose-600'
                 : ($area['nivel'] === 'ambar' ? 'bg-amber-500' : 'bg-emerald-500') }}"></span>
            <h2 class="text-sm font-semibold">{{ $area['area'] }}</h2>
            <span class="rounded px-2 py-0.5 text-xs
              {{ $area['nivel'] === 'rojo' ? 'bg-rose-100 text-rose-800'
                 : ($area['nivel'] === 'ambar' ? 'bg-amber-100 text-amber-800'
                 : 'bg-emerald-100 text-emerald-800') }}">
              {{ $area['nivel'] === 'rojo' ? 'Atender'
                 : ($area['nivel'] === 'ambar' ? 'Revisar' : 'Listo') }}
            </span>
          </div>

          @if ($area['ruta'])
            {{-- Un aviso sin sitio al que ir es media ayuda: el enlace está
                 siempre, también cuando el área está en verde, porque también
                 se entra a mirar lo que ya está bien. --}}
            <a href="{{ route($area['ruta']) }}" class="text-xs text-marca-700 hover:underline shrink-0">
              Ir a {{ mb_strtolower($area['area']) }} →
            </a>
          @endif
        </div>

        @forelse ($area['avisos'] as $aviso)
          <div class="flex gap-3 px-5 py-3 border-b border-slate-50 last:border-0">
            <span class="mt-0.5 shrink-0 inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase
              {{ $aviso->nivel === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
              {{ $aviso->nivel === 'rojo' ? 'Atender' : 'Revisar' }}
            </span>
            <p class="text-sm text-slate-700">{{ $aviso->texto }}</p>
          </div>
        @empty
          <p class="px-5 py-3 text-sm text-slate-500">Nada pendiente aquí.</p>
        @endforelse
      </div>
    @endforeach
  </div>
  @endforeach

  @if (count($revision) < $totalAreas)
    {{-- Se dice que hay más y no se dice cuáles: quien no puede arreglar un
         área tampoco necesita saber qué le falta a esa área. --}}
    <p class="mt-5 text-xs text-slate-400">
      Hay {{ $totalAreas - count($revision) }}
      {{ $totalAreas - count($revision) === 1 ? 'área más' : 'áreas más' }}
      que no se muestran porque su configuración la lleva otro permiso.
    </p>
  @endif
@endsection
