{{--
  La miga de pan de las pantallas de configuración (9.20).

  El desorden que arregla no era el orden del menú: era que estas pantallas
  **no decían de dónde venían**. Se entraba desde Configuración y se aterrizaba
  en algo que se veía igual que Campañas, con el menú marcando otra entrada.
  Con esto, cada una dice a qué pertenece y cómo se vuelve.

  Uso:  @include('parciales.miga', ['aqui' => 'Series y correlativos'])

  L-2b: con un escalón intermedio, para una pantalla que cuelga de otra área y
  no de Configuración directamente:

        @include('parciales.miga', [
            'aqui' => $pagina->title,
            'volver' => route('paginas.index'), 'volverA' => 'Páginas',
        ])
--}}
<nav class="mb-4 flex items-center gap-2 text-xs text-slate-500" aria-label="Dónde estoy">
  <a href="{{ route('configuracion') }}" class="hover:text-marca-700 hover:underline">Configuración</a>
  <span aria-hidden="true">›</span>
  @if (! empty($volver))
    <a href="{{ $volver }}" class="hover:text-marca-700 hover:underline">{{ $volverA }}</a>
    <span aria-hidden="true">›</span>
  @endif
  <span class="text-slate-700 font-medium">{{ $aqui }}</span>
</nav>
