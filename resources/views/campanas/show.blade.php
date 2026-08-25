@extends('layouts.panel')
@section('titulo', $campana->name)
@section('subtitulo', $campana->code)

@section('contenido')
  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-5">
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Datos</h2>
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div><dt class="text-slate-500">Estado</dt>
            <dd class="font-medium">{{ $estados[$campana->status] ?? $campana->status }}</dd></div>
          <div><dt class="text-slate-500">Objetivo</dt>
            <dd>{{ $objetivos[$campana->objective] ?? $campana->objective }}</dd></div>
          <div><dt class="text-slate-500">Fechas</dt>
            <dd>{{ $campana->starts_on }} → {{ $campana->ends_on }}</dd></div>
          <div><dt class="text-slate-500">Ingreso</dt>
            <dd>
              {{ number_format((float) $campana->revenue_amount, 2) }} {{ $campana->currency_code }}
              {{-- Un cero se explica SIEMPRE, de las dos maneras: enseñar sólo la
                   etiqueta «gratuita» dejaría el otro cero --el que nadie ha
                   contestado-- pareciendo un dato normal. --}}
              @if ((float) $campana->revenue_amount <= 0)
                @if ($campana->is_gratis)
                  <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">gratuita</span>
                @else
                  <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">sin precio</span>
                @endif
              @endif
            </dd></div>
          <div><dt class="text-slate-500">Rondas incluidas</dt>
            <dd>{{ $campana->included_revision_rounds }}</dd></div>
          <div><dt class="text-slate-500">Edad mínima</dt>
            <dd>{{ $campana->min_creator_age > 0 ? $campana->min_creator_age.' años' : 'sin restricción' }}</dd></div>
        </dl>
      </div>

      {{-- EL BRIEF. Lo primero de la columna porque es lo que decide si la
           campaña puede salir de borrador (`BR-CAMPAIGN-004`). --}}
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Qué hay que entregar</h2>

        @if ($requisitos->isEmpty())
          <p class="text-sm text-amber-800">
            El brief todavía no dice qué hay que entregar. Sin al menos un formato, la campaña
            no puede salir de borrador: un creador no puede decidir si acepta algo que nadie
            ha descrito.
          </p>
        @else
          <table class="w-full text-sm mb-4">
            <thead class="text-slate-500">
              <tr>
                <th class="text-left font-medium pb-2">Formato</th>
                <th class="text-right font-medium pb-2">Cantidad</th>
                <th class="text-right font-medium pb-2">Entrega</th>
                <th class="text-right font-medium pb-2">Permanencia</th>
                <th></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              @foreach ($requisitos as $r)
                <tr>
                  <td class="py-2">
                    {{ $r->red ? $r->red.' · ' : '' }}{{ $r->formato }}
                    @if ($r->notes)
                      <p class="text-xs text-slate-500">{{ $r->notes }}</p>
                    @endif
                  </td>
                  <td class="py-2 text-right">{{ $r->quantity }}</td>
                  <td class="py-2 text-right">{{ $r->deadline_offset_days }} d</td>
                  <td class="py-2 text-right">{{ $r->permanence_days }} d</td>
                  <td class="py-2 text-right">
                    @can('campaign.manage')
                      @if ($editable)
                        <form method="POST"
                              action="{{ route('campanas.requisitos.quitar', [$campana->uuid, $r->id]) }}">
                          @csrf @method('DELETE')
                          <button class="text-xs text-rose-600 hover:underline">Quitar</button>
                        </form>
                      @endif
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif

        @can('campaign.manage')
          {{-- `$editable` lo decide el controlador. La lista de estados NO se
               repite aqui: una plantilla que sabe cuales son los estados
               iniciales es una plantilla que hay que acordarse de tocar el dia
               que se anada uno. --}}
          @if ($editable)
            <form method="POST" action="{{ route('campanas.requisitos.anadir', $campana->uuid) }}"
                  class="border-t border-slate-100 pt-4 grid gap-3 sm:grid-cols-5 items-end">
              @csrf
              <div class="sm:col-span-2">
                <label for="content_format_id" class="block text-xs text-slate-600 mb-1">Formato</label>
                <select id="content_format_id" name="content_format_id"
                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  @foreach ($formatos as $f)
                    <option value="{{ $f->id }}" data-permanencia="{{ $f->default_permanence_days }}">
                      {{ $f->red ? $f->red.' · ' : '' }}{{ $f->code }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="quantity" class="block text-xs text-slate-600 mb-1">Cantidad</label>
                <input id="quantity" name="quantity" type="number" min="1" max="999"
                       value="{{ old('quantity', 1) }}"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <label for="deadline_offset_days" class="block text-xs text-slate-600 mb-1">Entrega (días)</label>
                <input id="deadline_offset_days" name="deadline_offset_days" type="number" min="0" max="365"
                       value="{{ old('deadline_offset_days', 7) }}"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <label for="permanence_days" class="block text-xs text-slate-600 mb-1">Permanencia (días)</label>
                <input id="permanence_days" name="permanence_days" type="number" min="0" max="3650"
                       value="{{ old('permanence_days', $formatos->first()->default_permanence_days ?? 30) }}"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div class="sm:col-span-4">
                <label for="notes" class="block text-xs text-slate-600 mb-1">Notas <span class="text-slate-400">(opcional)</span></label>
                <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="255"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
              </div>
              <div>
                <button class="w-full rounded-lg bg-marca-500 px-3 py-2 text-sm font-medium text-white hover:bg-marca-600">
                  Añadir
                </button>
              </div>
            </form>

            @foreach (['content_format_id', 'quantity', 'deadline_offset_days', 'permanence_days', 'notes'] as $campo)
              @error($campo) <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            @endforeach
          @endif
        @endcan
      </div>

      @if ($campana->briefing)
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700 mb-3">Briefing</h2>
          <p class="text-sm text-slate-600 whitespace-pre-line">{{ $campana->briefing }}</p>
        </div>
      @endif
    </div>

    <div class="space-y-5">
      {{-- QUIÉN FACTURA, y de dónde sale.
           Se enseña el dato GUARDADO, no el que devolvería la cobertura hoy:
           `BR-LE-001` dice que nunca se deduce de la configuración vigente en el
           momento de la consulta. Cuando los dos no coinciden se dice, porque
           entonces hay algo que mirar. --}}
      {{-- Se enseña ANTES de que nadie pulse un botón. Descubrir lo que falta
           al intentar mover la campaña es enterarse tarde, y con todos los
           motivos de una vez para no tener que volver dos veces. --}}
      @if ($faltan !== [])
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
          <p class="font-medium">Todavía no puede salir de borrador</p>
          <ul class="mt-2 space-y-1 list-disc list-inside">
            @foreach ($faltan as $motivo)
              <li>{{ $motivo }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700 mb-3">Quién la factura</h2>

        @if ($campana->billing_legal_entity_id)
          <p class="text-sm font-medium">
            {{ $cobertura->hay() ? $cobertura->entidad->legal_name : 'Sociedad #'.$campana->billing_legal_entity_id }}
          </p>
          <p class="mt-1 text-xs text-slate-500">
            Resuelto al {{ $campana->starts_on }}, la fecha en que empieza el servicio
            (<code>BR-LE-003</code>).
          </p>

          @if ($campana->confirmed_at)
            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
              Congelada: la campaña se confirmó el {{ $campana->confirmed_at }} y su sociedad
              ya no se puede cambiar (<code>BR-LE-002</code>). Corregirla exige anular la
              campaña y crear otra.
            </p>
          @endif
        @else
          <p class="text-sm text-amber-800">{{ $cobertura->explicacion }}</p>
          <p class="mt-2 text-xs text-slate-500">
            Hasta que exista esa cobertura, la campaña no puede salir de borrador.
          </p>
        @endif
      </div>

      @if ($transiciones !== [])
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <h2 class="text-sm font-medium text-slate-700 mb-3">Mover a</h2>
          {{-- Sólo lo que ESTE usuario puede hacer: un botón que va a dar 403
               es peor que no tenerlo. --}}
          <div class="flex flex-wrap gap-2">
            @foreach ($transiciones as $destino => $nombre)
              <form method="POST" action="{{ route('campanas.estado', $campana->uuid) }}">
                @csrf
                <input type="hidden" name="estado" value="{{ $destino }}">
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                  {{ $nombre }}
                </button>
              </form>
            @endforeach
          </div>
        </div>
      @endif

      @can('campaign.manage')
        @if (in_array($campana->status, ['draft', 'pending_approval', 'cancelled'], true))
          <a href="{{ route('campanas.edit', $campana->uuid) }}"
             class="block text-center rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
            Editar
          </a>
        @endif
      @endcan
    </div>
  </div>
@endsection
