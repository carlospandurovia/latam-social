{{-- 9.17h: la configuración real de la fuente de tipos de cambio.

     Hasta hoy esta pestaña decía «se configura en su pantalla». Ahora es la
     pantalla: la clave, a dónde se llama, y el botón que comprueba que
     funciona. Lo que se quedó en Tipos de cambio son las tasas, quién publica
     cada par, el registro de las traídas y la carga a mano — eso es el trabajo
     diario, no la configuración de una integración. --}}

<div class="mb-5 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
  <span>
    <span class="text-slate-400">En efecto:</span>
    <strong class="font-semibold text-slate-800">
      @if ($credencial['origen'] === 'base')
        la clave guardada aquí
      @elseif ($credencial['origen'] === 'entorno')
        la del servidor ({{ '.env' }})
      @else
        ninguna — no entra ninguna tasa
      @endif
    </strong>
  </span>
  @if ($credencial['ultimos'])
    <span><span class="text-slate-400">Termina en:</span>
      <code class="rounded bg-white px-1">{{ $credencial['ultimos'] }}</code>
      @if ($credencial['version']) <span class="text-slate-400">· v{{ $credencial['version'] }}</span> @endif
    </span>
  @endif
  @if ($credencial['puesta_por'])
    <span><span class="text-slate-400">La puso:</span> {{ $credencial['puesta_por'] }}
      @if ($credencial['puesta_el']) el {{ substr((string) $credencial['puesta_el'], 0, 16) }} @endif
    </span>
  @endif
  <span><span class="text-slate-400">Llama a:</span>
    <span class="font-mono">{{ $credencial['url'] ?: $urlPorDefecto }}</span>
    @if (! $credencial['url'])
      <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[11px]">del proveedor</span>
    @endif
  </span>
</div>

<form method="POST" action="{{ route('cambio.credencial') }}" class="space-y-5">
  @csrf

  <div class="grid gap-4 sm:grid-cols-2">
    <label class="block text-sm">
      <span class="font-medium text-slate-700">
        {{ $credencial['origen'] === 'base' ? 'Reemplazar la clave' : 'Clave de la API' }}
      </span>
      {{-- `type=password` y `autocomplete=off`: no se reenseña ni se guarda en
           el gestor del navegador. Y la clave que YA está no se precarga aquí
           nunca --una pantalla que la reenseña es una pantalla que la filtra por
           encima del hombro-- (`BR-SEC-001`). --}}
      <input name="api_key" type="password" autocomplete="off"
             placeholder="{{ $credencial['origen'] === 'base' ? '•••••••• (ya configurada — escribe para reemplazar)' : '' }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      @error('api_key') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
      <span class="mt-1 block text-xs text-slate-500">
        Se guarda cifrada y no vuelve a salir. Guardar una nueva
        <strong>revoca la anterior</strong> y quedan las dos en el histórico.
      </span>
    </label>

    <label class="block text-sm">
      <span class="font-medium text-slate-700">
        URL <span class="font-normal text-slate-400">— sólo si es distinta de la del proveedor</span>
      </span>
      <input name="api_base_url" type="url" maxlength="255" placeholder="Déjalo vacío"
             value="{{ old('api_base_url', $credencial['conexion']->base_url ?? '') }}"
             class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      @error('api_base_url') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
      <span class="mt-1 block text-xs text-slate-500">
        Vacío significa <strong>la que declara el proveedor</strong>:
        <span class="font-mono">{{ $urlPorDefecto }}</span>. Es fija y pública, así que
        teclearla sólo sirve para equivocarse.
      </span>
    </label>
  </div>

  <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
    <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:opacity-90">
      Guardar la clave
    </button>
    <span class="text-xs text-slate-500">
      Guardar no trae nada todavía: pruébala aquí abajo.
    </span>
  </div>
</form>

@if ($credencial['origen'] !== 'ninguna')
  <div class="mt-5 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
    {{-- Traer de verdad es lo que convierte «configure la clave» en «la clave
         funciona», y pasa por el MISMO camino que el cron: si aqui va, manana
         tambien. --}}
    {{-- `fx.manage` y no `integration.manage`: traer es trabajo con tasas, y la
         ruta lo exige. Un boton que devuelve 403 ensena a desconfiar de lo que
         se ve, asi que a quien solo administra llaves no se le pinta. --}}
    @can('fx.manage')
    <form method="POST" action="{{ route('cambio.traer') }}" class="flex flex-wrap items-end gap-3">
      @csrf
      <input type="hidden" name="volver" value="fx">
      <label class="block text-sm">
        <span class="font-medium text-slate-700">Traer el tipo de cambio del</span>
        <input type="date" name="fecha" value="{{ $hoy }}" max="{{ $hoy }}"
               class="mt-1 block w-48 rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </label>
      <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
        Probar ahora
      </button>
    </form>
    @endcan
    <span class="pb-2 text-xs text-slate-500">
      Sólo publica <strong>USD → PEN</strong>, compra y venta: es lo único que publica SUNAT.
    </span>
  </div>

  @if ($credencial['origen'] === 'base')
    <form method="POST" action="{{ route('cambio.credencial.olvidar') }}" class="mt-4">
      @csrf @method('DELETE')
      <button class="text-xs text-slate-500 underline hover:text-rose-700">
        Retirar la clave guardada
      </button>
      <span class="ml-1 text-xs text-slate-400">
        — no se borra: queda revocada, con quién la puso y hasta cuándo estuvo en uso.
      </span>
    </form>
  @endif
@endif
