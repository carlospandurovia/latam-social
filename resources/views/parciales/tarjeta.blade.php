{{-- 9.17i: la tarjeta de una integración. UNA plantilla para todas.

     Crítica del negocio, con la pantalla de otro producto suyo delante:
     *«esta pantalla no se compara a la que me hiciste para LOTEALO, esperaba
     algo así»*. Tenía razón, y lo que le faltaba a la mía se puede enumerar:

     1. **No decía qué hace la integración ni qué pasa si no se activa.** Sólo
        regañaba en rojo. Una pantalla que sólo regaña no enseña a nadie a
        usarla; la de LOTEALO explica primero y avisa después.
     2. **El aviso salía dos veces** —arriba en la lista de la pestaña y otra
        vez dentro— porque la lista era de la PESTAÑA y no de la tarjeta. Aquí
        cada tarjeta lleva los suyos y arriba no queda nada que repetir.
     3. **No había estado.** Ni chapa de «activo», ni forma de apagar.
     4. **No había a dónde ir a por lo que la integración pide** (el token, el
        certificado, la clave de aplicación). El pie de la tarjeta es para eso.
     5. Ocupaba una columna estrecha con media pantalla vacía al lado.

     ### Qué recibe

     - `titulo`     — obligatorio.
     - `icono`      — nombre en `parciales.icono`.
     - `explica`    — qué hace, en texto llano.
     - `destacado`  — qué pasa si NO se activa. Va en negrita porque es lo que
                      nadie descubre hasta que algo no llega.
     - `estado`     — `['nivel' => activo|falta|apagado|parcial, 'texto' => '…']`.
     - `avisos`     — los de ESTA integración, no los de la pestaña.
     - `enlaces`    — `[['texto' => '…', 'url' => '…', 'externo' => bool]]`.
     - `cuerpo`     — la vista con los campos.

     Todo se escapa: `explica` y `destacado` son TEXTO. Se resistió la tentación
     de admitir HTML para poder poner negritas dentro —hoy el texto lo escribo
     yo, pero mañana sale de la base y entonces la tarjeta sería un agujero de
     XSS que nadie recuerda haber abierto--. --}}
@php
    $chapas = [
        'activo' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'Activo'],
        'falta' => ['bg-rose-50 text-rose-800 ring-rose-200', 'Falta configurar'],
        'apagado' => ['bg-slate-100 text-slate-600 ring-slate-200', 'Apagado'],
        'parcial' => ['bg-amber-50 text-amber-800 ring-amber-200', 'A medias'],
    ];
    $nivel = $estado['nivel'] ?? null;
    [$colorChapa, $textoChapa] = $chapas[$nivel] ?? $chapas['apagado'];
@endphp

<section class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
  <header class="flex flex-wrap items-start justify-between gap-4 px-6 pt-5">
    <div class="flex min-w-0 items-start gap-3">
      <span class="mt-0.5 shrink-0 text-slate-400">
        @include('parciales.icono', ['nombre' => $icono ?? 'enchufe', 'clase' => 'h-5 w-5'])
      </span>
      <div class="min-w-0">
        <h2 class="text-base font-semibold text-slate-900">{{ $titulo }}</h2>
        @if (!empty($explica))
          <p class="mt-1 max-w-3xl text-sm leading-relaxed text-slate-600">
            {{ $explica }}
            @if (!empty($destacado))
              <strong class="font-semibold text-slate-800">{{ $destacado }}</strong>
            @endif
          </p>
        @endif
      </div>
    </div>

    @if ($nivel !== null)
      <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $colorChapa }}">
        {{ $estado['texto'] ?? $textoChapa }}
      </span>
    @endif
  </header>

  {{-- Los avisos DE ESTA integración, dentro de ella. Antes vivían todos
       juntos arriba y había que adivinar a cuál se referían. --}}
  @foreach ($avisos ?? [] as $aviso)
    <p class="mx-6 mt-4 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo'
         ? 'border-rose-200 bg-rose-50 text-rose-800'
         : 'border-amber-200 bg-amber-50 text-amber-800' }}">
      {{ $aviso->texto }}
    </p>
  @endforeach

  <div class="px-6 pb-6 pt-5">
    @include($cuerpo)
  </div>

  @if (!empty($enlaces))
    <footer class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-100 bg-slate-50/70 px-6 py-3 text-xs">
      @foreach ($enlaces as $enlace)
        {{-- Un enlace que devuelve 403 ensena a desconfiar de lo que se ve: si
             hace falta un permiso para lo que hay al otro lado, se declara y
             aqui no se pinta. Es la misma regla del menu lateral. --}}
        @if (empty($enlace['permiso']) || auth()->user()?->can($enlace['permiso']))
          <a href="{{ $enlace['url'] }}"
             @if (!empty($enlace['externo'])) target="_blank" rel="noopener noreferrer" @endif
             class="text-marca-700 hover:underline">{{ $enlace['texto'] }}</a>
        @endif
      @endforeach
    </footer>
  @endif
</section>
