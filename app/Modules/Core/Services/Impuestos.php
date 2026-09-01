<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Database\Vigencia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Cuánto era el impuesto, y cuándo (9.9a).
 *
 * ### Por qué esto es un periodo y no un número
 *
 * `invoices` tenía desde la Fase 2 el importe del impuesto y hasta la aritmética
 * comprobada por la base. Lo que no existía era **la tasa**: nadie sabía que el
 * IGV es 18 %.
 *
 * Y no puede ser una constante por dos motivos. El primero es `DEC-190` —un 18
 * en el código es la regla de un país escrita en el código de todos—. El segundo
 * es el que decide: **las tasas cambian y las facturas de antes no**. El IGV
 * peruano ha sido 16, 17 y 19 % en distintos momentos; sin vigencia, subir la
 * tasa hoy reescribiría el impuesto de una factura de hace dos años la próxima
 * vez que alguien la recalculara.
 *
 * ### Se pregunta SIEMPRE por una fecha
 *
 * `vigente()` exige la fecha del documento, no la de hoy. Es la misma disciplina
 * que `Cobertura::queTapaLaFecha()` desde `T-73`: preguntar «¿cuál es?» en vez de
 * «¿cuál era?» produce respuestas correctas hasta el día en que algo cambia, y
 * entonces produce facturas mal calculadas hacia atrás.
 */
final class Impuestos
{
    /** El de Perú. No es una lista cerrada: es el que se pregunta por defecto. */
    public const IGV = 'IGV';

    /**
     * La tasa que regía en esa fecha, o `null` si no hay ninguna declarada.
     *
     * Devuelve `null` y no lanza: un país sin tasa declarada es un estado normal
     * —es el de todos los países menos Perú hoy— y quien llama tiene que poder
     * decirlo con palabras en vez de estrellarse.
     */
    public static function vigente(int $paisId, string $codigo = self::IGV, ?string $fecha = null): ?object
    {
        if (!Schema::hasTable('tax_rates')) {
            return null;
        }

        $dia = Vigencia::fecha($fecha ?? now()->toDateString());

        return DB::table('tax_rates')
            ->where('country_id', $paisId)
            ->where('code', $codigo)
            ->where('valid_from', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $dia))
            // Si hubiera dos --que `tg_tax_sin_solape` impide-- se queda con la
            // que empezo despues, que es la que un humano llamaria «la ultima».
            ->orderByDesc('valid_from')
            ->first(['id', 'country_id', 'code', 'name', 'rate', 'official_code',
                'valid_from', 'valid_to', 'note']);
    }

    /**
     * El impuesto de venta de un país en una fecha, o `null`.
     *
     * Cuál de los impuestos de ese país va en una factura de venta lo dice
     * `countries.sales_tax_code`, no una constante: «IGV» es peruano y esto se
     * usa desde el código que factura para seis países (`DEC-190`, `9.9b`).
     *
     * Devuelve `null` en los dos casos en que no se puede responder —el país no
     * ha declarado cuál es su impuesto de venta, o no hay tasa vigente esa
     * fecha— y quien llama tiene que poder decirlo con palabras.
     */
    public static function deVenta(int $paisId, ?string $fecha = null): ?object
    {
        $codigo = self::codigoDeVenta($paisId);

        return $codigo === null ? null : self::vigente($paisId, $codigo, $fecha);
    }

    /** Cómo se llama el impuesto de venta de ese país, si alguien lo ha dicho. */
    public static function codigoDeVenta(int $paisId): ?string
    {
        if (!Schema::hasColumn('countries', 'sales_tax_code')) {
            return null;
        }

        $codigo = DB::table('countries')->where('id', $paisId)->value('sales_tax_code');

        return ($codigo === null || $codigo === '') ? null : (string) $codigo;
    }

    /**
     * Declara cuál de los impuestos de un país va en sus facturas de venta.
     *
     * Se hace desde la misma pantalla que publica la tasa porque es la misma
     * decisión dicha dos veces —«el IGV es el 18 %» y «el impuesto de una
     * factura peruana es el IGV»— y separarlas en dos sitios es la forma más
     * barata de que alguien haga la primera y olvide la segunda.
     */
    public static function marcarComoImpuestoDeVenta(int $paisId, string $codigo): void
    {
        $codigo = mb_strtoupper(trim($codigo));
        $antes = self::codigoDeVenta($paisId);

        if ($antes === $codigo) {
            return;
        }

        DB::table('countries')->where('id', $paisId)
            ->update(['sales_tax_code' => $codigo, 'updated_at' => now()]);

        Bitacora::registrar(
            accion: 'country.sales_tax_set',
            tipoEntidad: 'country',
            idEntidad: $paisId,
            cambios: ['impuesto_de_venta' => ['antes' => $antes, 'despues' => $codigo]],
        );
    }

    /**
     * Todas, con su país, para la pantalla.
     *
     * @return Collection<int, \stdClass>
     */
    public static function todas(): Collection
    {
        return DB::table('tax_rates as t')
            ->join('countries as c', 'c.id', '=', 't.country_id')
            ->orderBy('c.name')->orderBy('t.code')->orderByDesc('t.valid_from')
            ->get(['t.id', 't.country_id', 't.code', 't.name', 't.rate', 't.official_code',
                't.valid_from', 't.valid_to', 't.note', 'c.name as pais', 'c.iso2',
                'c.sales_tax_code']);
    }

    /**
     * Publica una tasa nueva y cierra la anterior el día antes.
     *
     * El cierre lo hace `Vigencia` y no una resta escrita aquí: `valid_to` es
     * **inclusivo** en todo el esquema, y cerrar con la fecha de la nueva deja
     * las dos vigentes ese día —el defecto que este proyecto ha cometido nueve
     * veces y que por eso vive en una sola clase—.
     *
     * @param array<string, mixed> $datos
     */
    public static function publicar(array $datos): int
    {
        $paisId = (int) $datos['country_id'];
        $codigo = mb_strtoupper(trim((string) $datos['code']));
        $desde = Vigencia::fecha((string) $datos['valid_from']);

        return DB::transaction(static function () use ($paisId, $codigo, $desde, $datos): int {
            $anterior = DB::table('tax_rates')
                ->where('country_id', $paisId)->where('code', $codigo)
                ->whereNull('valid_to')
                ->orderByDesc('valid_from')
                ->lockForUpdate()
                ->first(['id', 'valid_from']);

            if ($anterior !== null) {
                if (!Vigencia::puedeRelevar($desde, (string) $anterior->valid_from)) {
                    throw new RuntimeException(
                        'La tasa nueva tiene que empezar despues de la que esta abierta: '
                        .'no se puede reescribir el impuesto de lo ya emitido.',
                    );
                }

                DB::table('tax_rates')->where('id', $anterior->id)->update([
                    'valid_to' => Vigencia::cerrarElDiaAntesDe($desde),
                    'updated_at' => now(),
                ]);
            }

            $id = (int) DB::table('tax_rates')->insertGetId([
                'country_id' => $paisId,
                'code' => $codigo,
                'name' => trim((string) $datos['name']),
                'rate' => (string) $datos['rate'],
                'official_code' => ($datos['official_code'] ?? '') !== '' ? (string) $datos['official_code'] : null,
                'valid_from' => $desde,
                'note' => ($datos['note'] ?? '') !== '' ? mb_substr((string) $datos['note'], 0, 255) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // La segunda mitad de la misma decision (`9.9b`). Va DENTRO de la
            // transaccion: publicar la tasa y no llegar a decir que es la de
            // venta dejaria el pais con tasa y sin saber cual usar.
            if ((bool) ($datos['es_de_venta'] ?? false)) {
                self::marcarComoImpuestoDeVenta($paisId, $codigo);
            }

            Bitacora::registrar(
                accion: 'tax_rate.published',
                tipoEntidad: 'tax_rate',
                idEntidad: $id,
                cambios: [
                    'impuesto' => ['antes' => null, 'despues' => $codigo],
                    'tasa' => ['antes' => null, 'despues' => (string) $datos['rate']],
                ],
            );

            return $id;
        });
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        if (!Schema::hasTable('tax_rates')) {
            return [];
        }

        $avisos = [];
        $hoy = Vigencia::fecha(now()->toDateString());

        // Un pais donde YA hay una sociedad activa y sin tasa vigente: el dia
        // que se emita alli, el impuesto seria cero sin que nadie lo decidiera.
        $sinTasa = DB::table('legal_entities as le')
            ->join('countries as c', 'c.id', '=', 'le.country_id')
            ->where('le.status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('tax_rates as t')
                ->whereColumn('t.country_id', 'le.country_id')
                ->where('t.valid_from', '<=', $hoy)
                ->where(fn ($x) => $x->whereNull('t.valid_to')->orWhere('t.valid_to', '>=', $hoy)))
            ->distinct()->pluck('c.name');

        if ($sinTasa->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Sin tasa de impuesto vigente: %s. Hay una sociedad activa allí, y el día que se '
                .'emita el impuesto saldría en cero sin que nadie lo haya decidido.',
                $sinTasa->implode(', '),
            ));
        }

        // Un pais con tasa vigente que no ha dicho CUAL de sus impuestos va en
        // una factura de venta. Se calcularia sin impuesto, que es el mismo
        // cero de arriba por otro camino (`9.9b`).
        if (Schema::hasColumn('countries', 'sales_tax_code')) {
            $sinCodigo = DB::table('legal_entities as le')
                ->join('countries as c', 'c.id', '=', 'le.country_id')
                ->where('le.status', 'active')
                ->where(fn ($q) => $q->whereNull('c.sales_tax_code')->orWhere('c.sales_tax_code', ''))
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('tax_rates as t')
                    ->whereColumn('t.country_id', 'le.country_id'))
                ->distinct()->pluck('c.name');

            if ($sinCodigo->isNotEmpty()) {
                $avisos[] = Aviso::rojo(sprintf(
                    'Hay tasas declaradas pero nadie ha dicho cuál es el impuesto de venta de %s. '
                    .'Marque una tasa como «la que va en la factura» o se emitirá sin impuesto.',
                    $sinCodigo->implode(', '),
                ));
            }
        }

        // Una tasa que se cierra y no tiene sucesora deja un hueco a futuro.
        $porTerminar = DB::table('tax_rates as t')
            ->join('countries as c', 'c.id', '=', 't.country_id')
            ->whereNotNull('t.valid_to')
            ->where('t.valid_to', '>=', $hoy)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('tax_rates as s')
                ->whereColumn('s.country_id', 't.country_id')
                ->whereColumn('s.code', 't.code')
                ->whereColumn('s.valid_from', '>', 't.valid_to'))
            ->pluck('c.name');

        if ($porTerminar->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Hay una tasa con fecha de fin y sin sucesora: %s. Cuando llegue ese día no habrá '
                .'con qué calcular el impuesto.',
                $porTerminar->implode(', '),
            ));
        }

        return $avisos;
    }
}
