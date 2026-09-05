@extends('layouts.publico')
@section('titulo', __('publico.gracias.titulo'))

@section('contenido')
  <section class="mx-auto max-w-2xl px-6 py-24 text-center">
    <h1 class="text-2xl font-semibold text-slate-900">{{ __('publico.gracias.encabezado') }}</h1>
    <p class="mt-3 text-slate-600">
      {{ __('publico.gracias.texto') }}
    </p>
    <a href="{{ route('portada.creadores') }}" class="mt-8 inline-block text-sm text-marca-700 hover:underline">
      {{ __('publico.gracias.volver') }}
    </a>
  </section>
@endsection
