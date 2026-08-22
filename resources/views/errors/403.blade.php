<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sin permiso · LATAM Social</title>
  <link rel="icon" href="{{ asset('img/brand/favicon.svg') }}">
  @vite('resources/css/app.css')
</head>
<body class="h-full bg-slate-50 font-sans antialiased">
<div class="min-h-full flex items-center justify-center p-6">
  <div class="w-full max-w-md bg-white rounded-2xl p-8 shadow-xl text-center">
    <div class="w-12 h-12 rounded-xl degradado-marca mx-auto mb-5"></div>

    <h1 class="text-xl font-semibold text-slate-900 mb-2">No tienes permiso</h1>

    {{-- Se dice QUÉ falta, no solo que no se puede: quien lo lea tiene que
         poder pedir el permiso correcto sin abrir una incidencia a ciegas. --}}
    <p class="text-slate-600 text-sm mb-1">
      {{ ($exception ?? null)?->getMessage() ?: 'Esta sección requiere un permiso que tu usuario no tiene.' }}
    </p>
    <p class="text-slate-400 text-xs mb-6">
      Si necesitas acceso, pídelo al administrador indicando la pantalla.
    </p>

    <a href="{{ route('panel') }}"
       class="inline-block px-5 py-2.5 rounded-xl bg-navy text-white text-sm font-medium hover:opacity-90">
      Volver al panel
    </a>
  </div>
</div>
</body>
</html>
