@extends('layouts.panel')
@section('titulo', $pagina->title)
@section('subtitulo', 'El texto de esta página, sus marcadores y su publicación')

@section('contenido')
  @include('parciales.miga', ['aqui' => $pagina->title, 'volver' => route('paginas.index'), 'volverA' => 'Páginas'])

  @if (session('mensaje'))
    <div class="mb-4 rounded-lg border border-marca-200 bg-marca-50 px-4 py-3 text-sm text-marca-800">
      {{ session('mensaje') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  {{-- L-2b: los marcadores que no se resuelven, arriba del todo y en rojo.
       Un `{{empresa.razon_social}}` sin valor se pinta como una raya en la
       página pública: el documento se lee, pero no nombra a nadie. Enterarse
       aquí y no leyendo la política publicada es toda la diferencia. --}}
  @if ($sinResolver)
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
      <p class="font-semibold">Hay marcadores que ninguna configuración resuelve</p>
      <p class="mt-1">
        En la página se pintan como una raya:
        <span class="font-mono">{{ implode(', ', $sinResolver) }}</span>.
        Se rellenan en <a href="{{ route('sitio.index') }}" class="underline">Sitio público</a>,
        en <a href="{{ route('marca.index') }}" class="underline">Marca</a> o en la sociedad operadora.
      </p>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      {{-- --------------------------------------------------------- el texto --}}
      <form method="POST" action="{{ route('paginas.texto', ['uuid' => $pagina->uuid]) }}"
            class="rounded-xl border border-slate-200 bg-white p-5">
        @csrf
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="text-sm font-semibold text-slate-900">El texto</h2>
          <span class="text-xs text-slate-400">Markdown · el HTML que escribas se enseña, no se ejecuta</span>
        </div>

        <textarea name="body_markdown" rows="26"
                  class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs leading-relaxed">{{ old('body_markdown', $texto) }}</textarea>

        <div class="mt-3 flex items-center gap-3">
          <button class="rounded-lg bg-marca-500 px-5 py-2 text-sm font-semibold text-white hover:opacity-90">
            Guardar borrador
          </button>
          <span class="text-xs text-slate-400">Guardar no publica: el sitio sigue enseñando la versión vigente.</span>
        </div>
      </form>

      {{-- --------------------------------------------------- la vista previa --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Cómo se va a ver</h2>
        <p class="mt-1 text-xs text-slate-500">
          Con los marcadores ya sustituidos por los valores de hoy. Es lo que va a leer un visitante.
        </p>
        <div class="prosa mt-4 max-h-96 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50 p-4">
          {!! $vistaPrevia !!}
        </div>
      </section>

      {{-- ----------------------------------------------------- los datos --}}
      <form method="POST" action="{{ route('paginas.guardar', ['uuid' => $pagina->uuid]) }}"
            class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
        @csrf
        <h2 class="text-sm font-semibold text-slate-900">Título, dirección y buscadores</h2>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">Título
            <input name="title" maxlength="160" required value="{{ old('title', $pagina->title) }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
          </label>

          <label class="block text-sm text-slate-600">Dirección
            <input name="slug" maxlength="60" required value="{{ old('slug', $pagina->slug) }}"
                   @disabled($pagina->is_system)
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm
                          disabled:bg-slate-50 disabled:text-slate-400">
            @if ($pagina->is_system)
              <span class="mt-1 block text-xs text-slate-400">
                Fija: su enlace vive en correos y contratos que ya salieron.
              </span>
            @endif
          </label>
        </div>

        <label class="block text-sm text-slate-600">Título en buscadores <span class="text-slate-400">(opcional)</span>
          <input name="meta_title" maxlength="70" value="{{ old('meta_title', $pagina->meta_title) }}"
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm text-slate-600">Descripción en buscadores <span class="text-slate-400">(opcional)</span>
          <textarea name="meta_description" rows="2" maxlength="180"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('meta_description', $pagina->meta_description) }}</textarea>
        </label>

        <div class="flex items-center gap-4">
          <label class="text-sm text-slate-600">Orden
            <input type="number" name="sort_order" min="0" max="9999"
                   value="{{ old('sort_order', $pagina->sort_order) }}"
                   class="mt-1 w-20 rounded-lg border border-slate-300 px-2 py-1">
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="show_in_footer" value="1"
                   @checked(old('show_in_footer', $pagina->show_in_footer))
                   class="rounded border border-slate-300">
            Sale en el pie
          </label>
        </div>

        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
          Guardar
        </button>
      </form>
    </div>

    {{-- ------------------------------------------------------- la columna --}}
    <div class="space-y-5">
      {{-- Publicar --}}
      <form method="POST" action="{{ route('paginas.publicar', ['uuid' => $pagina->uuid]) }}"
            class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
        @csrf
        <h2 class="text-sm font-semibold text-slate-900">Publicar</h2>

        @if ($borrador)
          <p class="text-xs text-slate-500">
            Hay un borrador sin publicar (v{{ $borrador->version }}). Publicarlo cierra la versión
            vigente el día anterior y abre ésta.
          </p>

          <label class="block text-sm text-slate-600">Vigente desde
            <input type="date" name="effective_from" required value="{{ now()->toDateString() }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
          </label>

          <button class="w-full rounded-lg bg-marca-500 px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
            Publicar
          </button>
        @else
          <p class="text-xs text-slate-500">
            No hay ningún borrador. Guarda un texto arriba y aparecerá aquí para publicarlo.
          </p>
        @endif

        @if ($vigente)
          <p class="border-t border-slate-100 pt-3 text-xs text-slate-500">
            Vigente ahora: <strong>v{{ $vigente->version }}</strong> desde
            {{ \Illuminate\Support\Str::of($vigente->effective_from)->substr(0, 10) }}.
          </p>
        @endif
      </form>

      {{-- Revisión jurídica --}}
      @if ($vigente)
        <form method="POST" action="{{ route('paginas.revision', ['uuid' => $pagina->uuid]) }}"
              class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
          @csrf
          <h2 class="text-sm font-semibold text-slate-900">Revisión jurídica</h2>
          <p class="text-xs text-slate-500">
            El texto de partida lo escribimos nosotros a estándar de industria. <strong>No es un
            dictamen.</strong> Se anota aquí cuando un abogado lo mire.
          </p>

          <label class="block text-sm text-slate-600">Estado
            <select name="review_status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
              @foreach ($revisiones as $valor => $etiqueta)
                <option value="{{ $valor }}" @selected($vigente->review_status === $valor)>{{ $etiqueta }}</option>
              @endforeach
            </select>
          </label>

          <label class="block text-sm text-slate-600">Nota <span class="text-slate-400">(opcional)</span>
            <input name="review_note" maxlength="255" placeholder="Quién y cuándo"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
          </label>

          <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
            Anotar
          </button>
        </form>
      @endif

      {{-- Marcadores --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Marcadores</h2>
        <p class="mt-1 text-xs text-slate-500">
          Escríbelos en el texto y se sustituyen solos. Así el documento no lleva escrita la razón
          social: el día que cambie, cambia en un sitio.
        </p>

        <dl class="mt-3 space-y-2 text-xs">
          @foreach ($catalogo as $clave => $explica)
            <div class="flex items-start gap-2">
              <dt class="shrink-0 font-mono {{ isset($valores[$clave]) ? 'text-marca-700' : 'text-rose-600' }}">
                &#123;&#123;{{ $clave }}&#125;&#125;
              </dt>
              <dd class="min-w-0 flex-1 truncate text-slate-500" title="{{ $explica }}">
                {{ $valores[$clave] ?? '— sin valor —' }}
              </dd>
            </div>
          @endforeach
        </dl>
      </section>

      {{-- Historial --}}
      @if ($historial->isNotEmpty())
        <section class="rounded-xl border border-slate-200 bg-white p-5">
          <h2 class="text-sm font-semibold text-slate-900">Versiones</h2>
          <ul class="mt-3 space-y-2 text-xs text-slate-500">
            @foreach ($historial as $v)
              <li class="flex items-baseline justify-between gap-2">
                <span>
                  <span class="font-mono text-slate-700">v{{ $v->version }}</span>
                  @if ($v->published_at)
                    · {{ \Illuminate\Support\Str::of($v->effective_from)->substr(0, 10) }}
                    @if ($v->effective_to) → {{ \Illuminate\Support\Str::of($v->effective_to)->substr(0, 10) }} @endif
                  @else
                    · <span class="text-amber-700">borrador</span>
                  @endif
                </span>
                @if ($v->autor)<span class="truncate text-slate-400">{{ $v->autor }}</span>@endif
              </li>
            @endforeach
          </ul>
          <p class="mt-3 text-xs text-slate-400">
            Una versión publicada no se reescribe: se publica la siguiente. Es el texto que alguien
            pudo haber leído el día que nos dio sus datos.
          </p>
        </section>
      @endif

      @unless ($pagina->is_system)
        <form method="POST" action="{{ route('paginas.borrar', ['uuid' => $pagina->uuid]) }}"
              class="rounded-xl border border-rose-200 bg-white p-5">
          @csrf @method('DELETE')
          <button class="text-sm text-rose-600 hover:underline">Borrar esta página</button>
        </form>
      @endunless
    </div>
  </div>
@endsection
