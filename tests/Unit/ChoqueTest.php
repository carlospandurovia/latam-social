<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Database\Choque;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `Choque`, probado sin base de datos (`T-17`).
 *
 * La decisión que toma esta clase es la de **absorber o contar**, y equivocarse
 * es caro en las dos direcciones: absorber un choque que era un error del
 * operador lo deja sin saber qué pasó, y contar uno que el sistema podía
 * resolver le enseña un `1062` en crudo.
 *
 * Lo que hace que la decisión sea frágil es que el mismo choque se cuenta
 * distinto según el motor —MySQL 8 antepone la tabla al índice y MariaDB y
 * Percona 5.7 no—. Estas pruebas fijan **los dos formatos a la vez**, que es lo
 * que un solo motor de desarrollo no puede comprobar.
 */
final class ChoqueTest extends TestCase
{
    /** Lo que dice MySQL 8.0.46. Comprobado contra el motor, no inventado. */
    public function test_lee_el_indice_con_la_tabla_delante_mysql_8(): void
    {
        $e = $this->choque("Duplicate entry 'acme' for key 'client_brands.uq_cb_slug'");

        $this->assertSame('uq_cb_slug', Choque::indice($e));
    }

    /** Lo que dice MariaDB 10.11 — y MySQL 5.7, o sea PRODUCCION. */
    public function test_lee_el_indice_sin_la_tabla_delante_mariadb_y_percona(): void
    {
        $e = $this->choque("Duplicate entry 'acme' for key 'uq_cb_slug'");

        $this->assertSame('uq_cb_slug', Choque::indice($e));
    }

    /**
     * **La prueba que justifica que se corte por el punto.**
     *
     * Comparar el mensaje entero contra `'uq_cb_slug'` funcionaría en
     * producción y fallaría en el CI: verde donde se prueba, roto donde se
     * cobra. Los dos formatos tienen que dar el MISMO nombre.
     */
    public function test_los_dos_motores_dan_el_mismo_nombre(): void
    {
        $this->assertSame(
            Choque::indice($this->choque("Duplicate entry 'x' for key 'client_brands.uq_cb_slug'")),
            Choque::indice($this->choque("Duplicate entry 'x' for key 'uq_cb_slug'")),
        );
    }

    /** Un slug con puntos es un DATO, no una tabla: el índice se lee de la segunda comilla. */
    public function test_un_valor_con_puntos_no_confunde_al_analizador(): void
    {
        $e = $this->choque("Duplicate entry 'acme.co.uk' for key 'uq_cb_slug'");

        $this->assertSame('uq_cb_slug', Choque::indice($e));
    }

    /** Una única compuesta trae el valor con guiones, y sigue siendo dato. */
    public function test_una_unica_compuesta_se_lee_igual(): void
    {
        $e = $this->choque("Duplicate entry '1-1-commercial' for key 'contacts.uq_contacts_primary'");

        $this->assertSame('uq_contacts_primary', Choque::indice($e));
    }

    public function test_lo_que_no_es_un_choque_no_tiene_indice(): void
    {
        $this->assertNull(Choque::indice(new RuntimeException('la conexion se cayo')));
        $this->assertNull(Choque::indice($this->choque('Cannot add or update a child row')));
    }

    /**
     * **La distinción que hace útil a esta clase.**
     *
     * Un `catch (QueryException)` a secas absorbería el `uq_cb_name` —que es el
     * operador dando de alta dos veces la misma marca y necesita leerlo— igual
     * que el `uq_cb_slug`, que el sistema sí sabe resolver solo.
     */
    public function test_un_choque_de_otro_indice_no_cuenta_como_este(): void
    {
        $e = $this->choque("Duplicate entry '7-Primor' for key 'client_brands.uq_cb_name'");

        $this->assertTrue(Choque::esDe($e, 'uq_cb_name'));
        $this->assertFalse(Choque::esDe($e, 'uq_cb_slug'));
    }

    public function test_reintenta_y_a_la_segunda_entra(): void
    {
        $intentos = 0;

        $resultado = Choque::reintentar('uq_cb_slug', function (int $n) use (&$intentos): string {
            $intentos = $n;

            if ($n === 1) {
                throw $this->choque("Duplicate entry 'acme' for key 'uq_cb_slug'");
            }

            return 'acme-2';
        });

        $this->assertSame('acme-2', $resultado);
        $this->assertSame(2, $intentos, 'tuvo que hacer falta un segundo intento');
    }

    /** A la acción se le dice qué intento es: no puede ignorar que se la repite. */
    public function test_la_accion_recibe_el_numero_de_intento(): void
    {
        $vistos = [];

        try {
            Choque::reintentar('uq_cb_slug', function (int $n) use (&$vistos): never {
                $vistos[] = $n;
                throw $this->choque("Duplicate entry 'acme' for key 'uq_cb_slug'");
            }, intentos: 3);
        } catch (UniqueConstraintViolationException) {
            // Se espera: tres intentos y el tercero sale.
        }

        $this->assertSame([1, 2, 3], $vistos);
    }

    /**
     * **La prueba que impide que esto se convierta en un `catch` universal.**
     *
     * Si absorbiera cualquier choque, un RUC repetido se reintentaría tres
     * veces y saldría igual de mal, tres veces más tarde y sin explicación.
     */
    public function test_un_choque_de_otro_indice_sale_a_la_primera(): void
    {
        $intentos = 0;

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            Choque::reintentar('uq_cb_slug', function (int $n) use (&$intentos): never {
                $intentos = $n;
                throw $this->choque("Duplicate entry '20123' for key 'uq_ctxp_taxid'");
            });
        } finally {
            $this->assertSame(1, $intentos, 'no se reintenta lo que no se sabe arreglar');
        }
    }

    /** Y lo que no es un choque tampoco se reintenta: un fallo de red no mejora repitiéndolo. */
    public function test_lo_que_no_es_un_choque_no_se_reintenta(): void
    {
        $intentos = 0;

        $this->expectException(RuntimeException::class);

        try {
            Choque::reintentar('uq_cb_slug', function (int $n) use (&$intentos): never {
                $intentos = $n;
                throw new RuntimeException('la conexion se cayo');
            });
        } finally {
            $this->assertSame(1, $intentos);
        }
    }

    /** Un bucle sin tope convierte un índice mal entendido en una petición eterna. */
    public function test_el_numero_de_intentos_tiene_suelo(): void
    {
        $intentos = 0;

        try {
            Choque::reintentar('uq_cb_slug', function (int $n) use (&$intentos): never {
                $intentos = $n;
                throw $this->choque("Duplicate entry 'acme' for key 'uq_cb_slug'");
            }, intentos: 0);
        } catch (UniqueConstraintViolationException) {
            // Se espera.
        }

        $this->assertSame(1, $intentos, 'cero intentos seria no ejecutar nada');
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Una `UniqueConstraintViolationException` como la que fabrica Laravel, con
     * el texto del motor dentro. No se toca la base: lo que se prueba es la
     * lectura del mensaje.
     */
    private function choque(string $delMotor): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'mysql',
            'insert into `client_brands` (`slug`) values (?)',
            ['acme'],
            new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 '.$delMotor),
        );
    }
}
