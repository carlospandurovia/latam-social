@extends('layouts.panel')
@section('titulo', 'Prospectos')
@section('subtitulo', 'Las marcas que escribieron por la portada')

@section('contenido')
  @if (session('exito'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  {{-- Los filtros por estado, con su cuenta. Un número al lado del nombre evita
       entrar en cada pestaña para ver si hay algo. --}}
  <div class="mb-5 flex flex-wrap gap-2 text-sm">
    @foreach (array_merge(['todos' => 'Todos'], $estados) as $clave => $texto)
      <a href="{{ route('prospectos.index', ['estado' => $clave]) }}"
         class="rounded-lg border px-3 py-1.5
           {{ $estado === $clave ? 'border-marca-200 bg-marca-50 text-marca-800 font-medium'
              : 'border-slate-200 text-slate-600 hover:border-slate-300' }}">
        {{ \Illuminate\Support\Str::before($texto, ' —') }}
        @if ($clave !== 'todos')
          <span class="text-slate-400">{{ $conteos[$clave] ?? 0 }}</span>
        @endif
      </a>
    @endforeach
  </div>

  @forelse ($prospectos as $p)
    <div class="mb-4 bg-white rounded-xl border overflow-hidden
      {{ $p->status === 'new' ? 'border-marca-200' : 'border-slate-200' }}">
      <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-2">
        <div>
          <h2 class="text-sm font-semibold">{{ $p->company_name }}</h2>
          <p class="text-xs text-slate-500">
            {{ $p->contact_name }} · {{ $p->email }}
            @if ($p->phone) · {{ $p->phone }} @endif
            · {{ $p->pais }}
          </p>
        </div>
        <span class="rounded px-2 py-0.5 text-xs
          {{ $p->status === 'new' ? 'bg-marca-500 text-white'
             : ($p->status === 'discarded' ? 'bg-slate-200 text-slate-600'
             : ($p->status === 'converted' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800')) }}">
          {{ \Illuminate\Support\Str::before($estados[$p->status] ?? $p->status, ' —') }}
        </span>
      </div>

      <div class="px-5 py-3 space-y-1 text-sm text-slate-600 border-b border-slate-50">
        @if ($p->website)
          <p class="text-xs"><span class="text-slate-400">Web:</span> {{ $p->website }}</p>
        @endif
        @if ($p->message)<p>{{ $p->message }}</p>@endif
        <p class="text-xs text-slate-400">
          Llegó el {{ substr((string) $p->submitted_at, 0, 16) }}
          @if ($p->revisor) · lo movió {{ $p->revisor }} el {{ substr((string) $p->reviewed_at, 0, 16) }} @endif
          @if ($p->cliente) · ahora es {{ $p->cliente }} @endif
        </p>
        @if ($p->note)
          <p class="text-xs text-slate-600 border-l-2 border-slate-200 pl-2">{{ $p->note }}</p>
        @endif
      </div>

      @can('client.manage')
        @if ($p->status !== 'converted')
          <div class="px-5 py-3 grid gap-3 lg:grid-cols-2">
            <form method="POST" action="{{ route('prospectos.mover', $p->uuid) }}" class="flex flex-wrap items-end gap-2">
              @csrf
              <label class="block text-xs text-slate-500">Mover a
                <select name="estado" class="mt-1 rounded border-slate-300 text-sm">
                  @foreach ($estados as $clave => $texto)
                    @continue($clave === 'new' || $clave === 'converted')
                    <option value="{{ $clave }}">{{ \Illuminate\Support\Str::before($texto, ' —') }}</option>
                  @endforeach
                </select>
              </label>
              <label class="block text-xs text-slate-500 flex-1 min-w-48">Nota
                <input name="nota" maxlength="500" placeholder="Obligatoria si se descarta"
                       class="mt-1 w-full rounded border-slate-300 text-sm">
              </label>
              <button class="rounded bg-slate-700 px-3 py-2 text-sm text-white">Mover</button>
            </form>

            <form method="POST" action="{{ route('prospectos.convertir', $p->uuid) }}" class="flex flex-wrap items-end gap-2">
              @csrf
              <label class="block text-xs text-slate-500 flex-1 min-w-48">Ya es este cliente
                <select name="client_organization_id" class="mt-1 w-full rounded border-slate-300 text-sm">
                  @foreach ($clientes as $c)
                    <option value="{{ $c->id }}">{{ $c->commercial_name }}</option>
                  @endforeach
                </select>
              </label>
              <button class="rounded bg-marca-500 px-3 py-2 text-sm text-white">Enlazar</button>
            </form>
          </div>
          <p class="px-5 pb-3 text-xs text-slate-400">
            El cliente se crea en su pantalla de siempre y aquí sólo se dice cuál: dos sitios donde
            se crea un cliente es como acaban divergiendo.
          </p>
        @endif
      @endcan
    </div>
  @empty
    <p class="text-sm text-slate-500">
      No hay contactos {{ $estado === 'todos' ? '' : 'en ese estado' }} todavía.
    </p>
  @endforelse

  {{ $prospectos->links() }}
@endsection
