@extends('layouts.panel')
@section('titulo', 'Series y correlativos')
@section('subtitulo', 'Un número sale una sola vez, y lo que sale queda escrito')

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

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-800'
         : 'bg-amber-50 border-amber-200 text-amber-800' }}">
      <span class="font-semibold uppercase text-xs mr-2">
        {{ $aviso->nivel === 'rojo' ? 'Prioridad alta' : 'Conviene revisar' }}
      </span>
      {{ $aviso->texto }}
    </div>
  @endforeach

  <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Aquí se configura; el número lo pide quien emite</p>
    <p>
      El correlativo se reserva <strong>bajo bloqueo</strong> cuando se emite un documento, y cada
      número que sale queda escrito con su estado. Un número reservado que nunca llegó a documento
      se <strong>anula con un motivo</strong>: el hueco existe, pero queda explicado. No se
      reutiliza nunca — reutilizarlo sería emitir dos comprobantes con el mismo número.
    </p>
  </div>

  <div class="grid gap-5 lg:grid-cols-3">
    {{-- ----------------------------------------------------------- series --}}
    <div class="lg:col-span-2 space-y-4">
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Series</h2>
        </div>

        @if ($series->isEmpty())
          <p class="px-5 py-6 text-sm text-slate-500">
            Todavía no hay ninguna serie. Ésta es la única configuración que no trae valor de
            fábrica: una serie se registra ante la administración tributaria, y una inventada
            produciría comprobantes inválidos.
          </p>
        @else
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th class="px-4 py-2 text-left">Sociedad</th>
                  <th class="px-4 py-2 text-left">Tipo</th>
                  <th class="px-4 py-2 text-left">Serie</th>
                  <th class="px-4 py-2 text-right">Siguiente</th>
                  <th class="px-4 py-2 text-left">Entorno</th>
                  <th class="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                @foreach ($series as $s)
                  <tr class="{{ (int) $s->id === (int) $verId ? 'bg-marca-50' : '' }}">
                    <td class="px-4 py-2">{{ $s->sociedad }}</td>
                    <td class="px-4 py-2">{{ $s->tipo }}</td>
                    <td class="px-4 py-2 font-mono">
                      {{ $s->series }}
                      @if ($s->is_default)
                        <span class="ml-1 rounded bg-marca-500 px-1.5 py-0.5 text-[10px] text-white">por defecto</span>
                      @endif
                      @unless ($s->is_active)
                        <span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-600">apagada</span>
                      @endunless
                    </td>
                    <td class="px-4 py-2 text-right font-mono">
                      {{ $s->series }}-{{ str_pad((string) $s->next_number, (int) $s->number_length, '0', STR_PAD_LEFT) }}
                    </td>
                    <td class="px-4 py-2 text-xs">{{ $entornos[$s->environment] ?? $s->environment }}</td>
                    <td class="px-4 py-2 text-right">
                      <a class="text-xs text-marca-700 underline"
                         href="{{ route('series.index', ['serie' => $s->id]) }}">ver el libro</a>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>

      {{-- ------------------------------------------------ el libro de una serie --}}
      @if ($ultimos->isNotEmpty())
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div class="px-5 py-3 border-b border-slate-100">
            <h2 class="text-sm font-semibold">Los últimos números de esa serie</h2>
            <p class="text-xs text-slate-500">Ni se borran ni se reescriben.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th class="px-4 py-2 text-left">Número</th>
                  <th class="px-4 py-2 text-left">Estado</th>
                  <th class="px-4 py-2 text-left">Reservado</th>
                  <th class="px-4 py-2 text-left">Documento / motivo</th>
                  <th class="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                @foreach ($ultimos as $n)
                  <tr>
                    <td class="px-4 py-2 font-mono">{{ $n->full_number }}</td>
                    <td class="px-4 py-2 text-xs">
                      <span class="rounded px-2 py-0.5
                        {{ $n->status === 'used' ? 'bg-emerald-100 text-emerald-800'
                           : ($n->status === 'voided' ? 'bg-slate-200 text-slate-700' : 'bg-amber-100 text-amber-800') }}">
                        {{ $estados[$n->status] ?? $n->status }}
                      </span>
                    </td>
                    <td class="px-4 py-2 text-xs text-slate-500">
                      {{ substr((string) $n->reserved_at, 0, 16) }}
                      @if ($n->autor) · {{ $n->autor }} @endif
                    </td>
                    <td class="px-4 py-2 text-xs text-slate-600">
                      @if ($n->status === 'used')
                        {{ $n->entity_type }} #{{ $n->entity_id }}
                      @elseif ($n->status === 'voided')
                        {{ $n->void_reason }}
                      @else
                        <span class="text-slate-400">todavía sin documento</span>
                      @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                      @if ($n->status === 'reserved')
                        <form method="POST" action="{{ route('series.anular', $n->id) }}"
                              class="flex items-center justify-end gap-1">
                          @csrf
                          <input type="hidden" name="serie" value="{{ $verId }}">
                          <input name="motivo" required minlength="10" maxlength="255"
                                 placeholder="Por qué no se emitió"
                                 class="w-52 rounded border-slate-300 text-xs">
                          <button class="rounded bg-slate-700 px-2 py-1 text-xs text-white">anular</button>
                        </form>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      @endif

      {{-- ------------------------------------------------------ tipos por país --}}
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Tipos de comprobante, por país</h2>
          <p class="text-xs text-slate-500">
            El país declara los suyos: su código oficial, la forma de la serie y cuántos dígitos
            tiene el correlativo. Añadir uno es una fila, no un despliegue.
          </p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th class="px-4 py-2 text-left">País</th>
                <th class="px-4 py-2 text-left">Tipo</th>
                <th class="px-4 py-2 text-left">Código oficial</th>
                <th class="px-4 py-2 text-left">Serie</th>
                <th class="px-4 py-2 text-right">Dígitos</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @forelse ($tipos as $t)
                <tr class="{{ $t->is_active ? '' : 'text-slate-400' }}">
                  <td class="px-4 py-2">{{ $t->pais }}</td>
                  <td class="px-4 py-2">{{ $t->name }} <span class="text-xs text-slate-400">{{ $t->code }}</span></td>
                  <td class="px-4 py-2 font-mono text-xs">{{ $t->official_code ?: '—' }}</td>
                  <td class="px-4 py-2 text-xs">{{ $t->series_label ?: 'cualquiera' }}</td>
                  <td class="px-4 py-2 text-right font-mono text-xs">{{ $t->number_length }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="px-4 py-6 text-sm text-slate-500">
                  Todavía no hay tipos de comprobante. Sin ellos no se puede crear ninguna serie.
                </td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>

    {{-- ------------------------------------------------------------ formularios --}}
    <div class="space-y-5">
      <form method="POST" action="{{ route('series.serie') }}"
            class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        @csrf
        <h2 class="text-sm font-semibold">Nueva serie</h2>

        <label class="block text-xs text-slate-500">Sociedad
          <select name="legal_entity_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
            @foreach ($sociedades as $s)
              <option value="{{ $s->id }}">{{ $s->code }} — {{ $s->legal_name }}</option>
            @endforeach
          </select>
        </label>

        <label class="block text-xs text-slate-500">Tipo de comprobante
          <select name="document_type_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
            @foreach ($tipos as $t)
              <option value="{{ $t->id }}">{{ $t->pais }} · {{ $t->name }}</option>
            @endforeach
          </select>
          <span class="text-[11px] text-slate-400">Tiene que ser del país de la sociedad.</span>
        </label>

        <label class="block text-xs text-slate-500">Serie
          <input name="series" required maxlength="10" placeholder="F001"
                 class="mt-1 w-full rounded border-slate-300 text-sm font-mono">
        </label>

        <label class="block text-xs text-slate-500">Siguiente número
          <input type="number" name="next_number" min="1" value="1"
                 class="mt-1 w-full rounded border-slate-300 text-sm">
          <span class="text-[11px] text-slate-400">
            Sólo se dice al crearla: una serie que ya circulaba no empieza en 1.
          </span>
        </label>

        <label class="block text-xs text-slate-500">Entorno
          <select name="environment" required class="mt-1 w-full rounded border-slate-300 text-sm">
            @foreach ($entornos as $clave => $texto)
              <option value="{{ $clave }}" @selected($clave === 'production')>{{ $texto }}</option>
            @endforeach
          </select>
        </label>

        <label class="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300"> Activa
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="is_default" value="1" class="rounded border-slate-300">
          La que se usa por defecto para ese tipo
        </label>

        <button class="w-full rounded bg-marca-500 px-3 py-2 text-sm text-white">Guardar serie</button>
      </form>

      <form method="POST" action="{{ route('series.tipo') }}"
            class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        @csrf
        <h2 class="text-sm font-semibold">Nuevo tipo de comprobante</h2>

        <label class="block text-xs text-slate-500">País
          <select name="country_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
            @foreach ($paises as $p)
              <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
          </select>
        </label>

        <label class="block text-xs text-slate-500">Código
          <input name="code" required maxlength="30" placeholder="invoice"
                 class="mt-1 w-full rounded border-slate-300 text-sm font-mono">
          <span class="text-[11px] text-slate-400">Minúsculas, sin espacios. Se cita en informes.</span>
        </label>

        <label class="block text-xs text-slate-500">Nombre
          <input name="name" required maxlength="80" placeholder="Factura electrónica"
                 class="mt-1 w-full rounded border-slate-300 text-sm">
        </label>

        <label class="block text-xs text-slate-500">Código oficial
          <input name="official_code" maxlength="5" placeholder="01"
                 class="mt-1 w-full rounded border-slate-300 text-sm font-mono">
          <span class="text-[11px] text-slate-400">El que viaja al emisor electrónico.</span>
        </label>

        <label class="block text-xs text-slate-500">Forma de la serie
          <input name="series_pattern" maxlength="120" placeholder="^F[A-Z0-9]{3}$"
                 class="mt-1 w-full rounded border-slate-300 text-sm font-mono">
        </label>

        <label class="block text-xs text-slate-500">Cómo se pide la serie
          <input name="series_label" maxlength="60" placeholder="Serie (F y tres más)"
                 class="mt-1 w-full rounded border-slate-300 text-sm">
          <span class="text-[11px] text-slate-400">Obligatorio si se pone una forma.</span>
        </label>

        <label class="block text-xs text-slate-500">Dígitos del correlativo
          <input type="number" name="number_length" min="1" max="12" value="8" required
                 class="mt-1 w-full rounded border-slate-300 text-sm">
        </label>

        <label class="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="requires_customer_tax_id" value="1" class="rounded border-slate-300">
          Exige identificación fiscal del cliente
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300"> Activo
        </label>

        <button class="w-full rounded bg-slate-700 px-3 py-2 text-sm text-white">Guardar tipo</button>
      </form>
    </div>
  </div>
@endsection
