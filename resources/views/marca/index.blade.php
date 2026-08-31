@extends('layouts.panel')
@section('titulo', 'Marca')
@section('subtitulo', 'El nombre, el logotipo y los colores de esta plataforma')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Marca'])

  {{-- Los avisos NO bloquean: informan y se ordenan por prioridad. Es el
       criterio de DEC-190 — «no me digas que algo es un stopper; ponme
       prioridades y un badge en rojo o amarillo según la importancia». Rojo es
       lo que un tercero va a ver mal; ámbar es lo que conviene y todavía se
       sostiene con el valor de partida. --}}
  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso['nivel'] === 'rojo'
          ? 'bg-rose-50 border-rose-200 text-rose-900'
          : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso['nivel'] === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso['nivel'] === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso['texto'] }}
    </div>
  @endforeach

  @if (! $avisos)
    <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
      <span class="inline-block rounded bg-emerald-600 px-1.5 py-0.5 text-xs font-semibold uppercase text-white mr-2">
        Listo
      </span>
      La marca está completa: nombre, logotipo, favicon, correo de soporte, web y pie legal.
    </div>
  @endif

  @if (session('exito'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  <form method="POST" action="{{ route('marca.update') }}" enctype="multipart/form-data"
        class="grid gap-5 lg:grid-cols-3">
    @csrf
    @method('PUT')

    <div class="lg:col-span-2 space-y-5">

      {{-- ---------------------------------------------------------- Identidad --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="text-sm font-semibold">Identidad</h2>
          <span class="text-xs text-slate-400">Sale en la barra, en la pestaña y en la pantalla de acceso</span>
        </div>

        <div>
          <label for="name" class="block text-xs text-slate-500 mb-1">Nombre de la plataforma</label>
          <input id="name" name="name" required maxlength="120"
                 value="{{ old('name', $fila?->name ?? $marca['nombre']) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
          <label for="tagline" class="block text-xs text-slate-500 mb-1">
            Lema <span class="text-slate-400">— la línea corta bajo el nombre</span>
          </label>
          <input id="tagline" name="tagline" maxlength="160" placeholder="Plataforma de Creator Marketing"
                 value="{{ old('tagline', $fila?->tagline) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
          <label for="legal_footer" class="block text-xs text-slate-500 mb-1">
            Pie legal <span class="text-slate-400">— razón social y RUC, al pie de la pantalla de acceso</span>
          </label>
          <input id="legal_footer" name="legal_footer" maxlength="255"
                 value="{{ old('legal_footer', $fila?->legal_footer) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-400">
            Los datos completos de la sociedad que factura —domicilio, representante legal— viven en
            <a href="{{ route('entidades.index') }}" class="text-marca-700 hover:underline">Entidades legales</a>.
            Esto es sólo la línea que acompaña a la marca.
          </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="website" class="block text-xs text-slate-500 mb-1">Web</label>
            <input id="website" name="website" maxlength="255" placeholder="https://…"
                   value="{{ old('website', $fila?->website) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label for="support_email" class="block text-xs text-slate-500 mb-1">Correo de soporte</label>
            <input id="support_email" name="support_email" type="email" maxlength="255"
                   value="{{ old('support_email', $fila?->support_email) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-slate-400">A donde escribe un creador cuando algo le falla.</p>
          </div>
        </div>
      </div>

      {{-- ------------------------------------------------------------ Archivos --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="text-sm font-semibold">Logotipo y favicon</h2>
          <span class="text-xs text-slate-400">
            {{ mb_strtoupper(implode(', ', $extensiones)) }} · hasta {{ number_format($maxKb / 1024, 0) }} MB
          </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="logo" class="block text-xs text-slate-500 mb-1">Logotipo</label>
            <div class="flex items-center gap-3 mb-2">
              @if ($marca['logo'])
                <img src="{{ $marca['logo'] }}" alt="Logotipo actual"
                     class="w-12 h-12 rounded-lg object-contain border border-slate-200 bg-slate-50">
                <span class="text-xs text-slate-500">Actual</span>
              @else
                <div class="w-12 h-12 rounded-lg degradado-marca"></div>
                <span class="text-xs text-rose-700">Sin logotipo: se dibuja este cuadrado</span>
              @endif
            </div>
            <input id="logo" name="logo" type="file" accept="image/*"
                   class="w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0
                          file:bg-slate-100 file:px-3 file:py-2 file:text-sm">
          </div>

          <div>
            <label for="favicon" class="block text-xs text-slate-500 mb-1">Favicon</label>
            <div class="flex items-center gap-3 mb-2">
              <img src="{{ $marca['favicon'] }}" alt="Favicon actual"
                   class="w-8 h-8 rounded object-contain border border-slate-200 bg-slate-50">
              <span class="text-xs text-slate-500">
                {{ $fila?->favicon_file_id ? 'Propio' : 'Prestado del logotipo o del de partida' }}
              </span>
            </div>
            <input id="favicon" name="favicon" type="file" accept="image/*"
                   class="w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0
                          file:bg-slate-100 file:px-3 file:py-2 file:text-sm">
            <p class="mt-1 text-xs text-slate-400">Cuadrado. El icono de la pestaña es 32×32.</p>
          </div>
        </div>

        <p class="text-xs text-slate-400">
          El archivo anterior no se borra: queda en el archivo con su huella, por si hay que
          demostrar qué marca se enseñaba en una fecha.
        </p>
      </div>

      {{-- -------------------------------------------------------------- Colores --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="text-sm font-semibold">Colores y tipografía</h2>
          <span class="text-xs text-slate-400">Se aplican en cuanto guardas, sin desplegar nada</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          @foreach ([
            ['primary_color', 'Color de marca', $marca['color'], 'Enlaces, resaltados y el primer tono del degradado'],
            ['secondary_color', 'Color secundario', $marca['color2'], 'El segundo tono del degradado'],
            ['sidebar_color', 'Color de la barra', $marca['barra'], 'El fondo de la barra lateral y de la pantalla de acceso'],
          ] as [$campo, $etiqueta, $valor, $nota])
            <div>
              <label for="{{ $campo }}" class="block text-xs text-slate-500 mb-1">{{ $etiqueta }}</label>
              <div class="flex items-center gap-2">
                <input type="color" value="{{ old($campo, $fila?->{$campo} ?? $valor) }}"
                       oninput="document.getElementById('{{ $campo }}').value = this.value"
                       class="h-9 w-10 rounded border border-slate-300 bg-white p-0.5">
                <input id="{{ $campo }}" name="{{ $campo }}" maxlength="7" placeholder="#RRGGBB"
                       value="{{ old($campo, $fila?->{$campo}) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
              </div>
              <p class="mt-1 text-xs text-slate-400">{{ $nota }}</p>
            </div>
          @endforeach
        </div>

        <div>
          <label for="font_family" class="block text-xs text-slate-500 mb-1">Tipografía</label>
          <input id="font_family" name="font_family" maxlength="80" placeholder="{{ $marca['tipografia'] }}"
                 value="{{ old('font_family', $fila?->font_family) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-400">
            El nombre de la familia tal como aparece en fonts.bunny.net. Sólo letras, números y
            espacios: lo que se escribe aquí acaba en una URL y en una hoja de estilo.
          </p>
        </div>
      </div>

      <button class="rounded-lg bg-navy px-5 py-2.5 text-sm font-medium text-white hover:opacity-90">
        Guardar la marca
      </button>
    </div>

    {{-- ------------------------------------------------------------- Lo que se ve --}}
    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Así se ve ahora</h2>
        </div>
        <div class="p-5">
          <div class="rounded-xl overflow-hidden border border-slate-200">
            <div class="bg-navy p-4">
              <div class="flex items-center gap-3">
                @include('parciales.marca-logo', ['clase' => 'w-9 h-9'])
                <div>
                  <p class="text-white font-bold text-sm leading-tight">{{ $marca['nombre'] }}</p>
                  @if ($marca['lema'])
                    <p class="text-slate-400 text-xs">{{ $marca['lema'] }}</p>
                  @endif
                </div>
              </div>
            </div>
            <div class="bg-marca-50 border-t border-marca-200 px-4 py-3">
              <p class="text-marca-800 text-xs">Un aviso con el color de marca.</p>
            </div>
          </div>
          <p class="mt-3 text-xs text-slate-400">
            Es la barra lateral de verdad, con los valores guardados. Lo que está en el formulario
            y todavía no se ha guardado no se refleja aquí.
          </p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-2">Qué NO se configura aquí</h2>
        <ul class="space-y-2 text-xs text-slate-500">
          <li>
            <span class="text-slate-700">La razón social, el RUC, el domicilio y el representante legal</span>
            son de la sociedad que factura, no de la marca, y una marca puede tener varias:
            están en <a href="{{ route('entidades.index') }}" class="text-marca-700 hover:underline">Entidades legales</a>.
          </li>
          <li>
            <span class="text-slate-700">El código interno de la marca</span>
            (<code class="rounded bg-slate-100 px-1">{{ $fila?->code ?? config('latam.marca.codigo') }}</code>)
            no se cambia: es con lo que el sembrador la encuentra. El nombre visible sí.
          </li>
        </ul>
      </div>
    </div>
  </form>
@endsection
