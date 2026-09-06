{{-- La idea sola, a página completa (L-3).

     Es el único sitio de la portada, además del héroe, donde manda el degradado
     de marca: `docs/14 §6` lo reserva para momentos, no para fondos. Si esto se
     usara en tres franjas dejaría de significar nada.

     No lleva tarjetas: los bloques, si los hay, salen como una línea de apoyo
     debajo. Una idea que necesita seis tarjetas para explicarse no es una
     idea. --}}
<section id="{{ $s->code }}" class="degradado-marca relative overflow-hidden">
  <div class="mx-auto max-w-4xl px-6 py-20 sm:py-28 text-center">
    @if ($s->eyebrow)
      <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">{{ $s->eyebrow }}</p>
    @endif

    @if ($s->title)
      <h2 class="fuente-titulos mt-3 text-3xl sm:text-5xl font-bold tracking-tight text-white text-balance">
        {{ $s->title }}
      </h2>
    @endif

    @if ($s->subtitle)
      <p class="mx-auto mt-5 max-w-2xl text-lg leading-relaxed text-white/85">{{ $s->subtitle }}</p>
    @endif

    @if ($s->bloques->isNotEmpty())
      <div class="mt-10 grid gap-6 sm:grid-cols-3 text-left">
        @foreach ($s->bloques as $b)
          <div class="aparece rounded-xl border border-white/20 bg-white/10 p-5 backdrop-blur-sm"
               style="--retraso: {{ $loop->index * 70 }}ms">
            <h3 class="text-sm font-semibold text-white">{{ $b->heading }}</h3>
            @if ($b->body)
              <p class="mt-1.5 text-sm leading-relaxed text-white/80">{{ $b->body }}</p>
            @endif
          </div>
        @endforeach
      </div>
    @endif

    @if ($s->cta_label)
      <div class="mt-10">
        <a href="{{ $s->cta_url ?: '#empezar' }}"
           @if (str_starts_with((string) $s->cta_url, 'https://')) target="_blank" rel="noopener" @endif
           data-evento="cta_franja_{{ $s->code }}"
           class="inline-flex items-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-slate-900
                  shadow-lg shadow-black/10 transition hover:-translate-y-0.5 hover:bg-slate-50">
          {{ $s->cta_label }}
        </a>
      </div>
    @endif
  </div>
</section>
