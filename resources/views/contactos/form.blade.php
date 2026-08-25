@extends('layouts.panel')
@section('titulo', $contacto ? 'Editar contacto' : 'Nuevo contacto')
@section('subtitulo', $cliente->commercial_name)

@section('contenido')
  <form method="POST"
        action="{{ $contacto ? route('contactos.update', [$cliente->uuid, $contacto->uuid]) : route('contactos.store', $cliente->uuid) }}"
        class="max-w-2xl space-y-5">
    @csrf
    @if ($contacto) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <div>
        <label for="full_name" class="block text-sm font-medium text-slate-700 mb-1">Nombre y apellidos</label>
        <input id="full_name" name="full_name" maxlength="160"
               value="{{ old('full_name', $contacto->full_name ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('full_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="contact_email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
          <input id="contact_email" name="contact_email" maxlength="255" type="email"
                 value="{{ old('contact_email', $contacto->contact_email ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          {{-- No es la identidad de acceso: eso es `users.email` y es única.
               Este es el canal comercial y puede repetirse a propósito
               (facturacion@cliente.com para varios contactos). --}}
          <p class="mt-1 text-xs text-slate-500">Canal comercial. Puede repetirse: no es un usuario del sistema.</p>
          @error('contact_email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="phone" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
          <input id="phone" name="phone" maxlength="30"
                 value="{{ old('phone', $contacto->phone ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('phone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
      </div>

      <div>
        <label for="position" class="block text-sm font-medium text-slate-700 mb-1">Cargo</label>
        <input id="position" name="position" maxlength="120" placeholder="Gerente de marketing"
               value="{{ old('position', $contacto->position ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('position') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="contact_type" class="block text-sm font-medium text-slate-700 mb-1">Tipo</label>
          <select id="contact_type" name="contact_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ($tipos as $v => $t)
              <option value="{{ $v }}" @selected(old('contact_type', $contacto->contact_type ?? 'commercial') === $v)>{{ $t }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
          <select id="status" name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach (['active' => 'Activo', 'inactive' => 'Inactivo'] as $v => $t)
              <option value="{{ $v }}" @selected(old('status', $contacto->status ?? 'active') === $v)>{{ $t }}</option>
            @endforeach
          </select>
          <p class="mt-1 text-xs text-slate-500">Desactivar libera el puesto de principal sin borrar nada.</p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <label class="flex items-start gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_primary" value="1"
               @checked(old('is_primary', (bool) ($contacto->is_primary ?? false)))
               class="mt-0.5 rounded border-slate-300">
        <span>
          <span class="font-medium">Contacto principal de su tipo</span>
          <span class="block text-xs text-slate-500">
            Sólo puede haber uno activo por tipo. Es a quien se le escribe por defecto.
          </span>
        </span>
      </label>

      {{-- El relevo (DEC-075) se hace, no se rechaza: obligar a quitarle la
           marca al anterior primero deja al cliente sin principal entre los dos
           pasos. Pero se avisa ANTES de pulsar, con nombre y apellidos, para
           que nadie desplace a nadie sin enterarse. --}}
      @if ($principales !== [])
        <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
          <p class="font-medium">Si marcas esta casilla, quien ocupe el puesto lo pierde:</p>
          <ul class="mt-1 space-y-0.5">
            @foreach ($principales as $tipo => $quien)
              <li>· <strong>{{ $tipos[$tipo] }}</strong>: hoy es {{ $quien->full_name }}</li>
            @endforeach
          </ul>
          <p class="mt-2">El relevo se hace solo, en el mismo guardado, y el mensaje te dirá a quién relevaste.</p>
        </div>
      @endif
    </div>

    <div class="flex gap-3">
      <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        {{ $contacto ? 'Guardar cambios' : 'Dar de alta' }}
      </button>
      <a href="{{ route('clientes.show', $cliente->uuid) }}"
         class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
        Cancelar
      </a>
    </div>
  </form>
@endsection
