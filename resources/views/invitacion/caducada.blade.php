@extends('layouts.acceso')
@section('titulo', 'Invitación')

@section('contenido')
  <h1 class="text-xl font-semibold text-slate-900 mb-2">Esta invitación no está disponible</h1>

  {{-- El motivo, con su texto. Delante no hay un operador que sepa leer un
       mensaje técnico: hay una persona que abrió un correo. --}}
  <p class="text-sm text-slate-600">
    {{ session('fallo', 'Puede que el enlace esté incompleto o que ya no sea válido.') }}
  </p>

  <p class="mt-4 text-xs text-slate-500">
    Si crees que es un error, responde al correo con el que te llegó la invitación.
  </p>
@endsection
