<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Creator\Services\CompletitudOperativa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La lista corta de una campaña (7.4).
 *
 * ### No hay tabla nueva
 *
 * La lista corta vive en `campaign_creators` con `status = 'shortlisted'`, que
 * es el valor **por omisión** de la columna desde la Fase 2. Estaba diseñada
 * para esto y nadie había escrito una fila todavía.
 *
 * Una tabla aparte habría obligado a mover la fila al invitar, y con ella el
 * mercado, la moneda y el historial. `campaign_creators` es el sitio donde la
 * participación **nace** y donde va a vivir hasta que la campaña termine: un
 * candidato descartado es una fila `cancelled`, no una fila borrada.
 *
 * ### El veto se revalida AQUÍ, no en el buscador
 *
 * Decisión de negocio (2026-08-25): el buscador enseña a todos los `active` y
 * marca lo que les falta; el veto real salta al añadir. Los motivos son dos:
 *
 * 1. Revalidar los seis requisitos de `BR-CREATOR-006` por candidato y por
 *    búsqueda es caro, y aquí se hace **una vez, sobre uno**.
 * 2. Un creador que desaparece de la búsqueda sin explicación parece un fallo
 *    del sistema. Uno que sale con «le falta el medio de pago» es una tarea.
 *
 * Y se revalidan **además** los filtros duros del buscador. No por desconfianza
 * del formulario: entre que la pantalla se pinta y alguien pulsa el botón, la
 * campaña puede haber perdido un mercado o el creador haberse bloqueado la
 * agenda. Una pantalla es una foto; el veto se decide con la fila.
 */
final class ListaCorta
{
    public const SHORTLISTED = 'shortlisted';

    /**
     * Quiénes están en la lista corta, con lo que hace falta para leerla.
     *
     * @return Collection<int, \stdClass>
     */
    public static function de(int $campanaId): Collection
    {
        return DB::table('campaign_creators as cc')
            ->join('creators as c', 'c.id', '=', 'cc.creator_id')
            ->leftJoin('campaign_markets as m', 'm.id', '=', 'cc.campaign_market_id')
            ->leftJoin('countries as p', 'p.id', '=', 'm.country_id')
            ->where('cc.campaign_id', $campanaId)
            ->orderBy('c.display_name')
            ->get([
                'cc.id', 'cc.uuid', 'cc.creator_id', 'cc.status', 'cc.campaign_market_id',
                'c.display_name', 'c.uuid as creador_uuid', 'p.name as mercado',
            ]);
    }

    /**
     * Por qué este creador **no** puede entrar en la lista corta, o `null`.
     *
     * Devuelve **todos** los motivos, no el primero: quien monta una campaña
     * prefiere una lista de tres cosas que arreglar a tres visitas.
     *
     * @return list<string>
     */
    public static function vetoParaAnadir(object $campana, int $creadorId): array
    {
        $motivos = [];

        // Primero la campana. Si esta cerrada no hay nada mas que mirar, y
        // decirlo junto a «al creador le falta el medio de pago» seria enviar al
        // operador a arreglar algo que no va a servir de nada.
        if ($campana->closed_at !== null) {
            return ['esta campana ya esta cerrada: no se le anaden creadores. '
                .'Si hay que sumar a alguien, es una campana nueva'];
        }

        $creador = DB::table('creators')->where('id', $creadorId)
            ->first(['id', 'display_name', 'status', 'country_id', 'birth_date', 'anonymized_at']);

        if ($creador === null || $creador->anonymized_at !== null) {
            return ['ese creador no existe o fue anonimizado'];
        }

        if ((string) $creador->status !== 'active') {
            $motivos[] = sprintf('el creador esta en «%s» y no activo: solo se invita a creadores activos',
                $creador->status);
        }

        // `BR-CREATOR-006` entera, la misma clase que decide la activacion. No
        // una copia: si un dia se anade un septimo requisito, este veto lo
        // hereda sin que nadie se acuerde de venir aqui.
        // Se recorre `revisar()` y no `pendientes()`: la segunda devuelve solo
        // los TITULOS, y aqui hace falta el detalle --que es lo que le dice al
        // operador que pedirle al creador-- y la regla que lo exige.
        foreach (CompletitudOperativa::revisar($creadorId) as $requisito) {
            if (!$requisito->cumple) {
                $motivos[] = 'le falta '.mb_strtolower($requisito->titulo).': '
                    .$requisito->detalle.' ('.$requisito->regla.')';
            }
        }

        $mercado = self::mercadoPara($campana, (int) $creador->country_id);

        if ($mercado === null) {
            $motivos[] = 'su pais no es un mercado de esta campana: anada el mercado primero, '
                .'o busque a alguien de los paises que la campana si cubre';
        }

        $edad = self::edadEn((string) $creador->birth_date, (string) $campana->starts_on);
        $minima = BuscadorDeCreadores::edadMinima($campana);

        if ($edad < $minima) {
            $motivos[] = sprintf(
                'el dia que empieza la campana tendra %d anos y se exigen %d (BR-CREATOR-012)',
                $edad, $minima,
            );
        }

        if (self::tieneRestriccionDeMarca($creadorId, (int) $campana->client_brand_id)) {
            $motivos[] = 'declaro por escrito que no trabaja con alguna categoria de esta marca '
                .'(BR-CAMPAIGN-007): invitarlo seria pedirle algo que ya dijo que no hace';
        }

        if (self::agendaBloqueada($creadorId, $campana)) {
            $motivos[] = 'tiene la agenda bloqueada durante las fechas de la campana';
        }

        return $motivos;
    }

    /**
     * Mete al creador en la lista corta.
     *
     * El mercado se **deriva del país del creador** y no se pide: es el único
     * que puede ser, y pedirlo sería pedirle al operador que repita un dato que
     * el sistema ya sabe. Se puede cambiar después si la campaña cubre varios
     * países y el creador va a trabajar para otro.
     *
     * `agreed_amount` nace en cero **a propósito**: el compromiso económico se
     * congela al aceptar (`BR-CREATOR-008`), no al meter a alguien en una lista.
     * Un candidato con importe es un acuerdo que nadie ha firmado.
     */
    public static function anadir(object $campana, int $creadorId): string
    {
        $creador = DB::table('creators')->where('id', $creadorId)->first(['id', 'country_id', 'payment_term_days']);
        $uuid = (string) Str::uuid();

        DB::table('campaign_creators')->insert([
            'uuid' => $uuid,
            'campaign_id' => $campana->id,
            'creator_id' => $creadorId,
            'campaign_market_id' => self::mercadoPara($campana, (int) $creador->country_id),
            'status' => self::SHORTLISTED,
            'agreed_amount' => 0,
            'currency_code' => $campana->currency_code,
            // El plazo de pago se copia de la ficha del creador AL ENTRAR, y no
            // se lee de ella despues. Es la misma logica que `billing_legal_entity_id`
            // en 7.1: dentro de un ano, «a cuantos dias se le pago» tiene que
            // responderlo la fila, no la ficha, que para entonces puede decir otra cosa.
            'payment_term_days_snapshot' => $creador->payment_term_days,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    /**
     * Saca al creador de la lista corta.
     *
     * Sólo mientras siga siendo candidato. En cuanto se le invitó hay una
     * conversación abierta con una persona, y eso no se borra: se cancela, y
     * eso es 7.6.
     */
    public static function vetoParaQuitar(object $fila): ?string
    {
        if ((string) $fila->status === self::SHORTLISTED) {
            return null;
        }

        return sprintf(
            'Ese creador ya esta en «%s»: hubo una invitacion de por medio y eso no se borra. '
            .'Se cancela la participacion, dejando el motivo, y asi el historico sigue contando '
            .'lo que paso de verdad.',
            $fila->status,
        );
    }

    // ------------------------------------------------------------------ apoyo

    /** El mercado de la campaña que cubre ese país, o `null` si no hay. */
    private static function mercadoPara(object $campana, int $paisId): ?int
    {
        $id = DB::table('campaign_markets')
            ->where('campaign_id', $campana->id)->where('country_id', $paisId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function edadEn(string $nacimiento, string $fecha): int
    {
        return (int) DB::selectOne(
            'SELECT TIMESTAMPDIFF(YEAR, ?, ?) AS edad', [$nacimiento, $fecha],
        )->edad;
    }

    private static function tieneRestriccionDeMarca(int $creadorId, int $marcaId): bool
    {
        return DB::table('creator_restrictions as cr')
            ->join('client_brand_categories as cbc', 'cbc.category_id', '=', 'cr.category_id')
            ->where('cr.creator_id', $creadorId)
            ->where('cbc.client_brand_id', $marcaId)
            ->exists();
    }

    private static function agendaBloqueada(int $creadorId, object $campana): bool
    {
        // Por la negacion, igual que en el buscador: dos periodos NO se solapan
        // si uno termina antes de que el otro empiece. Enumerar los cuatro casos
        // de solape es donde se cuela el error de un dia, once veces en este
        // proyecto.
        return DB::table('creator_blackouts')
            ->where('creator_id', $creadorId)
            ->whereRaw('NOT (ends_on < ? OR starts_on > ?)', [$campana->starts_on, $campana->ends_on])
            ->exists();
    }
}
