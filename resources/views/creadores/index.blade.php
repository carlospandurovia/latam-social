@extends('layouts.panel')
@section('titulo', 'Creadores')
@section('subtitulo', $creadores->total().' en total')

@section('contenido')
  <form method="GET" class="mb-5 flex gap-3">
    <input name="q" value="{{ $q }}" placeholder="Buscar por nombre, correo o documento…"
           class="flex-1 max-w-md rounded-lg border border-slate-300 px-3 py-2 text-sm
                  focus:border-marca-400 focus:ring-2 focus:ring-marca-200 focus:outline-none">
    <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
      Buscar
    </button>
    @if ($q)
      <a href="{{ route('creadores.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
        Limpiar
      </a>
    @endif
  </form>

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 border-b border-slate-200">
        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
          <th class="px-4 py-3 font-medium">Creador</th>
          <th class="px-4 py-3 font-medium">Documento</th>
          <th class="px-4 py-3 font-medium">País</th>
          <th class="px-4 py-3 font-medium">Edad</th>
          <th class="px-4 py-3 font-medium">Estado</th>
          <th class="px-4 py-3 font-medium">Plazo pago</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($creadores as $c)
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <a href="{{ route('creadores.show', $c->uuid) }}" class="font-medium text-marca-600 hover:underline">
                {{ $c->display_name }}
              </a>
              <p class="text-xs text-slate-400">{{ $c->email }}</p>
            </td>
            <td class="px-4 py-3 text-slate-600 tabular-nums">
              {{ $c->document_type }} {{ $c->document_number }}
            </td>
            <td class="px-4 py-3 text-slate-600">{{ $c->pais }}</td>
            <td class="px-4 py-3 tabular-nums">
              {{ $c->edad }}
              @if ($c->edad < 18)
                <span class="ml-1 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                  menor
                </span>
              @endif
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                {{ match($c->status) {
                     'active' => 'bg-emerald-50 text-emerald-700',
                     'pending' => 'bg-amber-50 text-amber-700',
                     'suspended', 'blacklisted' => 'bg-rose-50 text-rose-700',
                     default => 'bg-slate-100 text-slate-600',
                   } }}">
                {{ $c->status }}
              </span>
            </td>
            <td class="px-4 py-3 text-slate-600 tabular-nums">{{ $c->payment_term_days }} días</td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="px-4 py-12 text-center">
              <p class="text-slate-400">
                @if ($q) Ningún creador coincide con «{{ $q }}». @else Todavía no hay creadores. @endif
              </p>
              @unless ($q)
                <p class="mt-2 text-xs text-slate-400">
                  Para ver datos de prueba: <code class="text-slate-600">php artisan db:seed --class=DemoSeeder</code>
                </p>
              @endunless
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-5">{{ $creadores->withQueryString()->links() }}</div>
@endsection
