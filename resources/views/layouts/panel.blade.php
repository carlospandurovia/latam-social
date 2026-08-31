<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Panel') · {{ $marca['nombre'] }}</title>
  @include('parciales.marca')
</head>
<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased">
<div class="min-h-full flex">

  {{-- Barra lateral --}}
  <aside class="w-64 shrink-0 bg-navy text-slate-300 flex flex-col">
    <div class="h-16 flex items-center gap-3 px-5 border-b border-white/10">
      @include('parciales.marca-logo', ['clase' => 'w-8 h-8'])
      <span class="font-bold text-white tracking-tight">{{ $marca['nombre'] }}</span>
    </div>

    <nav class="flex-1 px-3 py-4 space-y-1 text-sm">
      @php
        // El permiso que exige cada entrada. Un menú que muestra enlaces que
        // devuelven 403 enseña al usuario a desconfiar de lo que ve; y ocultar
        // sin comprobar en la ruta sería seguridad de decorado. Se hacen las dos
        // cosas: la ruta manda, el menú acompaña.
        $secciones = [
          ['Panel', 'panel', 'panel', null],
          ['Solicitudes', 'solicitudes.index', 'solicitudes', 'creator.approve'],
          ['Creadores', 'creadores.index', 'creadores', 'creator.view'],
          ['Clientes', 'clientes.index', 'clientes', 'client.view'],
          // 7.1: va detras de Clientes porque una campana se hace PARA un
          // cliente y su marca; sin cliente dado de alta no hay nada que ver aqui.
          ['Campañas', 'campanas.index', 'campanas', 'campaign.view'],
          // 8.3: detrás de Campañas porque es lo que llega DE ellas, y con
          // entrada propia porque es la pantalla de un turno de trabajo: se
          // abre para vaciarla, no para consultar algo.
          ['Revisión', 'revision.cola', 'revision', 'content.review'],
          // 8.7: detrás de Revisión porque es el paso siguiente del mismo
          // trabajo, y con entrada propia porque también se abre para vaciarla.
          ['Verificación', 'verificacion.cola', 'verificacion', 'content.deliverable.view'],
          // 9.17: la primera de las tres de configuracion, y delante de las
          // otras dos porque es la que se toca el primer dia: sin marca, la
          // plataforma se ensena con los valores de partida.
          ['Marca', 'marca.index', 'marca.', 'brand.manage'],
          // 4.5: la pantalla a la que BR-LE-004 lleva mandando desde 4.1.
          // `legal_entity.manage` es solo de admin, asi que el resto no la ve.
          ['Entidades legales', 'entidades.index', 'entidades', 'legal_entity.manage'],
          // 9.16: junto a Entidades legales porque las dos son configuracion de
          // la plataforma y las dos las toca la misma persona.
          ['Términos', 'terminos.index', 'terminos', 'legal_entity.manage'],
          // 9.2: junto a Entidades legales porque las dos contestan «por que no
          // puedo cobrarle a este» --una por sociedad, otra por moneda-- y las
          // dos se tocan pocas veces al ano.
          ['Tipos de cambio', 'cambio.index', 'tipos-de-cambio', 'fx.manage'],
          // 9.6: delante de Tipos de cambio porque es trabajo de todos los meses
          // y aquello se toca dos veces al ano.
          ['Lotes de pago', 'lotes.index', 'lotes', 'finance.view'],
          // 9.7: detras de Lotes porque es el paso siguiente del mismo trabajo,
          // y con entrada propia porque se abre para vaciarla.
          ['Conciliación', 'pagos.conciliar', 'pagos', 'finance.view'],
          // 9.10: con permiso PROPIO y distinto de los tres de arriba. Quien
          // concilia pagos no tiene por que ver cuanto gana la empresa por
          // campana, y una entrada de menu que aparece o no es la forma mas
          // barata de que eso se note (BR-SEC-001).
          ['Rentabilidad', 'rentabilidad.index', 'rentabilidad', 'campaign.view_margin'],
          // 4.9: junto a la bitacora porque responde a la misma clase de
          // pregunta --que paso y cuando-- y porque el modo de fallo que importa
          // («al creador no le llego su enlace») lo descubre operaciones, no un
          // desarrollador leyendo storage/logs.
          ['Correos', 'correos.index', 'correos', 'comms.view'],
          ['Bitácora', 'bitacora', 'bitacora', 'audit.view'],
        ];
        $catalogos = [
          ['Países', 'countries'], ['Monedas', 'currencies'], ['Categorías', 'categories'],
          ['Redes sociales', 'platforms'], ['Formatos', 'content_formats'], ['Idiomas', 'languages'],
        ];
      @endphp

      @foreach ($secciones as [$texto, $ruta, $activo, $permiso])
        @continue($permiso !== null && ! auth()->user()->can($permiso))
        <a href="{{ route($ruta) }}"
           class="block px-3 py-2 rounded-lg transition
                  {{ request()->routeIs($activo.'*') ? 'bg-marca-500 text-white font-medium' : 'hover:bg-white/5 hover:text-white' }}">
          {{ $texto }}
        </a>
      @endforeach

      @can('catalog.view')
      <p class="px-3 pt-5 pb-1 text-[11px] uppercase tracking-wider text-slate-500">Catálogos</p>
      @foreach ($catalogos as [$texto, $tabla])
        <a href="{{ route('catalogos.show', $tabla) }}"
           class="block px-3 py-2 rounded-lg transition
                  {{ request()->route('catalogo') === $tabla ? 'bg-marca-500 text-white font-medium' : 'hover:bg-white/5 hover:text-white' }}">
          {{ $texto }}
        </a>
      @endforeach
      @endcan
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
