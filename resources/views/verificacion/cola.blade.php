@extends('layouts.panel')
@section('titulo', 'Verificación')
@section('subtitulo', $cola->count().' '.\Illuminate\Support\Str::plural('publicación', $cola->count()).' por comprobar')

@section('contenido')
  <div class="space-y-5">

    @if (session('exito'))
      <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
        {{ session('exito') }}
      </div>
    @endif

    <form method="GET" class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end">
      <div>
        <label for="campana" class="block text-xs text-slate-500 mb-1">Campaña</label>
        <select id="campana" name="campana"
                class="text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          <option value="">Todas</option>
          @foreach ($campanas as $c)
            <option value="{{ $c->id }}" @selected($campanaSeleccionada === (int) $c->id)>{{ $c->name }}</option>
          @endforeach
        </select>
      </div>
      <button type="submit"
              class="text-sm px-3 py-2 rounded-lg bg-marca-500 text-white hover:bg-marca-600">Filtrar</button>
      @if ($campanaSeleccionada)
        <a href="{{ route('verificacion.cola') }}" class="text-xs text-slate-500 hover:underline">Quitar filtro</a>
      @endif
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      @if ($cola->isEmpty())
        <p class="p-5 text-sm text-slate-400">No hay publicaciones esperando comprobación.</p>
      @else
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th class="text-left font-medium px-4 py-2">Publicado</th>
                <th class="text-left font-medium px-4 py-2">Creador</th>
                <th class="text-left font-medium px-4 py-2">Campaña</th>
                <th class="text-left font-medium px-4 py-2">Red</th>
                <th class="text-left font-medium px-4 py-2">Enlace</th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($cola as $p)
                @php $espera = \Illuminate\Support\Carbon::parse($p->published_at)->diffInDays(); @endphp
                <tr class="hover:bg-slate-50">
                  {{-- Lo que lleva más esperando primero: un post sin verificar
                       es un pago que no puede salir. --}}
                  <td class="px-4 py-2 whitespace-nowrap {{ $espera >= 3 ? 'text-amber-700 font-medium' : 'text-slate-500' }}">
                    {{ $espera === 0 ? 'hoy' : 'hace '.$espera.' d' }}
                  </td>
                  <td class="px-4 py-2 text-slate-700">{{ $p->creador }}</td>
                  <td class="px-4 py-2 text-slate-500">{{ $p->campana }}</td>
                  <td class="px-4 py-2 text-slate-500">{{ $p->red ?? '—' }}</td>
                  <td class="px-4 py-2 max-w-xs">
                    {{-- `rel="noopener noreferrer"`: el enlace lo pegó alguien de
                         fuera, y no le regalamos ni la referencia ni la ventana. --}}
                    <a href="{{ $p->url }}" target="_blank" rel="noopener noreferrer"
                       class="text-marca-600 hover:underline break-all text-xs">{{ $p->url }}</a>
                  </td>
                  <td class="px-4 py-2 text-right whitespace-nowrap">
                    <a href="{{ route('verificacion.ver', $p->uuid) }}"
                       class="text-marca-600 hover:underline">Comprobar</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>

    <p class="text-xs text-slate-400">
      Se comprueba a mano y no solo: Instagram y TikTok responden igual a un post vivo
      que a un bloqueo, así que un estado HTTP no prueba que el post exista.
    </p>
  </div>
@endsection
