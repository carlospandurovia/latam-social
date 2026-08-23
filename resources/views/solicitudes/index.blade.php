@extends('layouts.panel')
@section('titulo', 'Solicitudes')
@section('subtitulo', 'Por aquí entra un creador al sistema')

@section('contenido')

@if (session('exito'))
  <div class="mb-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">{{ session('exito') }}</div>
@endif

@php
  $pestanas = [
    'submitted' => 'Nuevas', 'in_review' => 'En revisión', 'approved' => 'Aprobadas',
    'rejected' => 'Rechazadas', 'duplicate' => 'Duplicadas', 'todas' => 'Todas',
  ];
@endphp
<div class="flex flex-wrap gap-2 mb-5 text-sm">
  @foreach ($pestanas as $clave => $texto)
    <a href="{{ route('solicitudes.index', ['estado' => $clave]) }}"
       class="px-3 py-1.5 rounded-lg border {{ $estado === $clave ? 'bg-marca-500 text-white border-marca-500 font-medium' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50' }}">
      {{ $texto }}
      @if ($clave !== 'todas' && ($conteos[$clave] ?? 0) > 0)
        <span class="ml-1 text-xs opacity-70">{{ $conteos[$clave] }}</span>
      @endif
    </a>
  @endforeach
</div>

<div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
      <tr>
        <th class="text-left font-medium px-4 py-3">Nombre</th>
        <th class="text-left font-medium px-4 py-3">Correo</th>
        <th class="text-left font-medium px-4 py-3">País</th>
        <th class="text-left font-medium px-4 py-3">Origen</th>
        <th class="text-left font-medium px-4 py-3">Estado</th>
        <th class="text-left font-medium px-4 py-3">Recibida</th>
        <th></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      @forelse ($solicitudes as $s)
        <tr class="hover:bg-slate-50/60">
          <td class="px-4 py-3 text-slate-800">{{ $s->full_name }}</td>
          <td class="px-4 py-3 text-slate-500">{{ $s->email }}</td>
          <td class="px-4 py-3 text-slate-500">{{ $s->pais }}</td>
          <td class="px-4 py-3 text-slate-400 text-xs">{{ $s->source }}</td>
          <td class="px-4 py-3">
            @php
              $color = match ($s->status) {
                'approved' => 'bg-emerald-50 text-emerald-700',
                'rejected', 'duplicate' => 'bg-rose-50 text-rose-700',
                'in_review' => 'bg-amber-50 text-amber-700',
                default => 'bg-slate-100 text-slate-600',
              };
            @endphp
            <span class="px-2 py-0.5 rounded text-xs {{ $color }}">{{ $s->status }}</span>
          </td>
          <td class="px-4 py-3 text-slate-400 text-xs tabular-nums">{{ $s->submitted_at }}</td>
          <td class="px-4 py-3 text-right">
            <a href="{{ route('solicitudes.show', $s->uuid) }}" class="text-marca-600 hover:underline">Revisar</a>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">Nada por aquí.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<div class="mt-4">{{ $solicitudes->links() }}</div>
@endsection
