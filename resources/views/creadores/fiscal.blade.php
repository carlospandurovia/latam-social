@extends('layouts.panel')
@section('titulo', 'Datos fiscales de '.$creador->display_name)
@section('subtitulo', 'BR-CREATOR-013 · no existe el pago informal')

@section('contenido')
<div class="max-w-5xl">

  <a href="{{ route('creadores.show', $creador->uuid) }}" class="text-sm text-slate-500 hover:text-slate-800">← Volver a la ficha</a>

  @if (session('exito'))
    <div class="mt-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">{{ session('exito') }}</div>
  @endif
  @if (session('aviso'))
    <div class="mt-4 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 text-sm">{{ session('aviso') }}</div>
  @endif
  @if ($errors->any())
    <div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm">
      <ul class="list-disc list-inside space-y-0.5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  @if ($esMenor)
    <div class="mt-4 rounded-xl bg-slate-100 border border-slate-300 text-slate-700 px-4 py-3 text-sm">
      Este creador es <strong>menor de edad</strong>. El perfil tributario exigido es el
      <strong>del tutor</strong>, que es quien emite el comprobante (BR-CREATOR-010 y BR-CREATOR-013).
      @if ($tutores->isEmpty())
        <span class="text-rose-700">No tiene ninguna tutela activa, así que todavía no hay a quién ponerlo.</span>
      @endif
    </div>
  @endif

  {{-- ------------------------------------------------------------ histórico --}}
  <div class="mt-5 bg-white rounded-2xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
      <h2 class="text-sm font-semibold text-slate-800">Perfiles tributarios</h2>
      <p class="text-xs text-slate-500 mt-0.5">
        Solo puede haber uno vigente por país. Aprobar uno nuevo cierra el anterior; nada se borra.
      </p>
    </div>

    @forelse ($perfiles as $p)
      <div class="px-6 py-4 border-b border-slate-100 last:border-0">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-slate-800">
              {{ $p->tax_id_type }} {{ $p->tax_id_number }}
              <span class="text-slate-400">·</span> {{ $p->tax_regime_code }}
              <span class="text-slate-400">·</span> {{ $p->pais }}
            </p>
            <p class="text-xs text-slate-500 mt-0.5">
              Titular:
              <strong>{{ $p->holder_type === 'guardian' ? ($p->tutor ?: 'tutor') : 'el creador' }}</strong>
              <span class="text-slate-400">·</span> emite {{ $p->issued_document_type }}
              <span class="text-slate-400">·</span> desde {{ $p->valid_from }}{{ $p->valid_to ? ' hasta '.$p->valid_to : '' }}
            </p>
          </div>
          <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium
            @class([
              'bg-emerald-50 text-emerald-700' => $p->status === 'approved',
              'bg-amber-50 text-amber-800' => $p->status === 'pending',
              'bg-rose-50 text-rose-700' => $p->status === 'rejected',
              'bg-slate-100 text-slate-600' => $p->status === 'superseded',
            {{-- Anulado se ve DISTINTO de reemplazado a propósito: uno estuvo
                 vigente y el otro no valió nunca, y esa diferencia es la que
                 explica un hueco en el histórico. --}}
            'bg-rose-100 text-rose-800 line-through' => $p->status === 'annulled',
            ])">
            {{ $p->status }}
          </span>
        </div>

        {{-- La retención: el dato por el que existe DEC-048. --}}
        <p class="text-xs mt-2">
          Retención:
          @if ($p->withholding_status === 'pending_review')
            <span class="text-amber-700 font-medium">sin decidir</span>
            <span class="text-slate-500">— hasta que se decida, este perfil no se puede aprobar (DEC-048).</span>
          @elseif ($p->withholding_status === 'applies')
            <span class="text-slate-800 font-medium">{{ rtrim(rtrim(number_format((float) $p->withholding_rate, 4), '0'), '.') }} %</span>
            <span class="text-slate-500">— {{ $p->withholding_basis }}</span>
          @else
            <span class="text-slate-800 font-medium">no aplica</span>
          @endif
        </p>

        <p class="text-xs text-slate-400 mt-1">
          Capturado por {{ $p->capturado_por ?: '—' }}
          @if ($p->status === 'approved' && $p->aprobado_por)
            · aprobado por {{ $p->aprobado_por }} el {{ $p->approved_at }}
          @elseif ($p->status === 'rejected')
            {{-- El rechazo no escribe «aprobado por»: quién rechazó está en la
                 bitácora, que además es inmutable. Ver H-04. --}}
            · rechazado — quién lo hizo consta en la bitácora
          @endif
        </p>

        @if ($p->rejection_note)
          <p class="text-xs text-rose-700 mt-1">Motivo del rechazo: {{ $p->rejection_note }}</p>
        @endif

        @if ($p->status === 'annulled')
          <p class="text-xs text-rose-800 mt-1">
            <strong>Anulado:</strong> {{ $p->annulment_reason }}
            <span class="text-slate-500">— no estuvo vigente ningún día; quién lo anuló consta en la bitácora.</span>
          </p>
        @endif

        {{-- Anular el vigente. Va detrás de su propio permiso (`creator.tax.annul`)
             y no de `creator.tax.approve`: rechazar para a un perfil ANTES de que
             aplique, anular deshace uno que YA aplicaba. --}}
        @if ($p->status === 'approved' && $p->valid_to === null)
          @can('creator.tax.annul')
            <details class="mt-3">
              <summary class="text-xs text-rose-700 cursor-pointer hover:underline">Anular este perfil</summary>
              <form method="POST" action="{{ route('creadores.fiscal.anular', [$creador->uuid, $p->id]) }}" class="mt-2 space-y-2">
                @csrf
                <p class="text-xs text-slate-600">
                  Anular no es reemplazar: dice que este perfil <strong>no debió aprobarse nunca</strong>.
                  El creador se queda <strong>sin perfil fiscal vigente</strong> y no se le podrá invitar
                  ni liquidar hasta que se apruebe otro.
                </p>
                <input name="annulment_reason" maxlength="255"
                       placeholder="Por qué no debió aprobarse (mínimo 10 caracteres)"
                       class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                <label class="flex items-center gap-2 text-xs text-slate-600">
                  <input type="checkbox" name="confirma" value="1" class="rounded border-slate-300">
                  Entiendo que el creador se queda sin perfil fiscal vigente.
                </label>
                <button class="px-4 py-2 rounded-xl border border-rose-300 text-rose-700 text-sm font-medium hover:bg-rose-50">
                  Anular
                </button>
              </form>
            </details>
          @endcan
        @endif

        {{-- La resolución: decidir la retención es parte de aprobar. --}}
        @if ($p->status === 'pending')
          @can('creator.tax.approve')
            <div class="mt-4 rounded-xl bg-slate-50 border border-slate-200 p-4">
              <form method="POST" action="{{ route('creadores.fiscal.aprobar', [$creador->uuid, $p->id]) }}" class="grid gap-3 sm:grid-cols-4 items-end">
                @csrf
                <div class="sm:col-span-1">
                  <label class="block text-xs text-slate-600 mb-1" for="ws{{ $p->id }}">¿Se retiene?</label>
                  <select id="ws{{ $p->id }}" name="withholding_status" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="not_applicable">No aplica</option>
                    <option value="applies">Sí, se retiene</option>
                  </select>
                </div>
                <div class="sm:col-span-1">
                  <label class="block text-xs text-slate-600 mb-1" for="wr{{ $p->id }}">Tasa %</label>
                  <input id="wr{{ $p->id }}" name="withholding_rate" type="number" step="0.0001" min="0" max="100"
                         class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm" placeholder="30">
                </div>
                <div class="sm:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1" for="wb{{ $p->id }}">Norma que la sustenta</label>
                  <input id="wb{{ $p->id }}" name="withholding_basis" maxlength="160"
                         class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
                         placeholder="p. ej. LIR art. 54 inc. f — por confirmar con contador">
                </div>
                <div class="sm:col-span-4">
                  <label class="flex gap-2 items-start text-xs text-slate-700">
                    <input type="checkbox" name="confirma_revision" value="1" class="mt-0.5" required>
                    <span>Confirmo que revisé el documento fiscal del creador y que estos datos son los suyos.</span>
                  </label>
                </div>
                <div class="sm:col-span-4">
                  <button class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-medium hover:opacity-90">
                    Aprobar y dejar vigente
                  </button>
                </div>
              </form>

              <form method="POST" action="{{ route('creadores.fiscal.rechazar', [$creador->uuid, $p->id]) }}" class="mt-3 flex gap-2">
                @csrf
                <input name="rejection_note" maxlength="255" placeholder="Motivo del rechazo (mínimo 10 caracteres)"
                       class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                <button class="px-4 py-2 rounded-xl border border-rose-300 text-rose-700 text-sm font-medium hover:bg-rose-50">
                  Rechazar
                </button>
              </form>
            </div>
          @else
            <p class="mt-3 text-xs text-slate-500">Pendiente de que lo apruebe alguien con permiso — y que no seas tú si lo capturaste.</p>
          @endcan
        @endif
      </div>
    @empty
      <p class="px-6 py-6 text-sm text-slate-400">
        Todavía no hay ningún perfil tributario. Sin uno aprobado y vigente, este creador no se activa.
      </p>
    @endforelse
  </div>

  {{-- --------------------------------------------------------------- captura --}}
  @can('creator.tax.manage')
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6">
      <h2 class="text-sm font-semibold text-slate-800">Capturar un perfil nuevo</h2>
      <p class="text-xs text-slate-500 mt-1 mb-4">
        Nace <strong>pendiente</strong>. La retención no se pregunta aquí a propósito: la decide quien
        aprueba, que es quien conoce la norma (DEC-048).
      </p>

      <form method="POST" action="{{ route('creadores.fiscal.store', $creador->uuid) }}" class="grid gap-4 sm:grid-cols-3">
        @csrf

        <div>
          <label class="block text-sm text-slate-600 mb-1" for="country_id">País</label>
          <select id="country_id" name="country_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            @foreach ($paises as $pais)
              <option value="{{ $pais->id }}" @selected((int) old('country_id') === (int) $pais->id)>{{ $pais->name }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="block text-sm text-slate-600 mb-1" for="tax_regime_code">Régimen</label>
          <input id="tax_regime_code" name="tax_regime_code" maxlength="30" value="{{ old('tax_regime_code') }}"
                 placeholder="RUS · RER · GENERAL · AUTONOMO"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="block text-sm text-slate-600 mb-1" for="issued_document_type">Qué entrega al cobrar</label>
          <select id="issued_document_type" name="issued_document_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            @foreach (['recibo_honorarios' => 'Recibo por honorarios', 'factura' => 'Factura', 'invoice' => 'Invoice', 'none' => 'Nada'] as $v => $etiqueta)
              <option value="{{ $v }}" @selected(old('issued_document_type') === $v)>{{ $etiqueta }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="block text-sm text-slate-600 mb-1" for="tax_id_type">Tipo de identificación</label>
          <input id="tax_id_type" name="tax_id_type" maxlength="20" value="{{ old('tax_id_type', 'RUC') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="block text-sm text-slate-600 mb-1" for="tax_id_number">Número</label>
          <input id="tax_id_number" name="tax_id_number" maxlength="40" value="{{ old('tax_id_number') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="block text-sm text-slate-600 mb-1" for="valid_from">Vigente desde</label>
          <input id="valid_from" name="valid_from" type="date" value="{{ old('valid_from', now()->toDateString()) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="sm:col-span-3 border-t border-slate-100 pt-4">
          <label class="block text-sm text-slate-600 mb-1" for="holder_type">¿De quién son estos datos?</label>
          <div class="grid gap-3 sm:grid-cols-2">
            <select id="holder_type" name="holder_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
              <option value="creator" @selected(old('holder_type', $esMenor ? 'guardian' : 'creator') === 'creator')>Del creador</option>
              <option value="guardian" @selected(old('holder_type', $esMenor ? 'guardian' : 'creator') === 'guardian')>Del tutor</option>
            </select>
            <select name="holder_guardian_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
              <option value="">— sin tutor —</option>
              @foreach ($tutores as $t)
                <option value="{{ $t->id }}" @selected((int) old('holder_guardian_id') === (int) $t->id)>{{ $t->full_name }}</option>
              @endforeach
            </select>
          </div>
          <p class="text-xs text-slate-500 mt-2">
            Para un menor, el número fiscal que se guarda aquí es el del tutor. Decirlo en la fila
            es lo que evita que dentro de un año nadie sepa de quién era ese RUC.
          </p>
        </div>

        <div class="sm:col-span-3">
          <button class="px-5 py-2.5 rounded-xl bg-marca-500 text-white text-sm font-medium hover:opacity-90">
            Guardar como pendiente
          </button>
        </div>
      </form>
    </div>
  @endcan
</div>
@endsection
