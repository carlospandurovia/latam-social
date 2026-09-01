{{-- 9.17i: los campos de la cuenta de correo. A todo el ancho y en rejilla.

     El formulario vivía en una columna de un tercio con media pantalla vacía al
     lado. En una pantalla de administración, ancho es lectura: caben la etiqueta,
     el valor y la ayuda de cada campo sin que ninguno se parta en tres líneas. --}}

{{-- Qué está en efecto AHORA. Va arriba y en una línea: es el dato que se viene
     a buscar, y hasta hoy había que deducirlo de dos cajas rojas. --}}
<div class="mb-5 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
  <span>
    <span class="text-slate-400">En efecto:</span>
    <strong class="font-semibold text-slate-800">
      @if ($efecto['origen'] === 'base') la cuenta guardada aquí @else la del servidor ({{ '.env' }}) @endif
    </strong>
  </span>
  <span><span class="text-slate-400">Transporte:</span>
    <span class="font-mono">{{ $efecto['transporte'] }}</span></span>
  @if ($efecto['host'])
    <span><span class="text-slate-400">Servidor:</span>
      <span class="font-mono">{{ $efecto['host'] }}:{{ $efecto['port'] }}</span></span>
  @endif
  @if ($efecto['from_address'])
    <span><span class="text-slate-400">Remitente:</span>
      <span class="font-mono">{{ $efecto['from_address'] }}</span></span>
  @endif
</div>

@if ($cuenta)
  {{-- El interruptor. `9.17g` guardaba y activaba en el mismo gesto y no dejaba
       camino de vuelta: apagar devuelve el correo al `.env` SIN borrar la cuenta
       ni su contraseña, para poder volver sin teclearla otra vez. --}}
  <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 px-4 py-3">
    <div class="text-sm">
      <p class="font-medium text-slate-800">{{ $cuenta->name }}</p>
      <p class="text-xs text-slate-500">
        @if ($cuenta->status === 'active')
          Encendida: el correo del sistema sale de esta cuenta.
        @else
          Apagada: guardada, con su contraseña, pero el correo sale del <span class="font-mono">.env</span>.
        @endif
        @if ($cuenta->last_success_at)
          Última prueba correcta el {{ substr((string) $cuenta->last_success_at, 0, 16) }}.
        @endif
      </p>
    </div>

    <form method="POST" action="{{ route('correo.conmutar') }}">
      @csrf
      <input type="hidden" name="encendida" value="{{ $cuenta->status === 'active' ? 0 : 1 }}">
      <button class="rounded-lg px-3 py-1.5 text-sm font-medium
        {{ $cuenta->status === 'active'
           ? 'border border-slate-300 text-slate-700 hover:bg-slate-50'
           : 'bg-emerald-600 text-white hover:opacity-90' }}">
        {{ $cuenta->status === 'active' ? 'Apagar' : 'Encender' }}
      </button>
    </form>
  </div>
@endif

<form method="POST" action="{{ route('correo.guardar') }}" class="space-y-5">
  @csrf

  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block text-sm sm:col-span-2">
      <span class="font-medium text-slate-700">Nombre de la cuenta</span>
      <input name="name" required maxlength="120" placeholder="Correo de LATAM Social"
             value="{{ old('name', $cuenta->name ?? '') }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      <span class="mt-1 block text-xs text-slate-500">Sólo para reconocerla en esta pantalla.</span>
    </label>

    <label class="block text-sm">
      <span class="font-medium text-slate-700">Servidor SMTP</span>
      <input name="host" required maxlength="160" placeholder="smtp.gmail.com"
             value="{{ old('host', $cuenta->host ?? '') }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </label>

    <label class="block text-sm">
      <span class="font-medium text-slate-700">Puerto</span>
      <input type="number" name="port" required min="1" max="65535"
             value="{{ old('port', $cuenta->port ?? 587) }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </label>

    <label class="block text-sm">
      <span class="font-medium text-slate-700">Cifrado</span>
      {{-- Columna propia y no media: «SSL (normalmente 465)» no cabe en media
           columna y el desplegable cortaba el texto justo donde estaba el dato
           util --el numero de puerto--. --}}
      <select name="encryption" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @foreach ($cifrados as $valor => $texto)
          <option value="{{ $valor }}" @selected(old('encryption', $cuenta->encryption ?? 'tls') === $valor)>
            {{ $texto }}
          </option>
        @endforeach
      </select>
    </label>
  </div>

  <p class="-mt-3 text-xs text-slate-500">
    El puerto y el cifrado van en pareja: TLS con {{ $puertos['tls'] }}, SSL con {{ $puertos['ssl'] }}.
    Cruzarlos suele no conectar, y el servidor sólo contesta con una espera agotada.
  </p>

  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block text-sm sm:col-span-2">
      <span class="font-medium text-slate-700">Usuario</span>
      <input name="username" maxlength="120" placeholder="administracion@portalcts.com"
             value="{{ old('username', $cuenta->username ?? '') }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      <span class="mt-1 block text-xs text-slate-500">No es un secreto: se ve entero.</span>
    </label>

    <label class="block text-sm sm:col-span-2">
      <span class="font-medium text-slate-700">Contraseña</span>
      <input type="password" name="password" autocomplete="new-password"
             placeholder="{{ $cuenta ? '•••••••• (ya configurada — escribe para reemplazar)' : '' }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      <span class="mt-1 block text-xs text-slate-500">
        @if ($cuenta)
          No se muestra por seguridad. Déjala vacía para conservar la actual.
        @else
          Se guarda cifrada y no vuelve a salir. En Gmail no vale la del correo: hace falta una
          contraseña de aplicación.
        @endif
      </span>
    </label>
  </div>

  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block text-sm sm:col-span-2">
      <span class="font-medium text-slate-700">Remitente (correo)</span>
      <input type="email" name="from_address" required maxlength="255" placeholder="hola@latamsocial.com"
             value="{{ old('from_address', $cuenta->from_address ?? '') }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </label>

    <label class="block text-sm">
      <span class="font-medium text-slate-700">Remitente (nombre)</span>
      <input name="from_name" required maxlength="120" placeholder="LATAM Social"
             value="{{ old('from_name', $cuenta->from_name ?? '') }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </label>

    <label class="block text-sm">
      <span class="font-medium text-slate-700">Espera máxima (s)</span>
      <input type="number" name="timeout_seconds" min="1" max="120"
             value="{{ old('timeout_seconds', $cuenta->timeout_seconds ?? 10) }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      <span class="mt-1 block text-xs text-slate-500">
        Un servidor que no contesta no puede dejar colgada la pantalla de quien espera.
      </span>
    </label>
  </div>

  <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
    <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:opacity-90">
      {{ $cuenta ? 'Guardar los cambios' : 'Guardar y encender' }}
    </button>
    <span class="text-xs text-slate-500">
      Guardar no manda nada todavía: pruébala aquí abajo antes de fiarte.
    </span>
  </div>
</form>

@if ($cuenta && $cuenta->status === 'active')
  {{-- La prueba, separada del guardado a proposito: son dos decisiones. --}}
  <form method="POST" action="{{ route('correo.probar') }}"
        class="mt-5 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
    @csrf
    <label class="block text-sm">
      <span class="font-medium text-slate-700">Mandar un correo de prueba a</span>
      <input type="email" name="destino" required placeholder="tu@correo.com"
             class="mt-1 block w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </label>
    <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
      Probar
    </button>
    <span class="pb-2 text-xs text-slate-500">
      Es lo que convierte «creo que está bien» en «funciona». El resultado queda escrito.
    </span>
  </form>

  @if ($cuenta->last_error_at && (! $cuenta->last_success_at || $cuenta->last_error_at > $cuenta->last_success_at))
    <p class="mt-3 text-xs text-rose-700">
      Última prueba fallida el {{ substr((string) $cuenta->last_error_at, 0, 16) }}:
      {{ $cuenta->last_error_message }}
    </p>
  @endif
@endif
