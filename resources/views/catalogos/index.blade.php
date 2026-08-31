@extends('layouts.panel')
@section('titulo', $titulo)
@section('subtitulo', $filas->total().' registros')

@section('contenido')
  {{-- Dos escalones: Configuración › Catálogos › éste. La miga corriente sólo
       sabe de uno, así que aquí se escribe entera. --}}
  <nav class="mb-4 flex items-center gap-2 text-xs text-slate-500" aria-label="Dónde estoy">
    <a href="{{ route('configuracion') }}" class="hover:text-marca-700 hover:underline">Configuración</a>
    <span aria-hidden="true">›</span>
    <a href="{{ route('catalogos.index') }}" class="hover:text-marca-700 hover:underline">Catálogos</a>
    <span aria-hidden="true">›</span>
    <span class="text-slate-700 font-medium">{{ $titulo }}</span>
  </nav>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-200">
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            @foreach ($columnas as $col)
              <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $col }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse ($filas as $fila)
            <tr class="hover:bg-slate-50">
              @foreach ($columnas as $col)
                @php $v = $fila->{$col} ?? null; @endphp
                <td class="px-4 py-2.5 whitespace-nowrap
                           {{ is_numeric($v) ? 'tabular-nums text-slate-600' : 'text-slate-700' }}">
                  @if (is_null($v))
                    <span class="text-slate-300">—</span>
                  @elseif ($col === 'is_active' || str_starts_with((string) $col, 'is_'))
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                 {{ $v ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                      {{ $v ? 'Sí' : 'No' }}
                    </span>
                  @else
                    {{ \Illuminate\Support\Str::limit((string) $v, 60) }}
                  @endif
                </td>
              @endforeach
            </tr>
          @empty
            <tr>
              <td colspan="{{ count($columnas) }}" class="px-4 py-10 text-center text-slate-400">
                Este catálogo está vacío. Ejecuta <code class="text-slate-600">php artisan db:seed</code>.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-5">{{ $filas->links() }}</div>

  <p class="mt-6 text-xs text-slate-400">
    Solo lectura por ahora. La edición llega con las pantallas de administración de catálogos.
  </p>
@endsection
