@extends('layouts.panel')
@section('titulo', 'Integraciones')
@section('subtitulo', 'Cada integración, con lo que de verdad necesita')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Integraciones'])

  @if (session('exito'))
    <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
      {{ session('exito') }}
    </div>
  @endif
  @if (session('aviso'))
    <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
      {{ session('aviso') }}
    </div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
      <ul class="list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  {{-- 9.17f: pestañas por PROPÓSITO y no un formulario para todo.
       Reportado por el negocio: «cada proveedor de integración tiene diferentes
       parámetros, sobre todo si es para diferentes fines». Tenía razón — el
       formulario único pedía lo mismo a un servidor de correo y a un emisor
       electrónico, y no le servía bien a ninguno de los dos. --}}
  <div class="mb-5 flex flex-wrap gap-1 border-b border-slate-200">
    @foreach ($pestanas as $clave => $titulo)
      <a href="{{ route('integraciones.index', ['p' => $clave]) }}"
         class="rounded-t-lg px-4 py-2 text-sm
           {{ $pestana === $clave
              ? 'border border-b-white border-slate-200 bg-white font-medium text-slate-900'
              : 'text-slate-500 hover:text-slate-800' }}">
        {{ $titulo }}
        @if (($pendientes[$clave] ?? 0) > 0)
          <span class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-[11px] text-rose-800">
            {{ $pendientes[$clave] }}
          </span>
        @endif
      </a>
    @endforeach
  </div>

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-800'
         : 'bg-amber-50 border-amber-200 text-amber-800' }}">
      {{ $aviso->texto }}
    </div>
  @endforeach

  @if ($pestana === 'fel')
    <p class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
      Para emitir hacen falta <strong>tres cosas</strong>, y están las tres aquí abajo: con
      <strong>quién</strong> se habla (la conexión y su clave), <strong>con qué se firma</strong>
      (el certificado, que va con la sociedad porque lleva su RUC) y <strong>qué números</strong>
      salen (las series y sus folios). Sólo puede haber <strong>un emisor activo</strong> por
      sociedad y entorno: se pueden dejar otros configurados, apagados.
    </p>

    @include('parciales.panel-conexiones')

    <h2 class="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
      Con qué se firma
    </h2>
    @include('parciales.panel-certificados')

    <h2 class="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
      Qué números salen — series y folios
    </h2>
    @include('parciales.panel-series')
  @endif

  @if ($pestana === 'fx')
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
      <p class="font-semibold text-slate-800 mb-1">Los tipos de cambio se configuran en su pantalla</p>
      <p>
        Sus fuentes, la clave de la API y el registro de cada traída viven en
        <a href="{{ route('cambio.index') }}" class="text-marca-700 hover:underline">Tipos de cambio</a>
        desde la iteración 9.2, y funcionan. <strong>Se traen a esta pestaña en la iteración
        siguiente</strong>: moverlos es una migración de datos sobre algo que hoy va bien, y eso se
        hace con su prueba, no de paso.
      </p>
    </div>
  @endif

  @if ($pestana === 'correo')
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
      <p class="font-semibold text-slate-800 mb-1">Hoy el correo se configura en el servidor</p>
      <p class="mb-2">
        Transporte actual: <strong class="font-mono">{{ $transporteDeCorreo }}</strong>.
        @if (in_array($transporteDeCorreo, ['log', 'array', 'null'], true))
          <span class="text-rose-700">Nada sale de este servidor: los correos se escriben en el registro.</span>
        @endif
      </p>
      <p>
        Se cambia en el <span class="font-mono">.env</span> (<span class="font-mono">MAIL_HOST</span>,
        <span class="font-mono">MAIL_PORT</span>, <span class="font-mono">MAIL_USERNAME</span>,
        <span class="font-mono">MAIL_PASSWORD</span>) y exige entrar a la máquina.
        <strong>Se trae a esta pestaña en la iteración siguiente</strong>, para poder cambiarlo sin
        desplegar. Lo que ya salió se ve en
        <a href="{{ route('correos.index') }}" class="text-marca-700 hover:underline">Correos</a>.
      </p>
    </div>
  @endif
@endsection
