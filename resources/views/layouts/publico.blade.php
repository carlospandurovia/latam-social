{{-- La plantilla de la calle (9.21b).

     Separada de `layouts.panel` a propósito: aquí no hay menú, ni sesión, ni
     nada que suponga que quien mira ya sabe qué es esto. Lo único que comparte
     con el panel es la marca —logotipo, colores y tipografía— porque es la misma
     empresa y tiene que parecerlo.

     El `<title>` y la descripción salen de la portada, no de la plantilla: son
     lo primero que ve alguien que llega desde una búsqueda o desde un enlace
     compartido por WhatsApp. --}}
<!DOCTYPE html>
{{-- L-1: el idioma sale del de la aplicacion y no escrito a mano: `§26`
     pide que traducir manana no obligue a tocar plantillas. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', $marca['nombre'])</title>

  {{-- L-1: lo que faltaba para estrenar un dominio.

       Lo mas caro que faltaba era `og:image`: la imagen existia
       --`public/img/brand/og-image.png`, 92 KB, hecha a proposito-- y NO LA
       REFERENCIABA NADIE. Compartir el enlace por WhatsApp o LinkedIn producia
       una tarjeta sin imagen, y este sitio va a recibir su trafico justamente
       de ahi: una fuga de clics en el primer metro.

       El canonico se arma con `url()->current()` y no con la ruta con nombre,
       porque tiene que ser la URL de ESTA pagina --incluida `/creadores`-- y
       porque en produccion sale con el dominio y el `https` de `APP_URL`. --}}
  @hasSection('descripcion')
    <meta name="description" content="@yield('descripcion')">
    <meta property="og:description" content="@yield('descripcion')">
    <meta name="twitter:description" content="@yield('descripcion')">
  @endif
  <link rel="canonical" href="{{ url()->current() }}">
  <meta property="og:title" content="@yield('titulo', $marca['nombre'])">
  <meta property="og:type" content="website">
  <meta property="og:url" content="{{ url()->current() }}">
  <meta property="og:site_name" content="{{ $marca['nombre'] }}">
  <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">
  <meta property="og:image" content="{{ asset('img/brand/og-image.png') }}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="@yield('titulo', $marca['nombre'])">
  <meta name="twitter:image" content="{{ asset('img/brand/og-image.png') }}">
  <link rel="manifest" href="{{ asset('img/brand/site.webmanifest') }}">
  @include('parciales.marca')

  {{-- L-6 (§20): quien es esta empresa, en el formato que leen los buscadores.
       Todo sale de la configuracion; nada escrito a mano. --}}
  @include('parciales.organizacion')

  {{-- L-5 (§21): la medicion, si esta configurada Y si esta maquina es la de
       verdad. Va en el `<head>` porque un medidor cargado despues del contenido
       pierde las visitas que se van antes de que termine de pintar. --}}
  @include('parciales.analitica')
</head>
<body class="h-full bg-white text-slate-800 font-sans antialiased">

  {{-- L-3: el salto al contenido.

       Lo primero que hay en el `<body>` y lo unico que se ve al tabular desde
       cero. Sin el, quien navega con teclado tiene que pasar por el logotipo,
       cuatro anclas, dos enlaces y un boton antes de llegar al titular; y en
       movil, ademas, por el panel entero. --}}
  <a href="#contenido" class="salto-al-contenido">{{ __('publico.saltar') }}</a>

  {{-- L-3: la cabecera que acompana.

       ### Que arregla

       `C-1`: no tenia CTA comercial. Sus dos unicas acciones eran «Soy creador»
       --publico secundario-- y «Entrar» --quien ya es cliente--, asi que quien
       llegaba, bajaba y no se decidia **no tenia a donde volver**.

       `V-6`: en movil no habia menu, ni anclas, ni boton, y «Soy creador»
       partia en dos lineas apretando contra «Entrar».

       ### La jerarquia, que es lo que hace que lo secundario no compita (§7)

       1. El boton comercial, con el degradado de marca.
       2. Las anclas, en gris, solo en escritorio.
       3. «Entrar», con borde y sin relleno.
       4. «Soy creador», texto pequeno y el ultimo.

       ### Por que se pega

       Porque la conversion esta al final de un scroll largo. Pegada, la accion
       viaja con quien lee. `backdrop-blur` con fondo translucido y no un blanco
       macizo: encima del degradado del heroe, un bloque blanco opaco corta la
       imagen en dos.

       ### Y por que el panel movil es `<details>` y no JavaScript

       Porque el navegador ya sabe abrirlo y cerrarlo, con teclado y con lector
       de pantalla, sin una linea de script. El unico script de esta plantilla
       --nueve lineas al final del `<body>`-- lo cierra al pulsar un ancla, que
       es lo unico que el navegador no hace solo. --}}
  <header class="cabecera-pegada">
    <div class="mx-auto max-w-6xl px-6 h-16 sm:h-20 flex items-center justify-between gap-4">
      {{-- L-1: el logotipo HORIZONTAL, y sin repetir el nombre al lado: el
           horizontal ya lleva el wordmark dentro, asi que ponerlo dos veces era
           decir «LATAM Social LATAM Social» --y un lector de pantalla lo leia
           asi de verdad--. `docs/14 §7`. --}}
      <a href="{{ route('portada.marcas') }}" class="flex shrink-0 items-center">
        @include('parciales.marca-logo', ['variante' => 'horizontal', 'clase' => 'h-8 sm:h-9 w-auto'])
      </a>

      {{-- --------------------------------------------------------- escritorio --}}
      <nav class="hidden lg:flex items-center gap-6 text-sm" aria-label="{{ __('publico.secciones') }}">
        @foreach ($navCabecera as $seccion)
          <a href="{{ $seccion->base ?? '' }}#{{ $seccion->code }}" class="enlace-nav">{{ $seccion->title }}</a>
        @endforeach
      </nav>

      <div class="hidden lg:flex items-center gap-4 text-sm">
        {{-- El enlace cruzado. Es lo que hace que una sola portada no deje
             fuera a la mitad de quien llega --pero el ultimo, y en gris: es
             publico secundario y no puede competir con el boton. --}}
        @if ($esDeCreadores ?? false)
          <a href="{{ route('portada.marcas') }}" class="text-slate-500 hover:text-slate-800">{{ __('publico.soy_marca') }}</a>
        @else
          <a href="{{ route('portada.creadores') }}" class="text-slate-500 hover:text-slate-800">{{ __('publico.soy_creador') }}</a>
        @endif

        <a href="{{ route('acceso') }}"
           class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 transition hover:border-slate-400 hover:bg-slate-50">
          {{ __('publico.entrar') }}
        </a>

        @if ($portadaCabecera)
          <a href="{{ $portadaCabecera->cta_url ?: (($esDeCreadores ?? false) ? route('portada.marcas').'#empezar' : '#empezar') }}"
             data-evento="cta_cabecera" class="boton-marca boton-marca-sm">
            {{ $portadaCabecera->cta_label }}
          </a>
        @endif
      </div>

      {{-- -------------------------------------------------------------- movil --}}
      <details class="panel-movil lg:hidden">
        <summary aria-label="{{ __('publico.menu') }}" class="flex h-10 w-10 cursor-pointer list-none items-center justify-center
                                          rounded-lg border border-slate-300 text-slate-700">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
               stroke-linecap="round" aria-hidden="true" class="h-5 w-5 icono-abrir">
            <path d="M4 7h16M4 12h16M4 17h16"/>
          </svg>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
               stroke-linecap="round" aria-hidden="true" class="h-5 w-5 icono-cerrar">
            <path d="M6 6l12 12M18 6 6 18"/>
          </svg>
        </summary>

        <div class="cajon-movil">
          <nav class="flex flex-col divide-y divide-slate-100" aria-label="{{ __('publico.secciones') }}">
            @foreach ($navCabecera as $seccion)
              <a href="{{ $seccion->base ?? '' }}#{{ $seccion->code }}"
                 class="py-3 text-sm font-medium text-slate-700">{{ $seccion->title }}</a>
            @endforeach

            @if ($esDeCreadores ?? false)
              <a href="{{ route('portada.marcas') }}" class="py-3 text-sm text-slate-500">{{ __('publico.soy_marca') }}</a>
            @else
              <a href="{{ route('portada.creadores') }}" class="py-3 text-sm text-slate-500">{{ __('publico.soy_creador') }}</a>
            @endif

            <a href="{{ route('acceso') }}" class="py-3 text-sm text-slate-500">{{ __('publico.entrar') }}</a>
          </nav>

          @if ($portadaCabecera)
            <a href="{{ $portadaCabecera->cta_url ?: (($esDeCreadores ?? false) ? route('portada.marcas').'#empezar' : '#empezar') }}"
               data-evento="cta_cabecera_movil" class="boton-marca mt-4 w-full justify-center">
              {{ $portadaCabecera->cta_label }}
            </a>
          @endif

          {{-- L-2a: el WhatsApp sale de «Sitio publico», nunca escrito aqui
               (§23). Si no esta configurado NO se pinta un enlace roto. --}}
          @if ($sitio['whatsappUrl'])
            <a href="{{ $sitio['whatsappUrl'] }}" target="_blank" rel="noopener"
               data-evento="whatsapp_cabecera_movil"
               class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200
                      py-3 text-sm font-medium text-slate-700">
              @include('parciales.icono', ['icono' => 'chat', 'clase' => 'h-4 w-4'])
              {{ __('publico.whatsapp_escribenos') }}
            </a>
          @endif
        </div>
      </details>
    </div>
  </header>

  <main id="contenido">
    @yield('contenido')
  </main>

  {{-- L-2a: el pie deja de ser una linea. Todo lo que sale aqui --el correo, el
       telefono, la direccion, las redes-- viene de «Sitio publico» en el admin;
       lo que no este configurado NO deja un hueco ni un enlace roto: no se
       pinta. Es la misma regla que el logotipo en `9.17` --una imagen rota es
       peor que ninguna imagen--. --}}
  <footer class="mt-16 border-t border-slate-100 bg-slate-50">
    <div class="mx-auto max-w-6xl px-6 py-10">
      <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <a href="{{ route('portada.marcas') }}" class="flex items-center">
            @include('parciales.marca-logo', ['variante' => 'horizontal', 'clase' => 'h-11 w-auto'])
          </a>
          @if ($marca['lema'])
            <p class="mt-2 text-xs text-slate-500">{{ $marca['lema'] }}</p>
          @endif

          @if ($redesDelPie->isNotEmpty())
            <ul class="mt-4 flex gap-3">
              @foreach ($redesDelPie as $red)
                <li>
                  <a href="{{ $red->url }}" target="_blank" rel="noopener me"
                     title="{{ $red->label }}" aria-label="{{ $red->label }}"
                     class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                    @include('parciales.icono-red', ['red' => $red, 'clase' => 'h-5 w-5'])
                  </a>
                </li>
              @endforeach
            </ul>
          @endif
        </div>

        <div>
          <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-600">{{ __('publico.pie.plataforma') }}</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            <li><a href="{{ route('portada.marcas') }}" class="inline-block py-1.5 hover:text-slate-900">{{ __('publico.pie.para_marcas') }}</a></li>
            <li><a href="{{ route('portada.creadores') }}" class="inline-block py-1.5 hover:text-slate-900">{{ __('publico.pie.para_creadores') }}</a></li>
            <li><a href="{{ route('acceso') }}" class="inline-block py-1.5 hover:text-slate-900">{{ __('publico.entrar') }}</a></li>
          </ul>
        </div>

        {{-- L-1: la columna entera desaparece si no hay nada que poner. Un
             encabezado «Contacto» sobre un hueco es peor que no tener la
             columna: promete algo que no esta. Se vio mirando la pantalla. --}}
        @if ($sitio['correo'] || $sitio['whatsappUrl'] || $sitio['telefono'] || $sitio['direccion'])
        <div>
          <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-600">{{ __('publico.pie.contacto') }}</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            @if ($sitio['correo'])
              <li><a href="mailto:{{ $sitio['correo'] }}" class="inline-block py-1.5 hover:text-slate-900">{{ $sitio['correo'] }}</a></li>
            @endif
            @if ($sitio['whatsappUrl'])
              <li>
                <a href="{{ $sitio['whatsappUrl'] }}" target="_blank" rel="noopener"
                   data-evento="whatsapp_pie" class="inline-block py-1.5 hover:text-slate-900">{{ __('publico.pie.whatsapp') }}</a>
              </li>
            @endif
            @if ($sitio['telefono'])
              <li>{{ $sitio['telefono'] }}</li>
            @endif
            @if ($sitio['direccion'])
              <li class="text-slate-500">{{ $sitio['direccion'] }}</li>
            @endif
          </ul>
        </div>
        @endif

        {{-- L-2b: las paginas del sitio. Solo salen las que tienen texto
             PUBLICADO --`Paginas::delPie()` lo exige--: una pagina creada y sin
             publicar enlazada desde aqui es un 404 en la portada. Y la columna
             entera desaparece si no hay ninguna, como la de contacto. --}}
        @if ($paginasDelPie->isNotEmpty())
        <div>
          <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-600">{{ __('publico.pie.legal') }}</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            @foreach ($paginasDelPie as $p)
              <li>
                <a href="{{ route('pagina', ['slug' => $p->slug]) }}" class="inline-block py-1.5 hover:text-slate-900">
                  {{ $p->title }}
                </a>
              </li>
            @endforeach
          </ul>
        </div>
        @endif
      </div>

      <p {{-- L-7: `text-slate-600` y no `-400`. Medido: sobre `slate-50` daba
           2.51 : 1, cuando el minimo es 4.5, y es la linea que lleva la razon
           social y el RUC --lo unico del pie que hay obligacion de poder leer--. --}}
      <p class="mt-10 border-t border-slate-200 pt-6 text-xs text-slate-600">
        {{ $marca['pieLegal'] ?: $marca['nombre'] }}
      </p>
    </div>
  </footer>
  {{-- L-3: las nueve lineas de script de toda la portada.

       ### Que hacen

       1. Cierran el panel movil al pulsar un ancla. Es lo unico que `<details>`
          no hace solo, y sin ello quien pulsa «Cómo funciona» baja a la seccion
          con el menu abierto tapandosela.
       2. Encienden la entrada de las tarjetas. La clase `js` la pone ESTE
          script, asi que sin JavaScript --o si falla-- todo se ve desde el
          primer momento: la animacion nunca puede esconder contenido.

       ### Y lo que NO hacen

       No hay libreria, ni `defer` de nada externo, ni proveedor de analitica
       atado (§21): los `data-evento` estan puestos en el HTML y quien conecte
       Google, Meta o lo que sea los lee sin tocar una plantilla.

       `prefers-reduced-motion` se respeta en el CSS, no aqui: asi tambien vale
       para los `hover` y las transiciones, que este script no toca. --}}
  <script>
    document.querySelectorAll('.panel-movil a').forEach(function (a) {
      a.addEventListener('click', function () { a.closest('details').open = false; });
    });

    var revelables = document.querySelectorAll('.aparece');
    if (revelables.length && 'IntersectionObserver' in window &&
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      document.documentElement.classList.add('js');
      var mirador = new IntersectionObserver(function (entradas) {
        entradas.forEach(function (e) {
          if (e.isIntersecting) { e.target.classList.add('visible'); mirador.unobserve(e.target); }
        });
      }, { rootMargin: '0px 0px -10% 0px' });
      revelables.forEach(function (el) { mirador.observe(el); });
    }
  </script>
</body>

</html>
