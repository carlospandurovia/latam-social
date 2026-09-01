@extends('layouts.panel')
@section('titulo', 'Tarifas y agenda de '.$creador->display_name)
@section('subtitulo', 'Cuánto cuesta y cuándo puede trabajar · lo que hace falta para invitarlo a una campaña')

@section('contenido')
<div class="max-w-5xl">

  <a href="{{ route('creadores.show', $creador->uuid) }}" class="text-sm text-slate-500 hover:text-slate-800">← Volver a la ficha</a>

  @if (session('exito'))
    <div class="mt-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">{{ session('exito') }}</div>
  @endif
  @if (session('aviso'))
    <div class="mt-4 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 text-sm">{{ session('aviso') }}</div>
  @endif
  @if ($errors->any())
    <div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm">
      <ul class="list-disc list-inside space-y-0.5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  {{-- ------------------------------------------------------------ tarifas --}}
  <h2 class="mt-8 font-semibold text-slate-900">Tarifas</h2>
  <p class="mt-1 text-sm text-slate-500">
    Una vigente por formato y moneda. Cambiar de tarifa no edita la anterior: la cierra
    el día antes y abre un periodo nuevo, para que el histórico tenga una sola respuesta por fecha.
  </p>

  <div class="mt-4 bg-white rounded-2xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
        <tr>
          <th class="px-5 py-3">Formato</th>
          <th class="px-5 py-3">Importe</th>
          <th class="px-5 py-3">Origen</th>
          <th class="px-5 py-3">Vigencia</th>
          <th class="px-5 py-3">La puso</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($tarifas as $t)
          <tr class="border-b border-slate-50 @if ($t->valid_to === null) bg-emerald-50/40 @endif">
            <td class="px-5 py-3">{{ $t->plataforma }} · <code class="text-xs">{{ $t->formato }}</code></td>
            <td class="px-5 py-3 font-medium">
              @if ($t->is_gratis)
                <span class="text-slate-500">gratuita</span>
              @else
                {{ $t->currency_code }} {{ number_format((float) $t->amount, 2) }}
              @endif
            </td>
            <td class="px-5 py-3">
              {{-- El origen no es adorno: «lo declaró el creador» y «lo estimamos
                   nosotros» se defienden distinto delante de un cliente. --}}
              <span class="inline-flex px-2 py-0.5 rounded-full text-xs
                @class([
                  'bg-slate-100 text-slate-700' => $t->source === 'self_declared',
                  'bg-marca-50 text-marca-700' => $t->source === 'negotiated',
                  'bg-amber-50 text-amber-800' => $t->source === 'estimated',
                ])">{{ $t->source }}</span>
            </td>
            <td class="px-5 py-3 text-slate-600">
              {{ $t->valid_from }} → {{ $t->valid_to ?? 'vigente' }}
            </td>
            <td class="px-5 py-3 text-slate-500">{{ $t->puesta_por ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="px-5 py-4 text-slate-500">Todavía no tiene ninguna tarifa. Sin tarifa no se le puede invitar a una campaña.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @can('creator.rate.manage')
    <form method="POST" action="{{ route('creadores.comercial.tarifa', $creador->uuid) }}"
          class="mt-4 bg-white rounded-2xl border border-slate-200 p-6 grid gap-4 sm:grid-cols-3">
      @csrf
      <div class="sm:col-span-2">
        <label class="block text-sm text-slate-700 mb-1" for="content_format_id">Formato</label>
        <select id="content_format_id" name="content_format_id" class="w-full rounded-lg border border-slate-300 text-sm">
          @foreach ($formatos as $f)<option value="{{ $f->id }}">{{ $f->plataforma }} · {{ $f->code }}</option>@endforeach
        </select>
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="currency_code">Moneda</label>
        <select id="currency_code" name="currency_code" class="w-full rounded-lg border border-slate-300 text-sm">
          @foreach ($monedas as $m)<option value="{{ $m->code }}">{{ $m->code }}</option>@endforeach
        </select>
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="amount">Importe</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}"
               class="w-full rounded-lg border border-slate-300 text-sm">
        <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="is_gratis" value="1" class="rounded border border-slate-300" @checked(old('is_gratis'))>
          Colaboración gratuita (canje, primera vez)
        </label>
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="source">¿De dónde sale el precio?</label>
        <select id="source" name="source" class="w-full rounded-lg border border-slate-300 text-sm">
          <option value="self_declared">Lo declaró el creador</option>
          <option value="negotiated">Negociado</option>
          <option value="estimated">Estimado por nosotros</option>
        </select>
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="valid_from">Vigente desde</label>
        <input id="valid_from" name="valid_from" type="date" value="{{ old('valid_from', $hoy) }}"
               class="w-full rounded-lg border border-slate-300 text-sm">
      </div>
      <div class="sm:col-span-3">
        <button class="px-4 py-2 rounded-xl bg-marca-600 text-white text-sm font-medium hover:bg-marca-700">Registrar tarifa</button>
      </div>
    </form>
  @endcan

  {{-- ----------------------------------------------------- disponibilidad --}}
  <h2 class="mt-10 font-semibold text-slate-900">Disponibilidad</h2>

  <div class="mt-4 bg-white rounded-2xl border border-slate-200 overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
        <tr>
          <th class="px-5 py-3">Vigencia</th>
          <th class="px-5 py-3">Viaja</th>
          <th class="px-5 py-3">Presencial</th>
          <th class="px-5 py-3">Solo producto</th>
          <th class="px-5 py-3">Máx./mes</th>
          <th class="px-5 py-3">Antelación</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($disponibilidades as $d)
          <tr class="border-b border-slate-50 @if ($d->valid_to === null) bg-emerald-50/40 @endif">
            <td class="px-5 py-3 text-slate-600">{{ $d->valid_from }} → {{ $d->valid_to ?? 'vigente' }}</td>
            <td class="px-5 py-3">{{ $d->accepts_travel ? $d->travel_scope : 'no' }}</td>
            <td class="px-5 py-3">{{ $d->accepts_in_person ? 'sí' : 'no' }}</td>
            <td class="px-5 py-3">{{ $d->accepts_product_only ? 'sí' : 'no' }}</td>
            <td class="px-5 py-3">{{ $d->max_campaigns_per_month ?? 'sin límite' }}</td>
            <td class="px-5 py-3">{{ $d->min_lead_time_days }} d</td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-5 py-4 text-slate-500">No ha declarado disponibilidad.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @can('creator.rate.manage')
    <form method="POST" action="{{ route('creadores.comercial.disponibilidad', $creador->uuid) }}"
          class="mt-4 bg-white rounded-2xl border border-slate-200 p-6 grid gap-4 sm:grid-cols-3">
      @csrf
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="travel_scope">¿Viaja?</label>
        <select id="travel_scope" name="travel_scope" class="w-full rounded-lg border border-slate-300 text-sm">
          <option value="">No viaja</option>
          <option value="local">Sí, local</option>
          <option value="national">Sí, nacional</option>
          <option value="international">Sí, internacional</option>
        </select>
        <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" name="accepts_travel" value="1" class="rounded border border-slate-300">
          Marcar si viaja (y elige el alcance arriba)
        </label>
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="max_campaigns_per_month">Máx. campañas/mes</label>
        <input id="max_campaigns_per_month" name="max_campaigns_per_month" type="number" min="1" max="200"
               value="{{ old('max_campaigns_per_month') }}" class="w-full rounded-lg border border-slate-300 text-sm"
               placeholder="sin límite">
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="min_lead_time_days">Antelación mínima (días)</label>
        <input id="min_lead_time_days" name="min_lead_time_days" type="number" min="0" max="365"
               value="{{ old('min_lead_time_days', 3) }}" class="w-full rounded-lg border border-slate-300 text-sm">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm text-slate-700 mb-1" for="notes">Notas</label>
        <input id="notes" name="notes" maxlength="255" value="{{ old('notes') }}"
               class="w-full rounded-lg border border-slate-300 text-sm">
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="disp_valid_from">Vigente desde</label>
        <input id="disp_valid_from" name="valid_from" type="date" value="{{ old('valid_from', $hoy) }}"
               class="w-full rounded-lg border border-slate-300 text-sm">
      </div>
      <div class="sm:col-span-3 flex flex-wrap gap-4 items-center">
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="accepts_in_person" value="1" class="rounded border border-slate-300" checked>
          Acepta trabajo presencial
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" name="accepts_product_only" value="1" class="rounded border border-slate-300">
          Acepta canje por producto
        </label>
        <button class="ml-auto px-4 py-2 rounded-xl bg-marca-600 text-white text-sm font-medium hover:bg-marca-700">
          Registrar disponibilidad
        </button>
      </div>
    </form>
  @endcan

  {{-- ---------------------------------------------------------- bloqueos --}}
  <h2 class="mt-10 font-semibold text-slate-900">Bloqueos de agenda</h2>
  <p class="mt-1 text-sm text-slate-500">
    Un bloqueo se registra aunque pise una campaña que el creador ya aceptó: si no puede, no puede.
    Lo que el sistema no hace es callárselo.
  </p>

  <div class="mt-4 space-y-3">
    @forelse ($bloqueos as $b)
      <div class="bg-white rounded-2xl border @if (isset($choques[$b->id])) border-amber-300 @else border-slate-200 @endif p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <p class="text-sm">
            <strong>{{ $b->starts_on }} → {{ $b->ends_on }}</strong>
            @if ($b->reason)<span class="text-slate-500">· {{ $b->reason }}</span>@endif
          </p>
          @can('creator.rate.manage')
            <form method="POST" action="{{ route('creadores.comercial.bloqueo.eliminar', [$creador->uuid, $b->id]) }}">
              @csrf @method('DELETE')
              <button class="text-xs text-slate-500 hover:text-rose-700">Eliminar</button>
            </form>
          @endcan
        </div>

        @if (isset($choques[$b->id]))
          <div class="mt-3 text-sm text-amber-900">
            <p class="font-medium">Pisa campañas que este creador ya aceptó:</p>
            <ul class="mt-1 list-disc list-inside">
              @foreach ($choques[$b->id] as $c)
                <li>{{ $c->codigo }} — {{ $c->campana }} <span class="text-xs text-amber-700">({{ $c->status }})</span></li>
              @endforeach
            </ul>
            <p class="mt-1 text-xs">Hay que hablar con él y con el cliente. Nadie se entera solo.</p>
          </div>
        @endif
      </div>
    @empty
      <p class="text-sm text-slate-500">Sin bloqueos registrados.</p>
    @endforelse
  </div>

  @can('creator.rate.manage')
    <form method="POST" action="{{ route('creadores.comercial.bloqueo', $creador->uuid) }}"
          class="mt-4 bg-white rounded-2xl border border-slate-200 p-6 grid gap-4 sm:grid-cols-4 items-end">
      @csrf
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="starts_on">Desde</label>
        <input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on', $hoy) }}"
               class="w-full rounded-lg border border-slate-300 text-sm">
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="ends_on">Hasta</label>
        <input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on', $hoy) }}"
               class="w-full rounded-lg border border-slate-300 text-sm">
      </div>
      <div>
        <label class="block text-sm text-slate-700 mb-1" for="reason">Motivo</label>
        <input id="reason" name="reason" maxlength="120" value="{{ old('reason') }}"
               class="w-full rounded-lg border border-slate-300 text-sm" placeholder="Vacaciones, viaje, salud…">
      </div>
      <div>
        <button class="w-full px-4 py-2 rounded-xl bg-marca-600 text-white text-sm font-medium hover:bg-marca-700">Bloquear</button>
      </div>
    </form>
  @endcan

</div>
@endsection
