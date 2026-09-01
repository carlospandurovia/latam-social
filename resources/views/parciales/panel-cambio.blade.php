{{-- 9.17i: los tipos de cambio, dentro de la tarjeta común. --}}
@include('parciales.tarjeta', [
    'icono' => 'cambio',
    'titulo' => 'Tipos de cambio',
    'explica' => 'Convierte lo que se cobra y lo que se paga cuando la campaña y el pago no van en '
        .'la misma moneda. Se traen solos cada madrugada.',
    'destacado' => 'Todavía se configuran en su propia pantalla, no aquí.',
    'estado' => ['nivel' => 'parcial', 'texto' => 'En otra pantalla'],
    'cuerpo' => 'parciales.cuerpo-cambio',
])
