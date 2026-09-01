@extends('layouts.panel')
@section('titulo', $solicitud->full_name)
@section('subtitulo', 'Solicitud de creador')

@section('contenido')
<div class="max-w-4xl">

  <a href="{{ route('solicitudes.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Volver a la bandeja</a>

  @if (session('aviso'))
    <div class="mt-4 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 px-4 py-3 text-sm">{{ session('aviso') }}</div>
  @endif
  @if (session('choque'))
    <div class="mt-4 rounded-xl bg-rose-50 border border-rose-300 text-rose-900 px-4 py-3 text-sm">
      <p class="font-medium mb-1">No se puede aprobar todavía</p>
      {{ session('choque') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm">
      <ul class="list-disc list-inside space-y-0.5">
        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
      </ul>
    </div>
  @endif

  {{-- Lo que envió el creador --}}
  <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6">
    <h2 class="text-sm font-semibold text-slate-800 mb-4">Lo que envió</h2>
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
      <div><dt class="text-xs text-slate-400">Nombre</dt><dd class="text-slate-800">{{ $solicitud->full_name }}</dd></div>
      <div><dt class="text-xs text-slate-400">Correo</dt><dd class="text-slate-800">{{ $solicitud->email }}</dd></div>
      <div><dt class="text-xs text-slate-400">Teléfono</dt><dd class="text-slate-800">{{ $solicitud->phone ?: '—' }}</dd></div>
      <div><dt class="text-xs text-slate-400">País</dt><dd class="text-slate-800">{{ $pais->name ?? '—' }}</dd></div>
      <div><dt class="text-xs text-slate-400">Origen</dt><dd class="text-slate-800">{{ $solicitud->source }}</dd></div>
      <div><dt class="text-xs text-slate-400">Referido</dt><dd class="text-slate-800">{{ $solicitud->referral_code ?: '—' }}</dd></div>
      <div><dt class="text-xs text-slate-400">Estado</dt><dd class="text-slate-800">{{ $solicitud->status }}</dd></div>
      <div><dt class="text-xs text-slate-400">Recibida</dt><dd class="text-slate-800 tabular-nums">{{ $solicitud->submitted_at }}</dd></div>
    </dl>
  </div>

  {{-- BR-CREATOR-003: se avisa ANTES de crear, no después de chocar. --}}
  @if ($posiblesDuplicados->isNotEmpty())
    <div class="mt-5 rounded-2xl bg-amber-50 border border-amber-300 p-5">
      <p class="font-medium text-amber-900 text-sm mb-2">Ya hay alguien con este correo</p>
      <ul class="space-y-1 text-sm text-amber-900">
        @foreach ($posiblesDuplicados as $d)
          <li>
            <a href="{{ route('creadores.show', $d->uuid) }}" class="underline">{{ $d->display_name }}</a>
            — {{ $d->document_type }} {{ $d->document_number }}
            <span class="text-xs opacity-70">({{ $d->status }})</span>
          </li>
        @endforeach
      </ul>
      <p class="text-xs text-amber-800 mt-2">
        Si es la misma persona, márcala como duplicada en lugar de aprobarla.
      </p>
    </div>
  @endif

  @if (in_array($solicitud->status, ['submitted', 'in_review'], true))

    {{-- Aprobar --}}
    <form method="POST" action="{{ route('solicitudes.aprobar', $solicitud->uuid) }}"
          class="mt-5 bg-white rounded-2xl border border-slate-200 p-6 space-y-5">
      @csrf
      <div>
        <h2 class="text-sm font-semibold text-slate-800">Aprobar y dar de alta</h2>
        <p class="text-xs text-slate-500 mt-1">
          Estos datos no se podrán editar luego desde la ficha: se capturan aquí, una vez, con tu nombre en la bitácora.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label for="first_name" class="block text-xs text-slate-500 mb-1">Nombres</label>
          <input id="first_name" name="first_name" required maxlength="80" value="{{ old('first_name') }}"
                 class="w-full rounded-lg border border-slate-300 text-sm">
        </div>
        <div>
          <label for="last_name" class="block text-xs text-slate-500 mb-1">Apellidos</label>
          <input id="last_name" name="last_name" required maxlength="80" value="{{ old('last_name') }}"
                 class="w-full rounded-lg border border-slate-300 text-sm">
        </div>
        <div>
          <label for="display_name" class="block text-xs text-slate-500 mb-1">Nombre público</label>
          <input id="display_name" name="display_name" required maxlength="120" value="{{ old('display_name') }}"
                 class="w-full rounded-lg border border-slate-300 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div>
          <label for="birth_date" class="block text-xs text-slate-500 mb-1">Fecha de nacimiento</label>
          <input id="birth_date" name="birth_date" type="date" required value="{{ old('birth_date') }}"
                 class="w-full rounded-lg border border-slate-300 text-sm">
          <p class="text-xs text-amber-600 mt-1">Si es menor, hará falta tutor para activarlo.</p>
        </div>
        <div>
          <label for="document_country_code" class="block text-xs text-slate-500 mb-1">País del documento</label>
          <select id="document_country_code" name="document_country_code" required class="w-full rounded-lg border border-slate-300 text-sm">
            @foreach ($paises as $p)
              <option value="{{ $p->iso2 }}" @selected(old('document_country_code') === $p->iso2)>{{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="document_type" class="block text-xs text-slate-500 mb-1">Tipo</label>
          <select id="document_type" name="document_type" required class="w-full rounded-lg border border-slate-300 text-sm">
            @foreach (['DNI','CE','RUC','PASSPORT','CC','NIT','CURP','RFC','RUT','SSN','NIE','NIF','OTHER'] as $t)
              <option value="{{ $t }}" @selected(old('document_type') === $t)>{{ $t }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="document_number" class="block text-xs text-slate-500 mb-1">Número</label>
          <input id="document_number" name="document_number" required maxlength="40" value="{{ old('document_number') }}"
                 class="w-full rounded-lg border border-slate-300 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label for="preferred_currency_code" class="block text-xs text-slate-500 mb-1">Moneda preferida</label>
          <select id="preferred_currency_code" name="preferred_currency_code" required class="w-full rounded-lg border border-slate-300 text-sm">
            @foreach ($monedas as $m)
              <option value="{{ $m->code }}" @selected(old('preferred_currency_code') === $m->code)>{{ $m->code }} — {{ $m->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="payment_term_days" class="block text-xs text-slate-500 mb-1">Plazo de pago (días)</label>
          <input id="payment_term_days" name="payment_term_days" type="number" min="0" max="180" required
                 value="{{ old('payment_term_days', 30) }}" class="w-full rounded-lg border border-slate-300 text-sm">
        </div>
      </div>

      <label class="flex items-start gap-2 text-sm text-slate-700 pt-2 border-t border-slate-100">
        <input type="checkbox" name="confirma_revision" value="1" class="mt-0.5 rounded border border-slate-300">
        <span>Confirmo que revisé el documento de identidad y los posibles duplicados de arriba.</span>
      </label>

      <div class="flex items-center gap-3">
        <button class="px-5 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-medium hover:opacity-90">
          Aprobar y crear creador
        </button>
        <span class="text-xs text-slate-400">Quedará en estado «pendiente», no activo.</span>
      </div>
    </form>

    {{-- Rechazar --}}
    <form method="POST" action="{{ route('solicitudes.rechazar', $solicitud->uuid) }}"
          class="mt-5 bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
      @csrf
      <h2 class="text-sm font-semibold text-slate-800">Rechazar</h2>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label for="motivo" class="block text-xs text-slate-500 mb-1">Motivo</label>
          <select id="motivo" name="motivo" required class="w-full rounded-lg border border-slate-300 text-sm">
            <option value="rejected">No cumple requisitos</option>
            <option value="duplicate">Es un duplicado</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label for="rejection_note" class="block text-xs text-slate-500 mb-1">Explicación (mínimo 10 caracteres)</label>
          <input id="rejection_note" name="rejection_note" required minlength="10" maxlength="255"
                 value="{{ old('rejection_note') }}" class="w-full rounded-lg border border-slate-300 text-sm"
                 placeholder="Qué le vas a poder explicar al creador dentro de seis meses.">
        </div>
      </div>
      <button class="px-5 py-2.5 rounded-xl bg-white border border-rose-300 text-rose-700 text-sm font-medium hover:bg-rose-50">
        Rechazar solicitud
      </button>
    </form>

  @else
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 p-6 text-sm">
      <p class="text-slate-800 font-medium mb-1">Esta solicitud ya fue resuelta</p>
      <p class="text-slate-500">Estado: {{ $solicitud->status }}. {{ $solicitud->rejection_note }}</p>
    </div>
  @endif
</div>
@endsection
