<?php

declare(strict_types=1);

namespace App\Modules\Creator\Services;

use App\Modules\Core\Services\Terminos;
use App\Shared\Config\Aviso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quién tiene que volver a aceptar los términos, y desde cuándo (9.19b).
 *
 * ### Por qué existe (`T-74`)
 *
 * En `9.19` esto vivía dentro de `Core\Services\Terminos`, que leía
 * `creators.activated_at` y recorría `creators` para contar. Funcionaba, y
 * estaba mal: `deptrac.yaml` dice `Core: [Framework, Shared]`, y una consulta a
 * la tabla de otro módulo es una frontera rota que **`deptrac` no ve**, porque
 * no hay ninguna clase importada.
 *
 * Es la trampa contra la que avisa la cabecera del `Vigilante` de `9.15`, y la
 * cometí una iteración después de escribirla. Se corrige aquí: `Terminos` se
 * queda con la aritmética —que no sabe qué es un creador— y esta clase, que sí
 * puede saberlo, aporta los dos datos de los que depende la respuesta.
 *
 * ### El reloj de cada uno
 *
 * `activated_at` puede ser nulo —un creador que nunca llegó a activarse— y
 * entonces vale su fecha de alta. Nunca `null`: una fecha ausente dejaría a
 * `Terminos` decidiendo con lo que no tiene.
 */
final class Reaceptacion
{
    /**
     * En qué situación está este creador respecto de los términos vigentes.
     *
     * @return array{estado: string, version: ?object, desde: ?string, limite: ?string,
     *               finLectura: ?string, dias: int}
     */
    public static function de(int $creadorId, ?string $hoy = null): array
    {
        $creador = DB::table('creators')->where('id', $creadorId)
            ->first(['activated_at', 'created_at']);

        return Terminos::estadoSegun(
            Terminos::aceptadaPor('creator', $creadorId),
            self::desdeCuandoLeAplica($creador, $hoy),
            $hoy,
        );
    }

    /**
     * Cuántos creadores activos hay en cada estado, para el panel de `9.17b`.
     *
     * ### Una consulta, no una por creador
     *
     * La primera versión llamaba a `estadoDe()` dentro de un `foreach` sobre
     * todos los creadores: con doscientos creadores, doscientas consultas cada
     * vez que alguien abre el panel de configuración. Aquí se traen de una vez
     * los que **no** han aceptado —que son los únicos que pueden estar en algún
     * estado— y el reparto se hace en memoria.
     *
     * @return array{pendientes: int, solo_lectura: int, bloqueados: int}
     */
    public static function recuento(?string $hoy = null): array
    {
        $recuento = ['pendientes' => 0, 'solo_lectura' => 0, 'bloqueados' => 0];

        if (Terminos::vigente(Terminos::codigo()) === null) {
            return $recuento;
        }

        $valen = Terminos::versionesQueValen(Terminos::codigo());

        $sinAceptar = DB::table('creators as c')
            ->where('c.status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('terms_acceptances as ta')
                ->whereColumn('ta.subject_id', 'c.id')
                ->where('ta.subject_type', 'creator')
                ->whereIn('ta.terms_version_id', $valen))
            ->get(['c.id', 'c.activated_at', 'c.created_at']);

        foreach ($sinAceptar as $creador) {
            // `aceptada: false` porque la consulta ya los dejo fuera. Pasarlo
            // como dato en vez de volver a preguntarlo es lo que convierte N
            // consultas en una.
            $estado = Terminos::estadoSegun(false, self::desdeCuandoLeAplica($creador, $hoy), $hoy);

            match ($estado['estado']) {
                Terminos::PENDIENTE => $recuento['pendientes']++,
                Terminos::SOLO_LECTURA => $recuento['solo_lectura']++,
                Terminos::BLOQUEADO => $recuento['bloqueados']++,
                default => null,
            };
        }

        return $recuento;
    }

    /**
     * Los avisos del área «Creadores» del panel de configuración.
     *
     * @return list<Aviso>
     */
    public static function avisos(): array
    {
        $recuento = self::recuento();
        $avisos = [];

        if ($recuento['bloqueados'] > 0) {
            $avisos[] = Aviso::rojo(sprintf(
                '%d %s entrar hasta que acepten los términos vigentes.',
                $recuento['bloqueados'],
                $recuento['bloqueados'] === 1 ? 'creador no puede' : 'creadores no pueden',
            ));
        }

        if ($recuento['solo_lectura'] > 0) {
            $avisos[] = Aviso::rojo(sprintf(
                '%d %s en sólo lectura por no haber aceptado a tiempo.',
                $recuento['solo_lectura'],
                $recuento['solo_lectura'] === 1 ? 'creador está' : 'creadores están',
            ));
        }

        if ($recuento['pendientes'] > 0) {
            $avisos[] = Aviso::ambar(sprintf(
                '%d %s la versión vigente, y siguen dentro de plazo.',
                $recuento['pendientes'],
                $recuento['pendientes'] === 1
                    ? 'creador todavía no ha aceptado'
                    : 'creadores todavía no han aceptado',
            ));
        }

        return $avisos;
    }

    /**
     * Los creadores activos con cuenta y correo, para avisarles (9.19b).
     *
     * @return Collection<int, \stdClass>
     */
    public static function aQuienesAvisar(): Collection
    {
        return DB::table('creators as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.status', 'active')
            ->whereNotNull('u.email')
            ->get(['c.id', 'c.display_name', 'u.email', 'u.locale']);
    }

    /**
     * Desde cuándo le aplica a este creador, con la fecha que de verdad tiene.
     *
     * `activated_at` puede ser nulo; entonces vale su alta. Si no hay ni ficha
     * —no debería pasar, pero el `first()` puede volver vacío— vale hoy, que es
     * el valor más benévolo: nadie nace bloqueado por una fila que falta.
     */
    private static function desdeCuandoLeAplica(?object $creador, ?string $hoy): string
    {
        $fecha = (string) ($creador->activated_at ?? $creador->created_at ?? '');

        return $fecha === ''
            ? ($hoy ?? now()->toDateString())
            : mb_substr($fecha, 0, 10);
    }
}
