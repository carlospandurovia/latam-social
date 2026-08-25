@extends('layouts.panel')
@section('titulo', $perfil ? 'Corregir identidad fiscal' : 'Nueva identidad fiscal')
@section('subtitulo', $cliente->commercial_name)

@section('contenido')
  <form method="POST"
        action="{{ $perfil ? route('clientes.fiscal.update', [$cliente->uuid, $perfil->id]) : route('clientes.fiscal.store', $cliente->uuid) }}"
        class="max-w-3xl space-y-5">
    @csrf
    @if ($perfil) @method('PUT') @endif

    {{-- Corregir y abrir periodo son actos distintos, y la pantalla lo dice
         antes de que se rellene nada. Confundirlos es lo que produce un
         histórico fiscal falso. --}}
    @if ($perfil)
      <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
        <p class="font-medium text-slate-700">Esto corrige un error de captura.</p>
        <p class="mt-1">
          Rige desde el <strong>{{ $perfil->valid_from }}</strong> y esa fecha no cambia aquí.
          Si lo que pasó es que el cliente <em>cambió</em> de razón social o de domicilio fiscal,
          no lo corrijas: <a href="{{ route('clientes.fiscal.create', $cliente->uuid) }}" class="text-marca-700 hover:underline">abre un periodo nuevo</a>,
          y este quedará cerrado el día antes. Así el histórico sigue explicando las facturas ya emitidas.
        </p>
      </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @if (!$perfil)
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="country_id" class="block text-sm font-medium text-slate-700 mb-1">País</label>
            <select id="country_id" name="country_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @foreach ($paises as $p)
                <option value="{{ $p->id }}" @selected((int) old('country_id', $cliente->country_id) === (int) $p->id)>
                  {{ $p->name }}@isset($vigentes[$p->id]) · ya tiene identidad vigente @endisset
                </option>
              @endforeach
            </select>
            @error('country_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
          </div>
          <div>
            <label for="valid_from" class="block text-sm font-medium text-slate-700 mb-1">Rige desde</label>
            <input id="valid_from" name="valid_from" type="date"
                   value="{{ old('valid_from') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @error('valid_from') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
          </div>
        </div>

        {{-- `valid_to` es INCLUSIVO: el anterior se cierra el día ANTES. Es el
             defecto que ha aparecido seis veces en este proyecto, así que la
             regla se enseña donde se elige la fecha, no sólo en el código. --}}
        @if ($vigentes !== [])
          <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
            <p class="font-medium">Estos países ya tienen identidad fiscal vigente:</p>
            <ul class="mt-1 space-y-0.5">
              @foreach ($vigentes as $paisId => $v)
                <li>· <strong>{{ $paises->firstWhere('id', $paisId)?->name ?? $paisId }}</strong>:
                    {{ $v->tax_id_type }} {{ $v->tax_id_number }}, desde el {{ $v->valid_from }}</li>
              @endforeach
            </ul>
            <p class="mt-2">
              Si eliges uno de ellos, el vigente se cierra <strong>el día antes</strong> de la fecha
              que pongas, y la nueva rige desde esa fecha. La nueva tiene que empezar después
              de que empezara la anterior.
            </p>
          </div>
        @endif
      @endif

      <div>
        <label for="legal_name" class="block text-sm font-medium text-slate-700 mb-1">Razón social</label>
        <input id="legal_name" name="legal_name" maxlength="200"
               value="{{ old('legal_name', $perfil->legal_name ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <p class="mt-1 text-xs text-slate-500">Va impresa en la factura. No es el nombre comercial.</p>
        @error('legal_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label for="tax_id_type" class="block text-sm font-medium text-slate-700 mb-1">Tipo de documento</label>
          <input id="tax_id_type" name="tax_id_type" maxlength="20" placeholder="RUC"
                 value="{{ old('tax_id_type', $perfil->tax_id_type ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('tax_id_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2">
          <label for="tax_id_number" class="block text-sm font-medium text-slate-700 mb-1">Número</label>
          <input id="tax_id_number" name="tax_id_number" maxlength="40"
                 value="{{ old('tax_id_number', $perfil->tax_id_number ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          {{-- El formato por país NO se valida (Q-55): una tabla de expresiones
               regulares mal puesta rechaza documentos válidos y no deja meterlos. --}}
          <p class="mt-1 text-xs text-slate-500">No se comprueba el formato: revísalo contra el documento del cliente.</p>
          @error('tax_id_number') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <h2 class="text-sm font-medium text-slate-700">Domicilio fiscal</h2>

      <div>
        <label for="address_line1" class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
        <input id="address_line1" name="address_line1" maxlength="180"
               value="{{ old('address_line1', $perfil->address_line1 ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('address_line1') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div>
        <label for="address_line2" class="block text-sm font-medium text-slate-700 mb-1">Dirección (línea 2)</label>
        <input id="address_line2" name="address_line2" maxlength="180"
               value="{{ old('address_line2', $perfil->address_line2 ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label for="city" class="block text-sm font-medium text-slate-700 mb-1">Ciudad</label>
          <input id="city" name="city" maxlength="100"
                 value="{{ old('city', $perfil->city ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('city') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="region" class="block text-sm font-medium text-slate-700 mb-1">Región</label>
          <input id="region" name="region" maxlength="100"
                 value="{{ old('region', $perfil->region ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="postal_code" class="block text-sm font-medium text-slate-700 mb-1">Código postal</label>
          <input id="postal_code" name="postal_code" maxlength="20"
                 value="{{ old('postal_code', $perfil->postal_code ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700">Facturación</h2>
      <div class="mt-3 grid grid-cols-2 gap-4">
        <div>
          <label for="billing_email" class="block text-sm font-medium text-slate-700 mb-1">Correo de facturación</label>
          <input id="billing_email" name="billing_email" type="email" maxlength="255"
                 value="{{ old('billing_email', $perfil->billing_email ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('billing_email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="payment_term_days" class="block text-sm font-medium text-slate-700 mb-1">Plazo de pago (días)</label>
          <input id="payment_term_days" name="payment_term_days" type="number" min="0" max="180"
                 value="{{ old('payment_term_days', $perfil->payment_term_days ?? 30) }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('payment_term_days') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>
    </div>

    <div class="flex gap-3">
      <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        {{ $perfil ? 'Guardar corrección' : 'Registrar identidad fiscal' }}
      </button>
      <a href="{{ route('clientes.show', $cliente->uuid) }}"
         class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
        Cancelar
      </a>
    </div>
  </form>
@endsection
