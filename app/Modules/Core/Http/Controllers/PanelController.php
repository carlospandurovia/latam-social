<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Alertas;
use App\Modules\Core\Services\FiltrosDeResumen;
use App\Modules\Core\Services\Resumen;
use App\Modules\Core\Services\Semaforo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La portada del backoffice: el centro de control.
 *
 * ### Desde `5.9` hay usuarios que NO son del equipo
 *
 * Hasta esa iteración todas las cuentas eran internas, así que enseñar aquí
 * cuántos creadores, clientes y campañas hay era enseñárselo al equipo. `5.9`
 * crea la primera cuenta de un creador, y esa misma pantalla pasaría a contarle
 * a un creador el tamaño de nuestra cartera de clientes.
 *
 * Eso choca de frente con una de las reglas no negociables del proyecto —*«nunca
 * mostrar información interna a clientes o creadores»*— y es la clase de fuga
 * que no falla, no avisa y no se nota hasta que alguien lo comenta por ahí.
 *
 * Así que la portada se bifurca **por tipo de usuario**, no por permiso: un
 * creador no tiene ninguno, y una comprobación de permisos que devuelve «no» a
 * todo dejaría una pantalla vacía y desconcertante en vez de una que explica
 * dónde está.
 *
 * ### `D-1`: lo técnico se fue a Configuración
 *
 * El motor, si aplica `CHECK`, cuántas tablas tiene el esquema y qué sociedad
 * factura en cada país vive en `Configuración → Sistema`. Quien abre el panel
 * por la mañana quiere saber qué hacer, no con qué versión de MySQL se habla.
 *
 * ### `D-2`: los filtros mandan sobre TODO lo que se pinte aquí
 *
 * El recorte se construye una vez y se le pasa entero a cada indicador. Es la
 * decisión que impide el fallo más caro de un panel: dos números en la misma
 * pantalla calculados sobre recortes distintos. Nadie los ve discrepar; se ven
 * raros, y todo el panel deja de creerse.
 *
 * ### `D-4`: cinco indicadores de operación
 *
 * Flujo y existencias, con la diferencia dicha en el tooltip de cada uno. Los de
 * comercial y finanzas llegan en `D-7` y `D-8`; el hueco lo dice la propia
 * pantalla para que no parezca un fallo.
 */
final class PanelController
{
    public function __invoke(Request $peticion): View
    {
        $usuario = Auth::user();

        if (($usuario->user_type ?? 'internal') !== 'internal') {
            return view('panel.espera', [
                'nombre' => (string) ($usuario->name ?? ''),
                'tipo' => (string) ($usuario->user_type ?? ''),
            ]);
        }

        $filtros = FiltrosDeResumen::desdePeticion($peticion);
        $nivel = $this->nivel($peticion);

        return view('panel.inicio', [
            // D-3: lo primero de la pantalla, y sin recorte. Va con el usuario
            // porque cada alerta declara el permiso con el que se arregla: si no
            // puedes arreglarlo, no lo ves (`9.17b`, `BR-SEC-001`).
            'alertas' => Alertas::para($usuario),
            'filtros' => $filtros,
            // La lista de periodos sale del controlador y no de la plantilla:
            // una vista que lee una constante de una clase de negocio es logica
            // en la plantilla, que es justo lo que `docs/08` prohibe.
            'periodos' => FiltrosDeResumen::NOMBRES,
            'opciones' => Resumen::opciones(),
            'actualizado' => Resumen::actualizadoEn(),
            'kpis' => Resumen::operacion($filtros),
            // D-7: comercial. Los cuatro son flujo, y el reparto de prospectos
            // es una distribucion --no un embudo--: no hay historia de estados.
            'comercial' => Resumen::comercial($filtros),
            'prospectos' => Resumen::prospectosPorEstado($filtros),
            // D-8: el dinero, detras de `finance.view` y por moneda. Igual que
            // el semaforo: si no puede verlo, no se consulta siquiera.
            'financiero' => $usuario->can('finance.view')
                ? Resumen::financiero($filtros)
                : null,
            // D-14: publicaciones y permanencia, detras de `campaign.view`
            // --que tiene tambien `content_reviewer`, medido en el sembrador,
            // que es justo quien hace ese trabajo--.
            'publicaciones' => $usuario->can('campaign.view')
                ? Resumen::publicaciones($filtros)
                : null,
            // D-13: el margen, detras de `campaign.view_margin` --que NO tiene
            // quien lleva campanas (`DEC-181`)--. Como el resto: sin el
            // permiso no se pinta Y no se consulta. Un `@can` en la plantilla
            // sobre datos ya traidos es una fuga esperando a que alguien borre
            // el `@can`.
            'margen' => $usuario->can('campaign.view_margin')
                ? Resumen::margen($filtros)
                : null,
            // D-9: la red de creadores, detras de `creator.view`.
            'creadores' => $usuario->can('creator.view')
                ? Resumen::creadores($filtros)
                : null,
            'red' => $usuario->can('creator.view')
                ? Resumen::redDeCreadores($filtros)
                : null,
            // D-10: la actividad, detras de `audit.view` --el MISMO permiso que
            // la pantalla de la bitacora--. Repartirla por accion seria un
            // segundo mapa de permisos en paralelo al de verdad.
            'actividad' => $usuario->can('audit.view')
                ? Resumen::actividad($filtros)
                : null,
            // D-5: los ocho estados y el embudo. Comparten el mismo recorte que
            // los KPIs; el bloque de alertas es el unico que no se recorta.
            'estados' => Resumen::porEstado($filtros),
            'embudo' => Resumen::embudo($filtros),
            // D-6: el semaforo. Es el unico bloque con un filtro propio --por
            // color-- porque es el unico donde el filtro no cambia el numero,
            // solo que filas se enseñan.
            'semaforo' => $nivel,
            'seguimiento' => $usuario->can('campaign.view')
                ? Semaforo::campanas($filtros, $nivel)
                : null,
            'tarjetas' => $this->tarjetas(),
        ]);
    }

    /**
     * Qué color de semáforo se está mirando.
     *
     * Un valor que no existe vuelve a «todas» en vez de reventar: esta pantalla
     * se comparte por enlace, igual que los filtros de `D-2`.
     */
    private function nivel(Request $peticion): string
    {
        $nivel = (string) $peticion->query('semaforo', Semaforo::TODAS);

        return array_key_exists($nivel, Semaforo::NIVELES) ? $nivel : Semaforo::TODAS;
    }

    /** @return list<array{titulo: string, valor: int, nota: string, ruta: string}> */
    private function tarjetas(): array
    {
        return [
            ['titulo' => 'Creadores', 'valor' => $this->cuenta('creators'),
                'nota' => 'registrados', 'ruta' => 'creadores.index'],
            ['titulo' => 'Clientes', 'valor' => $this->cuenta('client_organizations'),
                'nota' => 'grupos', 'ruta' => 'clientes.index'],
            ['titulo' => 'Campañas', 'valor' => $this->cuenta('campaigns'),
                'nota' => 'creadas', 'ruta' => 'campanas.index'],
        ];
    }

    /**
     * Cuenta si la tabla existe.
     *
     * La guarda no es paranoia: durante un despliegue a medias --migraciones a
     * medio aplicar-- la portada del backoffice es justo la pantalla que alguien
     * abre para ver que pasa, y un 500 ahi no dice nada util.
     */
    private function cuenta(string $tabla): int
    {
        return DB::getSchemaBuilder()->hasTable($tabla) ? DB::table($tabla)->count() : 0;
    }
}
