{{-- El marco de las pantallas a las que se llega SIN sesión: entrar, pedir un
     enlace y poner una contraseña.

     Existe porque a partir de `4.1` son tres y no una. Tres copias del mismo
     `<head>` es la forma segura de que un día el favicon o la tipografía cambien
     en dos de ellas. --}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Acceso') · LATAM Social</title>
  {{-- Que el token de un enlace de contraseña no salga de aquí dentro de una
       cabecera `Referer` hacia el dominio de las tipografías. La redirección a
       una URL limpia ya lo evita; esto es el cinturón del cinturón. --}}
  <meta name="referrer" content="same-origin">
  <link rel="icon" href="{{ asset('img/brand/favicon.svg') }}">
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet">
  @vite('resources/css/app.css')
</head>
<body class="h-full bg-navy font-sans antialiased">
<div class="min-h-full flex items-center justify-center p-6">
  <div class="w-full max-w-sm">

    <div class="flex items-center gap-3 mb-8">
      <div class="w-11 h-11 rounded-xl degradado-marca"></div>
      <div>
        <p class="text-white font-bold text-lg leading-tight">LATAM Social</p>
        <p class="text-slate-400 text-xs">Plataforma de Creator Marketing</p>
      </div>
    </div>

    <div class="bg-white rounded-2xl p-7 shadow-xl">
      @yield('contenido')
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
      Soluciones Tecnológicas a Medida S.A.C. · RUC 20603203896
    </p>
  </div>
</div>
</body>
</html>
