@extends('layouts.panel')
@section('titulo', 'Panel')
@section('subtitulo', 'Estado del sistema')

@section('contenido')
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    @foreach ($tarjetas as $t)
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <p class="text-sm text-slate-500">{{ $t['titulo'] }}</p>
        <p class="mt-1 text-3xl font-bold text-slate-900 tabular-nums">{{ number_format($t['valor']) }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ $t['nota'] }}</p>
      </div>
    @endforeach
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Motor de base de datos</h2>
      <p class="text-sm text-slate-500 mb-4">
        Lo que el servidor hace de verdad, no lo que dice su número de versión.
      </p>
      <dl class="space-y-2.5 text-sm">
        @foreach ($motor as $etiqueta => [$ok, $detalle])
          <div class="flex items-start justify-between gap-4">
            <dt class="text-slate-600">{{ $etiqueta }}</dt>
            <dd class="flex items-center gap-2 shrink-0">
              <span class="text-xs text-slate-400">{{ $detalle }}</span>
              <span class="inline-block w-2 h-2 rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
            </dd>
          </div>
        @endforeach
      </dl>
      @unless ($motor['Aplica los CHECK de forma nativa'][0])
        <p class="mt-4 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
          Este motor ignora los <code>CHECK</code>. Las {{ $restricciones }} restricciones del esquema
          se imponen con <code>TRIGGER</code> (DEC-042). Funcionan igual: verificado.
        </p>
      @endunless
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Sociedades que facturan</h2>
      <p class="text-sm text-slate-500 mb-4">Una sola vigente por país, sin empates posibles.</p>
      @if ($cobertura->isEmpty())
        <p class="text-sm text-slate-400">Sin cobertura configurada todavía.</p>
      @else
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-slate-400 border-b border-slate-200">
              <th class="pb-2 font-medium">País</th>
              <th class="pb-2 font-medium">Factura</th>
              <th class="pb-2 font-medium">Motivo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($cobertura as $c)
              <tr>
                <td class="py-2 text-slate-700">{{ $c->pais }}</td>
                <td class="py-2 font-medium text-slate-900">{{ $c->sociedad }}</td>
                <td class="py-2 text-slate-500">{{ $c->motivo }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
@endsection
