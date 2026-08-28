<?php

declare(strict_types=1);

/*
 * Configuración propia de LATAM Social.
 *
 * Este archivo existe por una razón concreta: `env()` SOLO funciona dentro de
 * `config/`. En cualquier otro sitio devuelve null en cuanto alguien ejecuta
 * `php artisan config:cache` — que es lo normal en producción, porque Laravel
 * deja de leer el `.env`.
 *
 * El seeder del administrador leía `env('ADMIN_PASSWORD')` directamente. Con la
 * configuración cacheada eso devuelve null SIEMPRE, así que ignoraba en silencio
 * la contraseña que el operador hubiera puesto y generaba una al azar. No falla,
 * no avisa: simplemente hace otra cosa. Lo detectó PHPStan, no una prueba.
 */

return [
    'admin' => [
        'nombre' => env('ADMIN_NAME', 'Administrador'),
        'correo' => env('ADMIN_EMAIL', 'admin@portalcts.com'),
        // Sin valor por defecto a propósito: si no está, el seeder genera una
        // al azar y la imprime una vez. Una contraseña por defecto en el
        // repositorio termina siempre en producción.
        'clave' => env('ADMIN_PASSWORD'),
    ],

    /*
     * Correo (iteración 4.9).
     *
     * `idioma_por_defecto` es al que se cae cuando no existe la plantilla en el
     * idioma del destinatario. La caída se ANOTA en `email_log.locale_requested`,
     * así que la lista de traducciones que faltan es una consulta y no una
     * revisión a mano.
     */
    'correo' => [
        'idioma_por_defecto' => env('CORREO_IDIOMA_POR_DEFECTO', 'es'),
    ],

    /*
     * Archivos subidos (iteración 3.5).
     *
     * Documentos de identidad y evidencias legales. El disco por defecto es el
     * privado del propio servidor; en producción esto pasa a S3 sin tocar
     * código, que es justo el motivo de que sea configuración.
     */
    'archivos' => [
        'disco' => env('LATAM_FILES_DISK', 'local'),
        'max_kb' => (int) env('LATAM_FILES_MAX_KB', 8192),
    ],

    /*
     * Términos y condiciones (iteración 3.5, DEC-059).
     *
     * El CÓDIGO del documento, no su versión: la versión vigente la decide la
     * tabla `terms_versions` (`effective_to IS NULL`). Poner aquí la versión
     * sería tener la misma verdad en dos sitios, y uno de los dos se quedaría
     * viejo.
     */
    /*
     * Umbrales de coherencia de métricas sociales (iteración 3.7, DEC-063).
     *
     * BR-CREATOR-004 pide «chequeos de coherencia» y no da números, así que los
     * números son míos y están abiertos a revisión. Viven aquí y no en el código
     * porque un 3 % de engagement es excelente en una cuenta de un millón de
     * seguidores y mediocre en una de mil: esto se va a ajustar con datos reales.
     *
     * Nada de esto rechaza una métrica. Solo la marca para que la mire alguien.
     */
    'redes' => [
        'engagement_min' => (float) env('LATAM_ENGAGEMENT_MIN', 0.1),
        'engagement_max' => (float) env('LATAM_ENGAGEMENT_MAX', 20.0),
        'salto_max_pct' => (float) env('LATAM_SALTO_SEGUIDORES_PCT', 50.0),
        'ventana_dias' => (int) env('LATAM_SALTO_VENTANA_DIAS', 30),
    ],

    'terminos' => [
        'creador' => env('LATAM_TERMS_CREATOR_CODE', 'creator_terms'),
    ],

    /*
     * Medios de pago (iteración 3.8).
     *
     * `BR-FIN-006` exige un «período de enfriamiento» para un medio nuevo o
     * modificado y **no da número**. Lo pongo aquí, no en el código, por el
     * mismo motivo que los umbrales de coherencia (`DEC-063`): es un juicio que
     * se va a ajustar, y ajustarlo no debe requerir un despliegue.
     *
     * Veinticuatro horas entre verificar una cuenta y poder pagarle. Es el
     * margen para que el aviso al canal de contacto anterior llegue y el
     * creador reaccione si no fue él quien la cambió. Cero no es una opción:
     * cumpliría la letra de la regla y no su intención.
     *
     * Y ahora eso está IMPUESTO, no sólo escrito aquí (`T-24`). Antes, con la
     * variable a `0`, la pantalla decía «no es pagable hasta dentro de 0 h»
     * mientras la cuenta ya era pagable —`ck_cpm_eligible_after` usa `>=`—; y
     * con un valor negativo, `eligible_from` quedaba antes de `verified_at` y el
     * `UPDATE` salía con un `45000` sin traducir. Un comentario que dice «esto no
     * puede pasar» y no lo impide es una nota, no una regla.
     */
    /*
     * Seguridad de acceso (T-23).
     */
    'seguridad' => [
        /*
         * Comprobar la contrasena nueva contra filtraciones publicas conocidas
         * (haveibeenpwned, k-anonymity: nunca se manda la contrasena).
         *
         * Es una llamada HTTP SALIENTE y **falla en abierto**: si el servidor no
         * puede salir a internet, Laravel da la contrasena por buena. Por eso se
         * puede apagar de forma explicita en vez de dejar que falle en silencio
         * en el sitio donde mas importa.
         *
         * No es la defensa —esa son los 12 caracteres y la mezcla—, es un extra.
         */
        'comprobar_filtraciones' => (bool) env('LATAM_COMPROBAR_FILTRACIONES', true),
    ],

    'pagos' => [
        'enfriamiento_horas' => max(1, (int) env('LATAM_PAGOS_ENFRIAMIENTO_HORAS', 24)),

        /*
         * Solo para la migración `000490`, y normalmente null.
         *
         * `created_by_user_id` pasa a ser obligatoria (`H-11`) y para las filas
         * que ya existen no hay ningún valor verdadero que inventar. Si la
         * tabla tiene datos reales, aquí va el id del usuario que los capturó
         * — una declaración de un humano, no una evidencia, y por eso se pide
         * explícitamente en vez de rellenarla sola.
         */
        'capturador_migracion' => env('LATAM_PAGOS_CAPTURADOR_MIGRACION'),
    ],

    /*
     * Tarifas del creador (iteración 3.9).
     *
     * Solo para la migración `000495`, y normalmente null. `created_by_user_id`
     * pasa a ser obligatoria (`H-18`) y para las filas que ya existen no hay
     * ningún valor verdadero que inventar. Misma decisión que en los medios de
     * pago: se pide explícitamente en vez de rellenarla sola.
     */
    'tarifas' => [
        'autor_migracion' => env('LATAM_TARIFAS_AUTOR_MIGRACION'),
    ],

    /*
     * Tipos de cambio (iteración 9.2).
     *
     * La clave de Decolecta se lee AQUÍ y no con `env()` desde el servicio, por
     * la misma razón que la contraseña del administrador: con
     * `php artisan config:cache` —que es lo normal en producción— `env()`
     * devuelve null en cualquier sitio que no sea `config/`, y el síntoma no
     * sería un error sino un cron que no trae nada y no dice por qué. Lo detectó
     * PHPStan, otra vez, y no una prueba.
     *
     * Sin valor por defecto a propósito. Vacío significa «no hay credencial en
     * el entorno», y entonces se usa la guardada desde la pantalla, cifrada.
     */
    'cambio' => [
        'decolecta' => [
            'clave' => env('DECOLECTA_API_KEY'),
            'url' => env('DECOLECTA_URL', 'https://api.decolecta.com'),
        ],
    ],
];
