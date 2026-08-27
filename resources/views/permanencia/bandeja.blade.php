@extends('layouts.panel')
@section('titulo', 'Permanencia')
@section('subtitulo', $bandeja->count().' '.\Illuminate\Support\Str::plural('post', $bandeja->count()).' bajo vigilancia')

@section('contenido')
  <div class="space-y-5">

    @if (session('exito'))
      <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
        {{ session('exito') }}
      </div>
    @endif

    @if ($desatendidas > 0)
      <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
        {{ $desatendidas }} {{ \Illuminate\Support\Str::plural('post', $desatendidas) }}
        {{ $desatendidas === 1 ? 'lleva' : 'llevan' }} una semana o más sin que nadie
        {{ $desatendidas === 1 ? 'lo' : 'los' }} mire. La ventana sigue abierta.
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
        <a href="{{ route('permanencia.bandeja') }}" class="text-xs text-slate-500 hover:underline">Quitar filtro</a>
      @endif
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      @if ($bandeja->isEmpty())
        <p class="p-5 text-sm text-slate-400">No hay ningún post bajo vigilancia.</p>
      @else
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th class="text-left font-medium px-4 py-2">Estado</th>
                <th class="text-left font-medium px-4 py-2">Hasta</th>
                <th class="text-left font-medium px-4 py-2">Creador</th>
                <th class="text-left font-medium px-4 py-2">Campaña</th>
                <th class="text-left font-medium px-4 py-2">Última mirada</th>
                <th class="text-left font-medium px-4 py-2">Enlace</th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($bandeja as $p)
                @php
                  $caida = $p->status === \App\Modules\Content\Services\Permanencia::CAIDA;
                  $quedan = $p->permanence_until
                      ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($p->permanence_until)->startOfDay(), false)
                      : null;
                @endphp
                <tr class="hover:bg-slate-50 {{ $caida ? 'bg-rose-50/40' : '' }}">
                  <td class="px-4 py-2 whitespace-nowrap">
                    @if ($caida)
                      <span class="text-xs px-2 py-0.5 rounded-full bg-rose-100 text-rose-700">Caído</span>
                    @else
                      <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">Vigilado</span>
                    @endif
                  </td>
                  <td class="px-4 py-2 whitespace-nowrap text-slate-500">
                    {{ $p->permanence_until ?? '—' }}
                    @if ($quedan !== null && !$caida)
                      <span class="text-xs text-slate-400">({{ $quedan }} d)</span>
                    @endif
                  </td>
                  <td class="px-4 py-2 text-slate-700">{{ $p->creador }}</td>
                  <td class="px-4 py-2 text-slate-500">{{ $p->campana }}</td>
                  <td class="px-4 py-2 whitespace-nowrap">
                    @if ($p->ultima_comprobacion === null)
                      <span class="text-xs text-amber-700">nunca</span>
                    @else
                      <span class="text-xs {{ (int) $p->ultima_viva === 0 ? 'text-rose-700 font-medium' : 'text-slate-500' }}">
                        {{ \Illuminate\Support\Carbon::parse($p->ultima_comprobacion)->diffForHumans() }}
                        · {{ (int) $p->ultima_viva === 1 ? 'estaba' : 'no estaba' }}
                      </span>
                    @endif
                  </td>
                  <td class="px-4 py-2 max-w-xs">
                    {{-- `rel="noopener noreferrer"`: el enlace lo pegó alguien de
                         fuera, y no le regalamos ni la referencia ni la ventana. --}}
                    <a href="{{ $p->url }}" target="_blank" rel="noopener noreferrer"
                       class="text-marca-600 hover:underline break-all text-xs">{{ $p->url }}</a>
                  </td>
                  <td class="px-4 py-2 text-right whitespace-nowrap">
                    <a href="{{ route('permanencia.ver', $p->uuid) }}"
                       class="text-marca-600 hover:underline">Mirar</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>

    <p class="text-xs text-slate-400">
      Nada de esto se comprueba solo: Instagram y TikTok responden igual ante un post
      borrado, un perfil en privado y un bloqueo, así que ningún estado HTTP puede
      acusar a un creador de incumplir. Una persona mira y una persona firma.
    </p>
  </div>
@endsection
