@extends('layouts.panel')
@section('titulo', $entidad ? 'Editar sociedad' : 'Nueva sociedad')
@section('subtitulo', $entidad?->code ?? 'Una empresa del grupo que emite facturas')

@section('contenido')
  <form method="POST"
        action="{{ $entidad ? route('entidades.update', $entidad->uuid) : route('entidades.store') }}"
        class="max-w-3xl space-y-5">
    @csrf
    @if ($entidad) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @if (!$entidad)
        <div class="grid grid-cols-3 gap-4">
          <div>
            <label for="code" class="block text-sm font-medium text-slate-700 mb-1">Código</label>
            <input id="code" name="code" maxlength="30" placeholder="CTS-PE"
                   value="{{ old('code') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            {{-- El código aparece en cada mensaje del sistema («CTS-PE factura a
                 Perú desde…»). Cambiarlo reescribiría lo que significaban los
                 mensajes ya emitidos, así que sólo se pide al crear. --}}
            <p class="mt-1 text-xs text-slate-500">Corto y estable. Después no se cambia.</p>
            @error('code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
          </div>
          <div class="col-span-2">
            <label for="country_id" class="block text-sm font-medium text-slate-700 mb-1">País de constitución</label>
            <select id="country_id" name="country_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($paises as $p)
                <option value="{{ $p->id }}" @selected((int) old('country_id') === (int) $p->id)>{{ $p->name }}</option>
              @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Dónde existe la empresa. Qué países <em>factura</em> se declara aparte.</p>
            @error('country_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
          </div>
        </div>
      @endif

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="legal_name" class="block text-sm font-medium text-slate-700 mb-1">Razón social</label>
          <input id="legal_name" name="legal_name" maxlength="200"
                 value="{{ old('legal_name', $entidad->legal_name ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('legal_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="trade_name" class="block text-sm font-medium text-slate-700 mb-1">Nombre comercial</label>
          <input id="trade_name" name="trade_name" maxlength="160"
                 value="{{ old('trade_name', $entidad->trade_name ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label for="tax_id_type" class="block text-sm font-medium text-slate-700 mb-1">Tipo de documento</label>
          <input id="tax_id_type" name="tax_id_type" maxlength="20" placeholder="RUC"
                 value="{{ old('tax_id_type', $entidad->tax_id_type ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('tax_id_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2">
          <label for="tax_id_number" class="block text-sm font-medium text-slate-700 mb-1">Número</label>
          <input id="tax_id_number" name="tax_id_number" maxlength="40"
                 value="{{ old('tax_id_number', $entidad->tax_id_number ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('tax_id_number') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <h2 class="text-sm font-medium text-slate-700">Domicilio fiscal</h2>
      <div>
        <label for="address_line1" class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
        <input id="address_line1" name="address_line1" maxlength="180"
               value="{{ old('address_line1', $entidad->address_line1 ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('address_line1') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>
      <div>
        <label for="address_line2" class="block text-sm font-medium text-slate-700 mb-1">Dirección (línea 2)</label>
        <input id="address_line2" name="address_line2" maxlength="180"
               value="{{ old('address_line2', $entidad->address_line2 ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>
      <div class="grid grid-cols-3 gap-4">
        <div>
          <label for="city" class="block text-sm font-medium text-slate-700 mb-1">Ciudad</label>
          <input id="city" name="city" maxlength="100"
                 value="{{ old('city', $entidad->city ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('city') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="region" class="block text-sm font-medium text-slate-700 mb-1">Región</label>
          <input id="region" name="region" maxlength="100"
                 value="{{ old('region', $entidad->region ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="postal_code" class="block text-sm font-medium text-slate-700 mb-1">Código postal</label>
          <input id="postal_code" name="postal_code" maxlength="20"
                 value="{{ old('postal_code', $entidad->postal_code ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700">Operación</h2>
      <div class="mt-3 grid grid-cols-2 gap-4">
        <div>
          <label for="default_currency_code" class="block text-sm font-medium text-slate-700 mb-1">Moneda por defecto</label>
          <select id="default_currency_code" name="default_currency_code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ($monedas as $m)
              <option value="{{ $m->code }}" @selected(old('default_currency_code', $entidad->default_currency_code ?? '') === $m->code)>
                {{ $m->code }} — {{ $m->name }}
              </option>
            @endforeach
          </select>
          @error('default_currency_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="timezone" class="block text-sm font-medium text-slate-700 mb-1">Zona horaria</label>
          <input id="timezone" name="timezone" maxlength="64" placeholder="America/Lima"
                 value="{{ old('timezone', $entidad->timezone ?? 'America/Lima') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('timezone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>
      <div class="mt-4 grid grid-cols-2 gap-4">
        <div>
          <label for="legal_representative" class="block text-sm font-medium text-slate-700 mb-1">Representante legal</label>
          <input id="legal_representative" name="legal_representative" maxlength="160"
                 value="{{ old('legal_representative', $entidad->legal_representative ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="incorporated_on" class="block text-sm font-medium text-slate-700 mb-1">Constituida el</label>
          <input id="incorporated_on" name="incorporated_on" type="date"
                 value="{{ old('incorporated_on', $entidad->incorporated_on ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('incorporated_on') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>
    </div>

    <div class="flex gap-3">
      <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        {{ $entidad ? 'Guardar cambios' : 'Dar de alta' }}
      </button>
      <a href="{{ $entidad ? route('entidades.show', $entidad->uuid) : route('entidades.index') }}"
         class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
        Cancelar
      </a>
    </div>
  </form>
@endsection
