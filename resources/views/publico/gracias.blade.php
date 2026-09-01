@extends('layouts.publico')
@section('titulo', 'Postulación recibida')

@section('contenido')
  <section class="mx-auto max-w-2xl px-6 py-24 text-center">
    <h1 class="text-2xl font-semibold text-slate-900">Recibimos tu postulación</h1>
    <p class="mt-3 text-slate-600">
      La revisamos y te escribimos al correo que dejaste — encaje o no encaje todavía.
      No hace falta que la envíes otra vez.
    </p>
    <a href="{{ route('portada.creadores') }}" class="mt-8 inline-block text-sm text-marca-700 hover:underline">
      ← Volver
    </a>
  </section>
@endsection
