@extends('layouts.publico')
@section('titulo', $pagina->meta_title ?: $pagina->headline)
@if ($pagina->meta_description)
  @section('descripcion', $pagina->meta_description)
@endif

@section('contenido')
  {{-- Todo lo que se lee aquí sale de `landing_pages` y de `landing_sections`
       con sus `landing_blocks`. Ni un titular, ni un encabezado de franja, ni el
       orden de las franjas escritos en la plantilla: esto es white label, y lo
       cambia quien administra desde /backoffice/landing sin desplegar nada. --}}
  <section class="degradado-marca relative overflow-hidden">
    {{-- Un velo radial sobre el degradado. Sin el, el degradado a 45° reparte
         el naranja en una esquina y el morado en la otra, y el titular blanco
         cae justo encima del naranja --que es el tono con menos contraste de
         los tres--. `docs/14 §6` pide que el degradado sea fondo, no
         competencia. --}}
    <div class="pointer-events-none absolute inset-0
                bg-[radial-gradient(120%_90%_at_15%_0%,rgba(0,0,0,.30),transparent_60%)]"></div>

    <div class="relative mx-auto max-w-6xl px-6 py-20 sm:py-28 lg:grid lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:gap-12">
      <div>
      <h1 class="fuente-titulos max-w-2xl text-4xl sm:text-6xl font-bold tracking-tight text-white text-balance">
        {{ $pagina->headline }}
      </h1>

      @if ($pagina->subheadline)
        <p class="mt-6 max-w-2xl text-lg sm:text-xl leading-relaxed text-white/85">{{ $pagina->subheadline }}</p>
      @endif

      <div class="mt-9 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3 sm:gap-4">
        <a href="{{ $pagina->cta_url ?: '#empezar' }}"
           data-evento="cta_heroe"
           class="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3.5 text-sm font-semibold
                  text-slate-900 shadow-lg shadow-black/15 transition hover:-translate-y-0.5 hover:bg-slate-50">
          {{ $pagina->cta_label }}
        </a>

        {{-- L-2a/§23: el WhatsApp sale de «Sitio público», nunca escrito aquí. Y
             si no está configurado, esto no pinta un enlace roto: no pinta
             nada. Es la misma regla que el logotipo en `9.17` --una imagen rota
             es peor que ninguna imagen--. --}}
        @if ($sitio['whatsappUrl'])
          <a href="{{ $sitio['whatsappUrl'] }}" target="_blank" rel="noopener"
             data-evento="whatsapp_heroe" class="boton-fantasma justify-center">
            @include('parciales.icono', ['icono' => 'chat', 'clase' => 'h-4 w-4'])
            {{ __('publico.whatsapp_escribenos') }}
          </a>
        @endif
      </div>

      <p class="mt-8 text-sm text-white/70">
        @if ($esDeCreadores)
          <a href="{{ route('portada.marcas') }}" class="inline-block py-1 underline-offset-4 hover:underline">{{ __('publico.eres_marca') }}</a>
        @else
          <a href="{{ route('portada.creadores') }}" class="inline-block py-1 underline-offset-4 hover:underline">{{ __('publico.eres_creador') }}</a>
        @endif
      </p>
      </div>

      {{-- L-4 (`V-2`): la mitad derecha dejaba 800 px de degradado liso. No es
           una fotografía porque no tenemos ninguna que sea nuestra, y poner
           caras de banco de imágenes junto a «creadores reales» se lee tan falso
           como una métrica inventada (§12). El dibujo **explica el modelo**:
           muchas comunidades unidas a una sola marca. --}}
      <div class="hidden lg:flex lg:h-[420px] lg:w-[420px] lg:items-center lg:justify-center">
        @include('parciales.heroe-voces')
      </div>
    </div>
  </section>

  {{-- L-3: el despachador de franjas.

       El `in_array` no es paranoia gratuita: la forma de pintar acaba dentro
       de un nombre de plantilla, y aunque `ck_ls_layout` ya la encierra en la base
       --de verdad, con CHECK o con trigger según el motor-- un valor que
       llegara por otro camino resolvería una ruta de archivo. La lista de la
       clase es la misma que la de la base; aquí se comprueba porque este es el
       sitio donde el error se convertiría en algo más que un dibujo feo.

       Y el comentario NO nombra la variable del parcial. `verificar-pantallas`
       lee los comentarios --no puede distinguirlos del código-- y acusó a esta
       plantilla de usar un dato que su controlador no le pasa. Es el mismo
       tropiezo que en `L-1`, donde un comentario dentro de `<style>` puso roja
       una prueba de permisos: lo que se escribe en un comentario de una
       plantilla lo leen las herramientas, y algunas lo mandan al navegador. --}}
  @foreach ($pagina->secciones as $seccion)
    @include(
      in_array($seccion->layout, array_keys(\App\Modules\Core\Services\Landing::LAYOUTS), true)
        ? 'publico.secciones.'.$seccion->layout
        : 'publico.secciones.plain',
      ['s' => $seccion]
    )
  @endforeach

  {{-- ------------------------------------------------ postular (sólo creadores) --}}
  @if ($esDeCreadores)
    <section id="empezar" class="mx-auto max-w-6xl px-6 py-16 sm:py-20">
      <div class="max-w-2xl">
      <h2 class="fuente-titulos text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">
        {{ $pagina->form_heading ?: $pagina->cta_label }}
      </h2>
      <p class="mt-3 text-base text-slate-600">
        {{ $pagina->form_intro ?: __('publico.formulario.cierre_creadores') }}
      </p>

      @if (session('aviso'))
        <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
          {{ session('aviso') }}
        </div>
      @endif
      @if ($errors->any())
        <div class="mt-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
          <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
      @endif

      <form method="POST" action="{{ route('postular') }}" class="mt-6 space-y-4">
        @csrf

        <label class="block text-sm text-slate-600">{{ __('publico.formulario.nombre_completo') }}
          <input name="full_name" required maxlength="160" value="{{ old('full_name') }}"
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </label>

        <label class="block text-sm text-slate-600">{{ __('publico.formulario.correo') }}
          <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">{{ __('publico.formulario.pais') }}
            <select name="country_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
              {{-- L-5 (`C-2`): sale marcado el pais por defecto, que lo dice
                   «Sitio publico» y en su defecto es el de la sociedad
                   operadora. Antes no salia marcado ninguno, asi que el
                   navegador elegia el primero de la lista --Chile, por orden
                   alfabetico-- y quien no se fijara mandaba su lead al pais
                   equivocado sin que nada lo dijera. --}}
              @foreach ($paises as $p)
                <option value="{{ $p->id }}"
                        @selected((string) old('country_id', (string) ($paisPorDefecto ?? '')) === (string) $p->id)>{{ $p->name }}</option>
              @endforeach
            </select>
          </label>

          <label class="block text-sm text-slate-600">{{ __('publico.formulario.telefono') }} <span class="text-slate-500">{{ __('publico.formulario.opcional') }}</span>
            <input name="phone" maxlength="30" value="{{ old('phone') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
          </label>
        </div>

        {{-- El campo trampa: una persona no lo ve, un robot lo rellena. Si viene
             lleno se contesta «gracias» y no se escribe nada. `aria-hidden` y
             `tabindex` para que un lector de pantalla tampoco lo ofrezca. --}}
        <div class="hidden" aria-hidden="true">
          <label>{{ __('publico.formulario.trampa') }}
            <input name="empresa" tabindex="-1" autocomplete="off" value="">
          </label>
        </div>

        <button class="boton-marca w-full justify-center py-3.5">
          {{ $pagina->cta_label }}
        </button>

        <p class="text-xs text-slate-500">
          {{ __('publico.formulario.solo_para_postular') }}
        </p>
      </form>
      </div>
    </section>
  @endif

  {{-- ---------------------------------------------------- contacto (marcas) --}}
  @unless ($esDeCreadores)
    <section id="empezar" class="mx-auto max-w-6xl px-6 py-16 sm:py-20 border-t border-slate-100">
      <div class="max-w-2xl">
      {{-- L-4 (`C-3`): hasta hoy este encabezado ERA `cta_label`, asi que la
           misma frase salia tres veces --boton del heroe, titulo de aqui y
           boton de enviar-- y la pagina leia como una plantilla rellenada. Con
           el campo vacio se sigue usando el boton: nada bloquea (`DEC-190`). --}}
      <h2 class="fuente-titulos text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">
        {{ $pagina->form_heading ?: $pagina->cta_label }}
      </h2>
      <p class="mt-3 text-base text-slate-600">
        {{ $pagina->form_intro ?: __('publico.formulario.cierre_marcas') }}
      </p>

      {{-- Y el WhatsApp al lado del formulario, que es lo que pedia el §3 de la
           arquitectura: quien no quiere rellenar siete campos tiene el canal de
           menor friccion de LATAM a un clic. Sale de «Sitio publico»; sin
           configurar, no se pinta. --}}
      @if ($sitio['whatsappUrl'])
        <p class="mt-3 text-sm text-slate-500">
          {{ __('publico.whatsapp_prefieres') }}
          <a href="{{ $sitio['whatsappUrl'] }}" target="_blank" rel="noopener"
             data-evento="whatsapp_cierre"
             class="font-medium text-marca-700 underline-offset-4 hover:underline">{{ __('publico.whatsapp_hablanos') }}</a>.
        </p>
      @endif

      @if (session('aviso'))
        <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
          {{ session('aviso') }}
        </div>
      @endif
      @if ($errors->any())
        <div class="mt-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
          <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
      @endif

      <form method="POST" action="{{ route('contacto') }}" class="mt-6 space-y-4">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">{{ __('publico.formulario.empresa') }}
            <input name="company_name" required maxlength="160" value="{{ old('company_name') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
          </label>

          <label class="block text-sm text-slate-600">{{ __('publico.formulario.tu_nombre') }}
            <input name="contact_name" required maxlength="160" value="{{ old('contact_name') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">{{ __('publico.formulario.correo') }}
            <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
          </label>

          <label class="block text-sm text-slate-600">{{ __('publico.formulario.pais') }}
            <select name="country_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
              {{-- L-5 (`C-2`): sale marcado el pais por defecto, que lo dice
                   «Sitio publico» y en su defecto es el de la sociedad
                   operadora. Antes no salia marcado ninguno, asi que el
                   navegador elegia el primero de la lista --Chile, por orden
                   alfabetico-- y quien no se fijara mandaba su lead al pais
                   equivocado sin que nada lo dijera. --}}
              @foreach ($paises as $p)
                <option value="{{ $p->id }}"
                        @selected((string) old('country_id', (string) ($paisPorDefecto ?? '')) === (string) $p->id)>{{ $p->name }}</option>
              @endforeach
            </select>
          </label>
        </div>

        {{-- L-5 (`C-7`): siete campos y un area de texto, todos juntos y sin
             explicar para que. Se puede calificar igual con menos.

             Los cuatro que quedan a la vista son los que hacen falta para
             CONTESTAR --con quien hablamos, de que marca, a donde escribimos y
             en que pais-- y el pais ya viene marcado, asi que son tres que
             teclear. El telefono y la web no cambian la respuesta: piden
             esfuerzo antes de que nadie haya prometido nada, y por eso van
             detras de un `<details>` en vez de fuera. Se guardan igual si
             alguien los rellena: `client_leads` no cambia y `Prospectos` tampoco
             --el §6 pide no crear soluciones paralelas--. --}}
        <label class="block text-sm text-slate-600">{{ __('publico.formulario.mensaje') }}
          <textarea name="message" rows="4" maxlength="1000"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">{{ old('message') }}</textarea>
        </label>

        <details class="rounded-lg border border-slate-200 px-4 py-3" @if (old('phone') || old('website')) open @endif>
          <summary class="cursor-pointer list-none py-1 text-sm font-medium text-slate-600">
            {{ __('publico.formulario.mas_datos') }} <span class="font-normal text-slate-500">{{ __('publico.formulario.opcional') }}</span>
          </summary>

          <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <label class="block text-sm text-slate-600">{{ __('publico.formulario.telefono') }}
              <input name="phone" maxlength="30" value="{{ old('phone') }}"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
            </label>

            <label class="block text-sm text-slate-600">{{ __('publico.formulario.web') }}
              <input name="website" maxlength="255" placeholder="https://…" value="{{ old('website') }}"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5">
            </label>
          </div>
        </details>

        {{-- El campo trampa, con otro nombre que el de la postulación: dos
             formularios públicos con la misma trampa se saltan de una vez. --}}
        <div class="hidden" aria-hidden="true">
          <label>{{ __('publico.formulario.trampa') }}
            <input name="empresa_2" tabindex="-1" autocomplete="off" value="">
          </label>
        </div>

        <button class="boton-marca w-full justify-center py-3.5">
          {{ $pagina->cta_label }}
        </button>

        <p class="text-xs text-slate-500">
          {{ __('publico.formulario.solo_para_responder') }}
          @if ($marca['correoSoporte'])
            {{ __('publico.formulario.si_prefieres_correo') }} {{ $marca['correoSoporte'] }}
          @endif
        </p>
      </form>
      </div>
    </section>
  @endunless
@endsection
