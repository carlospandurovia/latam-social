<?php

declare(strict_types=1);

namespace App\Modules\Creator\Providers;

use App\Modules\Core\Services\Terminos;
use App\Modules\Creator\Console\RecalcularHuellasCommand;
use App\Modules\Creator\Listeners\AvisarReaceptacion;
use App\Modules\Creator\Services\Reaceptacion;
use App\Shared\Auth\Permisos;
use App\Shared\Config\Preparacion;
use App\Shared\Eventos\TerminosPublicados;
use App\Shared\Files\Vigilante;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Creator aporta al framework.
 *
 * Mismo motivo que `IdentityServiceProvider`: `ModuleServiceProvider` vive en
 * `App\Shared`, y en `deptrac.yaml` la capa `Shared` no puede depender de
 * ningún módulo. Cada módulo que necesite registrar comandos tiene el suyo.
 */
final class CreatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RecalcularHuellasCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // 9.19: el aviso de los terminos, en la barra de arriba de TODAS las
        // pantallas del creador. Es el «recordatorio al entrar» de `Q-46`
        // resuelto como una franja y no como un popup: un popup se cierra sin
        // leerlo y no vuelve a aparecer hasta la siguiente sesion, y esto tiene
        // que seguir estando mientras siga sin aceptarse.
        //
        // Devuelve `null` para el equipo, que es lo que hace que esta consulta
        // no le cueste una peticion a nadie mas.
        // 9.19b: el aviso de `Q-46`. Core levanta el evento y no sabe que
        // alguien escucha; Creator escucha y no sabe quien lo levanto. Mismo
        // patron que `CorreoPedido` y por lo mismo.
        Event::listen(TerminosPublicados::class, AvisarReaceptacion::class);

        // 9.19b (`T-74`): el area «Creadores» del panel de configuracion la
        // registra Creator, no Core. Contar quien no ha aceptado exige leer
        // `creators`, y Core no puede saber de esa tabla. Para esto es un
        // registro y no un decisor central (`DEC-205`).
        //
        // `creator.view` y no `legal_entity.manage`: quien ve creadores es quien
        // puede hacer algo con esto --escribirles, llamarles--; quien lleva los
        // documentos legales ya tiene el area de Terminos.
        Preparacion::area('Creadores', 'creator.view', 'creadores.index',
            static fn (): array => Reaceptacion::avisos(), orden: 25);

        View::composer('layouts.panel', static function (\Illuminate\View\View $vista): void {
            $usuario = Auth::user();

            if ($usuario === null || ($usuario->user_type ?? 'internal') === 'internal') {
                $vista->with('avisoTerminos', null);

                return;
            }

            $creadorId = DB::table('creators')
                ->where('user_id', $usuario->getAuthIdentifier())->value('id');

            $estado = $creadorId === null ? null : Reaceptacion::de((int) $creadorId);

            $vista->with('avisoTerminos',
                $estado === null || $estado['estado'] === Terminos::AL_DIA ? null : $estado);
        });

        // 9.15: los dos archivos que cuelgan de un creador.
        //
        // **El creador ve los suyos**, incluido su documento de identidad: es
        // informacion suya, negarle ver lo que el mismo subio no protege a
        // nadie, y le permite comprobar que archivamos el correcto cuando le
        // rechacen algo por eso. Los de OTRO creador, nunca.
        //
        // Dentro del equipo hace falta `creator.view_sensitive`, que es el mismo
        // permiso que abre sus datos fiscales y su cuenta bancaria (3.6): el
        // documento de identidad esta en esa familia y no en la de «ver
        // creadores».
        //
        // Los dos son SENSIBLES: queda escrito quien los abrio y cuando.
        // El documento cuelga de `creators.identity_document_file_id`.
        Vigilante::regla('identity_document', static function (object $archivo, int $usuarioId): bool {
            if (Permisos::tiene($usuarioId, 'creator.view_sensitive')) {
                return true;
            }

            return DB::table('creators')
                ->where('identity_document_file_id', $archivo->id)
                ->where('user_id', $usuarioId)
                ->exists();
        }, sensible: true);

        // La evidencia de aceptacion cuelga de `terms_acceptances`, que es
        // polimorfica: `subject_type` = 'creator' y `subject_id` el creador.
        Vigilante::regla('terms_evidence', static function (object $archivo, int $usuarioId): bool {
            if (Permisos::tiene($usuarioId, 'creator.view_sensitive')) {
                return true;
            }

            return DB::table('terms_acceptances as ta')
                ->join('creators as c', 'c.id', '=', 'ta.subject_id')
                ->where('ta.evidence_file_id', $archivo->id)
                ->where('ta.subject_type', 'creator')
                ->where('c.user_id', $usuarioId)
                ->exists();
        }, sensible: true);
    }
}
