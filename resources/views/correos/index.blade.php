@extends('layouts.panel')
@section('titulo', 'Correos enviados')
@section('subtitulo', 'Qué salió, a quién, y qué no salió')

@section('contenido')
  <div class="space-y-5">
    {{-- Las traducciones que faltan, arriba: es lo único de esta pantalla que
         pide una acción y no sólo información. --}}
    @if ($faltanTraducciones->isNotEmpty())
      <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
        <p class="font-medium">Hay avisos saliendo en un idioma que no era el del destinatario</p>
        <p class="mt-1 text-xs">
          No es un error: la plantilla no existe en ese idioma y el sistema cae al de por defecto
          para que el aviso llegue igual. Esta lista es lo que falta por traducir.
        </p>
        <table class="w-full text-xs mt-3">
          <thead class="text-amber-800">
            <tr>
              <th class="text-left font-medium pb-1">Plantilla</th>
              <th class="text-left font-medium pb-1">Se pidió</th>
              <th class="text-left font-medium pb-1">Se envió en</th>
              <th class="text-right font-medium pb-1">Envíos</th>
              <th class="text-right font-medium pb-1">Último</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($faltanTraducciones as $f)
              <tr>
                <td class="py-1"><code>{{ $f->template_code }}</code></td>
                <td class="py-1">{{ $f->locale_requested }}</td>
                <td class="py-1">{{ $f->template_locale }}</td>
                <td class="py-1 text-right">{{ $f->envios }}</td>
                <td class="py-1 text-right">{{ $f->ultimo }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    <div class="flex flex-wrap gap-2 text-sm">
      @foreach (['failed' => 'Fallidos', 'queued' => 'En cola', 'sent' => 'Enviados', 'cancelled' => 'Cancelados', '' => 'Todos'] as $clave => $nombre)
        <a href="{{ route('correos.index', $clave === '' ? [] : ['estado' => $clave]) }}"
           class="rounded-lg border px-3 py-1.5 {{ $estado === $clave ? 'border-marca-300 bg-marca-50 text-marca-800 font-medium' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">
          {{ $nombre }}
          @if ($clave !== '')
            <span class="text-xs text-slate-400">{{ $conteos[$clave] ?? 0 }}</span>
          @endif
        </a>
      @endforeach
      <a href="{{ route('correos.plantillas') }}"
         class="ml-auto rounded-lg border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-50">
        Plantillas
      </a>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      @if ($correos->isEmpty())
        <p class="text-sm text-slate-500">
          {{ $estado === 'failed' ? 'Ningún correo ha fallado. Es la respuesta que se venía a buscar.' : 'No hay correos con ese estado.' }}
        </p>
      @else
        <table class="w-full text-sm">
          <thead class="text-slate-500">
            <tr>
              <th class="text-left font-medium pb-2">Cuándo</th>
              <th class="text-left font-medium pb-2">Para</th>
              <th class="text-left font-medium pb-2">Asunto</th>
              <th class="text-left font-medium pb-2">Plantilla</th>
              <th class="text-right font-medium pb-2">Intentos</th>
              <th class="text-left font-medium pb-2">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @foreach ($correos as $c)
              <tr>
                <td class="py-2 text-xs text-slate-500 whitespace-nowrap">{{ $c->queued_at }}</td>
                <td class="py-2">{{ $c->to_email }}</td>
                <td class="py-2">
                  {{ $c->subject }}
                  @if ($c->status === 'failed')
                    {{-- El motivo, no un identificador de error. Es la razón por
                         la que esta pantalla existe. --}}
                    <p class="mt-0.5 text-xs text-rose-700">{{ $c->last_error }}</p>
                  @endif
                </td>
                <td class="py-2 text-xs">
                  <code>{{ $c->template_code }}</code>
                  <span class="text-slate-400">{{ $c->template_version }}</span>
                  @if ($c->locale_requested !== $c->template_locale)
                    <span class="ml-1 rounded bg-amber-50 px-1 py-0.5 text-amber-800">
                      {{ $c->locale_requested }} → {{ $c->template_locale }}
                    </span>
                  @endif
                </td>
                <td class="py-2 text-right">{{ $c->attempts }}</td>
                <td class="py-2">
                  @php
                    $color = match ($c->status) {
                        'sent' => 'bg-emerald-50 text-emerald-800',
                        'failed' => 'bg-rose-50 text-rose-800',
                        'queued' => 'bg-slate-100 text-slate-600',
                        default => 'bg-slate-100 text-slate-500',
                    };
                  @endphp
                  <span class="rounded px-1.5 py-0.5 text-xs {{ $color }}">{{ $c->status }}</span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>

        <p class="mt-4 text-xs text-slate-400">
          El <strong>cuerpo</strong> del correo no se guarda aquí: lleva los datos de la persona,
          y la versión de la plantilla es inmutable, así que se puede reconstruir. Lo que sí queda
          es su huella SHA-256, que demuestra qué texto salió.
        </p>
      @endif
    </div>
  </div>
@endsection
