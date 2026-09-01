@extends('layouts.publico')
@section('titulo', 'Gracias por escribir')

@section('contenido')
  <section class="mx-auto max-w-2xl px-6 py-24 text-center">
    <h1 class="text-2xl font-semibold text-slate-900">Recibimos tu mensaje</h1>
    <p class="mt-3 text-slate-600">
      Te escribimos al correo que dejaste para contarte cómo sería tu campaña.
      No hace falta que lo envíes otra vez.
    </p>
    <a href="{{ route('portada.marcas') }}" class="mt-8 inline-block text-sm text-marca-700 hover:underline">
      ← Volver
    </a>
  </section>
@endsection
