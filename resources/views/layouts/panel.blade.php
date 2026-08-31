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
        //
        // 9.20 -- POR QUE ESTO ESTA AGRUPADO Y POR QUE HAY UNA SOLA
        // «CONFIGURACION»
        //
        // Hasta hoy eran veinticinco entradas en una lista plana, y nueve de
        // ellas --Marca, Terminos, Series, Integraciones, Tipos de cambio,
        // Politica de precios, Entidades legales, Correo y los seis catalogos--
        // estaban DOS VECES: aqui sueltas y dentro de `/configuracion`. Entrar
        // desde el panel de configuracion dejaba al usuario en una pantalla que
        // se veia igual que Campanas, con el menu marcando otra entrada. Se
        // perdia el sitio.
        //
        // Ahora el menu solo lleva lo que se usa PARA TRABAJAR, en tres grupos
        // por la clase de trabajo que es, y la configuracion tiene UNA puerta.
        // Lo que hay detras de esa puerta lo decide `Preparacion`, no esta
        // plantilla: por eso anadir un area nueva ya no exige acordarse de dos
        // sitios.
        $grupos = [
          ['Operación', [
            ['Panel', 'panel', 'panel', null],
            ['Solicitudes', 'solicitudes.index', 'solicitudes', 'creator.approve'],
            ['Creadores', 'creadores.index', 'creadores', 'creator.view'],
            ['Clientes', 'clientes.index', 'clientes', 'client.view'],
            // 7.1: detras de Clientes porque una campana se hace PARA un cliente
            // y su marca; sin cliente dado de alta no hay nada que ver aqui.
            ['Campañas', 'campanas.index', 'campanas', 'campaign.view'],
            // 8.3 y 8.7: las dos son la pantalla de un turno de trabajo --se
            // abren para vaciarlas-- y van seguidas porque son el mismo trabajo.
            ['Revisión', 'revision.cola', 'revision', 'content.review'],
            ['Verificación', 'verificacion.cola', 'verificacion', 'content.deliverable.view'],
          ]],
          ['Finanzas', [
            ['Lotes de pago', 'lotes.index', 'lotes', 'finance.view'],
            ['Conciliación', 'pagos.conciliar', 'pagos', 'finance.view'],
            // 9.10: permiso PROPIO y distinto de los otros dos. Quien concilia
            // pagos no tiene por que ver cuanto gana la empresa por campana, y
            // una entrada que aparece o no es la forma mas barata de que eso se
            // note (`BR-SEC-001`).
            ['Rentabilidad', 'rentabilidad.index', 'rentabilidad', 'campaign.view_margin'],
          ]],
          ['Registros', [
            // 4.9: las dos contestan la misma clase de pregunta --que paso y
            // cuando--, y el modo de fallo que importa («al creador no le llego
            // su enlace») lo descubre operaciones, no un desarrollador leyendo
            // `storage/logs`.
            ['Correos', 'correos.index', 'correos', 'comms.view'],
            ['Bitácora', 'bitacora', 'bitacora', 'audit.view'],
          ]],
        ];

        // La entrada de Configuracion se queda encendida en CUALQUIER pantalla
        // que cuelgue de ella. La lista de rutas sale del registro de areas
        // --`Preparacion::rutas()`-- y no de aqui: escribirla otra vez seria
        // volver a tenerla en dos sitios, que es lo que 9.20 vino a quitar.
        //
        // De `marca.index` se queda `marca.` y se pregunta por `marca.*`, para
        // que las rutas hermanas de una pantalla --`marca.logo`, `series.anular`--
        // tambien la enciendan. Con el punto, y no sin el: `marca*` encenderia
        // tambien `marcas.index`, que son las marcas de los CLIENTES y no tiene
        // nada que ver.
        $enConfiguracion = request()->routeIs('configuracion');
        foreach (\App\Shared\Config\Preparacion::rutas() as $rutaDeArea) {
          $familia = str_contains($rutaDeArea, '.') ? explode('.', $rutaDeArea)[0].'.*' : $rutaDeArea;
          $enConfiguracion = $enConfiguracion || request()->routeIs($familia);
        }
      @endphp

      @foreach ($grupos as [$titulo, $entradas])
        @php
          $visibles = array_filter($entradas, fn ($e) => $e[3] === null || auth()->user()->can($e[3]));
        @endphp
        @continue($visibles === [])

        <p class="px-3 pt-4 first:pt-0 pb-1 text-[11px] uppercase tracking-wider text-slate-500">
          {{ $titulo }}
        </p>
        @foreach ($visibles as [$texto, $ruta, $activo, $permiso])
          <a href="{{ route($ruta) }}"
             class="block px-3 py-2 rounded-lg transition
                    {{ request()->routeIs($activo.'*') ? 'bg-marca-500 text-white font-medium' : 'hover:bg-white/5 hover:text-white' }}">
            {{ $texto }}
          </a>
        @endforeach
      @endforeach

      @can('config.view')
        <p class="px-3 pt-4 pb-1 text-[11px] uppercase tracking-wider text-slate-500">Ajustes</p>
        <a href="{{ route('configuracion') }}"
           class="block px-3 py-2 rounded-lg transition
                  {{ $enConfiguracion ? 'bg-marca-500 text-white font-medium' : 'hover:bg-white/5 hover:text-white' }}">
          Configuración
        </a>
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

    {{-- 9.19: mientras un creador no acepte los términos vigentes, la franja
         está ahí. No es un popup: un popup se cierra sin leerlo y no vuelve
         hasta la siguiente sesión. --}}
    @if (($avisoTerminos ?? null) !== null)
      <div class="px-8 pt-6">
        <div class="rounded-xl border px-4 py-3 text-sm
          {{ $avisoTerminos['estado'] === 'pendiente'
              ? 'bg-amber-50 border-amber-200 text-amber-900'
              : 'bg-rose-50 border-rose-200 text-rose-900' }}">
          @if ($avisoTerminos['estado'] === 'pendiente')
            Hay una versión nueva de los términos.
            Te quedan <strong>{{ $avisoTerminos['dias'] }}</strong>
            {{ $avisoTerminos['dias'] === 1 ? 'día' : 'días' }} para aceptarla.
          @elseif ($avisoTerminos['estado'] === 'solo_lectura')
            El plazo para aceptar los términos terminó el {{ $avisoTerminos['limite'] }}:
            puedes mirar, pero no cambiar nada.
          @else
            Hace falta aceptar los términos para continuar.
          @endif
          <a href="{{ route('terminos.mios') }}" class="ml-1 font-medium underline">Leerlos y aceptar</a>
        </div>
      </div>
    @endif

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
