<?php

declare(strict_types=1);

namespace App\Modules\Content\Providers;

use App\Modules\Content\Console\VigilarPermanenciaCommand;
use App\Modules\Content\Listeners\GenerarEntregables;
use App\Shared\Auth\Permisos;
use App\Shared\Eventos\EventoOcurrido;
use App\Shared\Files\Vigilante;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Content aporta al framework (8.1, 8.8).
 *
 * El oyente se registra aquí y no en Campaign, que es lo que mantiene el grafo
 * acíclico: Campaign levanta un nombre y un array y **no sabe que alguien
 * escucha**; Content escucha y **no sabe quién lo levantó**.
 *
 * ### El planificador de permanencia se declara aquí (8.8)
 *
 * Por lo mismo que el de invitaciones se declara en Campaign: quien sabe cada
 * cuánto hay que mirar una ventana de permanencia es el módulo que la abre.
 *
 * **Una vez al día, temprano.** `permanence_until` es una `DATE` y una ventana de
 * permanencia se contrata en días: comprobarla cada diez minutos sería trabajo
 * repetido 143 veces para un dato que cambia como mucho una vez al día. A las
 * 6:00 porque lo que cierra —una ventana cumplida— habilita un pago, y conviene
 * que esté hecho antes de que nadie abra la pantalla de finanzas.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VigilarPermanenciaCommand::class]);
        }
    }

    public function boot(): void
    {
        Event::listen(EventoOcurrido::class, GenerarEntregables::class);

        // 9.15: los dos archivos de contenido. **Ninguno de los dos es
        // sensible**: se abren decenas de veces al dia --revisar una pieza es
        // mirarla-- y anotar cada apertura convertiria la bitacora en ruido que
        // nadie lee. Lo que se mira aqui es de quien son, no cuantas veces.

        // Lo que el creador entrega. Lo ve quien revisa entregables, y **el
        // creador que lo entrego**: es su trabajo.
        Vigilante::regla('deliverable', static function (object $archivo, int $usuarioId): bool {
            if (Permisos::tiene($usuarioId, 'content.deliverable.view')) {
                return true;
            }

            return DB::table('deliverable_versions as dv')
                ->join('deliverables as d', 'd.id', '=', 'dv.deliverable_id')
                ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
                ->join('creators as c', 'c.id', '=', 'cc.creator_id')
                ->where('dv.file_id', $archivo->id)
                ->where('c.user_id', $usuarioId)
                ->exists();
        });

        // La captura que prueba que el post existe. La ve quien verifica, quien
        // ve finanzas --de esto depende un pago-- y **el creador del post**: si
        // se le rechaza por lo que se ve en la captura, tiene que poder verla.
        Vigilante::regla('publication_evidence', static function (object $archivo, int $usuarioId): bool {
            if (Permisos::tiene($usuarioId, 'content.verify')
                || Permisos::tiene($usuarioId, 'content.deliverable.view')
                || Permisos::tiene($usuarioId, 'finance.view')) {
                return true;
            }

            return DB::table('publication_evidence as pe')
                ->join('publications as p', 'p.id', '=', 'pe.publication_id')
                ->join('deliverables as d', 'd.id', '=', 'p.deliverable_id')
                ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
                ->join('creators as c', 'c.id', '=', 'cc.creator_id')
                ->where('pe.file_id', $archivo->id)
                ->where('c.user_id', $usuarioId)
                ->exists();
        });

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $planificador */
            $planificador = $this->app->make(Schedule::class);

            $planificador->command('permanencia:vigilar')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                // Al log del planificador y no al de la aplicacion: «.corrio el
                // cron?» tiene que poder contestarse mirando un sitio (7.6).
                ->appendOutputTo(storage_path('logs/planificador.log'));
        });
    }
}
