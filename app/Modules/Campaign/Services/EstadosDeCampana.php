<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

/**
 * Qué transición de estado es legal, y quién puede hacerla (7.1).
 *
 * ### El hueco que cierra
 *
 * `ck_camp_status` admite ocho estados y **no dice nada de cómo se pasa de uno
 * a otro**. Un `CHECK` no puede: sólo ve la fila nueva, no de dónde venía. Así
 * que sin esto, `UPDATE campaigns SET status='completed'` sobre un borrador es
 * perfectamente válido para la base.
 *
 * Eso importa porque de estos estados cuelgan cosas que no se deshacen:
 * `confirmed_at` congela la sociedad que factura (`BR-LE-002`), y a partir de
 * `recruiting` empiezan a comprometerse cupos y precios con creadores reales.
 *
 * ### El grafo, y por qué NO es una lista de estados «siguientes»
 *
 * Cada transición lleva **quién puede hacerla**. La misma flecha
 * `pending_approval → approved` es la que fija el ingreso comprometido y la
 * sociedad emisora: es una decisión de dinero, y por eso la firma finanzas y no
 * quien negoció el precio. Es la misma separación que `DEC-044` impone en la
 * base para los perfiles fiscales y los medios de pago.
 *
 * Modelar sólo «a dónde puede ir» y dejar el permiso en el controlador
 * garantiza que la próxima pantalla que alguien escriba se olvide de la mitad.
 *
 * ### `cancelled` sale de casi todas partes, y de `completed` no
 *
 * Una campaña se puede cancelar mientras esté viva. Lo que no se puede es
 * cancelar una **terminada**: eso no es cancelar, es reescribir la historia, y
 * cuando haya facturas colgando de ella (`BR-LE-001`) sería negar un documento
 * emitido.
 */
final class EstadosDeCampana
{
    public const BORRADOR = 'draft';

    public const EN_APROBACION = 'pending_approval';

    public const APROBADA = 'approved';

    public const RECLUTANDO = 'recruiting';

    public const EN_CURSO = 'in_progress';

    public const EN_REVISION = 'in_review';

    public const TERMINADA = 'completed';

    public const CANCELADA = 'cancelled';

    /**
     * Estados en los que la campaña **todavía se está escribiendo**.
     *
     * Es el mismo conjunto que `ck_camp_billing_entity` deja pasar sin sociedad
     * y que `ck_camp_confirmed` deja pasar sin `confirmed_at`. Se escribe una vez
     * y se usa en los tres sitios: tres listas iguales acaban siendo tres listas
     * distintas.
     *
     * @var list<string>
     */
    public const INICIALES = [self::BORRADOR, self::EN_APROBACION, self::CANCELADA];

    /**
     * Qué se puede hacer desde cada estado, y con qué permiso.
     *
     * @var array<string, array<string, string>> origen => [destino => permiso]
     */
    private const GRAFO = [
        self::BORRADOR => [
            self::EN_APROBACION => 'campaign.manage',
            self::CANCELADA => 'campaign.manage',
        ],
        self::EN_APROBACION => [
            // Aquí se fija el ingreso comprometido y la sociedad que factura.
            // Lo firma finanzas, no quien negoció el precio (decisión de
            // negocio, 2026-08-25).
            self::APROBADA => 'campaign.approve',
            // Devolver a borrador NO es aprobar: lo puede hacer quien la monta.
            self::BORRADOR => 'campaign.manage',
            self::CANCELADA => 'campaign.manage',
        ],
        self::APROBADA => [
            self::RECLUTANDO => 'campaign.manage',
            self::CANCELADA => 'campaign.manage',
        ],
        self::RECLUTANDO => [
            self::EN_CURSO => 'campaign.manage',
            self::CANCELADA => 'campaign.manage',
        ],
        self::EN_CURSO => [
            self::EN_REVISION => 'campaign.manage',
            self::CANCELADA => 'campaign.manage',
        ],
        self::EN_REVISION => [
            self::TERMINADA => 'campaign.manage',
            self::CANCELADA => 'campaign.manage',
        ],
        // Terminales. Una campaña terminada no se cancela: eso seria negar
        // documentos ya emitidos.
        self::TERMINADA => [],
        self::CANCELADA => [],
    ];

    /**
     * Nombres para la pantalla. En español porque los lee un operador; el valor
     * guardado es el inglés del esquema y no se traduce en la base.
     *
     * @var array<string, string>
     */
    public const NOMBRES = [
        self::BORRADOR => 'Borrador',
        self::EN_APROBACION => 'En aprobación',
        self::APROBADA => 'Aprobada',
        self::RECLUTANDO => 'Reclutando',
        self::EN_CURSO => 'En curso',
        self::EN_REVISION => 'En revisión',
        self::TERMINADA => 'Terminada',
        self::CANCELADA => 'Cancelada',
    ];

    /**
     * Los estados que fijan `confirmed_at`.
     *
     * `ck_camp_confirmed` lo exige a partir de `approved`. Se deriva de
     * `INICIALES` en vez de escribirse otra vez, porque son el complemento
     * exacto: o la campaña está todavía escribiéndose, o está confirmada.
     *
     * @return list<string>
     */
    public static function confirmados(): array
    {
        return array_values(array_diff(array_keys(self::GRAFO), self::INICIALES));
    }

    /** ¿Existe esta transición? */
    public static function permitida(string $desde, string $hasta): bool
    {
        return isset(self::GRAFO[$desde][$hasta]);
    }

    /** El permiso que exige una transición, o `null` si la transición no existe. */
    public static function permiso(string $desde, string $hasta): ?string
    {
        return self::GRAFO[$desde][$hasta] ?? null;
    }

    /**
     * A dónde se puede ir desde aquí.
     *
     * @return array<string, string> destino => permiso
     */
    public static function desde(string $estado): array
    {
        return self::GRAFO[$estado] ?? [];
    }

    /**
     * Por qué NO se puede, con palabras.
     *
     * Devuelve `null` cuando sí se puede. Que el mismo sitio que decide sea el
     * que explica es deliberado: un mensaje escrito en el controlador se
     * desincroniza del grafo en cuanto alguien añade un estado.
     */
    public static function veto(string $desde, string $hasta): ?string
    {
        if (self::permitida($desde, $hasta)) {
            return null;
        }

        $nombre = static fn (string $e): string => self::NOMBRES[$e] ?? $e;

        if (self::desde($desde) === []) {
            return sprintf(
                'Una campana %s ya no cambia de estado. Si hay que rehacerla, se crea otra: '
                .'reescribir una campana terminada seria negar los documentos que cuelgan de ella.',
                mb_strtolower($nombre($desde)),
            );
        }

        $posibles = array_map($nombre, array_keys(self::desde($desde)));

        return sprintf(
            'Desde «%s» no se puede pasar a «%s». Lo que se puede hacer ahora es: %s.',
            $nombre($desde),
            $nombre($hasta),
            implode(', ', $posibles),
        );
    }
}
