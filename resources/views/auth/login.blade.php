<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar · LATAM Social</title>
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
      <h1 class="text-xl font-semibold text-slate-900 mb-1">Entrar</h1>
      <p class="text-sm text-slate-500 mb-6">Accede con tu cuenta interna.</p>

      @if ($errors->any())
        <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {{ $errors->first() }}
        </div>
      @endif

      <form method="POST" action="{{ route('entrar') }}" class="space-y-4">
        @csrf
        <div>
          <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Correo</label>
          <input id="email" name="email" type="email" required autofocus
                 value="{{ old('email') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                        focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
        </div>
        <div>
          <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Contraseña</label>
          <input id="password" name="password" type="password" required
                 class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm
                        focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" name="remember" class="rounded border-slate-300 text-marca-500 focus:ring-marca-200">
          Mantener la sesión
        </label>
        <button type="submit"
                class="w-full rounded-lg bg-marca-500 px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-marca-600 focus:ring-2 focus:ring-marca-300 focus:outline-none transition">
          Entrar
        </button>
      </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
      Soluciones Tecnológicas a Medida S.A.C. · RUC 20603203896
    </p>
  </div>
</div>
</body>
</html>
