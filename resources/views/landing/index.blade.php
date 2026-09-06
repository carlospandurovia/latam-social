@extends('layouts.panel')
@section('titulo', 'Portada pública')
@section('subtitulo', 'Lo que ve quien todavía no es cliente')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Portada pública'])

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
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-amber-50 border-amber-200 text-amber-800' }}">
      {{ $aviso->texto }}
    </div>
  @endforeach

  {{-- L-4: los marcadores. Se enseñan AQUÍ y no en un manual: un marcador que
       nadie sabe que existe acaba siendo una razón social escrita a mano dentro
       del texto, y el día que la empresa cambie de nombre habrá que buscarla por
       toda la portada. Es el mismo motor que los documentos legales de `L-2b`. --}}
  <details class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <summary class="cursor-pointer font-semibold text-slate-800">
      Puedes escribir datos entre llaves y el sistema los sustituye
    </summary>
    <p class="mt-2">
      En cualquier título, bajada o texto de bloque. Lo que no esté configurado sale como una
      raya <code>—</code> y la pantalla te avisa en rojo.
    </p>
    {{-- Las llaves se arman con `str_repeat` y NO se escriben en la plantilla.
         Escribirlas revienta la compilación de Blade --«Unclosed '(' does not
         match '}'»-- porque el compilador busca `{{` en el texto crudo y no
         distingue si está dentro de una cadena. Lo cazó `NavegacionTest`, que
         abre todas las pantallas de configuración: las pruebas de esta
         iteración no tocan esta pantalla. --}}
    @php($abre = str_repeat('{', 2))
    @php($cierra = str_repeat('}', 2))
    <ul class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2">
      @foreach ($marcadores as $clave => $texto)
        <li><code class="text-marca-700">{{ $abre.' '.$clave.' '.$cierra }}</code> — {{ $texto }}</li>
      @endforeach
    </ul>
  </details>

  <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Esto se publica en cuanto lo guardas</p>
    <p>
      No hay borrador ni previsualización: lo que escribes aquí es lo que se ve en la calle.
      Si quieres preparar algo sin enseñarlo todavía, deja el bloque <strong>oculto</strong> y
      enciéndelo el día que toque.
    </p>
  </div>

  @foreach ($paginas as $p)
    <div class="mb-8 bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-2">
        <div>
          <h2 class="text-sm font-semibold">
            {{ $p->code === 'marcas' ? 'Portada de marcas' : 'Portada de creadores' }}
          </h2>
          <p class="text-xs text-slate-500">
            {{ $p->code === 'marcas' ? route('portada.marcas') : route('portada.creadores') }}
          </p>
        </div>
        <div class="flex items-center gap-3">
          <span class="rounded px-2 py-0.5 text-xs
            {{ $p->is_published ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">
            {{ $p->is_published ? 'Publicada' : 'Sin publicar' }}
          </span>
          <a href="{{ $p->code === 'marcas' ? route('portada.marcas') : route('portada.creadores') }}"
             target="_blank" rel="noopener" class="text-xs text-marca-700 hover:underline">Verla →</a>
        </div>
      </div>

      <form method="POST" action="{{ route('landing.update', $p->id) }}" class="px-5 py-4 space-y-4">
        @csrf
        @method('PUT')

        <label class="block text-xs text-slate-500">Titular
          <input name="headline" required minlength="10" maxlength="160" value="{{ old('headline', $p->headline) }}"
                 class="mt-1 w-full rounded border border-slate-300 text-sm">
        </label>

        <label class="block text-xs text-slate-500">Debajo del titular
          <textarea name="subheadline" rows="2" maxlength="320"
                    class="mt-1 w-full rounded border border-slate-300 text-sm">{{ old('subheadline', $p->subheadline) }}</textarea>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-xs text-slate-500">Texto del botón
            <input name="cta_label" required maxlength="60" value="{{ old('cta_label', $p->cta_label) }}"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
          </label>

          <label class="block text-xs text-slate-500">A dónde lleva
            <input name="cta_url" maxlength="255" placeholder="vacío = el formulario de la página"
                   value="{{ old('cta_url', $p->cta_url) }}"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
            <span class="text-[11px] text-slate-400">Con https, o una ruta propia que empiece por «/».</span>
          </label>
        </div>

        {{-- L-4 (`C-3`): el cierre deja de repetir el botón. Hasta hoy el título
             de la sección del formulario era `cta_label`, así que la misma
             frase salía tres veces --botón del héroe, título de la sección y
             botón de enviar-- y la página leía como una plantilla rellenada. --}}
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-xs text-slate-500">Encabezado del formulario
            <input name="form_heading" maxlength="120" value="{{ old('form_heading', $p->form_heading) }}"
                   placeholder="vacío = no se pinta encabezado"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
          </label>

          <label class="block text-xs text-slate-500">Frase debajo del encabezado
            <input name="form_intro" maxlength="320" value="{{ old('form_intro', $p->form_intro) }}"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-xs text-slate-500">Título para buscadores
            <input name="meta_title" maxlength="70" value="{{ old('meta_title', $p->meta_title) }}"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
          </label>

          <label class="block text-xs text-slate-500">Descripción para buscadores
            <input name="meta_description" maxlength="180" value="{{ old('meta_description', $p->meta_description) }}"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
            <span class="text-[11px] text-slate-400">Es lo que sale al compartir el enlace.</span>
          </label>
        </div>

        <label class="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="is_published" value="1" @checked($p->is_published) class="rounded border border-slate-300">
          Publicada — si se apaga, quien entre verá la pantalla de acceso
        </label>

        <button class="rounded bg-marca-500 px-4 py-2 text-sm text-white">Guardar portada</button>
      </form>

      {{-- ------------------------------------------------------------- franjas --}}
      <div class="border-t border-slate-100 px-5 py-4">
        <h3 class="text-xs uppercase tracking-wider text-slate-500 mb-3">Franjas de la página</h3>

        @forelse ($p->secciones as $s)
          <div class="mb-4 rounded-lg border border-slate-200">
            <form method="POST" action="{{ route('landing.seccion', $p->id) }}"
                  class="grid gap-3 px-4 py-3 sm:grid-cols-12 items-end">
              @csrf
              <input type="hidden" name="id" value="{{ $s->id }}">

              <label class="block text-xs text-slate-500 sm:col-span-2">Ancla
                <input name="code" required maxlength="60" value="{{ $s->code }}"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
                <span class="text-[11px] text-slate-400">Sale en la URL: #{{ $s->code }}</span>
              </label>

              <label class="block text-xs text-slate-500 sm:col-span-2">Forma
                <select name="layout" class="mt-1 w-full rounded border border-slate-300 text-sm">
                  @foreach ($layouts as $clave => $texto)
                    <option value="{{ $clave }}" @selected($s->layout === $clave)>{{ $clave }}</option>
                  @endforeach
                </select>
              </label>

              <label class="block text-xs text-slate-500 sm:col-span-2">Sobretítulo
                <input name="eyebrow" maxlength="60" value="{{ $s->eyebrow }}"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
              </label>

              <label class="block text-xs text-slate-500 sm:col-span-3">Encabezado
                <input name="title" maxlength="120" value="{{ $s->title }}"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
              </label>

              <label class="block text-xs text-slate-500 sm:col-span-1">Orden
                <input type="number" name="sort_order" min="0" max="9999" value="{{ $s->sort_order }}"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
              </label>

              <div class="sm:col-span-2 flex items-center gap-2">
                <button class="rounded bg-slate-700 px-3 py-2 text-sm text-white">Guardar</button>
              </div>

              <label class="block text-xs text-slate-500 sm:col-span-6">Bajada
                <input name="subtitle" maxlength="320" value="{{ $s->subtitle }}"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
              </label>

              <label class="block text-xs text-slate-500 sm:col-span-3">Botón de la franja
                <input name="cta_label" maxlength="60" value="{{ $s->cta_label }}"
                       placeholder="vacío = sin botón"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
              </label>

              <label class="block text-xs text-slate-500 sm:col-span-3">A dónde lleva
                <input name="cta_url" maxlength="255" value="{{ $s->cta_url }}"
                       placeholder="vacío = el formulario de la página"
                       class="mt-1 w-full rounded border border-slate-300 text-sm">
              </label>

              <label class="flex items-center gap-2 text-xs text-slate-600 sm:col-span-3">
                <input type="checkbox" name="is_visible" value="1" @checked($s->is_visible)
                       class="rounded border border-slate-300">
                Visible en la página
              </label>

              <label class="flex items-center gap-2 text-xs text-slate-600 sm:col-span-4">
                <input type="checkbox" name="show_in_nav" value="1" @checked($s->show_in_nav)
                       class="rounded border border-slate-300">
                En el menú de la cabecera — necesita encabezado
              </label>
            </form>

            {{-- ----------------------------------------------------- sus bloques --}}
            <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">
              @forelse ($s->bloques as $b)
                <div class="mb-2 flex items-start gap-3 rounded border border-slate-200 bg-white px-3 py-2">
                  @if ($b->icon)
                    <span class="mt-0.5 shrink-0 text-slate-500">
                      @include('parciales.icono', ['icono' => $b->icon, 'clase' => 'h-4 w-4'])
                    </span>
                  @endif
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-800">{{ $b->heading }}</p>
                    @if ($b->body)<p class="text-xs text-slate-500">{{ $b->body }}</p>@endif
                  </div>
                  @unless ($b->is_visible)
                    <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[11px] text-slate-600">oculto</span>
                  @endunless
                  <span class="text-xs text-slate-400">{{ $b->sort_order }}</span>
                  <form method="POST" action="{{ route('landing.bloque.borrar', [$p->id, $s->id, $b->id]) }}">
                    @csrf
                    @method('DELETE')
                    <button class="text-xs text-rose-700 hover:underline">quitar</button>
                  </form>
                </div>
              @empty
                <p class="mb-2 text-xs text-slate-500">
                  Sin bloques: se pinta el encabezado sobre un hueco.
                </p>
              @endforelse

              <form method="POST" action="{{ route('landing.bloque', [$p->id, $s->id]) }}"
                    class="grid gap-3 sm:grid-cols-12 items-end pt-2">
                @csrf

                <label class="block text-xs text-slate-500 sm:col-span-2">Icono
                  <select name="icon" class="mt-1 w-full rounded border border-slate-300 text-sm">
                    <option value="">— ninguno —</option>
                    @foreach ($iconos as $clave => $texto)
                      <option value="{{ $clave }}">{{ $texto }}</option>
                    @endforeach
                  </select>
                </label>

                <label class="block text-xs text-slate-500 sm:col-span-3">Título
                  <input name="heading" required minlength="3" maxlength="120"
                         class="mt-1 w-full rounded border border-slate-300 text-sm">
                </label>

                <label class="block text-xs text-slate-500 sm:col-span-5">Texto
                  <input name="body" maxlength="600" class="mt-1 w-full rounded border border-slate-300 text-sm">
                </label>

                <label class="block text-xs text-slate-500 sm:col-span-1">Orden
                  <input type="number" name="sort_order" value="100" min="0" max="9999"
                         class="mt-1 w-full rounded border border-slate-300 text-sm">
                </label>

                <div class="sm:col-span-1">
                  <input type="hidden" name="is_visible" value="1">
                  <button class="w-full rounded bg-slate-700 px-3 py-2 text-sm text-white">Añadir</button>
                </div>
              </form>
            </div>

            <div class="border-t border-slate-100 px-4 py-2 text-right">
              <form method="POST" action="{{ route('landing.seccion.borrar', [$p->id, $s->id]) }}">
                @csrf
                @method('DELETE')
                <button class="text-xs text-rose-700 hover:underline">quitar la franja y sus bloques</button>
              </form>
            </div>
          </div>
        @empty
          <p class="mb-4 text-sm text-slate-500">
            Sin franjas: la página sale sólo con el titular y el formulario.
          </p>
        @endforelse

        {{-- ------------------------------------------------------ franja nueva --}}
        <form method="POST" action="{{ route('landing.seccion', $p->id) }}"
              class="grid gap-3 sm:grid-cols-12 items-end border-t border-slate-100 pt-4">
          @csrf

          <label class="block text-xs text-slate-500 sm:col-span-3">Ancla
            <input name="code" required maxlength="60" placeholder="como-funciona"
                   class="mt-1 w-full rounded border border-slate-300 text-sm">
          </label>

          <label class="block text-xs text-slate-500 sm:col-span-3">Forma
            <select name="layout" class="mt-1 w-full rounded border border-slate-300 text-sm">
              @foreach ($layouts as $clave => $texto)
                <option value="{{ $clave }}">{{ $texto }}</option>
              @endforeach
            </select>
          </label>

          <label class="block text-xs text-slate-500 sm:col-span-4">Encabezado
            <input name="title" maxlength="120" class="mt-1 w-full rounded border border-slate-300 text-sm">
          </label>

          <div class="sm:col-span-2 flex items-end gap-2">
            <label class="block text-xs text-slate-500 w-20">Orden
              <input type="number" name="sort_order" value="100" min="0" max="9999"
                     class="mt-1 w-full rounded border border-slate-300 text-sm">
            </label>
            <input type="hidden" name="is_visible" value="1">
            <button class="rounded bg-marca-500 px-3 py-2 text-sm text-white">Añadir</button>
          </div>
        </form>
      </div>
    </div>
  @endforeach
@endsection
