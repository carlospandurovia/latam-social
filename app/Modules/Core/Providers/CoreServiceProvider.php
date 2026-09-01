<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Console\PublicarTerminosCommand;
use App\Modules\Core\Console\TraerTiposDeCambioCommand;
use App\Modules\Core\Http\Controllers\IntegracionesController;
use App\Modules\Core\Services\Certificados;
use App\Modules\Core\Services\Cobertura;
use App\Modules\Core\Services\Correlativos;
use App\Modules\Core\Services\CredencialFuente;
use App\Modules\Core\Services\Decolecta;
use App\Modules\Core\Services\Impuestos;
use App\Modules\Core\Services\Integraciones;
use App\Modules\Core\Services\Landing;
use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Politica;
use App\Modules\Core\Services\Terminos;
use App\Modules\Core\Services\TraidaDeCambio;
use App\Shared\Config\Aviso;
use App\Shared\Config\Pestanas;
use App\Shared\Config\Preparacion;
use App\Shared\Files\Vigilante;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // 9.21b: `layouts.publico` entra aqui el mismo dia que existe: la marca
        // de la calle es la misma que la de dentro, o son dos empresas.
        //
        // Un compositor sobre la PLANTILLA no alcanza a la vista que la extiende
        // --la portada salio con un 500 diciendo «Undefined variable $marca»--.
        // La tentacion era un comodin `publico.*`, y `verificar-pantallas.py` lo
        // rechazo con razon: esconde de quien lee el controlador que la vista
        // necesita ese dato. `publico.landing` la recibe de su controlador; aqui
        // se queda solo `publico.gracias`, que no tiene mas que la plantilla.
        View::composer(['layouts.panel', 'layouts.acceso', 'layouts.publico', 'publico.gracias',
            'errors.403', 'errors::403'],
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

        $this->registrarPestanas();
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
    /**
     * Las pestañas de Integraciones que alimenta este módulo (9.17g).
     *
     * La del correo no está aquí: la registra `Communication`, que es de quien
     * son esos parámetros. `Core` no puede depender de `Communication`.
     */
    private function registrarPestanas(): void
    {
        Pestanas::registrar(
            'fel',
            'Facturación electrónica',
            datos: static fn (): array => IntegracionesController::datosDeFacturacion(),
            avisos: static fn (): array => array_merge(
                Integraciones::avisos(),
                Certificados::avisos(),
                Correlativos::avisos(),
            ),
            orden: 10,
        );

        // 9.17h se la lleva a esta pestaña de verdad. Hoy dice dónde vive.
        Pestanas::registrar(
            'fx',
            'Tipos de cambio',
            datos: static fn (): array => [],
            avisos: static fn (): array => [],
            orden: 20,
        );
    }

    private function registrarPreparacion(): void
    {
        Preparacion::area('Marca', 'brand.manage', 'marca.index',
            static fn (): array => Aviso::desdeArrays(Marca::avisos()), orden: 10,
            grupo: Preparacion::IDENTIDAD);

        // 9.18: delante de los terminos porque de estos dos numeros sale el
        // costo de TODO lo que se pacte, y una instalacion sin ellos pacta
        // netos que no retienen nada.
        Preparacion::area('Política de precios', 'pricing.manage', 'politica.index',
            static fn (): array => Politica::avisos(), orden: 15,
            grupo: Preparacion::FISCAL);

        Preparacion::area('Términos', 'legal_entity.manage', 'terminos.index',
            static fn (): array => Aviso::desdeArrays(Terminos::avisos()), orden: 20,
            grupo: Preparacion::IDENTIDAD);

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

                // 9.17c: lo que el comprobante electronico va a pedir y hoy
                // no esta. `requires_tax_location` lo declara el PAIS: rojo si
                // ese pais lo exige, y ni se pregunta si no lo declara.
                $sinLocalidad = DB::table('legal_entities as le')
                    ->join('countries as c', 'c.id', '=', 'le.country_id')
                    ->where('le.status', 'active')
                    ->where('c.requires_tax_location', 1)
                    ->whereNull('le.tax_location_code')
                    ->get(['le.code', 'c.tax_location_label']);

                if ($sinLocalidad->isNotEmpty()) {
                    $avisos[] = Aviso::rojo(sprintf(
                        'Sin %s: %s. Es un campo obligatorio del comprobante electrónico, así que '
                        .'ese dato falta antes de poder emitir la primera factura.',
                        mb_strtolower((string) $sinLocalidad->first()->tax_location_label),
                        $sinLocalidad->pluck('code')->implode(', '),
                    ));
                }

                // El sembrador escribe «Por completar» en la direccion y en la
                // ciudad porque las dos columnas son NOT NULL y no hay nada
                // verdadero que poner. Hasta hoy eso no lo decia nadie: la
                // sociedad parecia completa y el texto habria salido impreso en
                // una factura.
                $sinDireccion = DB::table('legal_entities')
                    ->where('status', 'active')
                    ->where(fn ($q) => $q->where('address_line1', 'Por completar')
                        ->orWhere('city', 'Por completar'))
                    ->pluck('code');

                if ($sinDireccion->isNotEmpty()) {
                    $avisos[] = Aviso::rojo(sprintf(
                        'El domicilio de %s todavía dice «Por completar»: es lo que sembró la '
                        .'instalación, y saldría impreso tal cual en el comprobante.',
                        $sinDireccion->implode(', '),
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
            }, orden: 30, grupo: Preparacion::FISCAL);

        // Las dos preguntas de esta area son distintas y las dos importan: si
        // HAY credencial --sin ella el cron no trae nada-- y si la ultima
        // traida hizo algo --con credencial y sin cron, tampoco entra nada--.
        // `loQueHayQueMirar()` ya contesta la segunda y devuelve `null` cuando
        // no hay nada que mirar, que es como debe ser: una pantalla que siempre
        // tiene un aviso es una pantalla cuyos avisos nadie lee.
        // 9.17d: delante de los tipos de cambio porque de aqui depende poder
        // facturar, y aquello se toca dos veces al ano.
        Preparacion::area('Integraciones', 'integration.manage', 'integraciones.index',
            // 9.17f: el area cubre lo que hay DENTRO de sus pestanas. Al
            // meter el certificado y las series en Integraciones dejaron de ser
            // areas sueltas, y sin esto sus avisos --«sin certificado», «sin
            // serie»-- habrian desaparecido del panel sin que nadie lo notara.
            static fn (): array => array_merge(
                Integraciones::avisos(),
                Certificados::avisos(),
                Correlativos::avisos(),
            ), orden: 35,
            grupo: Preparacion::CONEXIONES);

        // 9.12: detras de Integraciones y delante de los tipos de cambio. Sin
        // serie no se emite nada, y esa es la unica configuracion del sistema
        // que NO trae valor de partida: una serie se registra ante la
        // administracion tributaria y una inventada produce comprobantes
        // invalidos. Por eso avisa en rojo en vez de sembrarse (`DEC-190`).
        // 9.9a: con las otras fiscales y delante de las series, porque sin
        // tasa el impuesto de una factura saldria en cero sin que nadie lo
        // hubiera decidido.
        Preparacion::area('Impuestos', 'pricing.manage', 'impuestos.index',
            static fn (): array => Impuestos::avisos(), orden: 34,
            grupo: Preparacion::FISCAL);

        // 9.21b: lo que ve quien todavia no es cliente. Con Marca y Terminos
        // porque es la misma pregunta --como nos presentamos-- y lo toca la
        // misma persona.
        Preparacion::area('Portada pública', 'brand.manage', 'landing.index',
            static fn (): array => Landing::avisos(), orden: 12,
            grupo: Preparacion::IDENTIDAD);

        // 9.20: los catalogos eran seis entradas sueltas debajo de un titulo del
        // menu lateral, que es una etiqueta y no un sitio. Ahora son un area de
        // la configuracion --se tocan pocas veces y no son trabajo del dia-- con
        // su portada y su miga de pan.
        //
        // Lo que comprueba: que ninguna lista de la que tira el sistema se haya
        // quedado sin NADA activo. Un catalogo vacio no da error: da un
        // desplegable vacio, y eso se descubre cuando alguien no puede terminar
        // lo que estaba haciendo.
        Preparacion::area('Catálogos', 'catalog.view', 'catalogos.index',
            static function (): array {
                $vacios = [];

                foreach (['countries' => 'Países', 'currencies' => 'Monedas',
                    'platforms' => 'Redes sociales', 'content_formats' => 'Formatos',
                    'languages' => 'Idiomas', 'categories' => 'Categorías'] as $tabla => $nombre) {
                    if (Schema::hasTable($tabla) && DB::table($tabla)->where('is_active', 1)->doesntExist()) {
                        $vacios[] = $nombre;
                    }
                }

                return $vacios === [] ? [] : [Aviso::rojo(sprintf(
                    'Sin ninguna fila activa: %s. No da error: deja un desplegable vacío, y eso se '
                    .'descubre cuando alguien no puede terminar lo que estaba haciendo.',
                    implode(', ', $vacios),
                ))];
            }, orden: 60, grupo: Preparacion::CATALOGOS);

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
            }, orden: 40, grupo: Preparacion::FISCAL);
    }
}
