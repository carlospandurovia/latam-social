{{-- El portal del creador. Extiende `layouts.acceso` y NO `layouts.panel`: ese
     trae el menú del back-office, y aunque todas sus secciones estén protegidas
     por permiso, enseñárselas sería un mapa de lo que hay dentro. Misma decisión
     que `entregas/mias.blade.php` en 8.1. --}}
@extends('layouts.acceso')
@section('titulo', 'Mis ingresos')

@section('contenido')
  <p class="mb-5 text-sm text-slate-600">
    Lo que has ganado con nosotros y en qué punto está cada cosa. Esta pantalla no
    tiene botones: los movimientos los hace el sistema cuando tu trabajo cumple lo
    acordado, y quedan escritos para siempre.
  </p>

  {{-- El saldo primero: es la pregunta con la que se entra aquí. Por MONEDA y no
       sumado, porque sumar dos monedas exige un tipo de cambio y el de hoy no es
       el del día en que se pague (`BR-FIN-009`). --}}
  <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="text-sm font-medium text-slate-700">Se te debe</h2>

    @forelse ($saldo as $s)
      <p class="mt-2 text-2xl font-semibold tabular-nums">
        {{ number_format((float) $s->total, 2) }}
        <span class="text-base font-normal text-slate-500">{{ $s->currency_code }}</span>
      </p>
      <p class="text-xs text-slate-500">
        {{ $s->asientos }} {{ (int) $s->asientos === 1 ? 'movimiento' : 'movimientos' }}
      </p>
    @empty
      <p class="mt-2 text-sm text-slate-500">
        Todavía no hay nada pendiente de cobro. Cuando aceptes una campaña, el
        importe acordado aparece aquí desde el primer día.
      </p>
    @endforelse

    {{-- Lo que NO se suma, dicho donde se busca. Sin esto, un creador con un
         asiento retenido resta a mano y cree que le falta dinero. --}}
    <p class="mt-3 text-xs text-slate-500">
      No cuenta lo que ya se te pagó ni lo que está en revisión.
    </p>
  </div>

  <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
    <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-medium text-slate-700">
      Movimientos
    </h2>

    @forelse ($asientos as $a)
      <div class="border-b border-slate-100 px-5 py-4 last:border-0">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium">
              {{ $a->campana ?? 'Movimiento de tu cuenta' }}
            </p>
            @if ($a->marca)
              <p class="text-xs text-slate-500">{{ $a->marca }}</p>
            @endif
            <p class="mt-1 text-xs text-slate-400">{{ substr($a->cuando, 0, 10) }}</p>
          </div>

          <div class="shrink-0 text-right">
            <p class="text-sm font-semibold tabular-nums">
              {{ number_format((float) $a->importe, 2) }}
              <span class="font-normal text-slate-500">{{ $a->moneda }}</span>
            </p>
            <span @class([
              'mt-1 inline-block rounded-full px-2 py-0.5 text-xs',
              'bg-emerald-50 text-emerald-700' => $a->estado === 'paid',
              'bg-sky-50 text-sky-700' => $a->estado === 'payable',
              'bg-slate-100 text-slate-600' => $a->estado === 'accrued',
              'bg-amber-50 text-amber-800' => $a->estado === 'on_hold',
            ])>{{ $a->dice }}</span>
          </div>
        </div>

        {{-- Qué falta, cuando falta algo. Es lo accionable: el creador puede
             hacer algo con «te falta subir tu medio de pago» y no puede hacer
             nada con una fecha que todavía no existe. --}}
        @if ($a->falta !== [])
          <ul class="mt-3 space-y-1 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
            @foreach ($a->falta as $motivo)
              <li>· {{ $motivo }}</li>
            @endforeach
          </ul>
        @endif

        {{-- Y la fecha SÓLO cuando ya es pagable. Antes de eso sería una promesa
             que depende de trabajo que todavía no está hecho. --}}
        @if ($a->se_paga)
          <p class="mt-3 rounded-lg bg-sky-50 p-3 text-xs text-sky-900">
            Previsto para el <strong>{{ $a->se_paga }}</strong>, contando desde que
            verificamos tu publicación.
          </p>
        @endif

        {{-- Un asiento en revisión NO lleva aquí el motivo interno: se escribe
             para el expediente y puede nombrar sospechas sin confirmar. Al
             creador se le explica por correo, que es donde cabe hacerlo bien. --}}
        @if ($a->estado === 'on_hold')
          <p class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
            Estamos revisando este pago y te escribimos por correo. Si no te ha
            llegado nada, contesta al último correo que te mandamos y lo miramos.
          </p>
        @endif
      </div>
    @empty
      <p class="px-5 py-6 text-sm text-slate-500">
        Aquí aparecerá cada campaña que aceptes, con su importe, desde el momento
        en que la aceptas.
      </p>
    @endforelse
  </div>
@endsection
