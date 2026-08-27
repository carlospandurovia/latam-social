@extends('layouts.acceso')
@section('titulo', 'Gracias')

@section('contenido')
  @if ($acepto)
    <h1 class="text-xl font-semibold text-slate-900 mb-2">¡Hecho!</h1>
    <p class="text-sm text-slate-600">
      Aceptaste la invitación y el importe queda cerrado. El equipo se pone en contacto
      contigo por correo con el brief y las fechas de entrega.
    </p>
  @else
    <h1 class="text-xl font-semibold text-slate-900 mb-2">Gracias por contestar</h1>
    <p class="text-sm text-slate-600">
      Queda anotado. Contar que no puedes vale tanto como decir que sí: nos evita
      insistir y nos ayuda a proponerte cosas que sí te encajen.
    </p>
  @endif

  <p class="mt-4 text-xs text-slate-500">
    Si esto no era lo que querías responder, escríbenos cuanto antes y lo corregimos.
  </p>
@endsection
