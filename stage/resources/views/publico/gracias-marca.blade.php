@extends('layouts.publico')
@section('titulo', __('publico.gracias_marca.titulo'))

@section('contenido')
  <section class="mx-auto max-w-2xl px-6 py-24 text-center">
    <h1 class="text-2xl font-semibold text-slate-900">{{ __('publico.gracias_marca.encabezado') }}</h1>
    <p class="mt-3 text-slate-600">
      {{ __('publico.gracias_marca.texto') }}
    </p>
    <a href="{{ route('portada.marcas') }}" class="mt-8 inline-block text-sm text-marca-700 hover:underline">
      {{ __('publico.gracias_marca.volver') }}
    </a>
  </section>
@endsection
