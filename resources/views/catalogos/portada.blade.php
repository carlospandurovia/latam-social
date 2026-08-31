@extends('layouts.panel')
@section('titulo', 'Catálogos')
@section('subtitulo', 'Las listas de las que tira todo lo demás')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Catálogos'])

  <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Se tocan pocas veces, y por eso están aquí</p>
    <p>
      Un país inactivo no desaparece: deja de ofrecerse. Es la diferencia entre
      <strong>existir</strong> y <strong>operar</strong>, y por eso lo que se apaga se queda
      escrito en vez de borrarse — hay campañas y creadores que lo siguen nombrando.
    </p>
  </div>

  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($catalogos as $c)
      <a href="{{ route('catalogos.show', $c['clave']) }}"
         class="block bg-white rounded-xl border border-slate-200 p-5 hover:border-marca-200 transition">
        <h2 class="text-sm font-semibold text-slate-800">{{ $c['titulo'] }}</h2>
        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $c['filas'] }}</p>
        <p class="text-xs text-slate-500">
          {{ $c['filas'] === 1 ? 'fila' : 'filas' }}
          @if ($c['activas'] !== null)
            · <span class="{{ $c['activas'] === 0 ? 'text-rose-700 font-medium' : '' }}">
              {{ $c['activas'] }} {{ $c['activas'] === 1 ? 'activa' : 'activas' }}
            </span>
          @endif
        </p>
      </a>
    @endforeach
  </div>
@endsection
