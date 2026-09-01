<?php

declare(strict_types=1);

namespace App\Modules\Communication\Providers;

use App\Modules\Communication\Console\ProbarCorreoCommand;
use App\Modules\Communication\Console\PublicarPlantillaCommand;
use App\Modules\Communication\Listeners\AvisarCambioSensible;
use App\Modules\Communication\Listeners\EnviarCorreoPedido;
use App\Modules\Communication\Services\CuentaDeCorreo;
use App\Shared\Config\Aviso;
use App\Shared\Config\Pestanas;
use App\Shared\Config\Preparacion;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Lo que el módulo Communication aporta al framework (4.9, 4.13).
 *
 * ### El oyente se registra AQUÍ, y eso es lo que mantiene el grafo acíclico
 *
 * Creator levanta el evento y **no sabe que alguien escucha**. Communication
 * escucha y **no sabe quién lo levantó**: le llega un nombre y un array. Así
 * `deptrac.yaml` sigue en verde —`Communication: [Framework, Shared, Core,
 * Identity]`, sin Creator— y un fallo del correo no puede tumbar la captura de
 * un dato fiscal.
 */
final class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublicarPlantillaCommand::class,
                ProbarCorreoCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Event::listen(EventoOcurrido::class, AvisarCambioSensible::class);

        // `5.9`, `4.1` y `7.6`: los correos cuyo contenido NO se puede guardar
        // --llevan un token en claro dentro-- no pasan por `EventoOcurrido`,
        // que persiste su payload. Van por `CorreoPedido`, que vive en `Shared`
        // para que lo pueda levantar cualquier modulo: en `5.9` vivio una
        // iteracion dentro de Identity y funciono de milagro --Identity esta en
        // la lista de arriba--; Campaign, que lo necesita igual en `7.6`, no.
        Event::listen(CorreoPedido::class, EnviarCorreoPedido::class);

        // 9.17g: la cuenta guardada manda sobre el `.env`. Se aplica al
        // arrancar y sólo si hay tabla y conexión activa: durante `migrate` la
        // tabla todavía no existe, y una excepción aquí deja la aplicación sin
        // arrancar --que es peor que quedarse con la del entorno--.
        try {
            CuentaDeCorreo::aplicar();
        } catch (\Throwable) {
            // Se sigue con la del `.env`. El panel lo dice en rojo.
        }

        Pestanas::registrar(
            'correo',
            'Servidor de correo',
            datos: static fn (): array => [
                // `guardada()` y no `vigente()` (9.17i): la tarjeta tiene que
                // poder ensenar la cuenta APAGADA, o no habria como encenderla.
                'cuenta' => CuentaDeCorreo::guardada(),
                'efecto' => CuentaDeCorreo::enEfecto(),
                'cifrados' => CuentaDeCorreo::CIFRADOS,
                'puertos' => CuentaDeCorreo::PUERTOS,
                // 9.17i: los avisos van DENTRO de su tarjeta. Antes se pintaban
                // todos juntos arriba y habia que adivinar a cual se referian.
                'avisosCorreo' => CuentaDeCorreo::avisos(),
            ],
            avisos: static fn (): array => CuentaDeCorreo::avisos(),
            orden: 30,
        );

        $this->registrarPreparacion();
    }

    /**
     * El área de correo del panel de configuración (9.17b).
     *
     * ### Por qué el transporte va en ROJO
     *
     * `config('mail.default')` vale `log` mientras nadie ponga una cuenta SMTP
     * (`Q-20`), y con ese valor **el correo no sale de la máquina**: se escribe
     * en `storage/logs`. La aplicación no falla, la bitácora dice «enviado» y el
     * creador no recibe su enlace de alta. Es el modo de fallo más caro que hay
     * en el sistema, porque **no se nota desde dentro**: sólo lo nota quien está
     * esperando un correo que nunca llega.
     *
     * En una instalación nueva esto es lo primero que hay que arreglar, y por
     * eso el área va la primera del panel.
     */
    private function registrarPreparacion(): void
    {
        // Sin permiso propio: `comms.view` abre la bandeja de correos enviados,
        // que es otra cosa --lo que salio-- y la tiene mas gente. Aqui se dice
        // si el correo esta configurado, y eso lo ve quien pueda abrir el panel.
        Preparacion::area('Correo', null, 'correos.index', static function (): array {
            // 9.17g: los avisos de la CUENTA salen de su servicio, que sabe si
            // manda la base o el `.env`. Leer `config('mail.default')` aqui
            // habria dicho «esta en log» aunque hubiera una cuenta guardada.
            $avisos = CuentaDeCorreo::avisos();

            // Un fallo suelto es la vida normal --un buzon lleno, una direccion
            // mal escrita--. Lo que importa es que haya fallos SIN MIRAR: el
            // modo de fallo real de este proyecto es «al creador no le llego su
            // enlace» descubierto por el creador, no por nosotros.
            $fallidos = DB::table('email_log')->where('status', 'failed')
                ->where('queued_at', '>=', now()->subDays(7))->count();

            if ($fallidos > 0) {
                $avisos[] = Aviso::ambar(sprintf(
                    '%d %s no se pudieron enviar en los últimos siete días. Revíselos antes de '
                    .'que alguien diga que no le llegó nada.',
                    $fallidos,
                    $fallidos === 1 ? 'correo' : 'correos',
                ));
            }

            return $avisos;
        }, orden: 5, grupo: Preparacion::CONEXIONES);
    }
}
