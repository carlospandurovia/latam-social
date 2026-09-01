{{-- 9.17i: la conexión con el emisor, dentro de la tarjeta común. --}}
@include('parciales.tarjeta', [
    'icono' => 'enchufe',
    'titulo' => 'Conexión con el emisor (SUNAT u OSE)',
    'explica' => 'Es con quién habla el sistema para mandar los comprobantes y recoger la respuesta: '
        .'la dirección del servicio y el usuario secundario con su contraseña.',
    'destacado' => 'Sólo puede haber un emisor activo por sociedad y entorno; los demás se dejan '
        .'configurados y apagados.',
    'estado' => $estadoConexion,
    'avisos' => $avisosConexion ?? [],
    'enlaces' => [
        ['texto' => 'Crear el usuario secundario en SUNAT (Clave SOL)',
            'url' => 'https://www.sunat.gob.pe/sol.html', 'externo' => true],
    ],
    'cuerpo' => 'parciales.cuerpo-conexiones',
])
