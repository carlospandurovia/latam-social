@extends('layouts.panel')
@section('titulo', 'Términos y condiciones')
@section('subtitulo', 'El acuerdo entre tú y la plataforma')

@section('contenido')
  @php
    $e = $estado['estado'];
    $hayQueAceptar = $e !== 'al_dia';
  @endphp

  @if ($e === 'pendiente')
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-5">
      <p class="text-sm font-semibold text-amber-900">
        Hay una versión nueva que aceptar
      </p>
      <p class="mt-1 text-sm text-amber-800">
        Te quedan <strong>{{ $estado['dias'] }}</strong>
        {{ $estado['dias'] === 1 ? 'día' : 'días' }} (hasta el {{ $estado['limite'] }}).
        Después de esa fecha podrás seguir mirando, pero no cambiar nada
        hasta el {{ $estado['finLectura'] }}.
      </p>
    </div>
  @elseif ($e === 'solo_lectura')
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-5">
      <p class="text-sm font-semibold text-rose-900">
        El plazo para aceptar terminó el {{ $estado['limite'] }}
      </p>
      <p class="mt-1 text-sm text-rose-800">
        Puedes seguir mirando tus campañas y tus ingresos, pero no cambiar nada.
        Te quedan <strong>{{ $estado['dias'] }}</strong>
        {{ $estado['dias'] === 1 ? 'día' : 'días' }} así; a partir del
        {{ $estado['finLectura'] }} hará falta aceptar para volver a entrar.
      </p>
    </div>
  @elseif ($e === 'bloqueado')
    <div class="mb-4 rounded-xl border border-rose-300 bg-rose-100 p-5">
      <p class="text-sm font-semibold text-rose-900">
        Hace falta aceptar los términos para continuar
      </p>
      <p class="mt-1 text-sm text-rose-800">
        El plazo terminó el {{ $estado['limite'] }} y el periodo de sólo lectura el
        {{ $estado['finLectura'] }}. Nada se ha perdido: en cuanto aceptes, todo vuelve
        a estar donde estaba.
      </p>
    </div>
  @else
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
      <p class="text-sm font-semibold text-emerald-900">Estás al día</p>
      @if ($aceptadaEl)
        <p class="mt-1 text-sm text-emerald-800">
          Aceptaste esta versión el {{ substr((string) $aceptadaEl, 0, 16) }}.
        </p>
      @endif
    </div>
  @endif

  @if ($version)
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="px-5 py-3 border-b border-slate-100 flex items-baseline justify-between gap-3">
        <h2 class="text-sm font-semibold">{{ $version->title }}</h2>
        <span class="text-xs text-slate-400">
          versión {{ $version->version }} · vigente desde {{ $version->effective_from }}
        </span>
      </div>

      {{-- El texto ENTERO, aquí. Aceptar algo que no se ve en la misma
           pantalla no es aceptar. --}}
      <div class="p-6 max-h-[32rem] overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ $version->body }}</div>

      @if ($hayQueAceptar)
        <form method="POST" action="{{ route('terminos.aceptar') }}"
              class="border-t border-slate-100 bg-slate-50 px-5 py-4">
          @csrf
          <p class="mb-3 text-xs text-slate-500">
            Al pulsar quedan registrados la fecha, tu dirección IP y tu navegador, como
            constancia de que fuiste tú.
          </p>
          <button class="rounded-lg bg-navy px-5 py-2.5 text-sm font-medium text-white hover:opacity-90">
            He leído y acepto los términos
          </button>
        </form>
      @endif
    </div>
  @else
    <p class="text-sm text-slate-500">Todavía no hay términos publicados.</p>
  @endif
@endsection
