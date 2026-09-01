{{-- 9.17h: los tipos de cambio, ya con su configuración de verdad. --}}
@include('parciales.tarjeta', [
    'icono' => 'cambio',
    'titulo' => 'Tipos de cambio (Decolecta)',
    'explica' => 'Trae solo, cada madrugada, el tipo de cambio oficial de SUNAT para convertir lo '
        .'que se cobra y lo que se paga cuando la campaña y el pago no van en la misma moneda.',
    'destacado' => 'Sin clave el cron corre y no trae nada: las conversiones se quedan con la '
        .'última tasa que haya, y convertir con una tasa vieja es convertir mal.',
    'estado' => $estadoCambio,
    'avisos' => $avisosCambio ?? [],
    'enlaces' => [
        ['texto' => 'Obtener una clave (decolecta.com)', 'url' => 'https://decolecta.com/',
            'externo' => true],
        ['texto' => 'Ver las tasas y el registro de traídas', 'url' => route('cambio.index'),
            'permiso' => 'fx.manage'],
    ],
    'cuerpo' => 'parciales.cuerpo-cambio',
])
