@extends('layouts.panel')
@section('titulo', 'Páginas')
@section('subtitulo', 'Las páginas públicas del sitio: privacidad, términos y las que hagan falta')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Páginas'])

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo'
          ? 'bg-rose-50 border-rose-200 text-rose-900'
          : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso->nivel === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso->nivel === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso->texto }}
    </div>
  @endforeach

  @if (session('mensaje'))
    <div class="mb-4 rounded-lg border border-marca-200 bg-marca-50 px-4 py-3 text-sm text-marca-800">
      {{ session('mensaje') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">Página</th>
            <th class="px-4 py-3">Estado</th>
            <th class="px-4 py-3">Revisión</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse ($paginas as $p)
            <tr>
              <td class="px-4 py-3">
                <a href="{{ route('paginas.editar', ['uuid' => $p->uuid]) }}"
                   class="font-medium text-slate-900 hover:text-marca-700">{{ $p->title }}</a>
                <span class="block font-mono text-xs text-slate-400">/{{ $p->slug }}</span>
              </td>
              <td class="px-4 py-3">
                @if ($p->published_at)
                  <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-xs text-emerald-700">
                    v{{ $p->version }} publicada
                  </span>
                @else
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">Sin publicar</span>
                @endif
                @unless ($p->show_in_footer)
                  <span class="ml-1 text-xs text-slate-400">fuera del pie</span>
                @endunless
              </td>
              <td class="px-4 py-3">
                @if ($p->published_at)
                  <span class="rounded px-1.5 py-0.5 text-xs
                    {{ $p->review_status === 'revisado'
                        ? 'bg-emerald-50 text-emerald-700'
                        : ($p->review_status === 'en_revision' ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-800') }}">
                    {{ \App\Modules\Core\Services\Paginas::REVISION[$p->review_status] ?? $p->review_status }}
                  </span>
                @endif
              </td>
              <td class="px-4 py-3 text-right">
                @if ($p->published_at)
                  <a href="{{ route('pagina', ['slug' => $p->slug]) }}" target="_blank" rel="noopener"
                     class="text-xs text-slate-500 hover:underline">Ver</a>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Todavía no hay ninguna.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <form method="POST" action="{{ route('paginas.guardar') }}"
          class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
      @csrf
      <h2 class="text-sm font-semibold text-slate-900">Añadir una página</h2>

      <label class="block text-sm text-slate-600">Título
        <input name="title" maxlength="160" required value="{{ old('title') }}"
               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
      </label>

      <label class="block text-sm text-slate-600">Dirección
        <div class="mt-1 flex items-center gap-1">
          <span class="text-xs text-slate-400">/</span>
          <input name="slug" maxlength="60" required placeholder="sobre-nosotros" value="{{ old('slug') }}"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm">
        </div>
        <span class="mt-1 block text-xs text-slate-400">
          En minúsculas y con guiones. No puede ser ninguna de las
          {{ count($reservadas) }} direcciones que ya usa el sistema: taparía una pantalla
          que existe y dejaría de abrirse.
        </span>
      </label>

      <div class="flex items-center gap-4">
        <label class="text-sm text-slate-600">Orden
          <input type="number" name="sort_order" value="100" min="0" max="9999"
                 class="mt-1 w-20 rounded-lg border border-slate-300 px-2 py-1">
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" name="show_in_footer" value="1" checked
                 class="rounded border border-slate-300">
          Sale en el pie
        </label>
      </div>

      <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
        Crear
      </button>

      <p class="text-xs text-slate-400">
        Se crea vacía. El texto se escribe dentro, y hasta que no se publique no se ve.
      </p>
    </form>
  </div>
@endsection
