@extends('layouts.publico')
@section('titulo', $pagina->meta_title ?: $pagina->headline)
@if ($pagina->meta_description)
  @section('descripcion', $pagina->meta_description)
@endif

@section('contenido')
  {{-- Todo lo que se lee aquí sale de `landing_pages` y `landing_blocks`. Ni un
       titular escrito en la plantilla: esto es white label, y el texto lo cambia
       quien administra desde /backoffice/landing sin desplegar nada. --}}
  <section class="degradado-marca">
    <div class="mx-auto max-w-5xl px-6 py-20 sm:py-28">
      <h1 class="max-w-3xl text-3xl sm:text-5xl font-bold tracking-tight text-white">
        {{ $pagina->headline }}
      </h1>

      @if ($pagina->subheadline)
        <p class="mt-5 max-w-2xl text-lg text-white/85">{{ $pagina->subheadline }}</p>
      @endif

      <div class="mt-8 flex flex-wrap items-center gap-4">
        <a href="{{ $pagina->cta_url ?: '#empezar' }}"
           class="rounded-lg bg-white px-5 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-100">
          {{ $pagina->cta_label }}
        </a>

        @if ($esDeCreadores)
          <a href="{{ route('portada.marcas') }}" class="text-sm text-white/80 hover:text-white">
            ¿Eres una marca? →
          </a>
        @else
          <a href="{{ route('portada.creadores') }}" class="text-sm text-white/80 hover:text-white">
            ¿Eres creador? →
          </a>
        @endif
      </div>
    </div>
  </section>

  @php
    $ventajas = $pagina->bloques->where('kind', 'feature');
    $pasos = $pagina->bloques->where('kind', 'step');
    $preguntas = $pagina->bloques->where('kind', 'faq');
  @endphp

  @if ($ventajas->isNotEmpty())
    <section class="mx-auto max-w-5xl px-6 py-16">
      <div class="grid gap-8 sm:grid-cols-3">
        @foreach ($ventajas as $b)
          <div>
            <h2 class="text-base font-semibold text-slate-900">{{ $b->heading }}</h2>
            @if ($b->body)<p class="mt-2 text-sm text-slate-600">{{ $b->body }}</p>@endif
          </div>
        @endforeach
      </div>
    </section>
  @endif

  @if ($pasos->isNotEmpty())
    <section class="border-y border-slate-100 bg-slate-50">
      <div class="mx-auto max-w-5xl px-6 py-16">
        <h2 class="text-xl font-semibold text-slate-900 mb-8">Cómo funciona</h2>
        <ol class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          @foreach ($pasos as $b)
            <li class="rounded-xl border border-slate-200 bg-white p-5">
              <h3 class="text-sm font-semibold text-slate-900">{{ $b->heading }}</h3>
              @if ($b->body)<p class="mt-2 text-sm text-slate-600">{{ $b->body }}</p>@endif
            </li>
          @endforeach
        </ol>
      </div>
    </section>
  @endif

  {{-- ------------------------------------------------ postular (sólo creadores) --}}
  @if ($esDeCreadores)
    <section id="empezar" class="mx-auto max-w-2xl px-6 py-16">
      <h2 class="text-xl font-semibold text-slate-900">{{ $pagina->cta_label }}</h2>
      <p class="mt-2 text-sm text-slate-600">
        Con esto basta para empezar. Revisamos tu perfil y te escribimos, encaje o no encaje
        todavía.
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

        <label class="block text-sm text-slate-600">Nombre y apellido
          <input name="full_name" required maxlength="160" value="{{ old('full_name') }}"
                 class="mt-1 w-full rounded-lg border border-slate-300">
        </label>

        <label class="block text-sm text-slate-600">Correo
          <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                 class="mt-1 w-full rounded-lg border border-slate-300">
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">País
            <select name="country_id" required class="mt-1 w-full rounded-lg border border-slate-300">
              @foreach ($paises as $p)
                <option value="{{ $p->id }}" @selected((string) old('country_id') === (string) $p->id)>{{ $p->name }}</option>
              @endforeach
            </select>
          </label>

          <label class="block text-sm text-slate-600">Teléfono <span class="text-slate-400">(opcional)</span>
            <input name="phone" maxlength="30" value="{{ old('phone') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300">
          </label>
        </div>

        {{-- El campo trampa: una persona no lo ve, un robot lo rellena. Si viene
             lleno se contesta «gracias» y no se escribe nada. `aria-hidden` y
             `tabindex` para que un lector de pantalla tampoco lo ofrezca. --}}
        <div class="hidden" aria-hidden="true">
          <label>Empresa
            <input name="empresa" tabindex="-1" autocomplete="off" value="">
          </label>
        </div>

        <button class="w-full rounded-lg bg-marca-500 px-5 py-3 text-sm font-semibold text-white">
          {{ $pagina->cta_label }}
        </button>

        <p class="text-xs text-slate-500">
          Sólo lo usamos para revisar tu postulación y escribirte.
        </p>
      </form>
    </section>
  @endif

  @if ($preguntas->isNotEmpty())
    <section class="mx-auto max-w-3xl px-6 py-16 border-t border-slate-100">
      <h2 class="text-xl font-semibold text-slate-900 mb-6">Preguntas</h2>
      <div class="space-y-5">
        @foreach ($preguntas as $b)
          <div>
            <h3 class="text-sm font-semibold text-slate-900">{{ $b->heading }}</h3>
            @if ($b->body)<p class="mt-1 text-sm text-slate-600">{{ $b->body }}</p>@endif
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ---------------------------------------------------- contacto (marcas) --}}
  @unless ($esDeCreadores)
    <section id="empezar" class="mx-auto max-w-2xl px-6 py-16 border-t border-slate-100">
      <h2 class="text-xl font-semibold text-slate-900">{{ $pagina->cta_label }}</h2>
      <p class="mt-2 text-sm text-slate-600">
        Cuéntanos qué tienes en mente y te escribimos con cómo sería tu campaña.
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

      <form method="POST" action="{{ route('contacto') }}" class="mt-6 space-y-4">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">Empresa o marca
            <input name="company_name" required maxlength="160" value="{{ old('company_name') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300">
          </label>

          <label class="block text-sm text-slate-600">Tu nombre
            <input name="contact_name" required maxlength="160" value="{{ old('contact_name') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300">
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">Correo
            <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300">
          </label>

          <label class="block text-sm text-slate-600">Teléfono <span class="text-slate-400">(opcional)</span>
            <input name="phone" maxlength="30" value="{{ old('phone') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300">
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">País
            <select name="country_id" required class="mt-1 w-full rounded-lg border border-slate-300">
              @foreach ($paises as $p)
                <option value="{{ $p->id }}" @selected((string) old('country_id') === (string) $p->id)>{{ $p->name }}</option>
              @endforeach
            </select>
          </label>

          <label class="block text-sm text-slate-600">Web <span class="text-slate-400">(opcional)</span>
            <input name="website" maxlength="255" placeholder="https://…" value="{{ old('website') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300">
          </label>
        </div>

        <label class="block text-sm text-slate-600">Qué tienes en mente
          <textarea name="message" rows="4" maxlength="1000"
                    class="mt-1 w-full rounded-lg border border-slate-300">{{ old('message') }}</textarea>
        </label>

        {{-- El campo trampa, con otro nombre que el de la postulación: dos
             formularios públicos con la misma trampa se saltan de una vez. --}}
        <div class="hidden" aria-hidden="true">
          <label>Empresa
            <input name="empresa_2" tabindex="-1" autocomplete="off" value="">
          </label>
        </div>

        <button class="w-full rounded-lg bg-marca-500 px-5 py-3 text-sm font-semibold text-white">
          {{ $pagina->cta_label }}
        </button>

        <p class="text-xs text-slate-500">
          Sólo lo usamos para responderte.
          @if ($marca['correoSoporte'])
            Si prefieres escribir tú: {{ $marca['correoSoporte'] }}
          @endif
        </p>
      </form>
    </section>
  @endunless
@endsection
