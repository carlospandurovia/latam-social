@extends('layouts.panel')
@section('titulo', $cliente ? 'Editar cliente' : 'Nuevo cliente')
@section('subtitulo', $cliente ? $cliente->commercial_name : 'Alta manual')

@section('contenido')
  <form method="POST"
        action="{{ $cliente ? route('clientes.update', $cliente->uuid) : route('clientes.store') }}"
        class="max-w-2xl space-y-5">
    @csrf
    @if ($cliente) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <div>
        <label for="commercial_name" class="block text-sm font-medium text-slate-700 mb-1">Nombre comercial</label>
        <input id="commercial_name" name="commercial_name" maxlength="160"
               value="{{ old('commercial_name', $cliente->commercial_name ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        {{-- La razón social no va aquí: vive en el perfil fiscal, que es POR
             PAÍS y puede ser distinta en cada uno. --}}
        <p class="mt-1 text-xs text-slate-500">
          Con el que se le conoce. La razón social va en el perfil fiscal, que es por país.
        </p>
        @error('commercial_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="client_code" class="block text-sm font-medium text-slate-700 mb-1">Código</label>
          <input id="client_code" name="client_code" maxlength="20"
                 value="{{ old('client_code', $cliente->client_code ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono uppercase">
          <p class="mt-1 text-xs text-slate-500">Aparece en la factura. Mayúsculas, sin espacios.</p>
          @error('client_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="country_id" class="block text-sm font-medium text-slate-700 mb-1">País</label>
          <select id="country_id" name="country_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ($paises as $p)
              <option value="{{ $p->id }}" @selected((int) old('country_id', $cliente->country_id ?? 0) === (int) $p->id)>{{ $p->name }}</option>
            @endforeach
          </select>
          <p class="mt-1 text-xs text-slate-500">Decide qué sociedad puede facturarle.</p>
          @error('country_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="industry_category_id" class="block text-sm font-medium text-slate-700 mb-1">Industria</label>
          <select id="industry_category_id" name="industry_category_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">—</option>
            @foreach ($industrias as $i)
              <option value="{{ $i->id }}" @selected((int) old('industry_category_id', $cliente->industry_category_id ?? 0) === (int) $i->id)>{{ $i->code }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="owner_user_id" class="block text-sm font-medium text-slate-700 mb-1">Ejecutivo responsable</label>
          <select id="owner_user_id" name="owner_user_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">—</option>
            @foreach ($ejecutivos as $u)
              <option value="{{ $u->id }}" @selected((int) old('owner_user_id', $cliente->owner_user_id ?? 0) === (int) $u->id)>{{ $u->name }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="website" class="block text-sm font-medium text-slate-700 mb-1">Web</label>
          <input id="website" name="website" maxlength="255" placeholder="https://…"
                 value="{{ old('website', $cliente->website ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('website') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
          <select id="status" name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach (['prospect' => 'Prospecto', 'active' => 'Activo', 'inactive' => 'Inactivo', 'blacklisted' => 'Vetado'] as $v => $t)
              <option value="{{ $v }}" @selected(old('status', $cliente->status ?? 'prospect') === $v)>{{ $t }}</option>
            @endforeach
          </select>
          {{-- BR-LE-004 / DEC-073: prospecto en cualquier país; activo solo si
               hay quien le facture. Se avisa aquí para que no sea una sorpresa
               al guardar. --}}
          <p class="mt-1 text-xs text-slate-500">
            <strong>Activo</strong> exige que alguna sociedad cubra su país.
            Un prospecto se puede apuntar en cualquiera.
          </p>
          @error('status') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>
    </div>

    <div class="flex gap-3">
      <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        {{ $cliente ? 'Guardar cambios' : 'Dar de alta' }}
      </button>
      <a href="{{ $cliente ? route('clientes.show', $cliente->uuid) : route('clientes.index') }}"
         class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
        Cancelar
      </a>
    </div>
  </form>
@endsection
