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
</head>
<body class="h-full bg-white text-slate-800 font-sans antialiased">

  <header class="border-b border-slate-100">
    <div class="mx-auto max-w-5xl px-6 h-16 sm:h-20 flex items-center justify-between gap-4">
      {{-- L-1: el logotipo HORIZONTAL, y sin repetir el nombre al lado: el
           horizontal ya lleva el wordmark dentro, asi que ponerlo dos veces era
           decir «LATAM Social LATAM Social» --y un lector de pantalla lo leia
           asi de verdad--. `docs/14 §7`. --}}
      <a href="{{ route('portada.marcas') }}" class="flex items-center">
        @include('parciales.marca-logo', ['variante' => 'horizontal', 'clase' => 'h-8 sm:h-10 w-auto'])
      </a>

      <nav class="flex items-center gap-5 text-sm">
        {{-- El enlace cruzado. Es lo que hace que una sola portada no deje
             fuera a la mitad de quien llega. --}}
        @if ($esDeCreadores ?? false)
          <a href="{{ route('portada.marcas') }}" class="text-slate-600 hover:text-slate-900">Soy una marca</a>
        @else
          <a href="{{ route('portada.creadores') }}" class="text-slate-600 hover:text-slate-900">Soy creador</a>
        @endif
        <a href="{{ route('acceso') }}"
           class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:border-slate-400">
          Entrar
        </a>
      </nav>
    </div>
  </header>

  @yield('contenido')

  {{-- L-2a: el pie deja de ser una linea. Todo lo que sale aqui --el correo, el
       telefono, la direccion, las redes-- viene de «Sitio publico» en el admin;
       lo que no este configurado NO deja un hueco ni un enlace roto: no se
       pinta. Es la misma regla que el logotipo en `9.17` --una imagen rota es
       peor que ninguna imagen--. --}}
  <footer class="mt-16 border-t border-slate-100 bg-slate-50">
    <div class="mx-auto max-w-5xl px-6 py-10">
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
                     class="block text-slate-400 hover:text-slate-700">
                    @include('parciales.icono-red', ['red' => $red, 'clase' => 'h-5 w-5'])
                  </a>
                </li>
              @endforeach
            </ul>
          @endif
        </div>

        <div>
          <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Plataforma</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            <li><a href="{{ route('portada.marcas') }}" class="hover:text-slate-900">Para marcas</a></li>
            <li><a href="{{ route('portada.creadores') }}" class="hover:text-slate-900">Para creadores</a></li>
            <li><a href="{{ route('acceso') }}" class="hover:text-slate-900">Entrar</a></li>
          </ul>
        </div>

        {{-- L-1: la columna entera desaparece si no hay nada que poner. Un
             encabezado «Contacto» sobre un hueco es peor que no tener la
             columna: promete algo que no esta. Se vio mirando la pantalla. --}}
        @if ($sitio['correo'] || $sitio['whatsappUrl'] || $sitio['telefono'] || $sitio['direccion'])
        <div>
          <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Contacto</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            @if ($sitio['correo'])
              <li><a href="mailto:{{ $sitio['correo'] }}" class="hover:text-slate-900">{{ $sitio['correo'] }}</a></li>
            @endif
            @if ($sitio['whatsappUrl'])
              <li>
                <a href="{{ $sitio['whatsappUrl'] }}" target="_blank" rel="noopener"
                   data-evento="whatsapp_pie" class="hover:text-slate-900">WhatsApp</a>
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
          <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Legal</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            @foreach ($paginasDelPie as $p)
              <li>
                <a href="{{ route('pagina', ['slug' => $p->slug]) }}" class="hover:text-slate-900">
                  {{ $p->title }}
                </a>
              </li>
            @endforeach
          </ul>
        </div>
        @endif
      </div>

      <p class="mt-10 border-t border-slate-200 pt-6 text-xs text-slate-400">
        {{ $marca['pieLegal'] ?: $marca['nombre'] }}
      </p>
    </div>
  </footer>
</body>
</html>
