@extends('layouts.acceso')
@section('titulo', 'Revisar una pieza')

@section('contenido')
  {{-- Lo que ve el cliente y NADA MÁS: su campaña, su marca, el formato, quién
       lo hizo y la pieza.

       Ni el importe del creador, ni el presupuesto, ni el margen, ni las otras
       piezas de la campaña. `BR-SEC-001` es 🔴, y la frontera de verdad está en
       `Aprobaciones::pieza()`, que enumera columnas. Esto es sólo la vista. --}}
  <h1 class="text-xl font-semibold text-slate-900 mb-1">
    {{ $pieza->marca ?? $pieza->campana }} · {{ $pieza->formato }}
  </h1>
  <p class="text-sm text-slate-500 mb-6">
    Pieza #{{ $pieza->sequence_number }} de {{ $pieza->campana }}, por {{ $pieza->creador }}.
  </p>

  <dl class="rounded-xl border border-slate-200 divide-y divide-slate-100 text-sm">
    <div class="flex justify-between gap-4 px-4 py-3">
      <dt class="text-slate-500">Formato</dt>
      <dd class="font-medium text-slate-900 text-right">
        {{ $pieza->formato }}@if ($pieza->red) · {{ $pieza->red }}@endif
      </dd>
    </div>
    <div class="flex justify-between gap-4 px-4 py-3">
      <dt class="text-slate-500">Versión</dt>
      <dd class="font-medium text-slate-900 text-right">
        {{ $pieza->version_number }} · {{ \Illuminate\Support\Carbon::parse($pieza->submitted_at)->format('d/m/Y') }}
      </dd>
    </div>
    @if ($pieza->external_url)
      <div class="px-4 py-3">
        <dt class="text-slate-500 mb-1">La pieza</dt>
        <dd>
          {{-- `rel="noopener noreferrer"`: el enlace lo pegó el creador, y no le
               regalamos ni la referencia ni la ventana. --}}
          <a href="{{ $pieza->external_url }}" target="_blank" rel="noopener noreferrer"
             class="text-marca-600 hover:underline break-all">{{ $pieza->external_url }}</a>
        </dd>
      </div>
    @endif
    @if ($pieza->caption)
      <div class="px-4 py-3">
        <dt class="text-slate-500 mb-1">Texto propuesto</dt>
        <dd class="text-slate-800 whitespace-pre-line">{{ $pieza->caption }}</dd>
      </div>
    @endif
    @if ($pieza->creator_notes)
      <div class="px-4 py-3">
        <dt class="text-slate-500 mb-1">Notas del creador</dt>
        <dd class="text-slate-700">{{ $pieza->creator_notes }}</dd>
      </div>
    @endif
  </dl>

  @if ($errors->any())
    <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
      {{ $errors->first() }}
    </div>
  @endif

  <form method="POST" action="{{ route('aprobacion.responder') }}" class="mt-6 space-y-4">
    @csrf

    <div>
      <label for="comentario" class="block text-sm text-slate-700 mb-1">
        Comentarios <span class="text-slate-400">(obligatorios si pide cambios)</span>
      </label>
      <textarea id="comentario" name="comentario" rows="4" maxlength="2000"
                class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500"
                placeholder="Qué hay que cambiar, y dónde.">{{ old('comentario') }}</textarea>
    </div>

    <div class="flex flex-wrap gap-3">
      <button type="submit" name="respuesta" value="approved"
              class="text-sm px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
        Me vale
      </button>
      <button type="submit" name="respuesta" value="changes_requested"
              class="text-sm px-4 py-2 rounded-lg bg-amber-600 text-white hover:bg-amber-700">
        Pedir cambios
      </button>
    </div>
  </form>

  {{-- Un cliente que pulsa «me vale» y no ve nada moverse tiene derecho a saber
       qué pasa después. `DEC-151`. --}}
  <p class="mt-6 text-xs text-slate-500">
    Su respuesta queda registrada y la revisa su contacto en LATAM Social, que es
    quien la cierra. Tiene hasta el
    {{ \Illuminate\Support\Carbon::parse($caduca)->format('d/m/Y') }} para contestar.
  </p>
@endsection
