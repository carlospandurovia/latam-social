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
    App\Modules\Communication\Providers\CommunicationServiceProvider::class,
    // 7.6: ademas de su comando, este declara el planificador que cierra las
    // invitaciones vencidas. Sin el, una invitacion sin contestar deja su
    // importe comprometido y su plaza del cupo ocupada para siempre.
    App\Modules\Campaign\Providers\CampaignServiceProvider::class,
    // 8.1: escucha `campaign_creator.accepted` y crea lo que hay que entregar.
    // Va por evento porque Campaign no puede conocer a Content --y no debe:
    // si generar la lista de tareas falla, la aceptacion sigue siendo cierta--.
    App\Modules\Content\Providers\ContentServiceProvider::class,
];
