<?php

declare(strict_types=1);

/**
 * Los rótulos de la calle (L-6, §26).
 *
 * ### Qué está aquí y qué no
 *
 * Aquí van **las palabras de la plantilla**: «Entrar», «Correo», «Ir al
 * contenido». No van los titulares, ni los encabezados de las franjas, ni las
 * preguntas: eso es **contenido**, vive en la base desde la `L-3` y lo cambia
 * quien administra sin desplegar (`DEC-190`). La diferencia es sencilla de
 * decir: si cambiarlo es traducir, va aquí; si cambiarlo es escribir, va en la
 * base.
 *
 * ### Por qué esto no es «para cuando lleguemos a Colombia»
 *
 * `R-2` de la auditoría lo dejó dicho: el texto de marketing ya vivía en la
 * base, pero cada etiqueta de formulario y cada enlace del pie estaban
 * **escritos en el `.blade.php`**, así que traducir el sitio obligaba a tocar
 * plantillas. Sacarlos no traduce nada por sí solo —hoy sólo hay español— pero
 * convierte «traducir» en «añadir un archivo» en vez de en «revisar seis
 * vistas buscando frases».
 *
 * Y lo sostiene un verificador (`tools/verificar-rotulos.py`): una frase nueva
 * escrita a mano en una vista pública pone el CI en rojo, o se escribe en
 * `ROTULOS-CRUDOS` con su motivo. Sin eso, esto se deshace solo en tres
 * iteraciones.
 */
return [

    // --------------------------------------------------------------- cabecera
    'saltar' => 'Ir al contenido',
    'menu' => 'Menú',
    'secciones' => 'Secciones',
    'entrar' => 'Entrar',
    'soy_creador' => 'Soy creador',
    'soy_marca' => 'Soy una marca',

    // ------------------------------------------------------------------- pie
    'pie' => [
        'plataforma' => 'Plataforma',
        'para_marcas' => 'Para marcas',
        'para_creadores' => 'Para creadores',
        'contacto' => 'Contacto',
        'legal' => 'Legal',
        'whatsapp' => 'WhatsApp',
    ],

    // -------------------------------------------------------------- WhatsApp
    'whatsapp_escribenos' => 'Escríbenos por WhatsApp',
    'whatsapp_prefieres' => '¿Prefieres escribir?',
    'whatsapp_hablanos' => 'Háblanos por WhatsApp',

    // -------------------------------------------------------- enlaces cruzados
    'eres_marca' => '¿Eres una marca? →',
    'eres_creador' => '¿Eres creador? →',

    // ------------------------------------------------------------ formularios
    'formulario' => [
        'opcional' => '(opcional)',
        'empresa' => 'Empresa o marca',
        'tu_nombre' => 'Tu nombre',
        'nombre_completo' => 'Nombre y apellido',
        'correo' => 'Correo',
        'telefono' => 'Teléfono',
        'pais' => 'País',
        'web' => 'Web',
        'mensaje' => 'Qué tienes en mente',
        'mas_datos' => 'Añadir teléfono y web',
        // El campo trampa. Se traduce igual que los demas: si fuera el unico
        // rotulo en espanol de una pagina en ingles, señalaria cual es la
        // trampa --que es justo lo que no tiene que hacer--.
        'trampa' => 'Empresa',
        'solo_para_responder' => 'Sólo lo usamos para responderte.',
        'solo_para_postular' => 'Sólo lo usamos para revisar tu postulación y escribirte.',
        'si_prefieres_correo' => 'Si prefieres escribir tú:',
        // Los de reserva del cierre: se usan cuando la portada no tiene los
        // suyos escritos, y por eso son rotulo y no contenido.
        'cierre_marcas' => 'Cuéntanos qué tienes en mente y te escribimos con cómo sería tu campaña.',
        'cierre_creadores' => 'Con esto basta para empezar. Revisamos tu perfil y te escribimos, '
            .'encaje o no encaje todavía.',
    ],

    // ------------------------------------------------------------- «gracias»
    'gracias_marca' => [
        'titulo' => 'Gracias por escribir',
        'encabezado' => 'Recibimos tu mensaje',
        'texto' => 'Te escribimos al correo que dejaste para contarte cómo sería tu campaña. '
            .'No hace falta que lo envíes otra vez.',
        'volver' => '← Volver',
    ],

    'gracias' => [
        'titulo' => 'Postulación recibida',
        'encabezado' => 'Recibimos tu postulación',
        'texto' => 'La revisamos y te escribimos al correo que dejaste — encaje o no encaje '
            .'todavía. No hace falta que la envíes otra vez.',
        'volver' => '← Volver',
    ],

    // ------------------------------------------------------- páginas legales
    'pagina' => [
        'version' => 'Versión :numero · vigente desde',
    ],
];
