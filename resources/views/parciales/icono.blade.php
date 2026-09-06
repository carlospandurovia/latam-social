{{-- Un icono de bloque, por nombre (L-3).

     ### Por qué por nombre y no por archivo subido

     Porque un icono no es una fotografía: es parte del sistema visual, tiene que
     heredar el color de la marca y verse igual a 20 px que a 40. Un PNG subido
     por quien administra no hace ninguna de las tres cosas, y además obliga a
     subir doce archivos antes de poder publicar una portada.

     ### Y por qué el nombre es texto libre en la base

     Un nombre que no conocemos **no rompe nada**: se dibuja un punto de marca.
     Es la misma regla que las redes del pie en `L-2a`, y por la misma razón: un
     hueco donde debería ir un icono se lee como una avería.

     `stroke` y no `fill`: un icono de línea envejece mejor junto a tipografía y
     no compite con el degradado, que es lo que tiene que mandar aquí
     (`docs/14 §6`). --}}
@php($n = strtolower(trim((string) ($icono ?? ''))))
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
     class="{{ $clase ?? 'h-6 w-6' }}">
  @switch($n)
    @case('personas')
      <path d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19"/>
      <circle cx="10" cy="7.5" r="3"/>
      <path d="M20 19v-1.5a3.5 3.5 0 0 0-2.6-3.38M15.4 4.7a3 3 0 0 1 0 5.6"/>
      @break
    @case('megafono')
      <path d="M4 10v4a1 1 0 0 0 1 1h2l6 4V5L7 9H5a1 1 0 0 0-1 1Z"/>
      <path d="M17 9.5a3.5 3.5 0 0 1 0 5M19.5 7a7 7 0 0 1 0 10"/>
      @break
    @case('rayo')
      <path d="M13 3 5 13.5h5.5L11 21l8-10.5h-5.5L13 3Z"/>
      @break
    @case('escudo')
      <path d="M12 3 5 6v5.5c0 4 2.9 7.6 7 8.5 4.1-.9 7-4.5 7-8.5V6l-7-3Z"/>
      @break
    @case('verificado')
      <path d="m12 3 2.1 1.6 2.6-.3 1 2.4 2.3 1.2-.6 2.6.6 2.6-2.3 1.2-1 2.4-2.6-.3L12 18l-2.1-1.6-2.6.3-1-2.4L4 13.1l.6-2.6L4 7.9l2.3-1.2 1-2.4 2.6.3L12 3Z"/>
      <path d="m9.2 10.8 1.9 1.9 3.7-3.7"/>
      @break
    @case('grafico')
      <path d="M4 20V4M4 20h16"/>
      <path d="M8 16v-4M12 16V7M16 16v-6M20 16v-9"/>
      @break
    @case('reloj')
      <circle cx="12" cy="12" r="8.5"/>
      <path d="M12 7.5V12l3 1.8"/>
      @break
    @case('camara')
      <path d="M4 8.5h3l1.4-2h7.2l1.4 2H20a1 1 0 0 1 1 1V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5a1 1 0 0 1 1-1Z"/>
      <circle cx="12" cy="13" r="3.2"/>
      @break
    @case('chat')
      <path d="M20 12.5c0 3.3-3.4 6-7.5 6a9 9 0 0 1-2.5-.35L5 20l1.2-3.1A5.6 5.6 0 0 1 5 12.5c0-3.3 3.4-6 7.5-6s7.5 2.7 7.5 6Z"/>
      @break
    @case('documento')
      <path d="M14 3H7a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V7l-4-4Z"/>
      <path d="M14 3v4h4M9 12h6M9 16h4"/>
      @break
    @case('moneda')
      <circle cx="12" cy="12" r="8.5"/>
      <path d="M14.5 9.3A3 3 0 0 0 12 8c-1.7 0-2.6.9-2.6 1.9 0 2.7 5.2 1.3 5.2 4.1 0 1.1-1 2-2.6 2a3 3 0 0 1-2.5-1.3M12 6.4v11.2"/>
      @break
    @case('estrella')
      <path d="m12 4 2.5 5.1 5.5.8-4 3.9.95 5.6L12 16.7 7.05 19.4 8 13.8 4 9.9l5.5-.8L12 4Z"/>
      @break
    @default
      {{-- Desconocido: un punto de marca. No finge ser nada. --}}
      <circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>
  @endswitch
</svg>
