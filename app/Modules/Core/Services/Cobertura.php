<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Database\Vigencia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué sociedad del grupo factura a un país, y desde cuándo (iteración 4.5).
 *
 * La consulta vive **en Core** porque el dato es de Core: `legal_entities` y
 * `legal_entity_countries` son la estructura societaria del grupo, no algo del
 * cliente. `Client\Services\CoberturaFacturacion` la envuelve para contestar la
 * pregunta desde el lado del cliente —Client puede depender de Core; al revés
 * no— y la pantalla de sociedades la usa directamente.
 *
 * Antes de 4.5 la consulta existía sólo dentro de `CoberturaFacturacion`, y la
 * pantalla de sociedades habría necesitado la suya. Dos implementaciones de
 * *«¿quién emite esta factura?»* es exactamente la clase de duplicado que
 * termina divergiendo.
 *
 * ### El bloqueo que hay que evitar
 *
 * `uq_lec_country` es `(current_gate, country_id)`: **una sola cobertura
 * abierta por país**, mire o no el estado de la sociedad. Pero resolver sólo
 * cuenta las sociedades `active`. Las dos cosas juntas dejan un agujero:
 *
 * > Se desactiva la sociedad que cubre Perú sin cerrar su cobertura. Ahora
 * > ninguna sociedad activa cubre Perú **y ninguna otra puede empezar**, porque
 * > la fila abierta de la inactiva sigue ocupando el sitio. El país queda
 * > incomunicado: no se le puede facturar y no se puede arreglar dando de alta
 * > la cobertura del sucesor.
 *
 * Se comprobó contra el motor antes de escribir esto. Por eso `DEC-081`: dar de
 * baja una sociedad **cierra sus coberturas abiertas** en la misma transacción,
 * y la pantalla dice qué países quedan descubiertos.
 */
final class Cobertura
{
    /**
     * Las sociedades ACTIVAS que cubren un país en una fecha.
     *
     * Devuelve una colección y no una fila porque quien pregunta necesita
     * distinguir «ninguna» de «más de una»: desde 3.10 el segundo caso no
     * debería poder darse, y si se da hay que verlo, no elegir por sorteo.
     *
     * `$fecha` es un parámetro y no `now()` a propósito: `BR-LE-003` dice «en la
     * fecha de la operación», y una campaña que se factura en marzo se rige por
     * la cobertura de marzo.
     *
     * @return Collection<int, \stdClass>
     */
    public static function quienCubre(int $paisId, string $fecha): Collection
    {
        return DB::table('legal_entity_countries as lec')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->where('lec.country_id', $paisId)
            ->whereDate('lec.valid_from', '<=', $fecha)
            ->where(function ($q) use ($fecha): void {
                $q->whereNull('lec.valid_to')->orWhereDate('lec.valid_to', '>=', $fecha);
            })
            ->where('le.status', 'active')
            ->orderBy('le.code')
            ->get(['le.id', 'le.code', 'le.legal_name', 'le.default_currency_code',
                'lec.coverage_basis', 'lec.valid_from', 'lec.valid_to']);
    }

    /**
     * La cobertura ABIERTA de un país, la cubra quien la cubra.
     *
     * A diferencia de `quienCubre()`, **no filtra por estado de la sociedad**.
     * Es deliberado: esta contesta *«¿está el sitio ocupado?»*, que es lo que
     * mira `uq_lec_country`, y el sitio lo ocupa igual una sociedad inactiva.
     * Confundir las dos preguntas es justo lo que produce el país incomunicado.
     */
    public static function abiertaEnPais(int $paisId): ?object
    {
        return DB::table('legal_entity_countries as lec')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->where('lec.country_id', $paisId)
            ->whereNull('lec.valid_to')
            ->first(['lec.id', 'lec.legal_entity_id', 'lec.valid_from',
                'le.code', 'le.legal_name', 'le.status']);
    }

    /**
     * Las coberturas abiertas de una sociedad, con el nombre del país.
     *
     * @return Collection<int, \stdClass>
     */
    public static function abiertasDe(int $entidadId): Collection
    {
        return DB::table('legal_entity_countries as lec')
            ->join('countries as c', 'c.id', '=', 'lec.country_id')
            ->where('lec.legal_entity_id', $entidadId)
            ->whereNull('lec.valid_to')
            ->orderBy('c.name')
            ->get(['lec.id', 'lec.country_id', 'lec.valid_from', 'lec.coverage_basis',
                'c.name as pais', 'c.iso2']);
    }

    /**
     * Abre una cobertura, cerrando la que hubiera **el día antes**.
     *
     * Va siempre dentro de una transacción de quien llama, y el orden —cerrar y
     * luego abrir— no es preferencia: `uq_lec_country` sólo admite una abierta
     * por país, así que al revés la base lo rechaza.
     */
    public static function abrir(
        int $entidadId,
        int $paisId,
        string $motivo,
        string $desde,
        ?object $ocupada = null,
    ): int {
        // Quien llama puede pasar la fila que ya leyo. Si no la pasa se lee
        // aqui, pero entonces el veto de quien llama y el cierre de aqui miran
        // lecturas distintas, y con dos peticiones a la vez el mensaje puede
        // nombrar a una sociedad y relevarse otra.
        $ocupada ??= self::abiertaEnPais($paisId);

        if ($ocupada !== null) {
            DB::table('legal_entity_countries')->where('id', $ocupada->id)->update([
                'valid_to' => Vigencia::cerrarElDiaAntesDe($desde),
                'updated_at' => now(),
            ]);
        }

        return (int) DB::table('legal_entity_countries')->insertGetId([
            'legal_entity_id' => $entidadId,
            'country_id' => $paisId,
            'coverage_basis' => $motivo,
            'valid_from' => $desde,
            'valid_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Coberturas abiertas que NO se pueden cerrar en `$hasta` porque empiezan
     * después.
     *
     * Cerrar una cobertura que empieza el 1 de enero de 2027 «hasta el 30 de
     * junio de 2026» le pone un `valid_to` anterior a su `valid_from`, y eso lo
     * rechaza `ck_lec_dates` con un `45000`. Y recortar la fecha tampoco vale:
     * lo que ese caso significa es que **esa cobertura no llegó a existir**, y
     * `legal_entity_countries` no admite borrado (es evidencia de quién podía
     * facturar qué). Así que se contesta con palabras y no se toca nada.
     *
     * @return Collection<int, \stdClass>
     */
    public static function noCerrablesEn(int $entidadId, string $hasta): Collection
    {
        // `Vigencia::fecha()` en los dos lados: `'2026-12-01' > '2026-9-30'` es
        // FALSO como cadena, asi que una cobertura que empieza en diciembre no
        // se detectaria como no cerrable en septiembre, y `cerrarTodasDe()` le
        // pondria un `valid_to` anterior a su `valid_from`. Es `ck_lec_dates`.
        $limite = Vigencia::fecha($hasta);

        return self::abiertasDe($entidadId)
            ->filter(fn (object $c): bool => Vigencia::fecha((string) $c->valid_from) > $limite)
            ->values();
    }

    /**
     * Cierra TODAS las coberturas abiertas de una sociedad, en `$hasta`.
     *
     * Aquí `$hasta` es el último día cubierto, no el día del relevo: quien da de
     * baja una sociedad sabe hasta cuándo facturó, no cuándo empieza la que
     * viene —puede que no venga ninguna—. Por eso no pasa por
     * `cerrarElDiaAntesDe()`.
     *
     * Devuelve los países que quedan descubiertos, para poder decirlo.
     *
     * @return Collection<int, \stdClass>
     */
    public static function cerrarTodasDe(int $entidadId, string $hasta): Collection
    {
        $abiertas = self::abiertasDe($entidadId);

        if ($abiertas->isNotEmpty()) {
            DB::table('legal_entity_countries')
                ->where('legal_entity_id', $entidadId)
                ->whereNull('valid_to')
                ->update(['valid_to' => $hasta, 'updated_at' => now()]);
        }

        return $abiertas;
    }
}
