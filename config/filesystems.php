<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // 9.14: `serve` pasa a `false`, y el motivo importa.
        //
        // Con `true`, Laravel registra DOS rutas que no son nuestras y que no
        // pasan por `permiso:`: un `GET /storage/{path}` que lee de
        // `storage/app/private` --donde viven los documentos de identidad, las
        // evidencias de publicacion y los comprobantes de pago-- y un
        // `PUT /storage/{path}` que ESCRIBE ahi.
        //
        // No eran una fuga: el disco es privado, asi que las dos exigen una URL
        // firmada y sin firma contestan 404. Pero **la aplicacion no genera
        // ninguna URL firmada de archivo**, asi que no servian para nada y eran
        // dos puertas mas que defender. Se cierran.
        //
        // Nadie las habia visto hasta que `MuroTest` recorrio las rutas una a
        // una: no estaban en `routes/web.php`, las registra el framework al
        // arrancar, y por eso no las encontraba ninguna lectura del codigo.
        //
        // El dia que haya que ENSENAR un archivo, la puerta es nuestra y con su
        // permiso (`T-67`), no esta.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
