<?php

declare(strict_types=1);

/**
 * Los mensajes de validación, en español (L-7).
 *
 * ### Por qué existe este archivo, y por qué es urgente
 *
 * Hasta la `L-6` **no había carpeta `lang/`**, así que Laravel usaba sus
 * traducciones internas y un correo mal escrito producía *«The email field must
 * be a valid email address»*: en inglés, pero una frase. Al crear `lang/` para
 * los rótulos de la calle, el traductor pasó a buscar aquí, no encontró nada, y
 * empezó a pintar **la clave**: `validation.email` en la cara de quien intenta
 * escribirnos.
 *
 * Lo encontró el barrido de la `L-7` **enviando el formulario con un correo
 * inválido**, que es una cosa que ninguna prueba de la `L-6` hacía. No es un
 * defecto de esta iteración: es un defecto que esta iteración descubrió, y es
 * exactamente para lo que sirve un QA que usa el producto en vez de leerlo.
 *
 * ### Qué hay aquí
 *
 * Las reglas que este proyecto usa de verdad, no las 90 de Laravel: lo que no se
 * usa no se puede comprobar, y una traducción que nadie ha leído es una promesa.
 * `attributes` da a cada campo el nombre que lleva en la pantalla, para que el
 * mensaje diga «El correo no tiene forma de correo» y no «El campo email…».
 */
return [

    'accepted' => 'Tienes que aceptar :attribute.',
    'after' => ':Attribute tiene que ser posterior a :date.',
    'after_or_equal' => ':Attribute no puede ser anterior a :date.',
    'array' => ':Attribute tiene que ser una lista.',
    'before' => ':Attribute tiene que ser anterior a :date.',
    'before_or_equal' => ':Attribute no puede ser posterior a :date.',
    'between' => [
        'array' => ':Attribute tiene que tener entre :min y :max elementos.',
        'file' => ':Attribute tiene que pesar entre :min y :max kilobytes.',
        'numeric' => ':Attribute tiene que estar entre :min y :max.',
        'string' => ':Attribute tiene que tener entre :min y :max caracteres.',
    ],
    'boolean' => ':Attribute sólo puede ser sí o no.',
    'confirmed' => ':Attribute no coincide con la confirmación.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => ':Attribute no es una fecha válida.',
    'date_format' => ':Attribute no tiene el formato :format.',
    'different' => ':Attribute y :other tienen que ser distintos.',
    'digits' => ':Attribute tiene que tener :digits dígitos.',
    'digits_between' => ':Attribute tiene que tener entre :min y :max dígitos.',
    'email' => ':Attribute no tiene forma de correo electrónico.',
    'ends_with' => ':Attribute tiene que terminar en: :values.',
    'exists' => 'Lo que has elegido en :attribute no existe.',
    'file' => ':Attribute tiene que ser un archivo.',
    'filled' => ':Attribute no puede quedarse vacío.',
    'gt' => [
        'numeric' => ':Attribute tiene que ser mayor que :value.',
        'string' => ':Attribute tiene que tener más de :value caracteres.',
    ],
    'gte' => [
        'numeric' => ':Attribute tiene que ser :value o más.',
        'string' => ':Attribute tiene que tener :value caracteres o más.',
    ],
    'image' => ':Attribute tiene que ser una imagen.',
    'in' => 'Lo que has elegido en :attribute no es válido.',
    'integer' => ':Attribute tiene que ser un número entero.',
    'ip' => ':Attribute tiene que ser una dirección IP.',
    'json' => ':Attribute tiene que ser JSON válido.',
    'lt' => [
        'numeric' => ':Attribute tiene que ser menor que :value.',
        'string' => ':Attribute tiene que tener menos de :value caracteres.',
    ],
    'lte' => [
        'numeric' => ':Attribute tiene que ser :value o menos.',
        'string' => ':Attribute tiene que tener :value caracteres o menos.',
    ],
    'max' => [
        'array' => ':Attribute no puede tener más de :max elementos.',
        'file' => ':Attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string' => ':Attribute no puede tener más de :max caracteres.',
    ],
    'mimes' => ':Attribute tiene que ser un archivo de tipo: :values.',
    'mimetypes' => ':Attribute tiene que ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':Attribute tiene que tener al menos :min elementos.',
        'file' => ':Attribute tiene que pesar al menos :min kilobytes.',
        'numeric' => ':Attribute tiene que ser al menos :min.',
        'string' => ':Attribute tiene que tener al menos :min caracteres.',
    ],
    'not_in' => 'Lo que has elegido en :attribute no es válido.',
    'numeric' => ':Attribute tiene que ser un número.',
    'present' => ':Attribute tiene que venir en el formulario.',
    'prohibited' => ':Attribute no se puede rellenar aquí.',
    'regex' => ':Attribute no tiene el formato esperado.',
    'required' => 'Falta :attribute.',
    'required_if' => 'Falta :attribute cuando :other es :value.',
    'required_with' => 'Falta :attribute cuando hay :values.',
    'required_without' => 'Falta :attribute cuando no hay :values.',
    'same' => ':Attribute y :other tienen que coincidir.',
    'size' => [
        'array' => ':Attribute tiene que tener :size elementos.',
        'file' => ':Attribute tiene que pesar :size kilobytes.',
        'numeric' => ':Attribute tiene que ser :size.',
        'string' => ':Attribute tiene que tener :size caracteres.',
    ],
    'string' => ':Attribute tiene que ser texto.',
    'unique' => ':Attribute ya está registrado.',
    'uploaded' => ':Attribute no se pudo subir.',
    'url' => ':Attribute no tiene forma de dirección web.',
    'uuid' => ':Attribute no es un identificador válido.',

    'custom' => [],

    /**
     * El nombre que cada campo tiene EN LA PANTALLA.
     *
     * Sin esto el mensaje dice «Falta company_name», que es el nombre de una
     * columna y no el del hueco que la persona está mirando.
     */
    'attributes' => [
        'company_name' => 'la empresa o marca',
        'contact_name' => 'tu nombre',
        'full_name' => 'el nombre y apellido',
        'email' => 'el correo',
        'phone' => 'el teléfono',
        'country_id' => 'el país',
        'website' => 'la web',
        'message' => 'lo que tienes en mente',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'headline' => 'el titular',
        'subheadline' => 'la bajada',
        'cta_label' => 'el texto del botón',
        'cta_url' => 'el enlace del botón',
        'form_heading' => 'el encabezado del formulario',
        'form_intro' => 'la frase del formulario',
        'meta_title' => 'el título para buscadores',
        'meta_description' => 'la descripción para buscadores',
        'title' => 'el encabezado',
        'subtitle' => 'la bajada',
        'eyebrow' => 'el sobretítulo',
        'code' => 'el ancla',
        'layout' => 'la forma de pintar',
        'heading' => 'el título',
        'body' => 'el texto',
        'icon' => 'el icono',
        'whatsapp_phone' => 'el WhatsApp',
        'whatsapp_message' => 'el mensaje de WhatsApp',
        'contact_email' => 'el correo de contacto',
        'contact_phone' => 'el teléfono de contacto',
        'public_address' => 'la dirección pública',
        'default_country_id' => 'el país por defecto',
        'analytics_provider' => 'el proveedor de medición',
        'analytics_id' => 'el identificador de medición',
        'network' => 'la red',
        'label' => 'la etiqueta',
        'url' => 'el enlace',
    ],
];
