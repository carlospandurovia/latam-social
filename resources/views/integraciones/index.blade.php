@extends('layouts.panel')
@section('titulo', 'Integraciones')
@section('subtitulo', 'Cada integración, con lo que de verdad necesita')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Integraciones'])

  @if (session('exito'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  {{-- 9.17f: pestañas por PROPÓSITO y no un formulario para todo.
       Reportado por el negocio: «cada proveedor de integración tiene diferentes
       parámetros, sobre todo si es para diferentes fines». Tenía razón — el
       formulario único pedía lo mismo a un servidor de correo y a un emisor
       electrónico, y no le servía bien a ninguno de los dos. --}}
  <div class="mb-5 flex flex-wrap gap-1 border-b border-slate-200">
    @foreach ($pestanas as $clave => $titulo)
      <a href="{{ route('integraciones.index', ['p' => $clave]) }}"
         class="rounded-t-lg px-4 py-2 text-sm
           {{ $pestana === $clave
              ? 'border border-b-white border-slate-200 bg-white font-medium text-slate-900'
              : 'text-slate-500 hover:text-slate-800' }}">
        {{ $titulo }}
        @if (($pendientes[$clave] ?? 0) > 0)
          <span class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[11px] text-rose-800">
            {{ $pendientes[$clave] }}
          </span>
        @endif
      </a>
    @endforeach
  </div>

  {{-- 9.17i: los avisos YA NO se pintan aquí sueltos.

       Crítica del negocio, con la pantalla de otro producto suyo delante:
       *«esta pantalla no se compara a la que me hiciste para LOTEALO»*. Una de
       las cosas que le faltaban era exactamente ésta: el aviso salía DOS VECES
       --arriba en la lista de la pestaña y otra vez dentro-- y arriba no decía
       a qué integración se refería. Ahora cada aviso vive en su tarjeta.

       La lista de la pestaña sigue existiendo en `Pestanas`, y sigue siendo la
       que cuenta la chapa roja del rótulo: eso no cambia. Lo que cambia es
       DÓNDE se pinta. --}}
  @if ($pestana === 'fel')
    @include('parciales.panel-conexiones')
    @include('parciales.panel-certificados')
    @include('parciales.panel-series')
  @endif

  @if ($pestana === 'fx')
    @include('parciales.panel-cambio')
  @endif

  @if ($pestana === 'correo')
    @include('parciales.panel-correo')
  @endif
@endsection
