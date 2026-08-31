{{-- El logotipo, o el cuadrado de color si todavía no hay ninguno (9.17).

     No se pinta un `<img>` a una ruta que devolvería 404: una imagen rota es
     peor que un cuadro de color, porque parece que el sistema está averiado.
     `Marca::datos()` devuelve `logo => null` cuando no hay archivo, y la
     pantalla de la marca lo dice en rojo. --}}
@if ($marca['logo'] !== null)
  <img src="{{ $marca['logo'] }}" alt="{{ $marca['nombre'] }}"
       class="{{ $clase ?? 'w-8 h-8' }} rounded-lg object-contain bg-white/5">
@else
  <div class="{{ $clase ?? 'w-8 h-8' }} rounded-lg degradado-marca"></div>
@endif
