<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Panel') · LATAM Social</title>
  <link rel="icon" href="{{ asset('img/brand/favicon.svg') }}">
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet">
  @vite('resources/css/app.css')
</head>
<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased">
<div class="min-h-full flex">

  {{-- Barra lateral --}}
  <aside class="w-64 shrink-0 bg-navy text-slate-300 flex flex-col">
    <div class="h-16 flex items-center gap-3 px-5 border-b border-white/10">
      <div class="w-8 h-8 rounded-lg degradado-marca"></div>
      <span class="font-bold text-white tracking-tight">LATAM Social</span>
    </div>

    <nav class="flex-1 px-3 py-4 space-y-1 text-sm">
      @php
        $secciones = [
          ['Panel', 'panel', 'panel'],
          ['Creadores', 'creadores.index', 'creadores'],
        ];
        $catalogos = [
          ['Países', 'countries'], ['Monedas', 'currencies'], ['Categorías', 'categories'],
          ['Redes sociales', 'platforms'], ['Formatos', 'content_formats'], ['Idiomas', 'languages'],
        ];
      @endphp

      @foreach ($secciones as [$texto, $ruta, $activo])
        <a href="{{ route($ruta) }}"
           class="block px-3 py-2 rounded-lg transition
                  {{ request()->routeIs($activo.'*') ? 'bg-marca-500 text-white font-medium' : 'hover:bg-white/5 hover:text-white' }}">
          {{ $texto }}
        </a>
      @endforeach

      <p class="px-3 pt-5 pb-1 text-[11px] uppercase tracking-wider text-slate-500">Catálogos</p>
      @foreach ($catalogos as [$texto, $tabla])
        <a href="{{ route('catalogos.show', $tabla) }}"
           class="block px-3 py-2 rounded-lg transition
                  {{ request()->route('catalogo') === $tabla ? 'bg-marca-500 text-white font-medium' : 'hover:bg-white/5 hover:text-white' }}">
          {{ $texto }}
        </a>
      @endforeach
    </nav>

    <div class="px-3 py-4 border-t border-white/10">
      <p class="px-3 text-sm text-white font-medium truncate">{{ auth()->user()->name }}</p>
      <p class="px-3 text-xs text-slate-400 truncate mb-2">{{ auth()->user()->email }}</p>
      <form method="POST" action="{{ route('salir') }}">
        @csrf
        <button class="w-full text-left px-3 py-2 rounded-lg text-sm hover:bg-white/5 hover:text-white transition">
          Cerrar sesión
        </button>
      </form>
    </div>
  </aside>

  {{-- Contenido --}}
  <div class="flex-1 min-w-0 flex flex-col">
    <header class="h-16 bg-white border-b border-slate-200 flex items-center px-8">
      <h1 class="text-lg font-semibold text-slate-900">@yield('titulo', 'Panel')</h1>
      @hasSection('subtitulo')
        <span class="ml-3 text-sm text-slate-500">@yield('subtitulo')</span>
      @endif
    </header>

    <main class="flex-1 p-8 overflow-x-auto">
      @if (session('mensaje'))
        <div class="mb-6 rounded-lg border border-marca-200 bg-marca-50 px-4 py-3 text-sm text-marca-800">
          {{ session('mensaje') }}
        </div>
      @endif
      @yield('contenido')
    </main>
  </div>
</div>
</body>
</html>
