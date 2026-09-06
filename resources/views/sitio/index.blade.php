@extends('layouts.panel')
@section('titulo', 'Sitio público')
@section('subtitulo', 'Lo que se pinta en la calle: contacto, WhatsApp, redes y la sociedad que opera la marca')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Sitio público'])

  {{-- Los avisos informan y no bloquean (`DEC-190`). Rojo es lo que un tercero
       va a ver mal o lo que deja un documento legal sin poder nombrar a nadie;
       ámbar es lo que conviene y mientras tanto se sostiene. --}}
  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo'
          ? 'bg-rose-50 border-rose-200 text-rose-900'
          : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso->nivel === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso->nivel === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso->texto }}
    </div>
  @endforeach

  @if (! $avisos)
    <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
      <span class="inline-block rounded bg-emerald-600 px-1.5 py-0.5 text-xs font-semibold uppercase text-white mr-2">
        Listo
      </span>
      El sitio público tiene todo lo que necesita para pintarse y para nombrar a la empresa.
    </div>
  @endif

  @if (session('mensaje'))
    <div class="mb-4 rounded-lg border border-marca-200 bg-marca-50 px-4 py-3 text-sm text-marca-800">
      {{ session('mensaje') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <form method="POST" action="{{ route('sitio.update') }}" class="lg:col-span-2 space-y-5">
      @csrf @method('PUT')

      {{-- ------------------------------------------------ la sociedad --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Quién opera esta marca</h2>
        <p class="mt-1 text-xs text-slate-500">
          De aquí salen la razón social, el identificador fiscal y el domicilio que aparecen en la
          política de privacidad, en los términos y en el pie de la portada. No se adivina: una
          política que nombra a la sociedad equivocada no vale como documento.
        </p>

        <label class="mt-4 block text-sm text-slate-600">Sociedad operadora
          <select name="operator_legal_entity_id"
                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">— sin declarar —</option>
            @foreach ($sociedades as $s)
              <option value="{{ $s->id }}"
                @selected((string) old('operator_legal_entity_id', $fila->operator_legal_entity_id ?? '') === (string) $s->id)>
                {{ $s->legal_name }} · {{ $s->tax_id_type }} {{ $s->tax_id_number }}
              </option>
            @endforeach
          </select>
        </label>
      </section>

      {{-- ------------------------------------------------ WhatsApp --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">WhatsApp</h2>
        <p class="mt-1 text-xs text-slate-500">
          El canal de contacto de menos fricción de la portada. El mensaje se escribe solo cuando
          alguien pulsa, para que la conversación no empiece en blanco.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">Número, en formato internacional
            <input name="whatsapp_phone" maxlength="20" placeholder="+51987654321"
                   value="{{ old('whatsapp_phone', $fila->whatsapp_phone ?? '') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <span class="mt-1 block text-xs text-slate-400">
              Sin espacios, guiones ni paréntesis: va dentro del enlace y cualquiera de los tres lo rompe.
            </span>
          </label>

          <div class="text-sm text-slate-600">
            Así queda el enlace
            @if ($datos['whatsappUrl'])
              <a href="{{ $datos['whatsappUrl'] }}" target="_blank" rel="noopener"
                 class="mt-1 block truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-marca-700 hover:underline">
                {{ $datos['whatsappUrl'] }}
              </a>
              <span class="mt-1 block text-xs text-slate-400">Púlsalo para comprobarlo de verdad.</span>
            @else
              <p class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-400">
                Sin número no hay enlace. Un <span class="font-mono">wa.me</span> sin destinatario abre
                la aplicación en blanco y quien lo pulsa cree que ha escrito.
              </p>
            @endif
          </div>
        </div>

        <label class="mt-4 block text-sm text-slate-600">Mensaje con el que empieza la conversación
          <textarea name="whatsapp_message" rows="2" maxlength="300"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('whatsapp_message', $fila->whatsapp_message ?? '') }}</textarea>
        </label>
      </section>

      {{-- ------------------------------------------------ contacto --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Contacto público</h2>
        <p class="mt-1 text-xs text-slate-500">
          Distinto del correo de soporte de <a href="{{ route('marca.index') }}" class="text-marca-700 hover:underline">Marca</a>:
          aquél es a donde escribe quien ya es cliente. Esto es lo que se enseña en la calle, y el
          correo es además la vía por la que se ejercen los derechos sobre los datos personales.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">Correo de contacto
            <input type="email" name="contact_email" maxlength="255"
                   value="{{ old('contact_email', $fila->contact_email ?? '') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
          </label>

          <label class="block text-sm text-slate-600">Teléfono <span class="text-slate-400">(opcional)</span>
            <input name="contact_phone" maxlength="30"
                   value="{{ old('contact_phone', $fila->contact_phone ?? '') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
          </label>
        </div>

        <label class="mt-4 block text-sm text-slate-600">Dirección que se enseña <span class="text-slate-400">(opcional)</span>
          <input name="public_address" maxlength="255"
                 value="{{ old('public_address', $fila->public_address ?? '') }}"
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
          <span class="mt-1 block text-xs text-slate-400">
            No tiene por qué ser el domicilio fiscal: ése va en la factura y sale de la sociedad.
          </span>
        </label>
      </section>

      {{-- ------------------------------------------------ L-5: los formularios --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-800">Los formularios de la calle</h2>

        <label class="mt-4 block text-sm text-slate-600">País que sale marcado
          <select name="default_country_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">— el de la sociedad operadora —</option>
            @foreach ($paises as $p)
              <option value="{{ $p->id }}"
                      @selected((string) old('default_country_id', (string) ($fila->default_country_id ?? '')) === (string) $p->id)>{{ $p->name }}</option>
            @endforeach
          </select>
          <span class="mt-1 block text-xs text-slate-400">
            @php($nombreEnVigor = $paises->firstWhere('id', $paisEnVigor)?->name)
            Hoy rige: <strong>{{ $nombreEnVigor ?: 'ninguno' }}</strong>.
            Sin esto el desplegable abre en el primero por orden alfabético, y quien no se fije
            etiquetará su lead en el país equivocado.
          </span>
        </label>
      </section>

      {{-- --------------------------------------------------- L-5: la medición --}}
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-800">Medición de visitas</h2>
        <p class="mt-1 text-xs text-slate-500">
          Los eventos ya están puestos en la portada —el botón de la cabecera, el del héroe, el
          WhatsApp, el de cada franja—. Aquí sólo se dice por dónde salen.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <label class="block text-sm text-slate-600">Proveedor
            <select name="analytics_provider" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
              <option value="">— sin medición —</option>
              @foreach ($medidores as $clave => $texto)
                <option value="{{ $clave }}"
                        @selected(old('analytics_provider', $fila->analytics_provider ?? '') === $clave)>{{ $texto }}</option>
              @endforeach
            </select>
          </label>

          <label class="block text-sm text-slate-600">Identificador
            <input name="analytics_id" maxlength="40" placeholder="G-XXXXXXXXXX"
                   value="{{ old('analytics_id', $fila->analytics_id ?? '') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <span class="mt-1 block text-xs text-slate-400">
              Sólo letras, números, punto y guion: este valor entra dentro de un
              <code>&lt;script&gt;</code> de todas las páginas públicas.
            </span>
          </label>
        </div>

        {{-- Lo que de verdad hay que saber mirando esta pantalla: si desde ESTA
             maquina sale la medicion. Un servidor de pruebas con un volcado de
             produccion trae dentro el identificador bueno, y sin esta barrera
             cada clic de una prueba se contaria como una visita real --no rompe
             nada, y por eso nadie lo notaria--. Misma llave que `9.22b`. --}}
        {{-- §56: un supuesto legal se identifica EXPLICITAMENTE, no se da por
             supuesto. Un medidor de visitas deja identificadores en el navegador
             de un tercero, y eso lo tiene que declarar la politica de
             privacidad --que existe desde `L-2b` y que nadie ha revisado
             juridicamente (`T-09`)--. Va aqui y NO como aviso en Configuracion,
             porque un ambar que no se apaga nunca acaba tapando los que si hay
             que leer (`DEC-282`). --}}
        <p class="mt-3 text-xs text-slate-500">
            Medir es tratar datos de un tercero. Si activas esto, la política de privacidad tiene
            que decir qué se mide y con qué proveedor, y puede que haga falta pedir consentimiento
            antes de cargarlo. No lo cubre ningún texto por defecto.
        </p>

        @if ($medicion['proveedor'] !== null)
          <p class="mt-4 rounded-lg px-4 py-3 text-sm
                    {{ $medicion['emite']
                        ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
                        : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
            @if ($medicion['emite'])
              Desde esta máquina <strong>sí se mide</strong>: es la instalación de producción.
            @else
              Configurada, pero <strong>desde esta máquina no se emite</strong>: no es la instalación
              de producción, y mandar visitas de prueba a la propiedad de verdad no da ningún error
              — sólo hace que los números dejen de significar algo.
            @endif
          </p>
        @endif
      </section>

      <button class="rounded-lg bg-marca-500 px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90">
        Guardar
      </button>
    </form>

    {{-- ------------------------------------------------ redes --}}
    <div class="space-y-5">
      <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Redes sociales</h2>
        <p class="mt-1 text-xs text-slate-500">
          Salen en el pie de la portada. El código de red decide el icono; uno que no conozcamos
          sale con un icono de enlace en vez de romperse.
        </p>

        @if ($redes->isEmpty())
          <p class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
            Todavía no hay ninguna.
          </p>
        @else
          <ul class="mt-4 space-y-2">
            @foreach ($redes as $red)
              <li class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                <span class="flex-1 min-w-0">
                  <span class="block text-sm text-slate-800">{{ $red->label }}</span>
                  <span class="block truncate text-xs text-slate-400">{{ $red->url }}</span>
                </span>
                @unless ($red->is_visible)
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500">apagada</span>
                @endunless
                <form method="POST" action="{{ route('sitio.red.borrar', ['red' => $red->id]) }}">
                  @csrf @method('DELETE')
                  <button class="text-xs text-rose-600 hover:underline">Quitar</button>
                </form>
              </li>
            @endforeach
          </ul>
        @endif
      </section>

      <form method="POST" action="{{ route('sitio.red') }}"
            class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
        @csrf
        <h2 class="text-sm font-semibold text-slate-900">Añadir una red</h2>

        <label class="block text-sm text-slate-600">Código
          <input name="network" maxlength="30" placeholder="instagram" required
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
        </label>

        <label class="block text-sm text-slate-600">Nombre
          <input name="label" maxlength="60" placeholder="Instagram" required
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm text-slate-600">Enlace
          <input name="url" maxlength="255" placeholder="https://instagram.com/…" required
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <div class="flex items-center gap-4">
          <label class="text-sm text-slate-600">Orden
            <input type="number" name="sort_order" value="100" min="0" max="9999"
                   class="mt-1 w-20 rounded-lg border border-slate-300 px-2 py-1">
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="is_visible" value="1" checked
                   class="rounded border border-slate-300">
            Se enseña
          </label>
        </div>

        <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
          Añadir
        </button>
      </form>
    </div>
  </div>
@endsection
