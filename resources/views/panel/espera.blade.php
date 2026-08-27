{{-- La portada de quien NO es del equipo interno.

     No extiende `layouts.panel` a propósito: ese trae el menú lateral con las
     secciones del back-office. Que estén todas protegidas por permiso significa
     que no puede entrar en ninguna; enseñárselas igualmente sería un mapa de lo
     que hay dentro, y un catálogo de sitios donde probar suerte. --}}
@extends('layouts.acceso')
@section('titulo', 'Tu cuenta')

@section('contenido')
  <h1 class="text-xl font-semibold text-slate-900 mb-2">Hola, {{ $nombre }}</h1>

  <p class="text-sm text-slate-600">
    Tu cuenta está activa y tu contraseña, guardada. Todavía no hay nada que puedas
    hacer aquí: el área de creadores no está abierta.
  </p>
  <p class="mt-3 text-sm text-slate-600">
    Mientras tanto, el equipo se pone en contacto contigo por correo para lo que haga
    falta. Cuando el área esté disponible te avisamos a esta misma dirección.
  </p>

  <form method="POST" action="{{ route('salir') }}" class="mt-6">
    @csrf
    <button class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50">
      Cerrar sesión
    </button>
  </form>
@endsection
