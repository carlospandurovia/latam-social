@extends('layouts.panel')
@section('titulo', 'Comprobantes')
@section('subtitulo', 'Lo que se le factura al cliente')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Comprobantes'])

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

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-800'
         : 'bg-amber-50 border-amber-200 text-amber-800' }}">
      {{ $aviso->texto }}
    </div>
  @endforeach

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
        <a href="{{ route('facturas.index') }}"
           class="rounded px-2 py-1 text-xs {{ $estado === '' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600' }}">Todas</a>
        @foreach ($estados as $clave => $texto)
          <a href="{{ route('facturas.index', ['estado' => $clave]) }}"
             class="rounded px-2 py-1 text-xs {{ $estado === $clave ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600' }}">
            {{ \Illuminate\Support\Str::before($texto, ' —') }}
          </a>
        @endforeach
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-4 py-2 text-left">Número</th>
              <th class="px-4 py-2 text-left">Cliente</th>
              <th class="px-4 py-2 text-left">Campaña</th>
              <th class="px-4 py-2 text-right">Total</th>
              <th class="px-4 py-2 text-left">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse ($facturas as $f)
              <tr class="{{ $f->status === 'voided' ? 'text-slate-400 line-through' : '' }}">
                <td class="px-4 py-2 font-mono text-xs">
                  <a class="text-marca-600 hover:underline" href="{{ route('facturas.ver', ['uuid' => $f->uuid]) }}">
                    @if ($f->number === null)
                      sin número
                    @else
                      {{ $f->series }}-{{ $f->number }}
                    @endif
                  </a>
                  <span class="block text-[11px] text-slate-400">{{ $f->issue_date }}</span>
                </td>
                <td class="px-4 py-2">
                  {{ $f->receiver_legal_name_snapshot ?: $f->cliente }}
                  <span class="block text-[11px] text-slate-400">{{ $f->sociedad }}</span>
                </td>
                <td class="px-4 py-2 text-xs">{{ $f->campana_codigo }} <span class="text-slate-400">{{ $f->campana }}</span></td>
                <td class="px-4 py-2 text-right font-mono">
                  {{ $f->currency_code }} {{ number_format((float) $f->total_amount, 2) }}
                  <span class="block text-[11px] text-slate-400">
                    {{ $f->tax_regime === 'gravado' ? 'con impuesto' : 'sin impuesto' }}
                  </span>
                </td>
                <td class="px-4 py-2 text-xs">{{ \Illuminate\Support\Str::before($estados[$f->status] ?? $f->status, ' —') }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-6 text-sm text-slate-500">
                Todavía no hay ningún comprobante. Los borradores salen de una campaña terminada.
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="px-4 py-3">{{ $facturas->withQueryString()->links() }}</div>
    </div>

    <div class="space-y-4">
      @if ($puedeEmitir)
        <form method="POST" action="{{ route('facturas.borrador') }}"
              class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
          @csrf
          <h2 class="text-sm font-semibold">Facturar una campaña</h2>
          <p class="text-xs text-slate-500">
            Se abre un <strong>borrador</strong>. Todavía no gasta correlativo: el número se pide
            al emitir, para que un borrador descartado no deje un hueco en la numeración.
          </p>

          <label class="block text-xs text-slate-500">Campaña
            <select name="campaign_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
              @forelse ($facturables as $c)
                <option value="{{ $c->id }}">
                  {{ $c->code }} — {{ $c->name }} ({{ $c->currency_code }} {{ number_format((float) $c->revenue_amount, 2) }})
                </option>
              @empty
                <option value="" disabled>No hay campañas terminadas por facturar</option>
              @endforelse
            </select>
          </label>

          <button class="w-full rounded bg-marca-500 px-3 py-2 text-sm text-white"
                  {{ count($facturables) === 0 ? 'disabled' : '' }}>Abrir borrador</button>
        </form>
      @endif

      <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
        <p class="font-semibold text-slate-800 mb-1">Una factura emitida no se corrige</p>
        <p>
          Se anula y se emite otra. El motor lo impone: en cuanto un comprobante deja de ser
          borrador, su importe, sus fechas y los datos de las dos partes quedan congelados.
          Y el número que gastó <strong>sigue siendo suyo</strong> aunque se anule.
        </p>
      </div>
    </div>
  </div>
@endsection
