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
                </td>
                <td class="py-2 text-right">
                  @can('campaign.manage')
                    <form method="POST"
                          action="{{ route('campanas.candidatos.quitar', [$campana->uuid, $p->id]) }}">
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
