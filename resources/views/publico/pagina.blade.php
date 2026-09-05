@extends('layouts.publico')
@section('titulo', $pagina->meta_title ?: $pagina->title)
@if ($pagina->meta_description)
  @section('descripcion', $pagina->meta_description)
@endif

@section('contenido')
  <article class="mx-auto max-w-3xl px-6 py-14 sm:py-20">
    <h1 class="fuente-titulos text-3xl sm:text-4xl font-bold tracking-tight text-slate-900">
      {{ $pagina->title }}
    </h1>

    <p class="mt-3 text-sm text-slate-500">
      Versión {{ $pagina->version }} · vigente desde
      <time datetime="{{ $pagina->effective_from }}">
        {{ \Illuminate\Support\Str::of($pagina->effective_from)->substr(0, 10) }}
      </time>
    </p>

    {{-- L-2b: el cuerpo llega de `Paginas::publica()` **ya sustituido y ya
         convertido**, con el HTML de dentro escapado por `Marcado`. Se pinta con
         `{!! !!}` porque es HTML generado por nosotros a partir de Markdown; lo
         que escribió la persona no puede ejecutar nada.

         Las clases se ponen aquí y no en el Markdown: quien escribe un documento
         legal escribe texto, no maquetación. --}}
    <div class="prosa mt-8">
      {!! $pagina->cuerpo !!}
    </div>
  </article>
@endsection
