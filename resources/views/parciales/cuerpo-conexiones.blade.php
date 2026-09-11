{{-- 9.17i: el cuerpo de la tarjeta de conexión. Antes era `panel-conexiones`
     entero, con su propio marco y su propia caja de explicación: los dos los
     pone ahora la tarjeta, y el bloque «Dónde va cada cosa» sobra porque desde
     `9.17f` el certificado y las series están en esta misma pestaña. --}}

<div class="mb-5 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
  <strong class="font-semibold text-slate-700">Un secreto entra y no vuelve a salir.</strong>
  Al guardar una credencial se ven sólo sus cuatro últimos, quién la puso y cuándo. No hay forma
  de volver a leerla: se reemplaza. Guardar una nueva revoca la anterior y queda constancia de las dos.
</div>

<div class="grid gap-5 xl:grid-cols-3">
  <div class="space-y-4 xl:col-span-2">
    @forelse ($conexiones as $c)
      <div class="overflow-hidden rounded-lg border
        {{ $c->status === 'active' ? 'border-emerald-200' : 'border-slate-200' }}">
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
          <div>
            <h3 class="text-sm font-semibold text-slate-800">{{ $c->name }}</h3>
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

        <div class="space-y-1 border-b border-slate-50 px-4 py-3 text-xs text-slate-600">
          {{-- 9.17e: se ensena la que se USA y de donde sale. «No se ve la URL»
               y «la URL esta mal» se arreglan en sitios distintos. --}}
          <p>
            <span class="text-slate-400">URL:</span>
            @if ($c->base_url)
              <span class="break-all font-mono">{{ $c->base_url }}</span>
              <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] text-amber-800">propia</span>
            @elseif ($c->url_del_proveedor)
              <span class="break-all font-mono">{{ $c->url_del_proveedor }}</span>
              <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px]">
                {{ $c->etiqueta_del_proveedor ?: 'del proveedor' }}
              </span>
            @else
              <span class="text-rose-700">— el proveedor no declara una para este entorno</span>
            @endif
          </p>
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
        <div class="px-4 py-3">
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

          {{-- Y si TIENE credenciales pero no la que hace falta, tambien se
               dice: la lista de arriba enseña lo que hay, no lo que falta.
               La cuenta la hace el controlador: una plantilla que pregunta a un
               servicio es logica de negocio en la vista. --}}
          @php($faltan = $faltanPorConexion[$c->id] ?? [])

          @if ($faltan !== [] && ($credenciales[$c->id] ?? []) !== [])
            <p class="mt-2 text-xs {{ $c->status === 'active' ? 'text-rose-700' : 'text-amber-700' }}">
              Falta la que este proveedor necesita: <strong>{{ implode(', ', $faltan) }}</strong>.
            </p>
          @endif

          {{-- L-3b: se ofrece SOLO lo que el proveedor declara. Con una sola
               clase deja de ser un desplegable: es un campo con su nombre de
               verdad --«Clave SOL del usuario secundario» dice lo que hay que
               pegar ahi; «Contraseña» no--. Sin declaracion, el catalogo entero
               y un ambar, porque un proveedor recien añadido tiene que poder
               recibir su clave hoy (`DEC-190`). --}}
          @php($oferta = $clasesPorConexion[$c->id] ?? ['clases' => $clases, 'declaradas' => false])

          @unless ($oferta['declaradas'])
            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
              Este proveedor todavía no declara qué credenciales necesita, así que se ofrecen todas
              las que existen. Elegir la que no es deja la conexión pareciendo configurada.
            </p>
          @endunless

          <form method="POST" action="{{ route('integraciones.credencial', $c->uuid) }}"
                class="mt-3 flex flex-wrap items-end gap-2">
            @csrf
            <div>
              @if (count($oferta['clases']) === 1)
                @php($unica = array_key_first($oferta['clases']))
                <input type="hidden" name="kind" value="{{ $unica }}">
                <label class="mb-1 block text-[11px] text-slate-500">Clase</label>
                <p class="rounded-lg bg-slate-50 px-2 py-1.5 text-sm text-slate-700">
                  {{ $oferta['clases'][$unica] }}
                </p>
              @else
                <label class="mb-1 block text-[11px] text-slate-500">Clase</label>
                <select name="kind" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  @foreach ($oferta['clases'] as $codigo => $texto)
                    <option value="{{ $codigo }}">{{ $texto }}</option>
                  @endforeach
                </select>
              @endif
            </div>
            <div class="min-w-[12rem] flex-1">
              <label class="mb-1 block text-[11px] text-slate-500">Valor nuevo</label>
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

        {{-- L-3a: corregir y retirar. Hasta hoy una conexion se creaba y ya no
             se podia tocar --ni el nombre--, y la ruta de actualizar existia
             sin que ninguna vista apuntara a ella (`T-131`). Va en un
             `<details>` cerrado: lo normal es mirar la conexion, no editarla. --}}
        <details class="border-t border-slate-100 px-4 py-3">
          <summary class="cursor-pointer text-xs font-medium text-slate-600 hover:text-slate-900">
            Corregir o retirar esta conexión
          </summary>

          <form method="POST" action="{{ route('integraciones.update', $c->uuid) }}"
                class="mt-3 space-y-3">
            @csrf
            @method('PUT')

            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block text-xs text-slate-500">Proveedor
                <select name="integration_provider_id" required
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  @foreach ($proveedores as $p)
                    <option value="{{ $p->id }}"
                      @selected((int) $c->integration_provider_id === (int) $p->id)>{{ $p->name }}</option>
                  @endforeach
                </select>
              </label>

              <label class="block text-xs text-slate-500">Nombre
                <input name="name" required maxlength="120" value="{{ $c->name }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              </label>

              <label class="block text-xs text-slate-500">Entorno
                <select name="environment"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  @foreach ($entornos as $codigo => $texto)
                    <option value="{{ $codigo }}"
                      @selected($c->environment === $codigo)>{{ $texto }}</option>
                  @endforeach
                </select>
              </label>

              <label class="block text-xs text-slate-500">Estado
                <select name="status"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  @foreach ($estados as $codigo => $texto)
                    <option value="{{ $codigo }}" @selected($c->status === $codigo)>{{ $texto }}</option>
                  @endforeach
                </select>
              </label>
            </div>

            <label class="block text-xs text-slate-500">Sociedad
              <select name="legal_entity_id"
                      class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Toda la plataforma</option>
                @foreach ($sociedades as $s)
                  <option value="{{ $s->id }}"
                    @selected((int) $c->legal_entity_id === (int) $s->id)>{{ $s->code }} — {{ $s->legal_name }}</option>
                @endforeach
              </select>
            </label>

            <label class="block text-xs text-slate-500">
              URL <span class="text-slate-400">— vacío = la del proveedor</span>
              <input name="base_url" maxlength="255" value="{{ $c->base_url }}"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>

            <label class="block text-xs text-slate-500">
              Usuario <span class="text-slate-400">— el secundario de SUNAT y equivalentes</span>
              <input name="username" maxlength="120" value="{{ $c->username }}"
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>

            <p class="text-xs text-slate-400">
              La contraseña no se toca desde aquí: se carga arriba y no vuelve a salir.
            </p>

            <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
              Guardar cambios
            </button>
          </form>

          {{-- Borrar de verdad SOLO lo que nunca hizo nada. Lo demas se
               desactiva arriba, en Estado: una credencial cuenta quien la puso
               y cuando, y eso no se tira. --}}
          <div class="mt-4 border-t border-slate-100 pt-3">
            @if (($borrables[$c->id] ?? 'sin comprobar') === null)
              <form method="POST" action="{{ route('integraciones.borrar', $c->uuid) }}">
                @csrf
                @method('DELETE')
                <p class="mb-2 text-xs text-slate-500">
                  Esta conexión no ha guardado ninguna credencial ni ha hecho ninguna llamada,
                  así que se puede borrar sin perder ninguna respuesta.
                </p>
                <button class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs text-rose-700
                               hover:bg-rose-50">
                  Borrar esta conexión
                </button>
              </form>
            @else
              <p class="text-xs text-slate-500">
                <span class="font-medium text-slate-700">No se puede borrar.</span>
                {{ $borrables[$c->id] }}
              </p>
            @endif
          </div>
        </details>
      </div>
    @empty
      <p class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">
        Todavía no hay ninguna conexión. La primera que hará falta es la de SUNAT.
      </p>
    @endforelse
  </div>

  <div class="space-y-5">
    <div class="rounded-lg border border-slate-200 p-4">
      <h3 class="mb-1 text-sm font-semibold text-slate-800">Conexión nueva</h3>
      <p class="mb-3 text-xs text-slate-500">
        Nace en borrador. No se usa hasta que se active. Y la contraseña se guarda después:
        aparece en la ficha de la conexión, en cuanto exista.
      </p>
      <form method="POST" action="{{ route('integraciones.store') }}" class="space-y-3">
        @csrf
        <div>
          <label for="integration_provider_id" class="mb-1 block text-xs text-slate-500">Proveedor</label>
          <select id="integration_provider_id" name="integration_provider_id" required
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ($proveedores as $p)
              <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="name" class="mb-1 block text-xs text-slate-500">Nombre</label>
          <input id="name" name="name" required maxlength="120" placeholder="SUNAT producción CTS Perú"
                 value="{{ old('name') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="environment" class="mb-1 block text-xs text-slate-500">Entorno</label>
            <select id="environment" name="environment"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($entornos as $codigo => $texto)
                <option value="{{ $codigo }}" @selected(old('environment') === $codigo)>{{ $texto }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="status" class="mb-1 block text-xs text-slate-500">Estado</label>
            <select id="status" name="status"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($estados as $codigo => $texto)
                <option value="{{ $codigo }}" @selected(old('status') === $codigo)>{{ $texto }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div>
          <label for="legal_entity_id" class="mb-1 block text-xs text-slate-500">Sociedad</label>
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
            El emisor electrónico va con la sociedad: lleva su RUC.
          </p>
        </div>
        <div>
          <label for="base_url" class="mb-1 block text-xs text-slate-500">
            URL <span class="text-slate-400">— sólo si es distinta de la del proveedor</span>
          </label>
          <input id="base_url" name="base_url" maxlength="255" placeholder="Déjalo vacío"
                 value="{{ old('base_url') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-400">
            Vacío significa la que declara el proveedor para ese entorno. Los extremos de SUNAT
            son fijos y públicos: teclearlos es la forma de que un carácter de más produzca
            comprobantes que no llegan.
          </p>
        </div>
        <div>
          <label for="username" class="mb-1 block text-xs text-slate-500">
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

    <div class="rounded-lg border border-slate-200 p-4">
      <h3 class="mb-2 text-sm font-semibold text-slate-800">A dónde llama cada entorno</h3>
      <p class="mb-3 text-xs text-slate-500">
        Estas direcciones vienen puestas. Se cambian aquí el día que el proveedor mueva una,
        sin desplegar.
      </p>
      <ul class="space-y-2 text-xs">
        @forelse ($extremos as $e)
          <li>
            <span class="text-slate-700">{{ $e->proveedor }}</span>
            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px]">{{ $entornos[$e->environment] ?? $e->environment }}</span>
            <span class="block break-all font-mono text-[11px] text-slate-500">{{ $e->base_url }}</span>
            @if ($e->notes)
              <span class="block text-[11px] text-slate-400">{{ $e->notes }}</span>
            @endif
          </li>
        @empty
          <li class="text-slate-500">Ningún proveedor declara direcciones todavía.</li>
        @endforelse
      </ul>
    </div>
  </div>
</div>
