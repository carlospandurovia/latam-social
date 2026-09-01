@extends('layouts.panel')
@section('titulo', 'Certificados de firma')
@section('subtitulo', 'Con qué firma cada sociedad sus comprobantes')

@section('contenido')
  @include('parciales.miga', ['aqui' => 'Certificados de firma'])

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

  @foreach ($avisos as $aviso)
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm
      {{ $aviso->nivel === 'rojo' ? 'bg-rose-50 border-rose-200 text-rose-800'
         : 'bg-amber-50 border-amber-200 text-amber-800' }}">
      {{ $aviso->texto }}
    </div>
  @endforeach

  <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Si el archivo de SUNAT no se deja abrir</p>
    <p>
      Los <code>.pfx</code> que emite SUNAT usan un cifrado antiguo que OpenSSL&nbsp;3 ya no lee.
      No es un error del archivo ni de la contraseña. Se convierte <strong>una sola vez</strong>:
    </p>
    <pre class="mt-2 overflow-x-auto rounded bg-slate-900 px-3 py-2 text-xs text-slate-100">openssl pkcs12 -legacy -in suyo.pfx -nodes -out convertido.pem</pre>
    <p class="mt-2">
      Y se sube el <code>.pem</code> que sale. El certificado y su clave privada se guardan
      <strong>cifrados en la base</strong>; el archivo no queda en disco y
      <strong>la contraseña del .pfx no se guarda</strong>: se usa al subirlo y se olvida.
    </p>
  </div>

  <div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-4 py-2 text-left">Sociedad</th>
              <th class="px-4 py-2 text-left">Entorno</th>
              <th class="px-4 py-2 text-left">A nombre de</th>
              <th class="px-4 py-2 text-left">Vence</th>
              <th class="px-4 py-2 text-left">Estado</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse ($certificados as $c)
              <tr class="{{ $c->status === 'active' ? '' : 'text-slate-400' }}">
                <td class="px-4 py-2">
                  {{ $c->sociedad }}
                  <span class="block text-[11px] text-slate-400">{{ $c->sociedad_nombre }}</span>
                </td>
                <td class="px-4 py-2 text-xs">{{ $entornos[$c->environment] ?? $c->environment }}</td>
                <td class="px-4 py-2">
                  <span class="font-mono text-xs">{{ $c->tax_id_number }}</span>
                  @if ($c->tax_id_number !== $c->sociedad_ruc)
                    <span class="ml-1 rounded bg-rose-100 px-1.5 py-0.5 text-[11px] text-rose-800">otro RUC</span>
                  @endif
                  <span class="block text-[11px] text-slate-400">{{ $c->issuer_name }}</span>
                </td>
                <td class="px-4 py-2 text-xs">{{ $c->valid_to }}</td>
                <td class="px-4 py-2 text-xs">
                  {{ $estados[$c->status] ?? $c->status }}
                  @if ($c->status === 'revoked' && $c->revoked_reason)
                    <span class="block text-[11px] text-slate-400">{{ $c->revoked_reason }}</span>
                  @endif
                </td>
                <td class="px-4 py-2 text-right">
                  @if ($c->status === 'active')
                    <form method="POST" action="{{ route('certificados.revocar', ['uuid' => $c->uuid]) }}"
                          class="flex items-center gap-1 justify-end">
                      @csrf
                      <input name="motivo" required minlength="10" maxlength="255" placeholder="Por qué"
                             class="w-32 rounded border-slate-300 text-xs">
                      <button class="text-xs text-rose-600 hover:underline">revocar</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="px-4 py-6 text-sm text-slate-500">
                Todavía no hay ningún certificado. Sin él, ninguna sociedad puede emitir comprobantes electrónicos.
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <form method="POST" action="{{ route('certificados.cargar') }}" enctype="multipart/form-data"
          class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      @csrf
      <h2 class="text-sm font-semibold">Cargar un certificado</h2>

      <label class="block text-xs text-slate-500">Sociedad
        <select name="legal_entity_id" required class="mt-1 w-full rounded border-slate-300 text-sm">
          @foreach ($sociedades as $s)
            <option value="{{ $s->id }}">{{ $s->code }} — {{ $s->tax_id_number }}</option>
          @endforeach
        </select>
      </label>

      <label class="block text-xs text-slate-500">Entorno
        <select name="environment" required class="mt-1 w-full rounded border-slate-300 text-sm">
          @foreach ($entornos as $clave => $texto)
            <option value="{{ $clave }}">{{ $texto }}</option>
          @endforeach
        </select>
        <span class="text-[11px] text-slate-400">El de pruebas y el real no se mezclan.</span>
      </label>

      <label class="block text-xs text-slate-500">Archivo (.pfx, .p12 o .pem)
        <input type="file" name="archivo" required accept=".pfx,.p12,.pem,.crt"
               class="mt-1 w-full rounded border-slate-300 text-sm">
      </label>

      <label class="block text-xs text-slate-500">Contraseña del archivo
        <input type="password" name="clave" autocomplete="off" class="mt-1 w-full rounded border-slate-300 text-sm">
        <span class="text-[11px] text-slate-400">Sólo para abrirlo. No se guarda.</span>
      </label>

      <button class="w-full rounded bg-marca-500 px-3 py-2 text-sm text-white">Cargar</button>
    </form>
  </div>
@endsection
