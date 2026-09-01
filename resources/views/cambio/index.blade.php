@extends('layouts.panel')
@section('titulo', 'Tipos de cambio')
@section('subtitulo', 'Quién publica cada par, qué tasas tenemos, y si el cron sigue vivo')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Tipos de cambio'])

  {{-- Lo que hay que mirar, arriba del todo y SOLO si lo hay. Una pantalla que
       siempre tiene un aviso es una pantalla cuyos avisos nadie lee. --}}
  @if ($aviso)
    <div class="mb-5 rounded-xl bg-amber-50 border border-amber-200 p-4">
      <p class="text-sm font-medium text-amber-900">Hay algo que mirar</p>
      <p class="mt-1 text-sm text-amber-800">{{ $aviso }}</p>
    </div>
  @endif

  <div class="mb-5 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
    Un tipo de cambio es <strong>de un día</strong>, y se aplica el de la fecha de la
    operación (<code>BR-FIN-009</code>). Los días sin publicar —fines de semana y
    feriados— usan <strong>la última tasa anterior</strong>, y lo que se guarda es la fecha
    de esa tasa, no la de la operación. Pasados <strong>{{ $diasAtras }} días</strong> sin
    publicar, deja de convertirse: eso ya no es un feriado.
    <br>
    Una tasa publicada <strong>no se reescribe ni se borra</strong>. Corregir una es anotar otra.
  </div>

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">

      {{-- 1. Quién manda. Sin esto no se convierte nada. --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Quién publica cada par</h2>
        <p class="mt-1 text-xs text-slate-500">
          En cualquier fecha manda <strong>una sola</strong> fuente por par. Declarar otra
          cierra la anterior el día antes; el histórico se sigue explicando con la de entonces.
        </p>

        <table class="mt-3 w-full text-sm">
          <thead class="text-xs uppercase text-slate-400">
            <tr class="text-left">
              <th class="py-2">Par</th><th>Fuente</th><th>Desde</th><th>Hasta</th><th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse ($oficiales as $o)
              <tr>
                <td class="py-2 font-medium">{{ $o->base_currency_code }} → {{ $o->quote_currency_code }}</td>
                <td>{{ $o->source_code }}</td>
                <td class="text-slate-500">{{ $o->valid_from }}</td>
                <td class="text-slate-500">{{ $o->valid_to ?? '—' }}</td>
                <td class="text-right">
                  @if ($o->current_gate)
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">vigente</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="py-3 text-slate-500">
                Ningún par tiene fuente declarada. Mientras no la haya, no se convierte nada.
              </td></tr>
            @endforelse
          </tbody>
        </table>

        <form method="POST" action="{{ route('cambio.oficial') }}" class="mt-4 grid gap-2 sm:grid-cols-5 items-end">
          @csrf
          <div>
            <label for="base_currency_code" class="block text-xs text-slate-500 mb-1">De</label>
            <select id="base_currency_code" name="base_currency_code" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              @foreach ($monedas as $m)
                <option value="{{ $m->code }}" @selected($m->code === 'USD')>{{ $m->code }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="quote_currency_code" class="block text-xs text-slate-500 mb-1">A</label>
            <select id="quote_currency_code" name="quote_currency_code" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              @foreach ($monedas as $m)
                <option value="{{ $m->code }}" @selected($m->code === 'PEN')>{{ $m->code }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="source_code" class="block text-xs text-slate-500 mb-1">Fuente</label>
            <select id="source_code" name="source_code" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              @foreach ($fuentes as $f)
                <option value="{{ $f->code }}">{{ $f->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label for="valid_from" class="block text-xs text-slate-500 mb-1">Desde</label>
            <input id="valid_from" name="valid_from" type="date" value="{{ $hoy }}"
                   class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          </div>
          <button class="rounded-lg bg-marca-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-marca-600 transition">
            Declarar
          </button>
        </form>
        @error('base_currency_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        @error('quote_currency_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      {{-- 2. Qué tenemos. --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Últimas tasas</h2>
        <table class="mt-3 w-full text-sm">
          <thead class="text-xs uppercase text-slate-400">
            <tr class="text-left"><th class="py-2">Fecha</th><th>Par</th><th>Lado</th><th class="text-right">Tasa</th><th class="text-right">Fuente</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse ($tasas as $t)
              <tr>
                <td class="py-2 text-slate-500">{{ $t->rate_date }}</td>
                <td class="font-medium">{{ $t->base_currency_code }} → {{ $t->quote_currency_code }}</td>
                <td class="text-slate-600">{{ $lados[$t->side] ?? $t->side }}</td>
                <td class="text-right tabular-nums">{{ $t->rate }}</td>
                <td class="text-right text-slate-500">{{ $t->source }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="py-3 text-slate-500">Todavía no hay ninguna tasa.</td></tr>
            @endforelse
          </tbody>
        </table>

        {{-- Teclear a mano existe porque SUNAT sólo publica USD→PEN. Se anota
             con fuente `manual` y no disfrazada de `sunat`: eso es lo que
             permite distinguir después qué se trajo y qué se tecleó. --}}
        <details class="mt-4">
          <summary class="cursor-pointer text-sm text-slate-600">Anotar una tasa a mano</summary>
          <p class="mt-2 text-xs text-slate-500">
            Para los pares que ningún proveedor publica. Se guarda con la fuente
            <code>manual</code>, para que se sepa que la tecleó una persona.
          </p>
          <form method="POST" action="{{ route('cambio.anotar') }}" class="mt-2 grid gap-2 sm:grid-cols-6 items-end">
            @csrf
            <div>
              <label for="m_base" class="block text-xs text-slate-500 mb-1">De</label>
              <select id="m_base" name="base_currency_code" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                @foreach ($monedas as $m) <option value="{{ $m->code }}">{{ $m->code }}</option> @endforeach
              </select>
            </div>
            <div>
              <label for="m_quote" class="block text-xs text-slate-500 mb-1">A</label>
              <select id="m_quote" name="quote_currency_code" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                @foreach ($monedas as $m) <option value="{{ $m->code }}">{{ $m->code }}</option> @endforeach
              </select>
            </div>
            <div>
              <label for="m_fecha" class="block text-xs text-slate-500 mb-1">Fecha</label>
              <input id="m_fecha" name="rate_date" type="date" value="{{ $hoy }}"
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
              <label for="m_lado" class="block text-xs text-slate-500 mb-1">Lado</label>
              <select id="m_lado" name="side" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                @foreach ($lados as $codigo => $nombre)
                  <option value="{{ $codigo }}">{{ $nombre }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="m_tasa" class="block text-xs text-slate-500 mb-1">Tasa</label>
              <input id="m_tasa" name="rate" type="text" inputmode="decimal" placeholder="3.74200000"
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 transition">
              Anotar
            </button>
          </form>
          @error('rate') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </details>
      </div>

      {{-- 3. Si el cron sigue vivo. --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Últimos intentos</h2>
        <p class="mt-1 text-xs text-slate-500">
          El comando <code>cambio:traer</code> corre solo cada día a las 05:30 y pide
          los tres últimos días. Repetir uno no duplica nada.
        </p>
        <table class="mt-3 w-full text-sm">
          <thead class="text-xs uppercase text-slate-400">
            <tr class="text-left"><th class="py-2">Cuándo</th><th>Día pedido</th><th>Resultado</th><th></th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse ($corridas as $c)
              <tr>
                <td class="py-2 text-slate-500">{{ substr((string) $c->ran_at, 0, 16) }}</td>
                <td class="text-slate-600">{{ $c->requested_date }}</td>
                <td>
                  <span class="rounded-full px-2 py-0.5 text-xs
                    {{ $c->outcome === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">
                    {{ $c->outcome }}{{ $c->outcome === 'ok' && $c->rates_new ? ' · '.$c->rates_new : '' }}
                  </span>
                </td>
                <td class="text-slate-500 text-xs">{{ $c->detail }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="py-3 text-slate-500">No se ha intentado nunca.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- 4. La credencial, que desde 9.17h vive en Integraciones.

         Se mudó porque es la configuración de una INTEGRACIÓN --con quién se
         habla y con qué clave-- y no trabajo con tipos de cambio. Lo que se
         queda aquí es lo de todos los días: las tasas, quién publica cada par,
         el registro de las traídas y la carga a mano.

         Se deja un enlace y no un formulario repetido: dos puertas a lo mismo
         es lo que `9.20` vino a quitar. --}}
    <div class="space-y-5">
      <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-medium text-slate-700">Credencial de Decolecta</h2>
        @if ($credencial['origen'] === 'ninguna')
          <p class="mt-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
            <strong>No hay ninguna configurada.</strong> Hasta que la haya, el cron corre y
            no trae nada — y lo dirá aquí arriba cada día.
          </p>
        @elseif ($credencial['origen'] === 'entorno')
          <p class="mt-2 text-sm text-slate-600">
            Sale del <strong>entorno</strong> (<code>DECOLECTA_API_KEY</code>), porque no hay
            ninguna guardada.
          </p>
        @else
          <p class="mt-2 text-sm text-slate-600">
            Guardada y cifrada, termina en <code>{{ $credencial['ultimos'] }}</code>.
          </p>
        @endif
        @can('integration.manage')
          <a href="{{ route('integraciones.index', ['p' => 'fx']) }}"
             class="mt-3 inline-block rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
            Configurarla en Integraciones
          </a>
        @else
          <p class="mt-3 text-xs text-slate-500">
            Configurar credenciales exige el permiso <code>integration.manage</code>.
          </p>
        @endcan
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Traer ahora</h2>
        <p class="mt-1 text-xs text-slate-500">
          Sin esperar al cron. Sirve para comprobar que la credencial funciona.
        </p>
        <form method="POST" action="{{ route('cambio.traer') }}" class="mt-3 space-y-2">
          @csrf
          <input name="fecha" type="date" value="{{ $hoy }}" max="{{ $hoy }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('fecha') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
          <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 transition">
            Traer ese día
          </button>
        </form>
      </div>

      {{-- Lo que la pantalla NO puede arreglar, dicho donde se pregunta. --}}
      <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 text-xs text-slate-600">
        <p class="font-medium text-slate-700">Decolecta sólo trae USD → PEN</p>
        <p class="mt-1">
          Publica el tipo de cambio de <strong>SUNAT</strong>, y SUNAT sólo publica el dólar.
          Para pagar a un creador en otra moneda hará falta otra fuente o anotar la
          tasa a mano — no es un fallo del cron.
        </p>
      </div>
    </div>
  </div>
@endsection
