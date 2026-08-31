@extends('layouts.panel')
@section('titulo', 'Entidades legales')
@section('subtitulo', 'Las sociedades del grupo y qué países factura cada una')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Entidades legales'])

  <div class="flex items-start justify-between mb-5">
    <p class="max-w-2xl text-sm text-slate-500">
      De la sociedad emisora salen la numeración de comprobantes (<code>BR-LE-007</code>),
      los datos que se congelan en cada factura (<code>BR-LE-005</code>) y las cuentas
      de cobro (<code>BR-LE-006</code>). <strong>Un país sólo puede tener una sociedad
      cubriéndolo a la vez.</strong>
    </p>
    <a href="{{ route('entidades.create') }}"
       class="shrink-0 rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
      Nueva sociedad
    </a>
  </div>

  {{-- La pregunta por la que se entra a esta pantalla es «¿por qué no puedo
       facturar a este cliente?». Se contesta arriba del todo, no escondida en
       una ficha. BR-LE-004 pide un mensaje accionable; esto es el mismo mensaje
       antes de que alguien tropiece con él. --}}
  @if ($descubiertos->isNotEmpty())
    <div class="mb-5 rounded-xl bg-amber-50 border border-amber-200 p-4">
      <p class="text-sm font-medium text-amber-900">
        Hay clientes en países que hoy ({{ $hoy }}) no puede facturar ninguna sociedad:
      </p>
      <ul class="mt-2 space-y-1 text-sm text-amber-800">
        @foreach ($descubiertos as $d)
          <li>· <strong>{{ $d->name }}</strong> — {{ $d->clientes }} {{ $d->clientes === 1 ? 'cliente' : 'clientes' }}</li>
        @endforeach
      </ul>
      <p class="mt-2 text-xs text-amber-700">
        Entra en la sociedad que corresponda y declárale la cobertura de ese país,
        diciendo desde cuándo y con qué motivo.
      </p>
    </div>
  @endif

  <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
        <tr>
          <th class="px-4 py-3">Código</th>
          <th class="px-4 py-3">Razón social</th>
          <th class="px-4 py-3">Constituida en</th>
          <th class="px-4 py-3">Moneda</th>
          <th class="px-4 py-3">Estado</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse ($entidades as $e)
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <a href="{{ route('entidades.show', $e->uuid) }}" class="font-medium text-marca-700 hover:underline">{{ $e->code }}</a>
            </td>
            <td class="px-4 py-3 text-slate-700">{{ $e->legal_name }}</td>
            <td class="px-4 py-3 text-slate-500">{{ $e->pais }}</td>
            <td class="px-4 py-3 text-slate-500">{{ $e->default_currency_code }}</td>
            <td class="px-4 py-3">
              @if ($e->status === 'active')
                <span class="text-emerald-700">activa</span>
              @else
                <span class="text-slate-400">{{ $e->status }}</span>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="px-4 py-6 text-center text-slate-400">
              No hay ninguna sociedad. <strong>Sin sociedad no se puede emitir ninguna factura.</strong>
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
@endsection
