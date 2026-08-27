@extends('layouts.acceso')
@section('titulo', 'Revisión de pieza')

@section('contenido')
  <h1 class="text-xl font-semibold text-slate-900 mb-2">Este enlace no está disponible</h1>

  {{-- El motivo, con su texto. Delante no hay un operador que sepa leer un
       mensaje técnico: hay una persona que abrió un correo. --}}
  <p class="text-sm text-slate-600">
    {{ session('fallo', 'Puede que el enlace esté incompleto o que ya no sea válido.') }}
  </p>

  <p class="mt-4 text-xs text-slate-500">
    Si cree que es un error, responda al correo con el que le llegó.
  </p>
@endsection
