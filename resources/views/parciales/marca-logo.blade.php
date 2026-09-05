{{-- El logotipo (9.17, reescrito en L-1).

     ### Lo que cambia

     Hasta `L-1` esto dibujaba un **cuadrado de degradado** cuando no había
     archivo subido, con un razonamiento correcto —«no se pinta un `<img>` a una
     ruta que devolvería 404»— y una premisa falsa: **sí había un logotipo**. El
     kit está en `public/img/brand/` desde el 22 de agosto y no lo referenciaba
     nadie, así que el logotipo de LATAM Social no había salido nunca a la calle.

     ### Dos variantes, y no es estética

     `docs/14 §7`: el **horizontal** en las landings públicas —«es donde la marca
     tiene que explicarse»— y el **isotipo** en el back-office, junto al nombre en
     texto. El horizontal mide 1122×530: metido en un hueco cuadrado con
     `object-contain` queda del alto de un sello.

     ### Parámetros

     - `variante` — `isotipo` (por defecto) u `horizontal`.
     - `clase` — las utilidades de tamaño. El horizontal quiere `h-8 w-auto`.
     - `conNombre` — `true` si el nombre va escrito al lado. Entonces el `alt` va
       vacío: si no, un lector de pantalla dice «LATAM Social LATAM Social». --}}
@php($fuente = ($variante ?? 'isotipo') === 'horizontal' ? $marca['logo'] : $marca['isotipo'])
<img src="{{ $fuente }}"
     alt="{{ ($conNombre ?? false) ? '' : $marca['nombre'] }}"
     @if ($conNombre ?? false) aria-hidden="true" @endif
     class="{{ $clase ?? 'w-8 h-8' }} object-contain">
