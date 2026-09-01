{{-- 9.17i: la cuenta de correo, dentro de la tarjeta común.

     Antes esto era el formulario metido en una columna estrecha con media
     pantalla vacía al lado y dos cajas rojas encima que decían casi lo mismo.
     Ahora es una tarjeta como las demás: explica qué hace, dice si está
     encendida, lleva sus propios avisos y tiene el interruptor. --}}
@include('parciales.tarjeta', [
    'icono' => 'correo',
    'titulo' => 'Servidor de correo (SMTP)',
    'explica' => 'Es la cuenta con la que sale TODO el correo del sistema: el enlace de alta de un '
        .'creador, el aviso de una campaña, el comprobante que se manda al cliente.',
    'destacado' => $efecto['sale_de_aqui']
        ? 'Si la apagas, se vuelve a usar la del servidor (.env) y no se borra nada.'
        : 'Mientras no guardes una cuenta aquí, se usa la del servidor — y ahora mismo esa no manda '
            .'nada: se escribe en el registro y el sistema no da ningún error.',
    'estado' => [
        'nivel' => $efecto['origen'] === 'base' ? 'activo' : ($efecto['sale_de_aqui'] ? 'apagado' : 'falta'),
        'texto' => $efecto['origen'] === 'base'
            ? 'Activo'
            : ($efecto['sale_de_aqui'] ? 'Usando el .env' : 'No sale ningún correo'),
    ],
    'avisos' => $avisosCorreo ?? [],
    'enlaces' => [
        ['texto' => 'Contraseñas de aplicación de Google (Gmail)',
            'url' => 'https://support.google.com/accounts/answer/185833', 'externo' => true],
        ['texto' => 'Ver los correos que han salido', 'url' => route('correos.index'),
            'permiso' => 'comms.view'],
    ],
    'cuerpo' => 'parciales.cuerpo-correo',
])
