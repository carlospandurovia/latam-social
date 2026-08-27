@extends('layouts.acceso')
@section('titulo', 'Gracias')

@section('contenido')
  @if ($respuesta === 'approved')
    <h1 class="text-xl font-semibold text-slate-900 mb-2">Gracias, queda registrado</h1>
    <p class="text-sm text-slate-600">
      Su conformidad con la pieza queda anotada con la fecha de hoy. Su contacto en
      LATAM Social la cierra y la campaña sigue.
    </p>
  @else
    <h1 class="text-xl font-semibold text-slate-900 mb-2">Recibimos su petición</h1>
    {{-- No se le promete que sea gratis: `DEC-153`. Si esa pieza ya gastó las
         rondas incluidas, alguien del equipo tiene que decidir si se cobra o la
         absorbemos, y decirle aquí «lo corregimos» sería prometer por su cuenta
         algo que todavía no está decidido. --}}
    <p class="text-sm text-slate-600">
      Sus comentarios quedan registrados y su contacto en LATAM Social los revisa.
      Le confirmarán los cambios y los plazos.
    </p>
  @endif

  <p class="mt-4 text-xs text-slate-500">
    Este enlace ya no vuelve a abrirse. Si necesita añadir algo, responda al correo
    con el que le llegó.
  </p>
@endsection
