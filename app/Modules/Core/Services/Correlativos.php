<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * El número que sale una sola vez (9.12).
 *
 * `BR-LE-007` (🔴): *«la numeración es correlativa por (entidad legal, país, tipo
 * de documento, serie) y se asigna bajo bloqueo, sin huecos ni duplicados, incluso
 * bajo concurrencia»*. Los cuatro ejes están: el país no es una columna porque el
 * **tipo** ya es de un país, y `tg_ds_forma_*` exige que sea el mismo país de la
 * sociedad que emite. Una columna más sería una segunda respuesta a la misma
 * pregunta, que es como se contradicen los datos.
 *
 * ### Por qué `lockForUpdate()` y no `MAX(number) + 1`
 *
 * `MAX()+1` es correcto exactamente hasta la primera vez que dos personas emiten
 * a la vez, y entonces las dos leen el mismo máximo. No es un fallo raro: es el
 * caso normal de un cierre de mes. El bloqueo de la fila de la serie pone en fila
 * a la segunda petición **antes** de que lea el contador; lo demuestra
 * `tools/pruebas/9.12-correlativos.sh` con dos conexiones de verdad.
 *
 * Y aun así existe `uq_dn_number`. El bloqueo es la primera línea y la única es
 * la última: si mañana alguien escribe un camino que reserva sin bloquear, la
 * base lo rechaza en vez de emitir dos comprobantes con el mismo número.
 *
 * ### El hueco existe, pero queda explicado
 *
 * Reservar y no emitir deja un número sin documento. Eso pasa —una petición que
 * falla a mitad, un envío que SUNAT rechaza— y no se puede evitar reutilizando el
 * número: reutilizarlo es el duplicado. Lo que se puede hacer, y es lo que exige
 * un requerimiento, es **decir qué pasó con él**: `anular()` lo cierra con un
 * motivo escrito. Un hueco explicado es un hueco defendible; uno mudo, no.
 */
final class Correlativos
{
    /** @var array<string, string> */
    public const ESTADOS = [
        'reserved' => 'Reservado — todavía sin documento',
        'used' => 'Usado — tiene documento',
        'voided' => 'Anulado — nunca se emitió',
    ];

    /** @var array<string, string> */
    public const ENTORNOS = ['sandbox' => 'Pruebas', 'production' => 'Producción'];

    /** Lo mínimo que explica algo. «Error» no explica nada. */
    private const MOTIVO_MINIMO = 10;

    /**
     * A partir de cuántas horas un número reservado y sin documento es un aviso.
     *
     * Es un umbral de AVISO, no una regla de negocio: no bloquea nada y no
     * cambia ningún dato. Por eso está aquí y no en una tabla. El día que una
     * instalación necesite otro, se convierte en fila —y entonces entra en
     * `T-69` como todo lo demás—.
     */
    private const HORAS_COLGADO = 24;

    // ------------------------------------------------------------- lecturas

    /**
     * Los tipos de comprobante de un país, o los de todos.
     *
     * @return Collection<int, \stdClass>
     */
    public static function tipos(?int $paisId = null, bool $soloActivos = false): Collection
    {
        return DB::table('document_types as dt')
            ->join('countries as c', 'c.id', '=', 'dt.country_id')
            ->when($paisId !== null, fn ($q) => $q->where('dt.country_id', $paisId))
            ->when($soloActivos, fn ($q) => $q->where('dt.is_active', 1))
            ->orderBy('c.name')->orderBy('dt.sort_order')->orderBy('dt.name')
            ->get(['dt.id', 'dt.country_id', 'dt.code', 'dt.name', 'dt.official_code',
                'dt.series_pattern', 'dt.series_label', 'dt.number_length',
                'dt.requires_customer_tax_id', 'dt.is_active', 'dt.sort_order',
                'c.name as pais', 'c.iso2']);
    }

    /**
     * Las series, con su sociedad, su tipo y por dónde va el contador.
     *
     * @return Collection<int, \stdClass>
     */
    public static function series(): Collection
    {
        return DB::table('document_series as ds')
            ->join('legal_entities as le', 'le.id', '=', 'ds.legal_entity_id')
            ->join('document_types as dt', 'dt.id', '=', 'ds.document_type_id')
            ->orderBy('le.code')->orderBy('dt.sort_order')->orderBy('ds.series')
            ->get(['ds.id', 'ds.legal_entity_id', 'ds.document_type_id', 'ds.series',
                'ds.next_number', 'ds.environment', 'ds.is_active', 'ds.is_default', 'ds.notes',
                'le.code as sociedad', 'le.legal_name as sociedad_nombre',
                'dt.name as tipo', 'dt.code as tipo_codigo', 'dt.number_length']);
    }

    /**
     * Los últimos números que salieron de una serie.
     *
     * @return Collection<int, \stdClass>
     */
    public static function ultimos(int $serieId, int $cuantos = 20): Collection
    {
        return DB::table('document_numbers as dn')
            ->leftJoin('users as u', 'u.id', '=', 'dn.reserved_by_user_id')
            ->where('dn.document_series_id', $serieId)
            ->orderByDesc('dn.number')->limit($cuantos)
            ->get(['dn.id', 'dn.number', 'dn.full_number', 'dn.status', 'dn.reserved_at',
                'dn.used_at', 'dn.entity_type', 'dn.entity_id', 'dn.voided_at',
                'dn.void_reason', 'u.name as autor']);
    }

    public static function serie(int $serieId): object
    {
        $fila = DB::table('document_series as ds')
            ->join('document_types as dt', 'dt.id', '=', 'ds.document_type_id')
            ->where('ds.id', $serieId)
            ->first(['ds.id', 'ds.series', 'ds.next_number', 'ds.is_active', 'ds.environment',
                'ds.legal_entity_id', 'ds.document_type_id', 'dt.number_length', 'dt.name as tipo']);

        if ($fila === null) {
            throw new RuntimeException('No existe esa serie.');
        }

        return $fila;
    }

    /**
     * «F001-00000123». La longitud la manda el tipo, no una constante.
     */
    public static function formatear(string $serie, int $numero, int $largo): string
    {
        return $serie.'-'.str_pad((string) $numero, $largo, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------- escritura

    /**
     * Reserva el siguiente número de una serie y lo deja escrito.
     *
     * Todo dentro de una transacción y con la fila de la serie **bloqueada**:
     * quien llegue después espera a que ésta termine, y entonces lee un contador
     * que ya está avanzado. Sin el bloqueo las dos leerían el mismo.
     *
     * Devuelve `{id, numero, completo}`. Quien lo llame tiene que llamar después
     * a `usar()` o a `anular()`: un número reservado y olvidado es un hueco, y el
     * panel lo dice en rojo a las 24 horas.
     */
    public static function reservar(int $serieId, ?int $usuarioId = null): object
    {
        return DB::transaction(static function () use ($serieId, $usuarioId): object {
            $serie = DB::table('document_series')
                ->where('id', $serieId)
                ->lockForUpdate()
                ->first(['id', 'series', 'next_number', 'is_active', 'document_type_id']);

            if ($serie === null) {
                throw new RuntimeException('No existe esa serie.');
            }

            if ((int) $serie->is_active !== 1) {
                throw new RuntimeException('Esa serie esta apagada: no puede dar numeros nuevos.');
            }

            $largo = (int) DB::table('document_types')
                ->where('id', $serie->document_type_id)->value('number_length');

            $numero = (int) $serie->next_number;
            $completo = self::formatear((string) $serie->series, $numero, $largo);

            $id = (int) DB::table('document_numbers')->insertGetId([
                'document_series_id' => $serieId,
                'number' => $numero,
                'full_number' => $completo,
                'status' => 'reserved',
                'reserved_at' => now(),
                'reserved_by_user_id' => $usuarioId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // El contador sube DENTRO de la misma transaccion. Si el insert de
            // arriba falla, esto no llega a pasar y el numero no se pierde.
            DB::table('document_series')->where('id', $serieId)->update([
                'next_number' => $numero + 1,
                'updated_at' => now(),
            ]);

            return (object) ['id' => $id, 'numero' => $numero, 'completo' => $completo];
        });
    }

    /**
     * Dice a qué documento se le entregó el número.
     *
     * `entity_type` / `entity_id` van sin foránea porque la tabla de documentos
     * es `9.9`; el nombre se escribe para que el día que exista se pueda
     * comprobar. `ck_dn_usado` impide que quede a medias.
     */
    public static function usar(int $numeroId, string $tipoEntidad, int $idEntidad): void
    {
        $fila = DB::table('document_numbers')->where('id', $numeroId)
            ->first(['id', 'status', 'full_number']);

        if ($fila === null) {
            throw new RuntimeException('No existe ese numero.');
        }

        if ($fila->status !== 'reserved') {
            throw new RuntimeException('Ese numero ya no esta reservado: '.$fila->status.'.');
        }

        DB::table('document_numbers')->where('id', $numeroId)->update([
            'status' => 'used',
            'used_at' => now(),
            'entity_type' => $tipoEntidad,
            'entity_id' => $idEntidad,
            'updated_at' => now(),
        ]);
    }

    /**
     * Cierra un número que nunca se emitió, con el motivo escrito.
     *
     * No lo devuelve a la serie. Devolverlo sería reutilizarlo, y dos documentos
     * con el mismo número es exactamente lo que esta tabla existe para impedir.
     */
    public static function anular(int $numeroId, string $motivo, ?int $usuarioId = null): void
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new RuntimeException(
                'El motivo tiene que explicar que paso: un hueco sin explicacion no se puede defender.',
            );
        }

        $fila = DB::table('document_numbers')->where('id', $numeroId)
            ->first(['id', 'status', 'full_number', 'document_series_id']);

        if ($fila === null) {
            throw new RuntimeException('No existe ese numero.');
        }

        if ($fila->status !== 'reserved') {
            throw new RuntimeException('Solo se anula un numero reservado: usado y anulado son finales.');
        }

        DB::table('document_numbers')->where('id', $numeroId)->update([
            'status' => 'voided',
            'voided_at' => now(),
            'void_reason' => mb_substr($motivo, 0, 255),
            'updated_at' => now(),
        ]);

        // Un hueco es de las pocas cosas que hay que poder explicar anos
        // despues: quien lo abrio, cuando y por que.
        Bitacora::registrar(
            accion: 'document_number.voided',
            tipoEntidad: 'document_number',
            idEntidad: $numeroId,
            cambios: [
                'numero' => ['antes' => (string) $fila->full_number, 'despues' => (string) $fila->full_number],
                'estado' => ['antes' => 'reserved', 'despues' => 'voided'],
                'motivo' => ['antes' => null, 'despues' => mb_substr($motivo, 0, 255)],
            ],
        );
    }

    /**
     * Crea o actualiza una serie.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarSerie(?int $id, array $datos): int
    {
        $campos = [
            'legal_entity_id' => (int) $datos['legal_entity_id'],
            'document_type_id' => (int) $datos['document_type_id'],
            'series' => mb_strtoupper(trim((string) $datos['series'])),
            'environment' => (string) $datos['environment'],
            'is_active' => (bool) ($datos['is_active'] ?? false),
            'is_default' => (bool) ($datos['is_default'] ?? false),
            'notes' => ($datos['notes'] ?? '') !== '' ? mb_substr((string) $datos['notes'], 0, 255) : null,
            'updated_at' => now(),
        ];

        if ($id === null) {
            // El contador de arranque se dice UNA vez, al crear: una serie que
            // ya circulaba en el sistema anterior no empieza en 1, y obligarla
            // a hacerlo produciria numeros repetidos ante SUNAT. Despues no se
            // toca desde aqui: lo mueve `reservar()`.
            $campos['next_number'] = max(1, (int) ($datos['next_number'] ?? 1));
            $campos['created_at'] = now();

            return (int) DB::table('document_series')->insertGetId($campos);
        }

        DB::table('document_series')->where('id', $id)->update($campos);

        return $id;
    }

    /**
     * Crea o actualiza un tipo de comprobante.
     *
     * @param array<string, mixed> $datos
     */
    public static function guardarTipo(?int $id, array $datos): int
    {
        $campos = [
            'country_id' => (int) $datos['country_id'],
            'code' => mb_strtolower(trim((string) $datos['code'])),
            'name' => (string) $datos['name'],
            'official_code' => ($datos['official_code'] ?? '') !== '' ? (string) $datos['official_code'] : null,
            'series_pattern' => ($datos['series_pattern'] ?? '') !== '' ? (string) $datos['series_pattern'] : null,
            'series_label' => ($datos['series_label'] ?? '') !== '' ? (string) $datos['series_label'] : null,
            'number_length' => (int) ($datos['number_length'] ?? 8),
            'requires_customer_tax_id' => (bool) ($datos['requires_customer_tax_id'] ?? false),
            'is_active' => (bool) ($datos['is_active'] ?? false),
            'sort_order' => (int) ($datos['sort_order'] ?? 100),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $campos['created_at'] = now();

            return (int) DB::table('document_types')->insertGetId($campos);
        }

        DB::table('document_types')->where('id', $id)->update($campos);

        return $id;
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        if (!Schema::hasTable('document_series') || !Schema::hasTable('document_types')) {
            return [];
        }

        $avisos = [];

        // Un pais que ya se factura y sin tipos configurados: el dia que haya
        // que emitir alli, no hay ni de que tipo es el comprobante.
        $paisesSinTipos = DB::table('legal_entities as le')
            ->join('countries as c', 'c.id', '=', 'le.country_id')
            ->where('le.status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('document_types as dt')
                ->whereColumn('dt.country_id', 'le.country_id')
                ->where('dt.is_active', 1))
            ->distinct()->pluck('c.name');

        if ($paisesSinTipos->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Sin tipos de comprobante configurados: %s. Hay una sociedad activa allí y no hay '
                .'de qué emitir.',
                $paisesSinTipos->implode(', '),
            ));
        }

        // Una sociedad activa sin ninguna serie. Aqui NO se siembra un valor de
        // partida --una serie inventada produciria comprobantes invalidos-- y
        // por eso el aviso es rojo y no un bloqueo (`DEC-190`).
        $sinSerie = DB::table('legal_entities')
            ->where('status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('document_series')
                ->whereColumn('document_series.legal_entity_id', 'legal_entities.id')
                ->where('document_series.is_active', 1))
            ->pluck('code');

        if ($sinSerie->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Sin ninguna serie activa: %s. Las series se registran ante la administración '
                .'tributaria, así que ésta es la única configuración que no trae valor de fábrica.',
                $sinSerie->implode(', '),
            ));
        }

        // Un numero reservado y olvidado es un hueco en formacion. Un dia es de
        // sobra: una emision tarda segundos.
        if (Schema::hasTable('document_numbers')) {
            // La cuenta la hace el MOTOR y no PHP: asi el umbral se mide con el
            // reloj del servidor --el mismo que puso `reserved_at`-- y ademas no
            // hay aritmetica de dias fuera de `Vigencia`, que es la puerta de
            // `H-16`. Aqui no es una vigencia, pero la regla no admite
            // excepciones por buenas intenciones.
            $colgados = DB::table('document_numbers')
                ->where('status', 'reserved')
                ->whereRaw('reserved_at < NOW() - INTERVAL '.self::HORAS_COLGADO.' HOUR')
                ->count();

            if ($colgados > 0) {
                $avisos[] = Aviso::rojo(sprintf(
                    '%d %s reservado%s sin documento desde hace más de un día. O se usa o se anula con su '
                    .'motivo: un hueco mudo no se puede defender.',
                    $colgados, $colgados === 1 ? 'número' : 'números', $colgados === 1 ? '' : 's',
                ));
            }
        }

        // Y la serie que se acaba. Avisar cuando ya no cabe es tarde: hay que
        // pedir una serie nueva y eso lleva tiempo.
        $casiAgotadas = DB::table('document_series as ds')
            ->join('document_types as dt', 'dt.id', '=', 'ds.document_type_id')
            ->where('ds.is_active', 1)
            ->whereRaw('ds.next_number > POW(10, dt.number_length) * 0.9')
            ->pluck('ds.series');

        if ($casiAgotadas->isNotEmpty()) {
            $avisos[] = Aviso::ambar(sprintf(
                'Series por encima del 90 %% de su numeración: %s.',
                $casiAgotadas->implode(', '),
            ));
        }

        return $avisos;
    }
}
