<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Database\Vigencia;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Lo que el usuario eligió mirar (`D-2`).
 *
 * ### Por qué es un objeto y no cinco parámetros sueltos
 *
 * Porque el panel va a tener una docena de indicadores y **todos** tienen que
 * responder al mismo recorte. Con parámetros sueltos, el bloque que alguien
 * añada dentro de tres meses se olvidará de uno —del país, casi seguro— y
 * enseñará un número que no cuadra con el de al lado. Dos números que no cuadran
 * en la misma pantalla destruyen la confianza en los diez que sí cuadran.
 *
 * Aquí el recorte es **un** valor que se pasa entero o no se pasa.
 *
 * ### El periodo se calcula en UTC, como todo lo demás
 *
 * `docs/03 §5`: se almacena en UTC y se convierte al mostrar. Los límites del
 * periodo salen del reloj de la aplicación —nunca del motor, `DEC-302`— y en
 * UTC. **Todavía no hay zona horaria por usuario**: enseñar «hoy» a alguien en
 * Lima usando el día UTC desplaza el corte cinco horas, y entre las 19:00 y la
 * medianoche de Lima «hoy» incluye cosas de mañana. Está anotado como `T-101`;
 * arreglarlo es decidir de dónde sale la zona de cada persona, no cambiar una
 * línea aquí.
 *
 * ### Los filtros que todavía no están
 *
 * Plataforma social y moneda **no se ofrecen aún**, y es deliberado: hoy no hay
 * ningún indicador al que puedan recortar. Un desplegable que no cambia ningún
 * número es peor que su ausencia —enseña que los filtros de esta pantalla no
 * hacen nada—. Entran con el bloque que los usa: plataforma en `D-5`, moneda en
 * `D-8`.
 */
final class FiltrosDeResumen
{
    public const HOY = 'hoy';

    public const SIETE = '7d';

    public const TREINTA = '30d';

    public const MES = 'mes';

    public const TRIMESTRE = 'trimestre';

    public const ANO = 'ano';

    public const RANGO = 'rango';

    /** Cómo se llama cada uno en la pantalla. */
    public const NOMBRES = [
        self::HOY => 'Hoy',
        self::SIETE => 'Últimos 7 días',
        self::TREINTA => 'Últimos 30 días',
        self::MES => 'Mes actual',
        self::TRIMESTRE => 'Trimestre actual',
        self::ANO => 'Año actual',
        self::RANGO => 'Rango personalizado',
    ];

    public const POR_DEFECTO = self::TREINTA;

    private function __construct(
        public readonly string $periodo,
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
        public readonly ?int $paisId,
        public readonly ?int $sociedadId,
        public readonly ?int $clienteId,
        public readonly ?int $campanaId,
    ) {}

    public static function desdePeticion(Request $peticion): self
    {
        $periodo = (string) $peticion->query('periodo', self::POR_DEFECTO);
        if (!array_key_exists($periodo, self::NOMBRES)) {
            // Un periodo que no existe vuelve al de siempre en vez de reventar:
            // esta pantalla se comparte por enlace y un enlace viejo no debe
            // dejar a nadie delante de un 500.
            $periodo = self::POR_DEFECTO;
        }

        [$desde, $hasta] = self::limites(
            $periodo,
            self::fecha($peticion->query('desde')),
            self::fecha($peticion->query('hasta')),
        );

        return new self(
            periodo: $periodo,
            desde: $desde,
            hasta: $hasta,
            paisId: self::entero($peticion->query('pais')),
            sociedadId: self::entero($peticion->query('sociedad')),
            clienteId: self::entero($peticion->query('cliente')),
            campanaId: self::entero($peticion->query('campana')),
        );
    }

    /** El de siempre, sin ningún recorte. */
    public static function porDefecto(): self
    {
        [$desde, $hasta] = self::limites(self::POR_DEFECTO, null, null);

        return new self(self::POR_DEFECTO, $desde, $hasta, null, null, null, null);
    }

    /**
     * El periodo inmediatamente anterior, de la MISMA duración.
     *
     * Comparar contra «el mes pasado» cuando se está mirando siete días daría
     * una variación que no significa nada. La duración se conserva y el corte
     * es el día justo antes de `desde`.
     */
    public function anterior(): self
    {
        // Días ENTEROS, y de comienzo de día a comienzo de día.
        //
        // `desde` es el arranque del día y `hasta` el final del último, así que
        // `diffInDays` entre los dos devuelve 29,99999… y no 30. Carbon 3 lo da
        // en coma flotante, y esa fracción se colaba en el `subDays()` de abajo:
        // el periodo anterior salía **un día más largo** que el elegido, que es
        // exactamente lo que `DEC-309` dice que no puede pasar. Una comparación
        // entre periodos de distinta duración da un porcentaje con toda la pinta
        // de dato y ningún significado.
        //
        // Lo cazó `test_el_periodo_anterior_dura_lo_mismo_y_termina_la_vispera`
        // al primer intento: siete días elegidos, ocho de comparación.
        $dias = (int) $this->desde->startOfDay()->diffInDays($this->hasta->startOfDay());

        // `Vigencia::cerrarElDiaAntesDe()` y no `subDay()` a mano, aunque aqui
        // no haya ninguna columna `valid_*`: **es el mismo concepto** --cerrar
        // un intervalo la vispera del siguiente-- y el error de un dia se paga
        // igual de caro en un panel que en una cobertura. La puerta de
        // vigencias lo pedia desde `D-1` y llevaba tres dias en rojo sin que
        // nadie la corriera (`T-137`).
        return new self(
            periodo: $this->periodo,
            desde: $this->desde->subDays($dias + 1)->startOfDay(),
            hasta: CarbonImmutable::parse(
                Vigencia::cerrarElDiaAntesDe($this->desde->toDateString()),
            )->endOfDay(),
            paisId: $this->paisId,
            sociedadId: $this->sociedadId,
            clienteId: $this->clienteId,
            campanaId: $this->campanaId,
        );
    }

    /** ¿Hay algo que limpiar? */
    public function hayRecorte(): bool
    {
        return $this->periodo !== self::POR_DEFECTO
            || $this->paisId !== null
            || $this->sociedadId !== null
            || $this->clienteId !== null
            || $this->campanaId !== null;
    }

    public function nombreDelPeriodo(): string
    {
        return self::NOMBRES[$this->periodo];
    }

    /**
     * Lo elegido, tal cual va en la URL.
     *
     * @return array<string, string>
     */
    public function comoConsulta(): array
    {
        $consulta = ['periodo' => $this->periodo];

        if ($this->periodo === self::RANGO) {
            $consulta['desde'] = $this->desde->toDateString();
            $consulta['hasta'] = $this->hasta->toDateString();
        }

        foreach (['pais' => $this->paisId, 'sociedad' => $this->sociedadId,
            'cliente' => $this->clienteId, 'campana' => $this->campanaId] as $clave => $valor) {
            if ($valor !== null) {
                $consulta[$clave] = (string) $valor;
            }
        }

        return $consulta;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function limites(string $periodo, ?CarbonImmutable $desde, ?CarbonImmutable $hasta): array
    {
        $ahora = CarbonImmutable::now();

        return match ($periodo) {
            self::HOY => [$ahora->startOfDay(), $ahora->endOfDay()],
            self::SIETE => [$ahora->subDays(6)->startOfDay(), $ahora->endOfDay()],
            self::MES => [$ahora->startOfMonth(), $ahora->endOfMonth()],
            self::TRIMESTRE => [$ahora->startOfQuarter(), $ahora->endOfQuarter()],
            self::ANO => [$ahora->startOfYear(), $ahora->endOfYear()],
            self::RANGO => self::rango($desde, $hasta, $ahora),
            default => [$ahora->subDays(29)->startOfDay(), $ahora->endOfDay()],
        };
    }

    /**
     * Un rango a mano, ordenado y con suelo.
     *
     * Si vienen al revés se enderezan en vez de devolver cero filas sin decir
     * por qué, que es el modo de fallo que hace pensar que el panel está roto.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function rango(?CarbonImmutable $desde, ?CarbonImmutable $hasta, CarbonImmutable $ahora): array
    {
        $desde ??= $ahora->subDays(29);
        $hasta ??= $ahora;

        if ($desde->greaterThan($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$desde->startOfDay(), $hasta->endOfDay()];
    }

    private static function fecha(mixed $valor): ?CarbonImmutable
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $valor) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function entero(mixed $valor): ?int
    {
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }

        $numero = (int) $valor;

        return $numero > 0 ? $numero : null;
    }
}
