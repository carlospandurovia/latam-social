@extends('layouts.panel')
@section('titulo', 'Revisar · '.$entregable->creador)
@section('subtitulo', $entregable->campana.' · '.$entregable->formato.' #'.$entregable->sequence_number)

@section('contenido')
  <div class="space-y-5 max-w-4xl">

    @if (session('exito'))
      <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
        {{ session('exito') }}
      </div>
    @endif

    @if (session('aviso'))
      <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
        {{ session('aviso') }}
      </div>
    @endif

    {{-- Lo que se está mirando, primero. Un revisor abre esta pantalla para ver
         el contenido; el brief y el historial son contexto. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <div class="flex items-baseline justify-between mb-3">
        <h2 class="text-sm font-medium text-slate-700">
          Versión {{ $version?->version_number ?? '—' }}
          {{-- 8.2: el puntero, dicho donde se mira. «Cuál es la buena» tenía que
               deducirse leyendo el historial, y eso es justo lo que nadie hace
               con prisa. --}}
          @if ($version && (int) $entregable->approved_version_id === (int) $version->id)
            <span class="ml-1 px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-xs">aprobada</span>
          @endif
        </h2>
        <span class="text-xs text-slate-400">
          {{ $version?->submitted_at ? \Illuminate\Support\Carbon::parse($version->submitted_at)->format('d/m/Y H:i') : '' }}
        </span>
      </div>

      @if ($version === null)
        <p class="text-sm text-slate-400">Este entregable no tiene ninguna versión todavía.</p>
      @else
        @if ($version->external_url)
          <p class="text-sm">
            <a href="{{ $version->external_url }}" target="_blank" rel="noopener noreferrer"
               class="text-marca-600 hover:underline break-all">{{ $version->external_url }}</a>
          </p>
        @endif
        @if ($version->caption)
          <div class="mt-3">
            <p class="text-xs text-slate-500 mb-1">Texto del post</p>
            <p class="text-sm text-slate-700 whitespace-pre-line">{{ $version->caption }}</p>
          </div>
        @endif
        @if ($version->creator_notes)
          <div class="mt-3">
            <p class="text-xs text-slate-500 mb-1">Nota del creador</p>
            <p class="text-sm text-slate-600 whitespace-pre-line">{{ $version->creator_notes }}</p>
          </div>
        @endif
      @endif
    </div>

    {{-- El brief, al lado. Revisar sin él es opinar. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700 mb-2">Lo que pedía el brief</h2>
      @if ($entregable->notes)
        <p class="text-sm text-slate-600 whitespace-pre-line">{{ $entregable->notes }}</p>
      @endif
      <div class="mt-2 flex flex-wrap gap-2 text-xs">
        @foreach (preg_split('/\s+/', trim((string) $entregable->hashtags), -1, PREG_SPLIT_NO_EMPTY) as $h)
          <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600">{{ $h }}</span>
        @endforeach
        @foreach (preg_split('/\s+/', trim((string) $entregable->mentions), -1, PREG_SPLIT_NO_EMPTY) as $m)
          <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600">{{ $m }}</span>
        @endforeach
      </div>
    </div>

    {{-- Las rondas, ANTES del formulario. Quien va a pedir cambios tiene que
         saber si esta vuelta se le puede cobrar al cliente antes de escribirla,
         no después de que la pantalla se lo impida. --}}
    <div class="bg-white rounded-xl border {{ $rondas['agotadas'] ? 'border-amber-300' : 'border-slate-200' }} p-5">
      <h2 class="text-sm font-medium text-slate-700">Rondas de corrección</h2>
      <p class="mt-1 text-sm {{ $rondas['agotadas'] ? 'text-amber-800' : 'text-slate-600' }}">
        @if ($rondas['agotadas'])
          Esta pieza ya gastó las {{ $rondas['incluidas'] }} rondas que incluye el precio.
          Otra corrección del cliente hay que <strong>cobrarla o absorberla</strong>, y queda constancia de quién lo decidió.
        @else
          Quedan {{ $rondas['quedan'] }} de {{ $rondas['incluidas'] }}.
          Sólo cuentan las que pide el cliente: las nuestras son control de calidad y no se cobran.
        @endif
      </p>
    </div>

    {{-- 8.5: lo que dijo el cliente, y el enlace para preguntárselo.

         Va ANTES del formulario del veredicto y por lo mismo que las rondas:
         quien va a emitirlo tiene que tenerlo delante mientras lo escribe. --}}
    @if ($respuestaCliente)
      <div class="bg-white rounded-xl border {{ $respuestaCliente->response === 'approved' ? 'border-emerald-300' : 'border-amber-300' }} p-5">
        <h2 class="text-sm font-medium text-slate-700">El cliente ya contestó</h2>
        <p class="mt-1 text-sm text-slate-600">
          <strong>{{ $respuestaCliente->sent_to }}</strong>,
          el {{ \Illuminate\Support\Carbon::parse($respuestaCliente->responded_at)->format('d/m/Y H:i') }}:
          @if ($respuestaCliente->response === 'approved')
            <span class="text-emerald-800 font-medium">le vale.</span>
          @else
            <span class="text-amber-800 font-medium">pide cambios.</span>
          @endif
        </p>
        @if ($respuestaCliente->comments)
          <p class="mt-2 text-sm text-slate-800 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 whitespace-pre-line">{{ $respuestaCliente->comments }}</p>
        @endif
        {{-- `DEC-151`: su respuesta no movió nada. Lo que la mueve es el
             veredicto de abajo, y por eso hay que decirlo aquí. --}}
        <p class="mt-2 text-xs text-slate-500">
          Su respuesta queda registrada y <strong>no ha movido la pieza</strong>. Emita el
          veredicto de abajo del lado «cliente» para cerrarla — y si ya no quedan rondas,
          diga si se le cobra o la absorbemos.
        </p>
      </div>
    @endif

    @if ($aprobado)
      <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h2 class="text-sm font-medium text-slate-700">Visto bueno del cliente</h2>
        @if ($enlaceCliente)
          <p class="mt-1 text-sm text-slate-600">
            Enlace enviado a <strong>{{ $enlaceCliente->sent_to }}</strong> el
            {{ \Illuminate\Support\Carbon::parse($enlaceCliente->sent_at)->format('d/m/Y') }}.
            Vence el {{ \Illuminate\Support\Carbon::parse($enlaceCliente->expires_at)->format('d/m/Y') }}.
            @if ($enlaceCliente->opened_at)
              Lo abrió el {{ \Illuminate\Support\Carbon::parse($enlaceCliente->opened_at)->format('d/m/Y') }}.
            @else
              <span class="text-amber-700">Todavía no lo ha abierto.</span>
            @endif
          </p>
          <p class="mt-1 text-xs text-slate-500">
            Si vuelve a mandarlo, el anterior queda anulado: sólo puede haber un enlace vivo por pieza.
          </p>
        @else
          <p class="mt-1 text-sm text-slate-600">
            Mándele la pieza para que la vea y la apruebe. Verá la campaña, el formato y el
            contenido — <strong>nunca importes ni presupuesto</strong>.
          </p>
        @endif

        <form method="POST" action="{{ route('revision.enlace_cliente', $entregable->uuid) }}"
              class="mt-3 flex flex-wrap gap-2 items-end">
          @csrf
          <div>
            <label for="correo" class="block text-xs text-slate-500 mb-1">Correo del cliente</label>
            <input type="email" id="correo" name="correo" required maxlength="255"
                   value="{{ old('correo', $enlaceCliente->sent_to ?? '') }}"
                   class="text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          </div>
          <button type="submit"
                  class="text-sm px-3 py-2 rounded-lg bg-slate-700 text-white hover:bg-slate-800">
            {{ $enlaceCliente ? 'Reenviar enlace' : 'Mandar enlace' }}
          </button>
        </form>
      </div>
    @endif

    {{-- 8.2: aprobado, la pantalla deja de ofrecer un veredicto y ofrece volver
         atrás. Sin esto, un entregable aprobado era un callejón sin salida y el
         único camino cuando el cliente cambiara de opinión —que va a pasar— era
         que alguien tocara la base a mano. --}}
    @if ($aprobado)
      <form method="POST" action="{{ route('revision.reabrir', $entregable->uuid) }}"
            class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        @csrf

        <div>
          <h2 class="text-sm font-medium text-slate-700">Este entregable ya está aprobado</h2>
          <p class="mt-1 text-sm text-slate-600">
            Si hay que volver atrás, se reabre diciendo por qué. La aprobación anterior
            <strong>no se borra</strong>: se queda en el historial y la reapertura es otra línea.
          </p>
        </div>

        <div>
          <label for="motivo" class="block text-sm text-slate-600 mb-1">Por qué se reabre</label>
          <select id="motivo" name="motivo" required @disabled(!$puedeReabrir)
                  class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500
                         disabled:bg-slate-50 disabled:text-slate-400">
            @foreach ($motivos as $codigo => $texto)
              <option value="{{ $codigo }}" @selected(old('motivo') === $codigo)>{{ $texto }}</option>
            @endforeach
          </select>
          @error('motivo')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
          <label for="nota" class="block text-sm text-slate-600 mb-1">
            Explicación <span class="text-slate-400">(obligatoria si el motivo es «otro»)</span>
          </label>
          <textarea id="nota" name="nota" rows="2" @disabled(!$puedeReabrir)
                    class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500
                           disabled:bg-slate-50">{{ old('nota') }}</textarea>
          @error('nota')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>

        @if ($puedeReabrir)
          <button type="submit"
                  class="text-sm px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
            Reabrir
          </button>
        @else
          <p class="text-xs text-slate-500">
            Reabrir un entregable aprobado necesita su permiso. Pídaselo a quien lleva la campaña.
          </p>
        @endif
        <a href="{{ route('revision.cola') }}" class="text-sm px-4 py-2 text-slate-500 hover:underline">Volver a la cola</a>
      </form>
    @else

    {{-- El veredicto. --}}
    <form method="POST" action="{{ route('revision.revisar', $entregable->uuid) }}"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      @csrf

      <div>
        <label for="reviewer_side" class="block text-sm text-slate-600 mb-1">Esto lo pide</label>
        <select id="reviewer_side" name="reviewer_side" required
                class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500">
          @foreach ($lados as $codigo => $texto)
            <option value="{{ $codigo }}" @selected(old('reviewer_side') === $codigo)>{{ $texto }}</option>
          @endforeach
        </select>
        @error('reviewer_side')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
      </div>

      <div>
        <label for="comments" class="block text-sm text-slate-600 mb-1">
          Qué hay que cambiar <span class="text-slate-400">(obligatorio si pide cambios)</span>
        </label>
        <textarea id="comments" name="comments" rows="4"
                  class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500"
                  placeholder="El logo se ve cortado en el segundo 4.">{{ old('comments') }}</textarea>
        @error('comments')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
      </div>

      @if ($rondas['agotadas'])
        <div>
          <label for="billing_decision" class="block text-sm text-slate-600 mb-1">
            Si el cliente pide esta corrección, ¿qué se hace con ella?
          </label>
          <select id="billing_decision" name="billing_decision"
                  @disabled(!$puedeAutorizar)
                  class="w-full text-sm border-slate-300 rounded-lg focus:border-marca-500 focus:ring-marca-500
                         disabled:bg-slate-50 disabled:text-slate-400">
            <option value="">— elija —</option>
            @foreach ($facturacion as $codigo => $texto)
              <option value="{{ $codigo }}" @selected(old('billing_decision') === $codigo)>{{ $texto }}</option>
            @endforeach
          </select>
          @unless ($puedeAutorizar)
            <p class="mt-1 text-xs text-slate-500">
              Autorizar una ronda de más es una decisión de facturación y necesita su permiso.
              Pídasela a quien lleva la campaña.
            </p>
          @endunless
          @error('billing_decision')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
      @endif

      <div class="flex gap-3 pt-1">
        <button type="submit" name="outcome" value="changes_requested"
                class="text-sm px-4 py-2 rounded-lg border border-amber-300 text-amber-800 hover:bg-amber-50">
          Pedir cambios
        </button>
        @if ($puedeAprobar)
          <button type="submit" name="outcome" value="approved"
                  class="text-sm px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
            Dar el visto bueno
          </button>
        @endif
        <a href="{{ route('revision.cola') }}"
           class="text-sm px-4 py-2 text-slate-500 hover:underline">Volver a la cola</a>
      </div>
    </form>
    @endif

    {{-- El historial. Append-only: cada veredicto es una fila y ninguna se
         reescribe --lo impide `tg_cvw_inmutable`--. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-medium text-slate-700 mb-3">Lo que ya se dijo</h2>
      @if ($historial->isEmpty())
        <p class="text-sm text-slate-400">Nadie lo ha revisado todavía.</p>
      @else
        <ul class="space-y-3">
          @foreach ($historial as $r)
            @php
              $etiqueta = ['approved' => 'Aprobado', 'changes_requested' => 'Cambios pedidos',
                           'reopened' => 'Reabierto', 'rejected' => 'Rechazado'][$r->outcome] ?? $r->outcome;
              $borde = ['approved' => 'border-emerald-300', 'reopened' => 'border-slate-400'][$r->outcome]
                       ?? 'border-amber-300';
            @endphp
            <li class="border-l-2 {{ $borde }} pl-3">
              <p class="text-xs text-slate-500">
                v{{ $r->version_number }} ·
                {{ $etiqueta }} ·
                {{ $r->reviewer_side === 'client' ? 'el cliente' : 'nosotros' }} ·
                {{ \Illuminate\Support\Carbon::parse($r->reviewed_at)->format('d/m/Y H:i') }}
                @if ($r->revisor) · {{ $r->revisor }} @endif
                @if ($r->over_included)
                  <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">
                    ronda de más · {{ $r->billing_decision === 'charge' ? 'se cobra' : 'absorbida' }}
                    @if ($r->autorizador) · {{ $r->autorizador }} @endif
                  </span>
                @endif
              </p>
              @if ($r->comments)
                <p class="text-sm text-slate-700 whitespace-pre-line">{{ $r->comments }}</p>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>
@endsection
