@extends('layouts.panel')
@section('titulo', 'Medios de pago de '.$creador->display_name)
@section('subtitulo', 'BR-FIN-006 · una cuenta verificada no es pagable hasta pasado el enfriamiento')

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

  {{-- El número de cuenta no llega a esta plantilla. Lo que se enseña es la
       máscara, y no hay ninguna ruta que devuelva el número entero. --}}
  @forelse ($medios as $m)
    <div class="mt-5 bg-white rounded-2xl border border-slate-200 overflow-hidden">
      <div class="px-6 py-4 flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-100">
        <div>
          <p class="font-semibold text-slate-900">
            {{ $m->bank_name ?: $m->method_type }} · {{ $m->account_number_masked }}
            <span class="ml-1 text-xs text-slate-400">{{ $m->currency_code }} · {{ $m->pais }}</span>
            @if ($m->is_default)<span class="ml-1 text-xs font-medium text-marca-600">predeterminado</span>@endif
          </p>
          <p class="text-xs text-slate-500 mt-0.5">
            A nombre de <strong>{{ $m->holder_name }}</strong>
            ({{ $m->holder_document_type }} {{ $m->holder_document_number }})
            @if ($m->owner_type === 'guardian')
              · cuenta del tutor {{ $m->tutor }} <span class="text-slate-400">(BR-CREATOR-010)</span>
            @endif
          </p>
        </div>
        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium
          @class([
            'bg-emerald-50 text-emerald-700' => $m->status === 'verified',
            'bg-amber-50 text-amber-800' => $m->status === 'pending',
            'bg-rose-50 text-rose-700' => $m->status === 'rejected',
            'bg-slate-100 text-slate-600' => $m->status === 'disabled',
          ])">
          {{ $m->status }}
        </span>
      </div>

      {{-- DEC-065: la misma cuenta en dos creadores no se rechaza, se marca.
           Hay casos legítimos (dos hermanos menores, un tutor) y hay una señal
           de fraude clásica. Decide una persona. --}}
      {{-- `compartida_con` se calcula al leer (`T-19`). La columna
           `shared_account_status` no servía: un disparador no puede actualizar
           su propia tabla, así que la fila del PRIMER creador seguía diciendo
           «única» mientras la cuenta ya estaba duplicada. --}}
      @if ($m->compartida_con > 0 && $m->shared_account_status !== 'cleared')
        <div class="px-6 py-3 bg-amber-50 border-b border-amber-200 text-sm text-amber-900">
          <p>Esta cuenta está registrada también en
             {{ $m->compartida_con === 1 ? 'otro creador' : $m->compartida_con.' creadores más' }}.
             No es un error por sí solo —un tutor puede cobrar por dos pupilos— pero
             alguien tiene que mirarlo.</p>
          @can('creator.payment.verify')
            <form method="POST" action="{{ route('creadores.pagos.compartida', [$creador->uuid, $m->id]) }}"
                  class="mt-2 flex flex-wrap gap-2 items-end">
              @csrf
              <div class="grow">
                <label class="block text-xs mb-1" for="sc{{ $m->id }}">¿Por qué es aceptable?</label>
                <input id="sc{{ $m->id }}" name="motivo" required minlength="10" maxlength="255"
                       class="w-full rounded-lg border-amber-300 text-sm" placeholder="Es la cuenta del tutor, que también cobra por su otro pupilo.">
              </div>
              <button class="px-3 py-2 rounded-lg bg-amber-600 text-white text-sm font-medium hover:bg-amber-700">Darla por buena</button>
            </form>
          @endcan
        </div>
      @endif

      <div class="px-6 py-4 border-b border-slate-100 text-sm text-slate-600 space-y-1">
        <p>Capturada por <strong>{{ $m->capturado_por ?: '—' }}</strong>.</p>

        @if ($m->verified_at)
          <p>
            Verificada por <strong>{{ $m->verificado_por }}</strong> el {{ $m->verified_at }}.
            @if ($m->eligible_from)
              Pagable desde <strong>{{ $m->eligible_from }}</strong>.
            @endif
          </p>
        @endif

        @if ($m->closed_at)
          <p>Retirada por <strong>{{ $m->retirado_por }}</strong> el {{ $m->closed_at }}. El motivo está en la bitácora.</p>
        @endif
      </div>

      @if (! in_array($m->status, ['rejected', 'disabled'], true))
        <div class="px-6 py-4 bg-slate-50 flex flex-wrap gap-3 items-start">
          @if ($m->status === 'pending')
            @can('creator.payment.verify')
              <form method="POST" action="{{ route('creadores.pagos.verificar', [$creador->uuid, $m->id]) }}">
                @csrf
                <button class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700">
                  Verificar esta cuenta
                </button>
              </form>
            @endcan
          @elseif (! $m->is_default)
            @can('creator.payment.manage')
              <form method="POST" action="{{ route('creadores.pagos.predeterminado', [$creador->uuid, $m->id]) }}">
                @csrf
                <button class="px-3 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-white">
                  Marcar como predeterminada
                </button>
              </form>
            @endcan
          @endif

          @can('creator.payment.verify')
            <form method="POST" action="{{ route('creadores.pagos.retirar', [$creador->uuid, $m->id]) }}"
                  class="flex flex-wrap gap-2 items-end grow">
              @csrf
              <div class="grow">
                <label class="block text-xs text-slate-600 mb-1" for="rt{{ $m->id }}">Motivo para retirarla</label>
                <input id="rt{{ $m->id }}" name="motivo" required minlength="10" maxlength="255"
                       class="w-full rounded-lg border-slate-300 text-sm" placeholder="El creador cambió de banco y trajo la constancia nueva.">
              </div>
              <button class="px-3 py-2 rounded-lg border border-rose-300 text-rose-700 text-sm font-medium hover:bg-rose-50">Retirar</button>
            </form>
          @endcan
        </div>
      @endif
    </div>
  @empty
    <p class="mt-5 text-sm text-slate-500">Este creador todavía no tiene ningún medio de pago.</p>
  @endforelse

  {{-- No existe «editar». La cuenta es inmutable (DEC-066): cambiar de cuenta
       es dar de alta otra y retirar la anterior, y así queda el rastro de
       todas las que existieron. --}}
  @can('creator.payment.manage')
    <div class="mt-8 bg-white rounded-2xl border border-slate-200 p-6">
      <h2 class="font-semibold text-slate-900">Dar de alta una cuenta</h2>
      <p class="mt-1 text-sm text-slate-500">
        Nace pendiente. Tiene que verificarla <strong>otra persona</strong>, y desde esa verificación
        pasan {{ $enfriamiento }} h antes de que se le pueda pagar (BR-FIN-006).
      </p>

      <form method="POST" action="{{ route('creadores.pagos.store', $creador->uuid) }}" class="mt-5 grid gap-4 sm:grid-cols-2">
        @csrf

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="method_type">Tipo</label>
          <select id="method_type" name="method_type" class="w-full rounded-lg border-slate-300 text-sm">
            <option value="bank_account">Cuenta bancaria</option>
            <option value="wallet">Billetera</option>
            <option value="paypal">PayPal</option>
            <option value="other">Otro</option>
          </select>
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="account_type">Modalidad</label>
          <select id="account_type" name="account_type" class="w-full rounded-lg border-slate-300 text-sm">
            <option value="">—</option>
            <option value="savings">Ahorros</option>
            <option value="checking">Corriente</option>
            <option value="other">Otra</option>
          </select>
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="bank_name">Banco</label>
          <input id="bank_name" name="bank_name" maxlength="80" value="{{ old('bank_name') }}"
                 class="w-full rounded-lg border-slate-300 text-sm">
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="account_number">Número de cuenta</label>
          {{-- `autocomplete="off"` no es cosmético: evita que el navegador lo
               guarde y lo reofrezca en la máquina de otro operador. --}}
          <input id="account_number" name="account_number" required minlength="6" maxlength="40"
                 autocomplete="off" spellcheck="false"
                 class="w-full rounded-lg border-slate-300 text-sm font-mono">
          <p class="mt-1 text-xs text-slate-500">Se guarda cifrado. En pantalla solo se verán los cuatro últimos dígitos.</p>
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="country_id">País de la cuenta</label>
          <select id="country_id" name="country_id" class="w-full rounded-lg border-slate-300 text-sm">
            @foreach ($paises as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
          </select>
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="currency_code">Moneda</label>
          <select id="currency_code" name="currency_code" class="w-full rounded-lg border-slate-300 text-sm">
            @foreach ($monedas as $mo)<option value="{{ $mo->code }}">{{ $mo->code }} — {{ $mo->name }}</option>@endforeach
          </select>
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="owner_type">¿De quién es la cuenta?</label>
          <select id="owner_type" name="owner_type" class="w-full rounded-lg border-slate-300 text-sm">
            <option value="creator">Del creador</option>
            <option value="guardian" @selected($esMenor)>De su tutor</option>
          </select>
          @if ($esMenor)
            <p class="mt-1 text-xs text-amber-700">Es menor de edad: el pago se emite a nombre del tutor (BR-CREATOR-010).</p>
          @endif
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="owner_guardian_id">¿Qué tutor?</label>
          <select id="owner_guardian_id" name="owner_guardian_id" class="w-full rounded-lg border-slate-300 text-sm">
            <option value="">—</option>
            @foreach ($tutores as $t)<option value="{{ $t->id }}">{{ $t->full_name }}</option>@endforeach
          </select>
          @if ($tutores->isEmpty())
            <p class="mt-1 text-xs text-slate-500">No hay tutelas activas registradas.</p>
          @endif
        </div>

        <div>
          <label class="block text-sm text-slate-700 mb-1" for="holder_name">Titular según el banco</label>
          <input id="holder_name" name="holder_name" required maxlength="160" value="{{ old('holder_name') }}"
                 class="w-full rounded-lg border-slate-300 text-sm">
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm text-slate-700 mb-1" for="holder_document_type">Documento</label>
            <input id="holder_document_type" name="holder_document_type" required maxlength="20"
                   value="{{ old('holder_document_type', 'DNI') }}" class="w-full rounded-lg border-slate-300 text-sm">
          </div>
          <div>
            <label class="block text-sm text-slate-700 mb-1" for="holder_document_number">Número</label>
            <input id="holder_document_number" name="holder_document_number" required maxlength="40"
                   value="{{ old('holder_document_number') }}" class="w-full rounded-lg border-slate-300 text-sm">
          </div>
        </div>

        <div class="sm:col-span-2">
          <button class="px-4 py-2 rounded-xl bg-marca-600 text-white text-sm font-medium hover:bg-marca-700">
            Dar de alta
          </button>
        </div>
      </form>
    </div>
  @endcan

</div>
@endsection
