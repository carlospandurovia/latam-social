{{-- El botón de una franja: el CTA intermedio del defecto `C-6` (L-3).

     Sin él hay un solo punto de conversión, al final de un scroll largo: quien
     se convence en el minuto uno tiene que seguir bajando para poder escribir.

     Vacío no pinta nada. Y `cta_url` vacía lleva al formulario de la propia
     página, igual que en `landing_pages`: es el destino por defecto y no hace
     falta escribirlo cada vez. --}}
@if ($s->cta_label)
  <div class="mt-8 {{ $centrado ?? false ? 'text-center' : '' }}">
    <a href="{{ $s->cta_url ?: '#empezar' }}"
       @if (str_starts_with((string) $s->cta_url, 'https://')) target="_blank" rel="noopener" @endif
       data-evento="cta_franja_{{ $s->code }}"
       class="boton-marca">
      {{ $s->cta_label }}
    </a>
  </div>
@endif
