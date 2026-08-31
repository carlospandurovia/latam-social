@extends('layouts.panel')
@section('titulo', 'Integraciones')
@section('subtitulo', 'Las credenciales de cada API, en un solo sitio')

@section('contenido')
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

  <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Un secreto entra y no vuelve a salir</p>
    <p>
      Al guardar una credencial se ve sólo sus cuatro últimos, quién la puso y cuándo. No hay
      forma de volver a leerla: se reemplaza. Guardar una nueva <strong>revoca la anterior</strong>
      y queda constancia de las dos.
    </p>
  </div>

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
      @forelse ($conexiones as $c)
        <div class="bg-white rounded-xl border overflow-hidden
          {{ $c->status === 'active' ? 'border-emerald-200' : 'border-slate-200' }}">
          <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <h2 class="text-sm font-semibold">{{ $c->name }}</h2>
              <p class="text-xs text-slate-500">
                {{ $c->proveedor_nombre }} · {{ $entornos[$c->environment] ?? $c->environment }}
                @if ($c->sociedad) · {{ $c->sociedad }} @else · toda la plataforma @endif
              </p>
            </div>
            <span class="rounded px-2 py-0.5 text-xs
              {{ $c->status === 'active' ? 'bg-emerald-100 text-emerald-800'
                 : ($c->status === 'draft' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') }}">
              {{ $estados[$c->status] ?? $c->status }}
            </span>
          </div>

          <div class="px-5 py-3 space-y-1 text-xs text-slate-600 border-b border-slate-50">
            <p><span class="text-slate-400">URL:</span> {{ $c->base_url ?: '— sin poner' }}</p>
            @if ($c->username)
              <p><span class="text-slate-400">Usuario:</span> {{ $c->username }}</p>
            @endif
            @if ($c->last_error_at && (! $c->last_success_at || $c->last_error_at > $c->last_success_at))
              <p class="text-rose-700">
                Último intento fallido el {{ substr((string) $c->last_error_at, 0, 16) }}:
                {{ $c->last_error_message }}
              </p>
            @elseif ($c->last_success_at)
              <p class="text-emerald-700">
                Última llamada buena el {{ substr((string) $c->last_success_at, 0, 16) }}
              </p>
            @endif
          </div>

          {{-- Las credenciales VIVAS. Nunca su valor. --}}
          <div class="px-5 py-3">
            @forelse ($credenciales[$c->id] ?? [] as $cred)
              <p class="text-xs text-slate-600">
                <span class="font-medium">{{ $clases[$cred['clase']] ?? $cred['clase'] }}</span>
                · termina en <code class="rounded bg-slate-100 px-1">{{ $cred['ultimos'] ?: '····' }}</code>
                · v{{ $cred['version'] }}
                @if ($cred['puesta_por']) · la puso {{ $cred['puesta_por'] }} @endif
                el {{ substr($cred['puesta_el'], 0, 16) }}
              </p>
            @empty
              <p class="text-xs {{ $c->status === 'active' ? 'text-rose-700' : 'text-slate-400' }}">
                Sin credenciales.
                @if ($c->status === 'active')
                  La conexión está activa, así que parece configurada y la primera llamada saldría sin clave.
                @endif
              </p>
            @endforelse

            <form method="POST" action="{{ route('integraciones.credencial', $c->uuid) }}"
                  class="mt-3 flex flex-wrap items-end gap-2">
              @csrf
              <div>
                <label class="block text-[11px] text-slate-500 mb-1">Clase</label>
                <select name="kind" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  @foreach ($clases as $codigo => $texto)
                    <option value="{{ $codigo }}">{{ $texto }}</option>
                  @endforeach
                </select>
              </div>
              <div class="flex-1 min-w-[12rem]">
                <label class="block text-[11px] text-slate-500 mb-1">Valor nuevo</label>
                {{-- `type=password` y `autocomplete=off`: no se guarda en el
                     gestor del navegador ni se lee por encima del hombro. --}}
                <input name="secreto" type="password" autocomplete="off" required minlength="4"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <button class="rounded-lg bg-navy px-3 py-1.5 text-sm text-white hover:opacity-90">
                Guardar
              </button>
            </form>
          </div>
        </div>
      @empty
        <p class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">
          Todavía no hay ninguna conexión. La primera que hará falta es la de SUNAT, para
          la facturación electrónica.
        </p>
      @endforelse
    </div>

    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-1">Conexión nueva</h2>
        <p class="text-xs text-slate-400 mb-3">
          Nace en borrador. No se usa hasta que se active, y para activarla hace falta la URL.
        </p>
        <form method="POST" action="{{ route('integraciones.store') }}" class="space-y-3">
          @csrf
          <div>
            <label for="integration_provider_id" class="block text-xs text-slate-500 mb-1">Proveedor</label>
            <select id="integration_provider_id" name="integration_provider_id" required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($proveedores as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="name" class="block text-xs text-slate-500 mb-1">Nombre</label>
            <input id="name" name="name" required maxlength="120" placeholder="SUNAT producción CTS Perú"
                   value="{{ old('name') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label for="environment" class="block text-xs text-slate-500 mb-1">Entorno</label>
              <select id="environment" name="environment"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($entornos as $codigo => $texto)
                  <option value="{{ $codigo }}" @selected(old('environment') === $codigo)>{{ $texto }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="status" class="block text-xs text-slate-500 mb-1">Estado</label>
              <select id="status" name="status"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($estados as $codigo => $texto)
                  <option value="{{ $codigo }}" @selected(old('status') === $codigo)>{{ $texto }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div>
            <label for="legal_entity_id" class="block text-xs text-slate-500 mb-1">Sociedad</label>
            <select id="legal_entity_id" name="legal_entity_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">Toda la plataforma</option>
              @foreach ($sociedades as $s)
                <option value="{{ $s->id }}" @selected((string) old('legal_entity_id') === (string) $s->id)>
                  {{ $s->code }} — {{ $s->legal_name }}
                </option>
              @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-400">
              El emisor electrónico va con la sociedad: lleva su RUC. El correo o los tipos de
              cambio son de toda la plataforma.
            </p>
          </div>
          <div>
            <label for="base_url" class="block text-xs text-slate-500 mb-1">URL</label>
            <input id="base_url" name="base_url" maxlength="255" placeholder="https://…"
                   value="{{ old('base_url') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label for="username" class="block text-xs text-slate-500 mb-1">
              Usuario <span class="text-slate-400">— el secundario de SUNAT y equivalentes</span>
            </label>
            <input id="username" name="username" maxlength="120" value="{{ old('username') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-slate-400">No es un secreto: se ve entero.</p>
          </div>
          <button class="w-full rounded-lg bg-navy px-4 py-2.5 text-sm font-medium text-white hover:opacity-90">
            Crear conexión
          </button>
        </form>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-semibold mb-2">Qué NO está aquí todavía</h2>
        <ul class="space-y-2 text-xs text-slate-500">
          <li>
            <span class="text-slate-700">El certificado digital</span> de SUNAT. Es un secreto de
            otra clase —un archivo— y va con la facturación electrónica, no con esta pantalla.
          </li>
          <li>
            <span class="text-slate-700">La clave de los tipos de cambio</span> sigue en
            <a href="{{ route('cambio.index') }}" class="text-marca-700 hover:underline">Tipos de cambio</a>,
            donde vive desde 9.2. Moverla es una migración de datos con riesgo y sin ganancia hoy.
          </li>
        </ul>
      </div>
    </div>
  </div>
@endsection
