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
  <title>@yield('titulo', 'Acceso') · {{ $marca['nombre'] }}</title>
  {{-- Que el token de un enlace de contraseña no salga de aquí dentro de una
       cabecera `Referer` hacia el dominio de las tipografías. La redirección a
       una URL limpia ya lo evita; esto es el cinturón del cinturón. --}}
  <meta name="referrer" content="same-origin">
  @include('parciales.marca')
</head>
<body class="h-full bg-navy font-sans antialiased">
<div class="min-h-full flex items-center justify-center p-6">
  <div class="w-full max-w-sm">

    <div class="flex items-center gap-3 mb-8">
      @include('parciales.marca-logo', ['clase' => 'w-11 h-11', 'conNombre' => true])
      <div>
        <p class="text-white font-bold text-lg leading-tight">{{ $marca['nombre'] }}</p>
        @if ($marca['lema'])
          <p class="text-slate-300 text-xs">{{ $marca['lema'] }}</p>
        @endif
      </div>
    </div>

    <div class="bg-white rounded-2xl p-7 shadow-xl">
      @yield('contenido')
    </div>

    {{-- L-7: `slate-300` y no `slate-400`. Medido sobre la barra: daba
         4.05 : 1 y el minimo es 4.5, y esta es la linea que lleva la razon
         social y el RUC.

         Y queda dicho lo que da por supuesto esta pantalla: **que la barra es
         oscura**. Lo da por supuesto desde `9.17` --el nombre de la marca sale
         en blanco-- y el color de la barra es configurable, asi que quien
         ponga una barra clara se queda sin poder leer nada aqui. `T-96`.

         9.17: el pie legal venia escrito aqui con la razon social y el RUC.
         Ahora sale de la marca, y si no hay ninguno no sale nada: inventar un
         pie legal es peor que no ponerlo. La pantalla de la marca lo avisa. --}}
    @if ($marca['pieLegal'])
      <p class="mt-6 text-center text-xs text-slate-300">{{ $marca['pieLegal'] }}</p>
    @endif
  </div>
</div>
</body>
</html>
