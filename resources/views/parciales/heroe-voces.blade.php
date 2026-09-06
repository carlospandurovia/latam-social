{{-- La composición del héroe: muchas voces, una campaña (L-4).

     ### Por qué existe

     `V-2` de la auditoría: el héroe tenía **la mitad derecha vacía** en
     escritorio —un titular sobre 800 px de degradado liso— y `V-1` decía que la
     portada entera no tiene ni una imagen.

     ### Y por qué NO es una fotografía

     Porque no tenemos ninguna que sea nuestra. Poner caras de banco de imágenes
     junto a «creadores reales» es exactamente la clase de mentira que el §12
     prohíbe: no es una métrica inventada, pero se lee igual de falso, y lo nota
     cualquiera que haya visto esa foto en otro sitio. El día que haya sesiones
     de campañas de verdad, esto se sustituye por ellas.

     Lo que se dibuja **no decora: explica el modelo**. Muchos puntos pequeños
     —comunidades— unidos por líneas finas a uno solo: una marca. Es el titular
     hecho dibujo, y no afirma nada que no sea cierto.

     ### Detalles que importan

     - `aria-hidden`: no dice nada que el titular no diga ya. Un lector de
       pantalla que anunciara «gráfico» aquí sólo estorbaría.
     - Sólo desde `lg`. En móvil compite con el titular y el botón, que es lo
       único que tiene que hacer trabajo ahí.
     - El movimiento es lentísimo y se apaga con `prefers-reduced-motion`, que se
       respeta en el CSS de `parciales.marca`. --}}
<svg viewBox="0 0 420 420" fill="none" aria-hidden="true" class="voces h-full w-full max-w-lg">
  {{-- Las lineas primero: van DEBAJO de los puntos. --}}
  <g stroke="rgb(255 255 255 / .28)" stroke-width="1">
    @foreach ([[70,70],[330,60],[60,210],[360,190],[95,340],[320,340],[210,45],[200,375],[40,130],[380,290]] as $p)
      <line x1="210" y1="210" x2="{{ $p[0] }}" y2="{{ $p[1] }}"/>
    @endforeach
  </g>

  {{-- Las voces. Tamanos distintos a proposito: son comunidades distintas. --}}
  @foreach ([[70,70,15],[330,60,11],[60,210,18],[360,190,13],[95,340,12],[320,340,17],[210,45,9],[200,375,14],[40,130,8],[380,290,10]] as $i => $p)
    <circle class="voz" style="--retraso: {{ $i * 320 }}ms"
            cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="{{ $p[2] }}"
            fill="rgb(255 255 255 / .22)" stroke="rgb(255 255 255 / .55)" stroke-width="1.5"/>
  @endforeach

  {{-- Y la campana: una sola, en el centro, la unica solida. --}}
  <circle cx="210" cy="210" r="46" fill="rgb(255 255 255 / .16)" stroke="rgb(255 255 255 / .7)" stroke-width="2"/>
  <circle cx="210" cy="210" r="14" fill="#fff"/>
</svg>
