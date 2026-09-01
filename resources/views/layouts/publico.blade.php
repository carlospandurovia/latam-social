{{-- La plantilla de la calle (9.21b).

     Separada de `layouts.panel` a propósito: aquí no hay menú, ni sesión, ni
     nada que suponga que quien mira ya sabe qué es esto. Lo único que comparte
     con el panel es la marca —logotipo, colores y tipografía— porque es la misma
     empresa y tiene que parecerlo.

     El `<title>` y la descripción salen de la portada, no de la plantilla: son
     lo primero que ve alguien que llega desde una búsqueda o desde un enlace
     compartido por WhatsApp. --}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', $marca['nombre'])</title>
  @hasSection('descripcion')
    <meta name="description" content="@yield('descripcion')">
    <meta property="og:description" content="@yield('descripcion')">
  @endif
  <meta property="og:title" content="@yield('titulo', $marca['nombre'])">
  <meta property="og:type" content="website">
  @include('parciales.marca')
</head>
<body class="h-full bg-white text-slate-800 font-sans antialiased">

  <header class="border-b border-slate-100">
    <div class="mx-auto max-w-5xl px-6 h-16 flex items-center justify-between gap-4">
      <a href="{{ route('portada.marcas') }}" class="flex items-center gap-3">
        @include('parciales.marca-logo', ['clase' => 'w-8 h-8'])
        <span class="font-bold tracking-tight text-slate-900">{{ $marca['nombre'] }}</span>
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

  <footer class="mt-16 border-t border-slate-100">
    <div class="mx-auto max-w-5xl px-6 py-8 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
      <p>{{ $marca['pieLegal'] ?: $marca['nombre'] }}</p>
      <div class="flex gap-4">
        <a href="{{ route('portada.marcas') }}" class="hover:text-slate-700">Para marcas</a>
        <a href="{{ route('portada.creadores') }}" class="hover:text-slate-700">Para creadores</a>
        <a href="{{ route('acceso') }}" class="hover:text-slate-700">Entrar</a>
      </div>
    </div>
  </footer>
</body>
</html>
