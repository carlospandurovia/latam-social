<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Database\Restriccion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * El compilador de restricciones, probado sin base de datos.
 *
 * Esta es la clase más crítica del proyecto y la que menos se nota si falla.
 * Convierte cada `CHECK` declarado en un `TRIGGER` equivalente para el motor de
 * producción, que analiza los `CHECK` y los ignora en silencio (`DEC-042`).
 *
 * Si `reescribirConNew()` se equivoca, el disparador generado no se crea o se
 * crea mal, y el resultado es el peor posible: **la regla existe en desarrollo
 * y no existe en producción, sin un solo mensaje de error**. Durante la Fase 2
 * eso ocurrió tres veces por fallos del generador que la rodea, y ninguna la
 * detectaron las 125 pruebas de restricción, porque corren contra el esquema de
 * referencia y no contra este código.
 *
 * De ahí que sean pruebas unitarias: no necesitan motor, corren en milisegundos
 * y cubren justo la lógica que ningún `INSERT` de prueba puede alcanzar.
 */
final class RestriccionTest extends TestCase
{
    public function test_reescribe_la_columna_declarada(): void
    {
        $this->assertSame(
            'NEW.`rate` > 0',
            Restriccion::reescribirConNew('rate > 0', ['rate']),
        );
    }

    /**
     * El caso que rompió la primera versión: `'status'` dentro de una lista de
     * literales NO es la columna `status`. Sustituirlo genera SQL inválido y el
     * disparador no llega a crearse.
     */
    public function test_no_toca_lo_que_esta_dentro_de_un_literal(): void
    {
        $this->assertSame(
            "NEW.`status` IN ('active','status')",
            Restriccion::reescribirConNew("status IN ('active','status')", ['status']),
        );
    }

    /**
     * Las columnas se ordenan de más larga a más corta antes de sustituir. Sin
     * eso, sustituir `status` primero parte `status_code` por la mitad.
     */
    public function test_una_columna_no_rompe_a_otra_que_la_contiene(): void
    {
        $this->assertSame(
            'NEW.`status_code` = 1 AND NEW.`status` = 2',
            Restriccion::reescribirConNew(
                'status_code = 1 AND status = 2',
                ['status', 'status_code'],
            ),
        );
    }

    /** Comparación de dos expresiones booleanas: `ck_ledger_payout_link`. */
    public function test_reescribe_dentro_de_expresiones_anidadas(): void
    {
        $this->assertSame(
            "(NEW.`entry_type` = 'payment') = (NEW.`payout_id` IS NOT NULL)",
            Restriccion::reescribirConNew(
                "(entry_type = 'payment') = (payout_id IS NOT NULL)",
                ['entry_type', 'payout_id'],
            ),
        );
    }

    /** Un argumento de función también se sustituye; el nombre de la función no. */
    public function test_reescribe_dentro_de_una_llamada_a_funcion(): void
    {
        $this->assertSame(
            'CHAR_LENGTH(NEW.`account_number_masked`) <= 30',
            Restriccion::reescribirConNew(
                'CHAR_LENGTH(account_number_masked) <= 30',
                ['account_number_masked'],
            ),
        );
    }

    /** `''` dentro de un literal es una comilla escapada, no el cierre del literal. */
    public function test_respeta_la_comilla_escapada_dentro_de_un_literal(): void
    {
        $this->assertSame(
            "NEW.`descripcion` <> 'no''va'",
            Restriccion::reescribirConNew("descripcion <> 'no''va'", ['descripcion']),
        );
    }

    /**
     * Adivinar las columnas leyendo la expresión sería frágil, así que se
     * declaran. Si faltan, hay que fallar en la migración y no generar un
     * disparador que no comprueba nada.
     */
    public function test_exige_que_se_declaren_las_columnas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Restriccion::reescribirConNew('rate > 0', []);
    }

    /**
     * `IF NOT (expr)` y no `IF (NOT expr)`.
     *
     * Con NULL, `NOT NULL` vale NULL —que no es cierto— así que no aborta. Es
     * exactamente lo que hace un CHECK real: solo rechaza lo que evalúa a FALSE,
     * nunca lo que evalúa a NULL. Escribirlo al revés cambiaría la semántica
     * entre los dos motores, que es justo lo que esta clase existe para evitar.
     */
    public function test_el_disparador_conserva_la_semantica_del_check_ante_null(): void
    {
        $sql = Restriccion::sqlTrigger(
            'currencies', 'ck_currencies_decimals', 'decimal_places <= 4',
            ['decimal_places'], 'Una moneda no puede tener mas de 4 decimales.', 'INSERT',
        );

        $this->assertStringContainsString('IF NOT (NEW.`decimal_places` <= 4) THEN', $sql);
        $this->assertStringNotContainsString('IF (NOT', $sql);
    }

    public function test_el_disparador_lleva_nombre_evento_y_tabla_correctos(): void
    {
        $sql = Restriccion::sqlTrigger(
            'currencies', 'ck_currencies_decimals', 'decimal_places <= 4',
            ['decimal_places'], 'mensaje', 'INSERT',
        );

        $this->assertStringContainsString('CREATE TRIGGER `tg_ck_currencies_decimals_ins`', $sql);
        $this->assertStringContainsString('BEFORE INSERT ON `currencies`', $sql);
        $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", $sql);
    }

    public function test_el_evento_update_usa_su_propio_sufijo(): void
    {
        $sql = Restriccion::sqlTrigger('t', 'ck_x', 'a > 0', ['a'], 'm', 'UPDATE');

        $this->assertStringContainsString('CREATE TRIGGER `tg_ck_x_upd`', $sql);
        $this->assertStringContainsString('BEFORE UPDATE ON `t`', $sql);
    }

    /** Una comilla sin escapar en el mensaje cerraría el literal y rompería el SQL. */
    public function test_escapa_las_comillas_del_mensaje(): void
    {
        $sql = Restriccion::sqlTrigger('t', 'ck_x', 'a > 0', ['a'], "No 'va' asi", 'UPDATE');

        $this->assertStringContainsString("MESSAGE_TEXT = 'No ''va'' asi'", $sql);
    }

    /** MySQL solo admite BEFORE INSERT y BEFORE UPDATE para esto. */
    public function test_rechaza_un_evento_no_soportado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Restriccion::sqlTrigger('t', 'ck_x', 'a > 0', ['a'], 'm', 'DELETE');
    }

    /** `MESSAGE_TEXT` no admite más de 128 caracteres. */
    public function test_trunca_el_mensaje_al_limite_de_mysql(): void
    {
        $sql = Restriccion::sqlTrigger(
            't', 'ck_x', 'a > 0', ['a'], str_repeat('x', 300), 'INSERT',
        );

        preg_match("/MESSAGE_TEXT = '(x+)'/", $sql, $m);
        $this->assertSame(128, strlen($m[1] ?? ''));
    }

    public function test_la_version_check_es_un_alter_table(): void
    {
        $this->assertSame(
            'ALTER TABLE `t` ADD CONSTRAINT `ck_x` CHECK (a > 0)',
            Restriccion::sqlCheck('t', 'ck_x', 'a > 0'),
        );
    }
}
