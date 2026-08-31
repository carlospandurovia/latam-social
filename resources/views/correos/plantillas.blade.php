@extends('layouts.panel')
@section('titulo', 'Plantillas de correo')
@section('subtitulo', 'Versionadas y con vigencia, como los términos')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Correo'])

  <div class="space-y-5">
    <a href="{{ route('correos.index') }}" class="text-sm text-marca-600 hover:underline">← Volver al registro</a>

    <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
      <p>
        Una versión publicada <strong>no se edita</strong>: se publica la siguiente y la anterior
        se cierra el día antes. Lo que se le envió a alguien tiene que poder demostrarse años
        después, y una plantilla editable convierte «esto es lo que le mandamos» en «esto es lo
        que le mandaríamos hoy».
      </p>
      <p class="mt-2 text-xs">
        Se publican con <code>php artisan correos:publicar &lt;codigo&gt; &lt;archivo&gt;</code>.
        Si no hay versión en el idioma del destinatario, se cae a
        <strong>{{ $porDefecto }}</strong> y queda anotado en el registro.
      </p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      @if ($plantillas->isEmpty())
        <p class="text-sm text-amber-800">
          No hay ninguna plantilla publicada todavía. Ningún aviso puede salir hasta que exista
          al menos una.
        </p>
      @else
        <table class="w-full text-sm">
          <thead class="text-slate-500">
            <tr>
              <th class="text-left font-medium pb-2">Código</th>
              <th class="text-left font-medium pb-2">Idioma</th>
              <th class="text-left font-medium pb-2">Versión</th>
              <th class="text-left font-medium pb-2">Asunto</th>
              <th class="text-left font-medium pb-2">Vigencia</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($plantillas as $p)
              <tr class="{{ $p->effective_to === null ? '' : 'text-slate-400' }}">
                <td class="py-2"><code>{{ $p->code }}</code></td>
                <td class="py-2">{{ $p->locale }}</td>
                <td class="py-2">{{ $p->version }}</td>
                <td class="py-2">{{ $p->subject }}</td>
                <td class="py-2 text-xs">
                  {{ $p->effective_from }} →
                  @if ($p->effective_to === null)
                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-800">vigente</span>
                  @else
                    {{ $p->effective_to }}
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
@endsection
