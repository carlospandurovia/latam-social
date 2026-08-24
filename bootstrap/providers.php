<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Shared\Providers\ModuleServiceProvider::class,
    App\Shared\Providers\AutorizacionServiceProvider::class,
    // Cada modulo registra lo suyo. `ModuleServiceProvider` vive en Shared y
    // Shared NO puede depender de ningun modulo (deptrac.yaml: `Shared: ~`);
    // si registrara ahi los comandos de Core, la flecha iria al reves y CI lo
    // rechazaria con razon. Las migraciones si las carga Shared porque se
    // localizan por RUTA, no importando clases.
    App\Modules\Core\Providers\CoreServiceProvider::class,
    App\Modules\Identity\Providers\IdentityServiceProvider::class,
    App\Modules\Creator\Providers\CreatorServiceProvider::class,
];
