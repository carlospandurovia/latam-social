{{-- 9.17i: las series y sus folios, dentro de la tarjeta común. --}}
@include('parciales.tarjeta', [
    'icono' => 'folios',
    'titulo' => 'Series y folios',
    'explica' => 'Son los números que salen: qué serie usa cada tipo de documento de cada sociedad '
        .'y por qué número va.',
    'destacado' => 'Un número reservado que no llegó a documento se anula con un motivo y no se '
        .'reutiliza nunca: reutilizarlo sería emitir dos comprobantes con el mismo número.',
    'estado' => $estadoSerie,
    'avisos' => $avisosSerie ?? [],
    'cuerpo' => 'parciales.cuerpo-series',
])
