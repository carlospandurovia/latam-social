@extends('layouts.panel')
@section('titulo', $campana ? 'Editar campaña' : 'Nueva campaña')
@section('subtitulo', $campana ? $campana->code : 'El código lo pone el sistema')

@section('contenido')
  <div class="max-w-3xl">
    {{-- Se avisa ANTES de teclear, no al guardar: de la fecha de inicio depende
         qué sociedad factura, y eso no es evidente mirando el formulario. --}}
    <div class="mb-5 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
      La <strong>fecha de inicio</strong> decide qué sociedad del grupo emite la factura
      (<code>BR-LE-003</code>): se resuelve con la cobertura vigente ese día, no con la de hoy.
      Y en cuanto la campaña se confirma, esa sociedad ya no se puede cambiar (<code>BR-LE-002</code>).
    </div>

    <form method="POST"
          action="{{ $campana ? route('campanas.update', $campana->uuid) : route('campanas.store') }}"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @csrf
      @if ($campana) @method('PUT') @endif

      <div>
        <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
        <input id="name" name="name" value="{{ old('name', $campana->name ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="client_organization_id" class="block text-sm font-medium text-slate-700 mb-1">Cliente</label>
          <select id="client_organization_id" name="client_organization_id"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">—</option>
            @foreach ($clientes as $c)
              <option value="{{ $c->id }}"
                @selected((int) old('client_organization_id', $campana->client_organization_id ?? 0) === (int) $c->id)>
                {{ $c->commercial_name }}
              </option>
            @endforeach
          </select>
          @error('client_organization_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
          <label for="client_brand_id" class="block text-sm font-medium text-slate-700 mb-1">Marca</label>
          <select id="client_brand_id" name="client_brand_id"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">—</option>
            @foreach ($marcas as $m)
              <option value="{{ $m->id }}" data-cliente="{{ $m->client_organization_id }}"
                @selected((int) old('client_brand_id', $campana->client_brand_id ?? 0) === (int) $m->id)>
                {{ $m->name }}
              </option>
            @endforeach
          </select>
          {{-- La comprobación de que la marca es DEL cliente vive en el
               `FormRequest`, no aquí: una validación que sólo existe en la
               pantalla se la salta cualquier importación. --}}
          @error('client_brand_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="starts_on" class="block text-sm font-medium text-slate-700 mb-1">Inicio</label>
          <input id="starts_on" name="starts_on" type="date"
                 value="{{ old('starts_on', $campana->starts_on ?? $hoy) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('starts_on') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="ends_on" class="block text-sm font-medium text-slate-700 mb-1">Fin</label>
          <input id="ends_on" name="ends_on" type="date"
                 value="{{ old('ends_on', $campana->ends_on ?? $hoy) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('ends_on') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <div>
          <label for="objective" class="block text-sm font-medium text-slate-700 mb-1">Objetivo</label>
          <select id="objective" name="objective" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ($objetivos as $codigo => $nombre)
              <option value="{{ $codigo }}"
                @selected(old('objective', $campana->objective ?? 'awareness') === $codigo)>{{ $nombre }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="revenue_amount" class="block text-sm font-medium text-slate-700 mb-1">Ingreso</label>
          <input id="revenue_amount" name="revenue_amount" type="number" step="0.01" min="0"
                 value="{{ old('revenue_amount', $campana->revenue_amount ?? '0') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          {{-- La casilla va DEBAJO del importe y no en otra fila: la pregunta que
               contesta es sobre ese cero, y separarlas haria que se contestara
               sin mirarlo. `hidden` con valor 0 para que «no marcada» llegue
               como respuesta y no como silencio. --}}
          <label class="mt-2 flex items-start gap-2 text-xs text-slate-600">
            <input type="hidden" name="is_gratis" value="0">
            <input type="checkbox" name="is_gratis" value="1" class="mt-0.5 rounded border-slate-300"
                   @checked((int) old('is_gratis', $campana->is_gratis ?? 0) === 1)>
            <span>
              Campana gratuita (canje o cortesia).
              <span class="text-slate-500">Marcarla dice que el cero es a proposito;
              sin marcar, un cero significa que todavia falta ponerle precio y la
              campana no podra aprobarse.</span>
            </span>
          </label>
          @error('revenue_amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
          @error('is_gratis') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="currency_code" class="block text-sm font-medium text-slate-700 mb-1">Moneda</label>
          <select id="currency_code" name="currency_code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ($monedas as $m)
              <option value="{{ $m->code }}"
                @selected(old('currency_code', $campana->currency_code ?? '') === $m->code)>{{ $m->code }}</option>
            @endforeach
          </select>
          @error('currency_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="included_revision_rounds" class="block text-sm font-medium text-slate-700 mb-1">
            Rondas de corrección incluidas
          </label>
          <input id="included_revision_rounds" name="included_revision_rounds" type="number" min="0" max="10"
                 value="{{ old('included_revision_rounds', $campana->included_revision_rounds ?? 2) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('included_revision_rounds') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="min_creator_age" class="block text-sm font-medium text-slate-700 mb-1">Edad mínima del creador</label>
          <input id="min_creator_age" name="min_creator_age" type="number" min="0" max="99"
                 value="{{ old('min_creator_age', $campana->min_creator_age ?? 0) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-500">0 = sin restricción. <code>BR-CREATOR-012</code>.</p>
          @error('min_creator_age') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>

      <div>
        <label for="publication_deadline" class="block text-sm font-medium text-slate-700 mb-1">
          Fecha límite de publicación <span class="text-slate-400">(opcional)</span>
        </label>
        <input id="publication_deadline" name="publication_deadline" type="date"
               value="{{ old('publication_deadline', $campana->publication_deadline ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('publication_deadline') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div>
        <label for="briefing" class="block text-sm font-medium text-slate-700 mb-1">
          Briefing <span class="text-slate-400">(opcional)</span>
        </label>
        <textarea id="briefing" name="briefing" rows="5"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('briefing', $campana->briefing ?? '') }}</textarea>
        @error('briefing') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="flex gap-3 pt-1">
        <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
          {{ $campana ? 'Guardar' : 'Crear borrador' }}
        </button>
        <a href="{{ $campana ? route('campanas.show', $campana->uuid) : route('campanas.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
          Cancelar
        </a>
      </div>
    </form>
  </div>
@endsection
