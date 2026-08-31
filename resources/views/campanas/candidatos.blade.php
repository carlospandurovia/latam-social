@extends('layouts.panel')
@section('titulo', 'Candidatos')
@section('subtitulo', $campana->code.' · '.$campana->name)

@section('contenido')
  <div class="space-y-5">
    <a href="{{ route('campanas.show', $campana->uuid) }}"
       class="text-sm text-marca-600 hover:underline">← Volver a la campaña</a>

    {{-- Lo que la campaña ya está filtrando, dicho ANTES de la lista. Sin esto,
         una búsqueda con pocos resultados parece un sistema roto en vez de una
         campaña muy acotada. --}}
    <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
      <p class="font-medium text-slate-700 mb-1">La campaña ya está filtrando por ti</p>
      <ul class="list-disc list-inside space-y-0.5 text-xs">
        <li>
          Países:
          @if ($mercados->isEmpty())
            <span class="text-amber-700">ninguno todavía — añada un mercado y volverán a salir candidatos</span>
          @else
            <strong>{{ $mercados->pluck('name')->join(', ') }}</strong>
          @endif
        </li>
        <li>
          Formatos del brief:
          @if ($requisitos->isEmpty())
            <span class="text-amber-700">el brief no pide ninguno todavía</span>
          @else
            <strong>{{ $requisitos->pluck('formato')->unique()->join(', ') }}</strong>
          @endif
        </li>
        <li>Edad mínima efectiva: <strong>{{ $edadMinima > 0 ? $edadMinima.' años' : 'sin restricción' }}</strong>
          <span class="text-slate-500">(la mayor entre la de la campaña y la de las categorías de la marca)</span></li>
        <li>Agenda libre entre el {{ $campana->starts_on }} y el {{ $campana->ends_on }}</li>
        <li>Sin restricción declarada contra las categorías de esta marca</li>
      </ul>
    </div>

    {{-- EL PRESUPUESTO. Va antes de la lista porque es el techo bajo el que se
         trabaja: verlo después de comprometer es verlo tarde. --}}
    @php
      $libre = $compromiso['presupuesto'] - $compromiso['comprometido'];
      $pasado = $libre < 0;
    @endphp
    <div class="rounded-xl border p-4 text-sm
                {{ $pasado ? 'bg-rose-50 border-rose-200' : 'bg-white border-slate-200' }}">
      <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <span class="text-slate-500">Comprometido con creadores:</span>
          <strong>{{ number_format($compromiso['comprometido'], 2) }}</strong>
          <span class="text-slate-500">de</span>
          <strong>{{ number_format($compromiso['presupuesto'], 2) }}</strong>
          {{ $campana->currency_code }}
        </div>
        <div class="{{ $pasado ? 'text-rose-700 font-medium' : 'text-slate-500' }}">
          @if ($pasado)
            Se pasa en {{ number_format(abs($libre), 2) }}
          @else
            Queda {{ number_format($libre, 2) }}
          @endif
        </div>
      </div>

      @if ($compromiso['autorizado'])
        <p class="mt-2 text-xs text-amber-800">
          <strong>Sobrecosto autorizado por finanzas.</strong> Motivo: {{ $compromiso['motivo'] }}
        </p>
      @elseif ($pasado || $compromiso['presupuesto'] <= 0)
        @can('campaign.approve')
          <form method="POST" action="{{ route('campanas.sobrecosto', $campana->uuid) }}"
                class="mt-3 flex flex-wrap items-end gap-2">
            @csrf
            <div class="grow">
              <label for="budget_override_reason" class="block text-xs text-slate-600 mb-1">
                Motivo de la autorización <span class="text-slate-400">(queda en la bitácora)</span>
              </label>
              <input id="budget_override_reason" name="budget_override_reason" maxlength="255"
                     class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <button class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700">
              Autorizar sobrecosto
            </button>
          </form>
        @else
          <p class="mt-2 text-xs text-slate-600">
            Pasarse del presupuesto lo tiene que autorizar finanzas (<code>BR-CAMPAIGN-005</code>).
          </p>
        @endcan
      @endif
    </div>

    {{-- LA LISTA CORTA primero: es el resultado del trabajo, no la búsqueda. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700 mb-3">
        Lista corta <span class="text-slate-400">({{ $lista->count() }})</span>
      </h2>

      @if ($lista->isEmpty())
        <p class="text-sm text-slate-500">Todavía no hay nadie. Añada candidatos desde la búsqueda de abajo.</p>
      @else
        <table class="w-full text-sm">
          <thead class="text-slate-500">
            <tr>
              <th class="text-left font-medium pb-2">Creador</th>
              <th class="text-left font-medium pb-2">Mercado</th>
              <th class="text-left font-medium pb-2">Estado</th>
              <th class="text-right font-medium pb-2">Monto acordado</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($lista as $p)
              <tr>
                <td class="py-2">
                  <a href="{{ route('creadores.show', $p->creador_uuid) }}"
                     class="text-marca-600 hover:underline">{{ $p->display_name }}</a>
                </td>
                <td class="py-2">{{ $p->mercado ?? '—' }}</td>
                <td class="py-2">
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $p->status }}</span>
                  {{-- 7.6: si hay invitación viva, hasta cuándo. Sin esto,
                       «¿le mandamos ya la invitación?» se contesta abriendo la
                       base de datos. --}}
                  @if ($invitaciones[$p->id] ?? null)
                    <div class="mt-0.5 text-xs text-slate-500">
                      hasta {{ \Illuminate\Support\Carbon::parse($invitaciones[$p->id]->expires_at)->format('d/m H:i') }}
                      @if ($invitaciones[$p->id]->opened_at)
                        · <span class="text-emerald-600">abierta</span>
                      @else
                        · <span class="text-slate-400">sin abrir</span>
                      @endif
                    </div>
                  @endif

                  {{-- `T-38`. Las preguntas sin atender salen en ámbar y arriba:
                       una pregunta que nadie lee es peor que no poder preguntar,
                       porque el creador se queda esperando y además cree que nos
                       importa. --}}
                  @foreach (($preguntas[$p->id] ?? collect()) as $q)
                    <div class="mt-1.5 rounded-lg border px-2 py-1.5 text-xs
                                {{ $q->seen_at ? 'border-slate-200 bg-slate-50' : 'border-amber-200 bg-amber-50' }}">
                      <p class="{{ $q->seen_at ? 'text-slate-500' : 'text-amber-900' }}">«{{ $q->body }}»</p>
                      <div class="mt-1 flex items-center gap-2">
                        <span class="text-slate-400">
                          {{ \Illuminate\Support\Carbon::parse($q->asked_at)->format('d/m H:i') }}
                        </span>
                        @if ($q->seen_at)
                          <span class="text-slate-400">· atendida por {{ $q->visto_por }}</span>
                        @else
                          @can('campaign.invite')
                            <form method="POST"
                                  action="{{ route('campanas.candidatos.pregunta', [$campana->uuid, $p->id, $q->id]) }}">
                              @csrf
                              <button class="text-amber-700 hover:underline">Me hago cargo</button>
                            </form>
                          @endcan
                        @endif
                      </div>
                    </div>
                  @endforeach
                </td>
                <td class="py-2 text-right">
                  {{-- Congelado en cuanto acepta: se enseña el número, sin
                       formulario. Un campo editable que rechaza al guardar es
                       peor que un número que se ve y no se toca. --}}
                  {{-- 9.18: cuando se pactó el NETO se enseñan las dos cifras.
                       La de arriba es la del creador —la que él conoce— y debajo
                       lo que la campaña provisiona de verdad. Enseñar sólo una
                       de las dos es donde nacen las conversaciones incómodas. --}}
                  @php $esNeto = $p->agreed_basis === 'net' && $p->agreed_net_amount !== null; @endphp

                  @if ($p->accepted_at !== null || ($invitaciones[$p->id] ?? null))
                    <span class="font-medium">{{ number_format((float) $p->agreed_amount, 2) }}</span>
                    @if ($p->accepted_at !== null)
                      <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">congelado</span>
                    @else
                      {{-- 7.6: con una invitación viva tampoco se toca — el
                           creador está mirando esa cifra. --}}
                      <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">ofrecido</span>
                    @endif
                    @if ($esNeto)
                      <p class="text-xs text-slate-400">
                        el creador recibe {{ number_format((float) $p->agreed_net_amount, 2) }}
                        · retención {{ rtrim(rtrim(number_format((float) $p->withholding_rate_snapshot, 4, '.', ''), '0'), '.') }} %
                      </p>
                    @endif
                  @elsecan('campaign.manage')
                    <form method="POST"
                          action="{{ route('campanas.candidatos.monto', [$campana->uuid, $p->id]) }}"
                          class="flex items-center justify-end gap-1">
                      @csrf
                      <select name="agreed_basis"
                              class="rounded-lg border border-slate-300 px-1 py-1 text-xs">
                        <option value="gross" @selected(! $esNeto)>cuesta</option>
                        <option value="net" @selected($esNeto)>recibe</option>
                      </select>
                      <input name="agreed_amount" type="number" step="0.01" min="0"
                             value="{{ (float) ($esNeto ? $p->agreed_net_amount : $p->agreed_amount) }}"
                             class="w-24 rounded-lg border border-slate-300 px-2 py-1 text-sm text-right">
                      <button class="text-xs text-marca-600 hover:underline">Guardar</button>
                    </form>
                    @if ($esNeto)
                      <p class="text-xs text-slate-400">
                        cuesta {{ number_format((float) $p->agreed_amount, 2) }}
                        · retención {{ rtrim(rtrim(number_format((float) $p->withholding_rate_snapshot, 4, '.', ''), '0'), '.') }} %
                      </p>
                    @endif
                  @else
                    {{ number_format((float) $p->agreed_amount, 2) }}
                  @endcan
                </td>
                <td class="py-2 text-right">
                  <div class="flex justify-end gap-3">
                    @can('campaign.invite')
                      @if ($invitaciones[$p->id] ?? null)
                        <form method="POST"
                              action="{{ route('campanas.candidatos.anular', [$campana->uuid, $p->id]) }}"
                              class="flex items-center gap-1">
                          @csrf
                          <input name="motivo" placeholder="motivo" maxlength="40"
                                 class="w-24 rounded border border-slate-300 px-1.5 py-0.5 text-xs">
                          <button class="text-xs text-amber-700 hover:underline">Anular</button>
                        </form>
                      @elseif (in_array($p->status, \App\Modules\Campaign\Services\Invitaciones::INVITABLES, true))
                        <form method="POST"
                              action="{{ route('campanas.candidatos.invitar', [$campana->uuid, $p->id]) }}">
                          @csrf
                          <button class="text-xs text-marca-600 hover:underline">
                            {{ $p->status === 'shortlisted' ? 'Invitar' : 'Volver a invitar' }}
                          </button>
                        </form>
                      @endif
                    @endcan

                    @if ($p->status === 'shortlisted')
                      @can('campaign.manage')
                        <form method="POST"
                              action="{{ route('campanas.candidatos.quitar', [$campana->uuid, $p->id]) }}">
                          @csrf @method('DELETE')
                          <button class="text-xs text-rose-600 hover:underline">Quitar</button>
                        </form>
                      @endcan
                    @endif
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>

    {{-- LA BÚSQUEDA --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700 mb-3">Buscar</h2>

      <form method="GET" class="grid gap-3 sm:grid-cols-5 items-end mb-4">
        <div class="sm:col-span-2">
          <label for="texto" class="block text-xs text-slate-600 mb-1">Nombre o usuario</label>
          <input id="texto" name="texto" value="{{ $filtros['texto'] }}"
                 class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div>
          <label for="categoria" class="block text-xs text-slate-600 mb-1">Categoría</label>
          <select id="categoria" name="categoria" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">Todas</option>
            @foreach ($categorias as $c)
              <option value="{{ $c->id }}" @selected($filtros['categoria'] === (int) $c->id)>{{ $c->code }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="formato" class="block text-xs text-slate-600 mb-1">Formato</label>
          <select id="formato" name="formato" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">Todos</option>
            @foreach ($formatos as $f)
              <option value="{{ $f->id }}" @selected($filtros['formato'] === (int) $f->id)>
                {{ $f->red ? $f->red.' · ' : '' }}{{ $f->code }}
              </option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="plataforma" class="block text-xs text-slate-600 mb-1">Red verificada</label>
          <select id="plataforma" name="plataforma" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">Cualquiera</option>
            @foreach ($plataformas as $p)
              <option value="{{ $p->id }}" @selected($filtros['plataforma'] === (int) $p->id)>{{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="sm:col-span-5 flex items-center gap-4">
          <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600">
            Buscar
          </button>
          {{-- El interruptor de auditoria: la misma consulta sin los filtros
               duros, diciendo por que cae cada uno. Contesta «¿por que no me
               sale Fulano?» sin abrir la base de datos. --}}
          <label class="flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" name="descartados" value="1" @checked($verDescartados)
                   class="rounded border-slate-300">
            Ver también los descartados, con el motivo
          </label>
          <a href="{{ route('campanas.candidatos', $campana->uuid) }}"
             class="text-xs text-slate-500 hover:underline">Limpiar</a>
        </div>
      </form>

      @if ($candidatos->isEmpty())
        <p class="text-sm text-slate-500">
          Ningún creador cumple. Marque «ver también los descartados» para saber por qué.
        </p>
      @else
        <table class="w-full text-sm">
          <thead class="text-slate-500">
            <tr>
              <th class="text-left font-medium pb-2">Creador</th>
              <th class="text-left font-medium pb-2">País</th>
              <th class="text-right font-medium pb-2">Edad</th>
              <th class="text-right font-medium pb-2">Coste estimado</th>
              @if ($verDescartados)<th class="text-left font-medium pb-2">Por qué no</th>@endif
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($candidatos as $c)
              @php
                $descartes = [];
                if ($verDescartados) {
                    foreach ($motivos as $clave => $texto) {
                        if (($c->{'descarte_'.$clave} ?? 0) == 1) { $descartes[] = $texto; }
                    }
                }
                $coste = $costes[$c->id] ?? null;
              @endphp
              <tr class="{{ $descartes !== [] ? 'opacity-60' : '' }}">
                <td class="py-2">
                  <a href="{{ route('creadores.show', $c->uuid) }}"
                     class="text-marca-600 hover:underline">{{ $c->display_name }}</a>
                  @if ($coste && $coste['formatos'] > 0)
                    <span class="ml-1 text-xs text-slate-400">{{ $coste['formatos'] }} formato(s) del brief</span>
                  @endif
                </td>
                <td class="py-2">{{ $c->pais }}</td>
                <td class="py-2 text-right">{{ $c->edad }}</td>
                <td class="py-2 text-right">
                  {{-- Un importe ausente NO es cero: es que no se puede calcular,
                       y el aviso dice por que. Ensenar «0» aqui seria ensenar un
                       creador gratis que no lo es. --}}
                  @if ($coste === null || $coste['importe'] === null)
                    <span class="text-xs text-amber-700">{{ $coste['aviso'] ?? 'sin datos' }}</span>
                  @else
                    {{ number_format($coste['importe'], 2) }} {{ $campana->currency_code }}
                  @endif
                </td>
                @if ($verDescartados)
                  <td class="py-2 text-xs text-rose-700">{{ implode('; ', $descartes) ?: '—' }}</td>
                @endif
                <td class="py-2 text-right">
                  @can('campaign.manage')
                    @if ($descartes === [])
                      <form method="POST" action="{{ route('campanas.candidatos.anadir', $campana->uuid) }}">
                        @csrf
                        <input type="hidden" name="creator_id" value="{{ $c->id }}">
                        <button class="text-xs text-marca-600 hover:underline">Añadir</button>
                      </form>
                    @endif
                  @endcan
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <p class="mt-3 text-xs text-slate-400">
          Se muestran como máximo 200. Afine los filtros si busca a alguien concreto.
        </p>
      @endif
    </div>
  </div>
@endsection
