@extends('layouts.panel')
@section('titulo', 'Moneda de consolidación')
@section('subtitulo', 'En qué moneda suma el panel cuando hay varias')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Moneda de consolidación'])

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-900'
                                  : 'bg-amber-50 border-amber-200 text-amber-900' }}">
      <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold uppercase mr-2
        {{ $aviso->nivel === 'rojo' ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white' }}">
        {{ $aviso->nivel === 'rojo' ? 'Atender' : 'Revisar' }}
      </span>
      {{ $aviso->texto }}
    </div>
  @endforeach

  @if (session('mensaje'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('mensaje') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">

      {{-- La regla, escrita. Un formulario con tres desplegables y sin la frase
           que forman obliga a adivinar qué hace cada uno. --}}
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Con estos ajustes, hoy</h2>
        </div>
        <dl class="divide-y divide-slate-100 text-sm">
          <div class="px-5 py-4">
            <dt class="font-medium text-slate-900">El panel suma en {{ $ajustes['moneda'] }}</dt>
            <dd class="text-slate-600 mt-0.5">
              Cada importe en otra moneda se convierte con la tasa del cierre del periodo —o la
              última publicada antes— y el total se presenta en {{ $ajustes['moneda'] }}.
              Las cifras por moneda siguen saliendo debajo, sin convertir.
            </dd>
          </div>
          <div class="px-5 py-4">
            <dt class="font-medium text-slate-900">Lo que entra, al tipo de
              {{ mb_strtolower($lados[$ajustes['ingresos']] ?? $ajustes['ingresos']) }}</dt>
            <dd class="text-slate-600 mt-0.5">
              Facturado, cobrado y por cobrar.
            </dd>
          </div>
          <div class="px-5 py-4">
            <dt class="font-medium text-slate-900">Lo que sale, al tipo de
              {{ mb_strtolower($lados[$ajustes['egresos']] ?? $ajustes['egresos']) }}</dt>
            <dd class="text-slate-600 mt-0.5">
              Pagado a creadores. Son dos lados y no uno porque usar el mismo para lo que entra y
              lo que sale sería elegir a cuál de los dos números mentirle.
            </dd>
          </div>
        </dl>
        <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 text-xs text-slate-500 space-y-1">
          <p>
            <strong>Si una moneda no tiene tasa</strong>, su importe no entra en el total y el panel
            lo dice: cuánto quedó fuera, en qué moneda y por qué. Nunca sale un total de menos en
            silencio.
          </p>
          <p>
            <strong>Esto no es contabilidad.</strong> Convierte un agregado con una sola tasa,
            mientras que cada factura y cada pago llevan la suya congelada dentro. Sirve para leer
            la pantalla, no para declarar.
          </p>
        </div>
      </div>
    </div>

    <div class="space-y-5">
      <form method="POST" action="{{ route('moneda.update') }}"
            class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        @csrf
        @method('PUT')

        <div class="px-5 py-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold">Los ajustes</h2>
        </div>

        <div class="p-5 space-y-4">
          <div>
            <label for="m-base" class="block text-xs font-medium text-slate-600 mb-1">
              Moneda de consolidación
            </label>
            <select id="m-base" name="base_currency_code" required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                           focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500">
              @foreach ($monedas as $codigo => $nombre)
                <option value="{{ $codigo }}"
                  @selected(old('base_currency_code', $ajustes['moneda']) === $codigo)>
                  {{ $codigo }} — {{ $nombre }}
                </option>
              @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-400">Del catálogo de monedas activas.</p>
          </div>

          @foreach ([
            ['income_rate_side', 'Lo que entra', $ajustes['ingresos'],
             'Facturado, cobrado y por cobrar.'],
            ['expense_rate_side', 'Lo que sale', $ajustes['egresos'],
             'Pagado a creadores.'],
          ] as [$campo, $etiqueta, $valor, $ayuda])
            <div>
              <label for="m-{{ $campo }}" class="block text-xs font-medium text-slate-600 mb-1">
                {{ $etiqueta }}
              </label>
              <select id="m-{{ $campo }}" name="{{ $campo }}" required
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                             focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500">
                @foreach ($lados as $codigo => $nombre)
                  <option value="{{ $codigo }}" @selected(old($campo, $valor) === $codigo)>
                    {{ $nombre }}
                  </option>
                @endforeach
              </select>
              <p class="mt-1 text-xs text-slate-400">{{ $ayuda }}</p>
            </div>
          @endforeach
        </div>

        <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between gap-3">
          <p class="text-xs text-slate-400">De partida: {{ $partida['moneda'] }}</p>
          <button type="submit"
                  class="rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white
                         transition hover:bg-marca-700
                         focus:outline-none focus-visible:ring-2 focus-visible:ring-marca-500 focus-visible:ring-offset-2">
            Guardar
          </button>
        </div>
      </form>

      <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs text-slate-500 space-y-2">
        <p class="text-sm font-semibold text-slate-700">Qué hace este ajuste</p>
        <p>
          Cambia cómo se presentan los totales del panel. No toca ni un importe guardado: las
          facturas, los pagos y los asientos siguen exactamente igual, cada uno en su moneda y con
          su tasa congelada.
        </p>
        @if ($confirmado)
          <p class="text-slate-400">Confirmado: {{ $confirmado }} UTC.</p>
        @else
          <p class="text-amber-600">Sin confirmar: el panel usa el valor de partida.</p>
        @endif
      </div>
    </div>
  </div>
@endsection
