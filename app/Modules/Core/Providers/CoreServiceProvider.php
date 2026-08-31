<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\PublicarTerminosCommand;
use App\Modules\Core\Console\TraerTiposDeCambioCommand;
use App\Modules\Core\Services\Cobertura;
use App\Modules\Core\Services\CredencialFuente;
use App\Modules\Core\Services\Decolecta;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Terminos;
use App\Modules\Core\Services\TraidaDeCambio;
use App\Shared\Config\Aviso;
use App\Shared\Config\Preparacion;
use App\Shared\Files\Vigilante;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Core aporta al framework.
 *
 * Existe por una razón de arquitectura y no de comodidad: `ModuleServiceProvider`
 * vive en `App\Shared`, y en `deptrac.yaml` la capa `Shared` no puede depender
 * de ningún módulo. Registrar ahí `PublicarTerminosCommand` habría hecho que
 * Shared importara Core y CI lo habría rechazado, con razón: si Shared conoce a
 * los módulos, deja de ser compartido y pasa a ser el centro de un grafo con
 * ciclos.
 *
 * Cada módulo que necesite registrar comandos, eventos o vistas tendrá el suyo.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublicarTerminosCommand::class,
                TraerTiposDeCambioCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // 9.17: la marca en las plantillas. Un compositor y no `View::share()`:
        // `share` se evalua en CADA peticion, incluidas las que devuelven un
        // archivo o un redirect y no pintan nada, y eso es una consulta por
        // peticion para nadie. El compositor solo corre si la plantilla se usa.
        //
        // Las tres son las que llevaban «LATAM Social» y el favicon escritos.
        //
        // `errors::403` **con dos puntos dobles** y no solo `errors.403`: el
        // manejador de excepciones de Laravel resuelve la pantalla de error por
        // el espacio de nombres `errors::`, no por la ruta de la vista. Con solo
        // `errors.403` registrado, el compositor no corria, `$marca` no existia
        // y la pantalla de «sin permiso» respondia **500 en vez de 403**. Lo
        // encontro `MarcaPlataformaTest`, que comprobaba un 403 y recibio un 500.
        View::composer(['layouts.panel', 'layouts.acceso', 'errors.403', 'errors::403'],
            static function (\Illuminate\View\View $vista): void {
                $vista->with('marca', Marca::datos());
            });

        // 9.17: el logotipo y el favicon de la plataforma.
        //
        // Su puerta de verdad es publica --sale en la pantalla de acceso, que se
        // ve sin sesion-- y esta regla existe para el OTRO camino: `9.15` niega
        // por omision, asi que sin ella un archivo de marca abierto por
        // `/archivos/{uuid}` daria 403 y nadie sabria por que. Lo ve cualquiera
        // que haya entrado, porque ya lo ha visto en la barra lateral.
        //
        // No es sensible: anotar en la bitacora cada vez que alguien carga el
        // logotipo es anotar cada carga de cada pantalla.
        Vigilante::regla(Marca::PROPOSITO, static fn (object $archivo, int $usuarioId): bool => $usuarioId > 0);

        $this->registrarPreparacion();

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $planificador */
            $planificador = $this->app->make(Schedule::class);

            // A las 05:30, antes que la vigilancia de permanencia de las 06:00:
            // si algun dia algo del ciclo de la manana necesita convertir, las
            // tasas del dia ya estan. SUNAT publica de madrugada.
            //
            // `withoutOverlapping` porque una corrida lenta --la API tarda-- no
            // debe solaparse con la siguiente: dos procesos pidiendo los mismos
            // tres dias es gastar el doble de cuota para no traer nada nuevo.
            $planificador->command('cambio:traer')
                ->dailyAt('05:30')
                ->withoutOverlapping()
                // Al log del planificador, como el resto: «.corrio el cron?»
                // tiene que poder contestarse mirando un sitio. Y ademas queda
                // en `fx_fetch_runs`, que es lo que la pantalla ensena.
                ->appendOutputTo(storage_path('logs/planificador.log'));
        });
    }

    /**
     * Las cuatro áreas de configuración que son de Core (9.17b).
     *
     * Cada una declara **el permiso con el que se arregla**, no el que hace
     * falta para abrir el panel: si no puedes arreglarlo, no lo ves, y así
     * ningún aviso lleva a un 403.
     *
     * El orden es el del primer día de una instalación: sin marca la
     * plataforma se enseña con los valores de partida, sin términos no se activa
     * un creador, sin cobertura no se le puede facturar a un cliente, y los
     * tipos de cambio se tocan dos veces al año.
     */
    private function registrarPreparacion(): void
    {
        Preparacion::area('Marca', 'brand.manage', 'marca.index',
            static fn (): array => Aviso::desdeArrays(Marca::avisos()), orden: 10);

        Preparacion::area('Términos', 'legal_entity.manage', 'terminos.index',
            static fn (): array => Aviso::desdeArrays(Terminos::avisos()), orden: 20);

        // `BR-LE-004`: un pais con clientes que no puede facturar nadie no es un
        // detalle de configuracion, es una factura que no se puede emitir. Rojo.
        //
        // Y las sociedades sin representante legal en ambar: hoy no rompe nada,
        // pero es un dato que el comprobante electronico va a pedir (`9.9`), y
        // enterarse el dia de la primera factura es tarde.
        Preparacion::area('Entidades legales', 'legal_entity.manage', 'entidades.index',
            static function (): array {
                $avisos = [];
                $descubiertos = Cobertura::paisesDescubiertos(now()->toDateString());

                if ($descubiertos->isNotEmpty()) {
                    $avisos[] = Aviso::rojo(sprintf(
                        'Hay %d %s con clientes que hoy no puede facturar ninguna sociedad: %s. '
                        .'Mientras siga así, a esos clientes no se les puede emitir un comprobante.',
                        $descubiertos->count(),
                        $descubiertos->count() === 1 ? 'país' : 'países',
                        $descubiertos->pluck('name')->implode(', '),
                    ));
                }

                $sinRepresentante = DB::table('legal_entities')
                    ->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('legal_representative')
                        ->orWhere('legal_representative', ''))
                    ->pluck('code');

                if ($sinRepresentante->isNotEmpty()) {
                    $avisos[] = Aviso::ambar(sprintf(
                        'Sin representante legal: %s. Hoy no impide nada; el comprobante '
                        .'electrónico lo va a pedir.',
                        $sinRepresentante->implode(', '),
                    ));
                }

                return $avisos;
            }, orden: 30);

        // Las dos preguntas de esta area son distintas y las dos importan: si
        // HAY credencial --sin ella el cron no trae nada-- y si la ultima
        // traida hizo algo --con credencial y sin cron, tampoco entra nada--.
        // `loQueHayQueMirar()` ya contesta la segunda y devuelve `null` cuando
        // no hay nada que mirar, que es como debe ser: una pantalla que siempre
        // tiene un aviso es una pantalla cuyos avisos nadie lee.
        Preparacion::area('Tipos de cambio', 'fx.manage', 'cambio.index',
            static function (): array {
                $avisos = [];
                $fuente = Decolecta::FUENTE;

                if (CredencialFuente::estado($fuente)['origen'] === CredencialFuente::NINGUNA) {
                    $avisos[] = Aviso::rojo(
                        'No hay credencial para la fuente oficial de tipos de cambio. Sin ella no '
                        .'entra ninguna tasa nueva, y convertir con una tasa vieja es convertir mal.',
                    );
                }

                if (($mirar = TraidaDeCambio::loQueHayQueMirar($fuente)) !== null) {
                    $avisos[] = Aviso::ambar($mirar);
                }

                return $avisos;
            }, orden: 40);
    }
}
