@extends('layouts.panel')
@section('titulo', $creador->display_name)
@section('subtitulo', $creador->email)

@section('contenido')
{{-- Confirmación tras guardar. Sin esto el operador no sabe si el cambio entró. --}}
@if (session('exito'))
  <div class="mb-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
    {{ session('exito') }}
  </div>
@endif
@if (session('aviso'))
  <div class="mb-5 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 px-4 py-3 text-sm">
    {{ session('aviso') }}
  </div>
@endif

{{-- El botón solo aparece si además se puede: el menú acompaña, la ruta manda. --}}
<div class="mb-5 flex flex-wrap gap-3">
  @can('creator.manage')
    <a href="{{ route('creadores.edit', $creador->uuid) }}"
       class="inline-block px-4 py-2 rounded-xl bg-marca-500 text-white text-sm font-medium hover:opacity-90">
      Editar datos de contacto
    </a>
  @endcan

  {{-- 3.7: cuentas sociales. Basta `creator.view` para mirarlas; añadir y
       verificar piden más, y los formularios se ocultan solos. --}}
  <a href="{{ route('creadores.redes', $creador->uuid) }}"
     class="inline-block px-4 py-2 rounded-xl border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">
    Redes sociales
  </a>

  {{-- 3.6: datos fiscales. Detrás de `creator.view_sensitive` porque DEC-053
       reserva los datos fiscales a finanzas y administración. --}}
  @can('creator.view_sensitive')
    <a href="{{ route('creadores.fiscal', $creador->uuid) }}"
       class="inline-block px-4 py-2 rounded-xl border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">
      Datos fiscales
    </a>
  @endcan

  {{-- 3.8: medios de pago. Misma puerta que los datos fiscales: es la fila
       que dice a dónde va el dinero. --}}
  @can('creator.view_sensitive')
    <a href="{{ route('creadores.pagos', $creador->uuid) }}"
       class="inline-block px-4 py-2 rounded-xl border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50">
      Medios de pago
    </a>
  @endcan

  {{-- 3.5: la puerta hacia `active`. Se enseña siempre que el creador esté
       pendiente, porque no verla es la razón de que nadie saliera de ahí. --}}
  @if ($creador->status === 'pending')
    @canany(['creator.activate', 'creator.verify'])
      <a href="{{ route('creadores.activacion', $creador->uuid) }}"
         class="inline-block px-4 py-2 rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-800 text-sm font-medium hover:bg-emerald-100">
        Revisar activación
      </a>
    @endcanany
  @endif
</div>

  <a href="{{ route('creadores.index') }}" class="text-sm text-marca-600 hover:underline">&larr; Volver al listado</a>

  <div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-4">Identidad</h2>
      <dl class="space-y-2.5 text-sm">
        @foreach ([
          'Nombre' => $creador->first_name.' '.$creador->last_name,
          'Documento' => $creador->document_country_code.' '.$creador->document_type.' '.$creador->document_number,
          'Nacimiento' => $creador->birth_date,
          'Edad' => $creador->edad.' años',
          'País' => $creador->pais,
          'Estado' => $creador->status,
          'Identidad' => $creador->identity_verified_at
              ? 'verificada '.$creador->identity_verified_at
              : 'sin verificar',
          'Activo desde' => $creador->activated_at ?: '—',
          'Plazo de pago' => $creador->payment_term_days.' días',
          'Moneda' => $creador->preferred_currency_code,
        ] as $k => $v)
          <div class="flex justify-between gap-4">
            <dt class="text-slate-500">{{ $k }}</dt>
            <dd class="text-slate-800 text-right">{{ $v }}</dd>
          </div>
        @endforeach
      </dl>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Tutela</h2>
      <p class="text-xs text-slate-500 mb-4">Si es menor, quien cobra es el tutor (BR-CREATOR-010).</p>
      @forelse ($tutores as $t)
        <div class="text-sm border-b border-slate-100 last:border-0 py-2.5">
          <p class="font-medium text-slate-800">{{ $t->full_name }}</p>
          <p class="text-slate-500 text-xs">{{ $t->relationship }} · {{ $t->document_type }} {{ $t->document_number }}</p>
          <p class="text-xs mt-1">
            <span class="inline-flex px-2 py-0.5 rounded-full font-medium
              {{ $t->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
              {{ $t->status }}
            </span>
            @if (! $t->authorization_file_id || ! $t->proof_of_relationship_file_id)
              <span class="ml-1 text-amber-600">faltan documentos</span>
            @endif
          </p>
        </div>
      @empty
        <p class="text-sm text-slate-400">Sin tutela registrada.</p>
      @endforelse
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900 mb-1">Cuentas sociales</h2>
      <p class="text-xs text-slate-500 mb-4">Los seguidores salen del último snapshot, nunca de una columna.</p>
      @forelse ($cuentas as $s)
        <div class="text-sm border-b border-slate-100 last:border-0 py-2.5">
          <div class="flex items-center justify-between gap-3">
            <p class="font-medium text-slate-800">{{ $s->red }} · {{ '@'.$s->handle }}</p>
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
              {{ $s->verification_status === 'verified' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
              {{ $s->verification_status }}
            </span>
          </div>
          <p class="text-xs text-slate-500 mt-0.5">
            @if ($s->followers)
              {{ number_format($s->followers) }} seguidores
              <span class="text-slate-400">· captado {{ $s->captured_at }}</span>
            @else
              Sin métricas capturadas
            @endif
          </p>
        </div>
      @empty
        <p class="text-sm text-slate-400">Sin cuentas registradas.</p>
      @endforelse
    </div>
  </div>

  <div class="mt-6 bg-white rounded-xl border border-slate-200 p-6">
    <h2 class="font-semibold text-slate-900 mb-1">Tarifas declaradas</h2>
    <p class="text-xs text-slate-500 mb-4">
      Son una <strong>referencia</strong>, no un compromiso (BR-CREATOR-008). Lo vinculante es el
      monto congelado en cada participación de campaña.
    </p>
    @forelse ($tarifas as $t)
      <div class="flex items-center justify-between text-sm border-b border-slate-100 last:border-0 py-2.5">
        <span class="text-slate-700">{{ $t->red }} · {{ $t->formato }}</span>
        <span class="tabular-nums font-medium text-slate-900">
          {{ $t->currency_code }} {{ number_format($t->amount, 2) }}
          <span class="ml-2 text-xs font-normal text-slate-400">desde {{ $t->valid_from }}</span>
        </span>
      </div>
    @empty
      <p class="text-sm text-slate-400">Sin tarifas declaradas.</p>
    @endforelse
  </div>
@endsection
