@extends('layouts.panel')
@section('titulo', $campana->name)
@section('subtitulo', $campana->code)

@section('contenido')
  {{-- 7.7: el acceso al panel de seguimiento. Va arriba y no en un menú porque
       según el roadmap es la pantalla más usada del sistema, y hoy la única
       forma de llegar a ella era teclear la URL. --}}
  @if ($campana->confirmed_at !== null)
    <div class="mb-5 flex justify-end">
      <a href="{{ route('campanas.seguimiento', $campana->uuid) }}"
         class="rounded-lg border border-marca-300 bg-white px-4 py-2 text-sm font-medium text-marca-700 hover:bg-marca-50">
        Ver cómo va
      </a>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Datos</h2>
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div><dt class="text-slate-500">Estado</dt>
            <dd class="font-medium">{{ $estados[$campana->status] ?? $campana->status }}</dd></div>
          <div><dt class="text-slate-500">Objetivo</dt>
            <dd>{{ $objetivos[$campana->objective] ?? $campana->objective }}</dd></div>
          <div><dt class="text-slate-500">Fechas</dt>
            <dd>{{ $campana->starts_on }} → {{ $campana->ends_on }}</dd></div>
          <div><dt class="text-slate-500">Ingreso</dt>
            <dd>
              {{ number_format((float) $campana->revenue_amount, 2) }} {{ $campana->currency_code }}
              {{-- Un cero se explica SIEMPRE, de las dos maneras: enseñar sólo la
                   etiqueta «gratuita» dejaría el otro cero --el que nadie ha
                   contestado-- pareciendo un dato normal. --}}
              @if ((float) $campana->revenue_amount <= 0)
                @if ($campana->is_gratis)
                  <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">gratuita</span>
                @else
                  <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">sin precio</span>
                @endif
              @endif
            </dd></div>
          <div><dt class="text-slate-500">Rondas incluidas</dt>
            <dd>{{ $campana->included_revision_rounds }}</dd></div>
          <div><dt class="text-slate-500">Edad mínima</dt>
            <dd>{{ $campana->min_creator_age > 0 ? $campana->min_creator_age.' años' : 'sin restricción' }}</dd></div>
        </dl>
      </div>

      {{-- El paso siguiente, donde se ve. Solo cuando la campana ya puede
           salir de borrador: buscar creadores para una campana sin brief ni
           mercados es buscar sin saber que se busca. --}}
      @can('campaign.manage')
        @if ($faltan === [])
          <div class="rounded-xl border border-marca-200 bg-marca-50 p-4 flex items-center justify-between gap-4">
            <p class="text-sm text-marca-900">
              Esta campaña ya tiene brief, mercados y precio. El paso siguiente es elegir a quién invitar.
            </p>
            <a href="{{ route('campanas.candidatos', $campana->uuid) }}"
               class="shrink-0 rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600">
              Buscar creadores
            </a>
          </div>
        @endif
      @endcan

      {{-- LOS MERCADOS. Van antes del brief porque el brief se puede partir por
           mercado y no al revés: sin países declarados, «para México» no existe
           como opción. --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Dónde se ejecuta</h2>

        @if ($mercados->isEmpty())
          <p class="text-sm text-amber-800">
            La campaña todavía no dice en qué países se ejecuta. Sin al menos un mercado no puede
            salir de borrador: de ahí sale a quién se puede invitar.
          </p>
        @else
          <table class="w-full text-sm mb-4">
            <thead class="text-slate-500">
              <tr>
                <th class="text-left font-medium pb-2">País</th>
                <th class="text-left font-medium pb-2">Brief</th>
                <th class="text-right font-medium pb-2">Creadores</th>
                <th></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($mercados as $m)
                <tr>
                  <td class="py-2">
                    <a href="{{ route('campanas.mercados.ver', [$campana->uuid, $m->id]) }}"
                       class="text-marca-600 hover:underline">{{ $m->pais }}</a>
                    <span class="text-xs text-slate-400">{{ $m->iso2 }}</span>
                  </td>
                  <td class="py-2">
                    {{-- `N-03`: si el mercado tiene requisitos propios, NO hereda
                         los generales. Decirlo aquí evita la lectura de que se
                         suman, que es la que la regla existe para descartar. --}}
                    @if (in_array($m->id, $conBriefPropio, true))
                      <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">propio</span>
                    @else
                      <span class="text-xs text-slate-500">el general</span>
                    @endif
                  </td>
                  <td class="py-2 text-right">
                    {{ $m->target_creators ?? '—' }}
                  </td>
                  <td class="py-2 text-right">
                    @can('campaign.manage')
                      <form method="POST"
                            action="{{ route('campanas.mercados.quitar', [$campana->uuid, $m->id]) }}">
                        @csrf @method('DELETE')
                        <button class="text-xs text-rose-600 hover:underline">Quitar</button>
                      </form>
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif

        {{-- Añadir SÍ se puede con la campaña confirmada: ampliar a un país nuevo
             es comercial y no rompe lo prometido. Quitar no, y de eso se encarga
             el veto del servicio. --}}
        @can('campaign.manage')
          @if ($paises->isNotEmpty())
            <form method="POST" action="{{ route('campanas.mercados.anadir', $campana->uuid) }}"
                  class="border-t border-slate-100 pt-4 grid gap-3 sm:grid-cols-4 items-end">
              @csrf
              <div class="sm:col-span-2">
                <label for="country_id" class="block text-xs text-slate-600 mb-1">País</label>
                <select id="country_id" name="country_id"
                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  @foreach ($paises as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="target_creators" class="block text-xs text-slate-600 mb-1">
                  Creadores <span class="text-slate-400">(opcional)</span>
                </label>
                <input id="target_creators" name="target_creators" type="number" min="1" max="999"
                       value="{{ old('target_creators') }}" placeholder="sin fijar"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <button class="w-full rounded-lg bg-marca-500 px-3 py-2 text-sm font-medium text-white hover:bg-marca-600">
                  Añadir
                </button>
              </div>
            </form>

            @foreach (['country_id', 'target_creators'] as $campo)
              @error($campo) <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            @endforeach
          @else
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-500">
              Todos los países del catálogo ya son mercados de esta campaña.
            </p>
          @endif
        @endcan
      </div>

      {{-- EL BRIEF. Lo primero de la columna porque es lo que decide si la
           campaña puede salir de borrador (`BR-CAMPAIGN-004`). --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Qué hay que entregar</h2>

        @if ($requisitos->isEmpty())
          <p class="text-sm text-amber-800">
            El brief todavía no dice qué hay que entregar. Sin al menos un formato, la campaña
            no puede salir de borrador: un creador no puede decidir si acepta algo que nadie
            ha descrito.
          </p>
        @else
          <table class="w-full text-sm mb-4">
            <thead class="text-slate-500">
              <tr>
                <th class="text-left font-medium pb-2">Formato</th>
                <th class="text-left font-medium pb-2">Para</th>
                <th class="text-right font-medium pb-2">Cantidad</th>
                <th class="text-right font-medium pb-2">Entrega</th>
                <th class="text-right font-medium pb-2">Permanencia</th>
                <th></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($requisitos as $r)
                <tr>
                  <td class="py-2">
                    {{ $r->red ? $r->red.' · ' : '' }}{{ $r->formato }}
                    @if ($r->notes)
                      <p class="text-xs text-slate-500">{{ $r->notes }}</p>
                    @endif
                  </td>
                  <td class="py-2">
                    @if ($r->campaign_market_id === null)
                      <span class="text-xs text-slate-500">todos los mercados</span>
                    @else
                      <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $r->mercado }}</span>
                    @endif
                  </td>
                  <td class="py-2 text-right">{{ $r->quantity }}</td>
                  <td class="py-2 text-right">{{ $r->deadline_offset_days }} d</td>
                  <td class="py-2 text-right">{{ $r->permanence_days }} d</td>
                  <td class="py-2 text-right">
                    @can('campaign.manage')
                      @if ($editable)
                        <form method="POST"
                              action="{{ route('campanas.requisitos.quitar', [$campana->uuid, $r->id]) }}">
                          @csrf @method('DELETE')
                          <button class="text-xs text-rose-600 hover:underline">Quitar</button>
                        </form>
                      @endif
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif

        @can('campaign.manage')
          {{-- `$editable` lo decide el controlador. La lista de estados NO se
               repite aqui: una plantilla que sabe cuales son los estados
               iniciales es una plantilla que hay que acordarse de tocar el dia
               que se anada uno. --}}
          @if ($editable)
            <form method="POST" action="{{ route('campanas.requisitos.anadir', $campana->uuid) }}"
                  class="border-t border-slate-100 pt-4 grid gap-3 sm:grid-cols-6 items-end">
              @csrf
              <div class="sm:col-span-2">
                <label for="content_format_id" class="block text-xs text-slate-600 mb-1">Formato</label>
                <select id="content_format_id" name="content_format_id"
                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  @foreach ($formatos as $f)
                    <option value="{{ $f->id }}" data-permanencia="{{ $f->default_permanence_days }}">
                      {{ $f->red ? $f->red.' · ' : '' }}{{ $f->code }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="campaign_market_id" class="block text-xs text-slate-600 mb-1">Para</label>
                <select id="campaign_market_id" name="campaign_market_id"
                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  {{-- Vacío = «todos los mercados» (`N-03`). Es la única vez que
                       un valor ausente SIGNIFICA algo en este modelo, así que se
                       dice con palabras en vez de con un guión. --}}
                  <option value="">Todos los mercados</option>
                  @foreach ($mercados as $m)
                    <option value="{{ $m->id }}" @selected((int) old('campaign_market_id') === (int) $m->id)>
                      Solo {{ $m->pais }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="quantity" class="block text-xs text-slate-600 mb-1">Cantidad</label>
                <input id="quantity" name="quantity" type="number" min="1" max="999"
                       value="{{ old('quantity', 1) }}"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <label for="deadline_offset_days" class="block text-xs text-slate-600 mb-1">Entrega (días)</label>
                <input id="deadline_offset_days" name="deadline_offset_days" type="number" min="0" max="365"
                       value="{{ old('deadline_offset_days', 7) }}"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <label for="permanence_days" class="block text-xs text-slate-600 mb-1">Permanencia (días)</label>
                <input id="permanence_days" name="permanence_days" type="number" min="0" max="3650"
                       value="{{ old('permanence_days', $formatos->first()->default_permanence_days ?? 30) }}"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div class="sm:col-span-5">
                <label for="notes" class="block text-xs text-slate-600 mb-1">Notas <span class="text-slate-400">(opcional)</span></label>
                <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="255"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <button class="w-full rounded-lg bg-marca-500 px-3 py-2 text-sm font-medium text-white hover:bg-marca-600">
                  Añadir
                </button>
              </div>
            </form>

            @foreach (['content_format_id', 'campaign_market_id', 'quantity', 'deadline_offset_days', 'permanence_days', 'notes'] as $campo)
              @error($campo) <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            @endforeach
          @endif
        @endcan
      </div>

      @if ($campana->briefing)
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700 mb-3">Briefing</h2>
          <p class="text-sm text-slate-600 whitespace-pre-line">{{ $campana->briefing }}</p>
        </div>
      @endif
    </div>

    <div class="space-y-5">
      {{-- QUIÉN FACTURA, y de dónde sale.
           Se enseña el dato GUARDADO, no el que devolvería la cobertura hoy:
           `BR-LE-001` dice que nunca se deduce de la configuración vigente en el
           momento de la consulta. Cuando los dos no coinciden se dice, porque
           entonces hay algo que mirar. --}}
      {{-- Se enseña ANTES de que nadie pulse un botón. Descubrir lo que falta
           al intentar mover la campaña es enterarse tarde, y con todos los
           motivos de una vez para no tener que volver dos veces. --}}
      @if ($faltan !== [])
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
          <p class="font-medium">Todavía no puede salir de borrador</p>
          <ul class="mt-2 space-y-1 list-disc list-inside">
            @foreach ($faltan as $motivo)
              <li>{{ $motivo }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Quién la factura y quién paga</h2>

        @if ($sociedad['guardada'])
          {{-- El nombre sale de la fila GUARDADA en la campaña, no de resolver la
               cobertura otra vez. Es `BR-LE-001` y es `T-58`. --}}
          <p class="text-sm font-medium">{{ $sociedad['guardada']->legal_name }}</p>
          <p class="text-xs text-slate-500">{{ $sociedad['guardada']->code }}</p>

          {{-- El PORQUÉ, no sólo el qué. Una sociedad a secas no se puede
               comprobar; con su motivo y su fecha, quien la lee sabe si es la
               que esperaba y puede discutirla. --}}
          @if ($sociedad['cobertura']->hay() && ! $sociedad['discrepa'])
            <p class="mt-2 text-xs text-slate-600">{{ $sociedad['cobertura']->explicacion }}</p>
          @endif
          <p class="mt-1 text-xs text-slate-500">
            Resuelto al {{ $campana->starts_on }}, la fecha en que empieza el servicio
            (<code>BR-LE-003</code>). No se elige a mano: en cualquier fecha hay como
            mucho una sociedad que cubra un país.
          </p>

          {{-- `BR-LE-009`: la que factura al cliente es la que liquida a TODOS los
               creadores de la campaña, sea cual sea el país de cada uno. Se dice
               aquí porque es donde se mira antes de invitar a nadie. --}}
          <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
            Paga a <strong>todos</strong> los creadores de esta campaña, también a los de
            otro país (<code>BR-LE-009</code>). El país del creador cambia cómo se le
            paga —retenciones, moneda, documento—, no quién le paga.
          </p>

          {{-- Si la cobertura de hoy diría otra cosa, se dice. Callarlo sería
               dejar que la pantalla y la factura cuenten cosas distintas. --}}
          @if ($sociedad['discrepa'])
            <p class="mt-3 rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
              <strong>Hay algo que mirar.</strong> Con la cobertura tal como está hoy, a esta
              campaña le tocaría {{ $sociedad['cobertura']->entidad->code }}
              ({{ $sociedad['cobertura']->explicacion }}). Manda la guardada, que es la que
              va en la factura; la diferencia significa que alguien corrigió un periodo de
              cobertura después de crearse esta campaña.
            </p>
          @endif

          @if ($campana->confirmed_at)
            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
              Congelada: la campaña se confirmó el {{ $campana->confirmed_at }} y su sociedad
              ya no se puede cambiar (<code>BR-LE-002</code>). Corregirla exige anular la
              campaña y crear otra.
            </p>
          @endif
        @else
          <p class="text-sm text-amber-800">{{ $sociedad['cobertura']->explicacion }}</p>
          <p class="mt-2 text-xs text-slate-500">
            Hasta que exista esa cobertura, la campaña no puede salir de borrador.
          </p>
        @endif
      </div>

      @if ($transiciones !== [])
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700 mb-3">Mover a</h2>
          {{-- Sólo lo que ESTE usuario puede hacer: un botón que va a dar 403
               es peor que no tenerlo. --}}
          <div class="flex flex-wrap gap-2">
            @foreach ($transiciones as $destino => $nombre)
              <form method="POST" action="{{ route('campanas.estado', $campana->uuid) }}">
                @csrf
                <input type="hidden" name="estado" value="{{ $destino }}">
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                  {{ $nombre }}
                </button>
              </form>
            @endforeach
          </div>
        </div>
      @endif

      @can('campaign.manage')
        @if (in_array($campana->status, ['draft', 'pending_approval', 'cancelled'], true))
          <a href="{{ route('campanas.edit', $campana->uuid) }}"
             class="block text-center rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
            Editar
          </a>
        @endif
      @endcan
    </div>
  </div>
@endsection
