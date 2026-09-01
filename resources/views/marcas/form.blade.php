@extends('layouts.panel')
@section('titulo', $marca ? 'Editar marca' : 'Nueva marca')
@section('subtitulo', $cliente->commercial_name)

@section('contenido')
  <form method="POST"
        action="{{ $marca ? route('marcas.update', [$cliente->uuid, $marca->uuid]) : route('marcas.store', $cliente->uuid) }}"
        class="max-w-2xl space-y-5">
    @csrf
    @if ($marca) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <div>
        <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Nombre de la marca</label>
        <input id="name" name="name" maxlength="120"
               value="{{ old('name', $marca->name ?? '') }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        {{-- El slug no se pide: se deriva del nombre y se desambigua solo. Es
             único globalmente, y quien da de alta una marca no tiene por qué
             saber qué slugs hay cogidos en otros clientes. --}}
        @if ($marca)
          <p class="mt-1 text-xs text-slate-500">Identificador actual: <code>{{ $marca->slug }}</code>. Cambia solo si cambias el nombre.</p>
        @endif
        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label for="website" class="block text-sm font-medium text-slate-700 mb-1">Web</label>
          <input id="website" name="website" maxlength="255" placeholder="https://…"
                 value="{{ old('website', $marca->website ?? '') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          @error('website') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
          <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
          <select id="status" name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach (['active' => 'Activa', 'paused' => 'Pausada', 'archived' => 'Archivada'] as $v => $t)
              <option value="{{ $v }}" @selected(old('status', $marca->status ?? 'active') === $v)>{{ $t }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700">Categorías</h2>
      {{-- No es un campo decorativo: `client_brand_categories` es lo que
           alimenta BR-CAMPAIGN-007. Dejarlo vacío apaga la detección de
           conflictos para esta marca, y eso hay que decirlo, no insinuarlo. --}}
      <p class="mt-1 text-xs text-slate-500">
        De aquí sale la detección de conflictos de marca (<code>BR-CAMPAIGN-007</code>):
        antes de invitar a un creador se comprueba si tiene exclusividad o competencia
        en estas categorías. <strong>Sin categorías, esa comprobación no puede hacerse
        para esta marca.</strong>
      </p>
      {{-- Testigo: un `<input type="checkbox">` sin marcar NO se manda, asi que
           «ninguna categoria» y «el campo no venia» llegaban iguales al
           servidor. Y como sincronizar empieza por un `delete()`, una peticion
           sin el campo borraba todas las categorias de la marca en silencio.
           Con esto, desmarcarlas todas sigue siendo posible y una peticion que
           no traiga la seccion no las toca. --}}
      <input type="hidden" name="categorias_enviadas" value="1">
      <div class="mt-3 grid grid-cols-3 gap-2">
        @foreach ($categorias as $c)
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="categorias[]" value="{{ $c->id }}"
                   @checked(in_array((int) $c->id, old('categorias', $elegidas), true))
                   class="rounded border border-slate-300">
            {{ $c->code }}
          </label>
        @endforeach
      </div>
      @error('categorias.*') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div class="flex gap-3">
      <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
        {{ $marca ? 'Guardar cambios' : 'Dar de alta' }}
      </button>
      <a href="{{ route('clientes.show', $cliente->uuid) }}"
         class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
        Cancelar
      </a>
    </div>
  </form>
@endsection
