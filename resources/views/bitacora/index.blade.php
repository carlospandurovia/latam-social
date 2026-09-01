@extends('layouts.panel')
@section('titulo', 'Bitácora')
@section('subtitulo', 'Quién hizo qué, y cuándo')

@section('contenido')

<form method="GET" class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 grid grid-cols-1 sm:grid-cols-5 gap-3 text-sm">
  <div>
    <label for="tipo" class="block text-xs text-slate-500 mb-1">Entidad</label>
    <select id="tipo" name="tipo" class="w-full rounded-lg border border-slate-300 text-sm">
      <option value="">Todas</option>
      @foreach ($tipos as $t)
        <option value="{{ $t }}" @selected($filtros['tipo'] === $t)>{{ $t }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label for="actor" class="block text-xs text-slate-500 mb-1">Quién</label>
    <select id="actor" name="actor" class="w-full rounded-lg border border-slate-300 text-sm">
      <option value="">Cualquiera</option>
      @foreach ($actores as $id => $nombre)
        <option value="{{ $id }}" @selected((int) $filtros['actor'] === (int) $id)>{{ $nombre }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label for="accion" class="block text-xs text-slate-500 mb-1">Acción empieza por</label>
    <input id="accion" name="accion" value="{{ $filtros['accion'] }}" placeholder="creator."
           class="w-full rounded-lg border border-slate-300 text-sm">
  </div>
  <div>
    <label for="desde" class="block text-xs text-slate-500 mb-1">Desde</label>
    <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}"
           class="w-full rounded-lg border border-slate-300 text-sm">
  </div>
  <div class="flex gap-2 items-end">
    <div class="flex-1">
      <label for="hasta" class="block text-xs text-slate-500 mb-1">Hasta</label>
      <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}"
             class="w-full rounded-lg border border-slate-300 text-sm">
    </div>
    <button class="px-4 py-2 rounded-lg bg-marca-500 text-white font-medium">Filtrar</button>
  </div>
</form>

<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
        <tr>
          <th class="text-left font-medium px-4 py-3">Cuándo</th>
          <th class="text-left font-medium px-4 py-3">Quién</th>
          <th class="text-left font-medium px-4 py-3">Acción</th>
          <th class="text-left font-medium px-4 py-3">Entidad</th>
          <th class="text-left font-medium px-4 py-3">Qué cambió</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($entradas as $e)
          <tr class="hover:bg-slate-50/60 align-top">
            <td class="px-4 py-3 whitespace-nowrap text-slate-500 tabular-nums">{{ $e->occurred_at }}</td>
            <td class="px-4 py-3">
              {{-- Se muestra la etiqueta CONGELADA, no el nombre actual: la
                   bitácora dice quién era esa persona entonces. El nombre de hoy
                   va debajo, y solo si cambió. --}}
              <span class="text-slate-800">{{ $e->actor_label ?? 'sistema' }}</span>
              @if ($e->actor_actual && $e->actor_label && ! str_starts_with($e->actor_label, $e->actor_actual))
                <span class="block text-xs text-amber-600">hoy: {{ $e->actor_actual }}</span>
              @endif
            </td>
            <td class="px-4 py-3"><code class="text-xs bg-slate-100 rounded px-1.5 py-0.5">{{ $e->action }}</code></td>
            <td class="px-4 py-3 text-slate-500">{{ $e->entity_type }} #{{ $e->entity_id }}</td>
            <td class="px-4 py-3">
              @php $cambios = $e->changes ? json_decode($e->changes, true) : null; @endphp
              @if (is_array($cambios))
                <ul class="space-y-0.5">
                  {{-- `Bitacora::legible()` y no `{{ $v['antes'] }}` a pelo.

                       Los valores NO son siempre escalares: una marca guarda sus
                       categorías como lista, y pintar un array reventaba con un
                       500 que se llevaba por delante la página entera. Bastaba
                       una fila así para no poder ver ninguna. --}}
                  @foreach ($cambios as $campo => $v)
                    <li class="text-xs">
                      <span class="text-slate-500">{{ $campo }}:</span>
                      @if (is_array($v) && (array_key_exists('antes', $v) || array_key_exists('despues', $v)))
                        <span class="line-through text-slate-400">{{ \App\Shared\Audit\Bitacora::legible($v['antes'] ?? null) }}</span>
                        <span class="text-slate-400">→</span>
                        <span class="text-slate-800 font-medium">{{ \App\Shared\Audit\Bitacora::legible($v['despues'] ?? null) }}</span>
                      @else
                        {{-- Una entrada que no tiene la forma antes/despues. No
                             debería haberlas, y si aparece una se enseña tal cual
                             en vez de perder la página. --}}
                        <span class="text-slate-800 font-medium">{{ \App\Shared\Audit\Bitacora::legible($v) }}</span>
                      @endif
                    </li>
                  @endforeach
                </ul>
              @else
                <span class="text-slate-300">—</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">Sin entradas para este filtro.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="mt-4">{{ $entradas->links() }}</div>

<p class="mt-4 text-xs text-slate-400">
  Las entradas no se pueden editar ni borrar: lo impide la propia base de datos
  (BR-SEC-004). Los campos sensibles se registran como <code>[redactado]</code>.
</p>
@endsection
