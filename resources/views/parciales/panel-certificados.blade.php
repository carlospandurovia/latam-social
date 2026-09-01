{{-- 9.17i: el certificado de firma, dentro de la tarjeta común. --}}
@include('parciales.tarjeta', [
    'icono' => 'certificado',
    'titulo' => 'Certificado de firma digital',
    'explica' => 'Es con qué se firma cada comprobante. Va con la SOCIEDAD y no con la conexión, '
        .'porque lleva su RUC: el mismo certificado firma salga por donde salga.',
    'destacado' => 'El archivo no se guarda en disco y la contraseña del .pfx no se guarda en '
        .'ninguna parte: se usa al subirlo y se olvida.',
    'estado' => $estadoCertificado,
    'avisos' => $avisosCertificado ?? [],
    'enlaces' => [
        ['texto' => 'Obtener el certificado en SUNAT',
            'url' => 'https://www.sunat.gob.pe/sol.html', 'externo' => true],
    ],
    'cuerpo' => 'parciales.cuerpo-certificados',
])
