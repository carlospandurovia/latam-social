@extends('layouts.panel')
@section('titulo', $entidad->code)
@section('subtitulo', $entidad->legal_name)

@section('contenido')
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-start justify-between">
          <h2 class="text-sm font-medium text-slate-700">Datos de la sociedad</h2>
          @if ($entidad->status === 'active')
            <a href="{{ route('entidades.edit', $entidad->uuid) }}" class="text-xs text-marca-700 hover:underline">Editar</a>
          @endif
        </div>
        <dl class="mt-3 space-y-2 text-sm">
          @foreach ([
            'Documento' => $entidad->tax_id_type.' '.$entidad->tax_id_number,
            'Nombre comercial' => $entidad->trade_name ?: '—',
            'Domicilio' => implode(', ', array_filter([
              $entidad->address_line1, $entidad->district, $entidad->city, $entidad->region,
            ])),
            // 9.17c: se llama como lo llame el pais de esta sociedad --«Ubigeo»
            // en Peru, «Codigo DANE» en Colombia--. La etiqueta sale del
            // catalogo, no del codigo.
            ($pais->tax_location_label ?: 'Código de localidad')
              => $entidad->tax_location_code ?: '— sin poner',
            'Establecimiento' => $entidad->establishment_code,
            'Moneda' => $entidad->default_currency_code,
            'Zona horaria' => $entidad->timezone,
            'Representante' => $entidad->legal_representative ?: '—',
            'Constituida el' => $entidad->incorporated_on ?: '—',
            'Estado' => $entidad->status,
          ] as $k => $v)
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">{{ $k }}</dt>
              <dd class="text-slate-800 text-right">{{ $v }}</dd>
            </div>
          @endforeach
        </dl>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Cobertura de facturación</h2>
        <p class="mt-1 text-xs text-slate-500">
          Qué países puede facturar y desde cuándo. <code>BR-LE-003</code> se resuelve
          <strong>en la fecha de la operación</strong>, no en la de hoy.
        </p>
        @forelse ($coberturas as $c)
          <div class="mt-3 border-t border-slate-100 pt-3 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-800">{{ $c->pais }}</span>
              @if ($c->valid_to)
                <span class="text-xs text-slate-400">cerrada</span>
              @else
                <span class="text-xs text-emerald-700">abierta</span>
              @endif
            </div>
            <div class="text-xs text-slate-500">{{ $motivos[$c->coverage_basis] ?? $c->coverage_basis }}</div>
            <div class="text-xs text-slate-400">
              @if ($c->valid_to)
                del {{ $c->valid_from }} al {{ $c->valid_to }}
              @else
                desde {{ $c->valid_from }}
              @endif
            </div>
          </div>
        @empty
          <p class="mt-3 text-sm text-amber-700">
            No cubre ningún país todavía: <strong>no se le puede facturar a nadie desde esta sociedad.</strong>
          </p>
        @endforelse
      </div>
    </div>

    <div class="space-y-5">
      @if ($entidad->status === 'active')
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700">Declarar cobertura de un país</h2>
          <form method="POST" action="{{ route('entidades.cobertura', $entidad->uuid) }}" class="mt-3 space-y-3">
            @csrf
            <div>
              <label for="country_id" class="block text-xs font-medium text-slate-600 mb-1">País</label>
              <select id="country_id" name="country_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($paises as $p)
                  <option value="{{ $p->id }}" @selected((int) old('country_id') === (int) $p->id)>
                    {{-- `ocupadosPorPais()` NO filtra por estado, y es correcto: contesta
                         «¿esta el sitio ocupado?», que es lo que mira `uq_lec_country`.
                         Pero pintarlo como «lo cubre» era decir justo lo contrario de lo
                         que esta iteracion vino a hacer visible: una sociedad inactiva
                         ocupa el sitio y NO puede facturar. Ese es el pais incomunicado. --}}
                    {{ $p->name }}@isset($ocupados[$p->id])
                      @if ($ocupados[$p->id]->status === 'active')
                        · lo cubre {{ $ocupados[$p->id]->code }}
                      @else
                        · sitio ocupado por {{ $ocupados[$p->id]->code }} ({{ $ocupados[$p->id]->status }}: NO puede facturar)
                      @endif
                    @endisset
                  </option>
                @endforeach
              </select>
              @error('country_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
              <label for="coverage_basis" class="block text-xs font-medium text-slate-600 mb-1">Motivo</label>
              <select id="coverage_basis" name="coverage_basis" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($motivos as $v => $t)
                  <option value="{{ $v }}" @selected(old('coverage_basis') === $v)>{{ $t }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="valid_from" class="block text-xs font-medium text-slate-600 mb-1">Desde</label>
              <input id="valid_from" name="valid_from" type="date" value="{{ old('valid_from', $hoy) }}"
                     class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              @error('valid_from') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            {{-- `valid_to` es INCLUSIVO: la anterior se cierra el día ANTES. Se
                 dice donde se elige la fecha, no sólo en el código. --}}
            <p class="text-xs text-slate-500">
              Si el país ya lo cubre otra sociedad, ésa queda cerrada <strong>el día anterior</strong>
              a la fecha que pongas, y la nueva rige desde esa fecha. Un país no puede tener
              dos sociedades el mismo día: de ahí sale quién emite la factura.
            </p>
            <button class="rounded-lg bg-marca-500 px-4 py-2 text-sm font-medium text-white hover:bg-marca-600 transition">
              Declarar cobertura
            </button>
          </form>
        </div>

        <div class="bg-white rounded-xl border border-rose-200 p-5">
          <h2 class="text-sm font-medium text-rose-900">Dar de baja</h2>
          {{-- DEC-081. Sin cerrar las coberturas, los países que cubría quedan
               sin cubrir Y sin poder cubrirse: la fila abierta sigue ocupando
               el sitio y ninguna otra sociedad puede entrar. --}}
          <p class="mt-1 text-xs text-rose-800">
            Al dar de baja se <strong>cierran sus coberturas abiertas</strong> en la fecha que
            indiques. Si no se cerraran, esos países quedarían sin cubrir y sin que ninguna
            otra sociedad pudiera cubrirlos.
          </p>
          @if ($coberturas->whereNull('valid_to')->isNotEmpty())
            <p class="mt-2 text-xs text-rose-800">
              Se cerrarán: <strong>{{ $coberturas->whereNull('valid_to')->pluck('pais')->implode(', ') }}</strong>.
            </p>
          @endif
          <form method="POST" action="{{ route('entidades.baja', $entidad->uuid) }}" class="mt-3 space-y-3">
            @csrf
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label for="hasta" class="block text-xs font-medium text-slate-600 mb-1">Último día que factura</label>
                <input id="hasta" name="hasta" type="date" value="{{ old('hasta', $hoy) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('hasta') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
              </div>
              <div>
                <label for="estado" class="block text-xs font-medium text-slate-600 mb-1">Motivo</label>
                <select id="estado" name="estado" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <option value="inactive">Inactiva (deja de operar)</option>
                  <option value="dissolved">Disuelta (deja de existir)</option>
                </select>
              </div>
            </div>
            <button class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50 transition">
              Dar de baja
            </button>
          </form>
        </div>
      @else
        <div class="bg-white rounded-xl border border-slate-200 p-5 text-sm text-slate-500">
          Esta sociedad está <strong>{{ $entidad->status }}</strong>@if ($entidad->dissolved_on) desde el {{ $entidad->dissolved_on }}@endif.
          No puede declarar cobertura: ocuparía el sitio de un país sin poder facturarlo,
          y ninguna otra sociedad podría tomarlo.
        </div>
      @endif
    </div>
  </div>
@endsection
