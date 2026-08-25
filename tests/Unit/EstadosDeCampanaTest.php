<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Campaign\Services\EstadosDeCampana as E;
use PHPUnit\Framework\TestCase;

/**
 * El grafo de estados de la campaña, probado sin base de datos (7.1).
 *
 * Un grafo de transiciones es fácil de escribir y fácil de equivocar en
 * silencio: una flecha de más deja hacer algo que no debería, y una de menos
 * deja al operador atascado sin saber por qué. Ninguna de las dos cosas la
 * detecta una prueba de pantalla, porque la pantalla sólo enseña lo que el grafo
 * le dice que enseñe.
 */
final class EstadosDeCampanaTest extends TestCase
{
    public function test_el_camino_normal_esta_completo(): void
    {
        $camino = [
            E::BORRADOR, E::EN_APROBACION, E::APROBADA,
            E::RECLUTANDO, E::EN_CURSO, E::EN_REVISION, E::TERMINADA,
        ];

        foreach (array_slice($camino, 0, -1) as $i => $desde) {
            $this->assertTrue(
                E::permitida($desde, $camino[$i + 1]),
                "falta la flecha {$desde} -> {$camino[$i + 1]}",
            );
        }
    }

    /**
     * **La prueba de la iteración.**
     *
     * Sin grafo, `UPDATE campaigns SET status='completed'` sobre un borrador es
     * válido para la base: un `CHECK` sólo ve la fila nueva, no de dónde venía.
     */
    public function test_no_se_salta_de_borrador_a_terminada(): void
    {
        $this->assertFalse(E::permitida(E::BORRADOR, E::TERMINADA));
        $this->assertFalse(E::permitida(E::BORRADOR, E::EN_CURSO));
        $this->assertFalse(E::permitida(E::EN_APROBACION, E::RECLUTANDO));
    }

    /** Aprobar es de finanzas; mover la campaña, de quien la monta. */
    public function test_aprobar_pide_otro_permiso_que_el_resto(): void
    {
        $this->assertSame('campaign.approve', E::permiso(E::EN_APROBACION, E::APROBADA));
        $this->assertSame('campaign.manage', E::permiso(E::APROBADA, E::RECLUTANDO));

        // Devolver a borrador NO es aprobar, y por eso no pide el permiso de
        // aprobacion: si lo pidiera, nadie podria corregir un dedazo sin
        // molestar a finanzas.
        $this->assertSame('campaign.manage', E::permiso(E::EN_APROBACION, E::BORRADOR));
    }

    public function test_una_transicion_que_no_existe_no_tiene_permiso(): void
    {
        $this->assertNull(E::permiso(E::BORRADOR, E::TERMINADA));
    }

    /** Se puede cancelar mientras la campaña esté viva. */
    public function test_se_cancela_desde_cualquier_estado_vivo(): void
    {
        foreach ([E::BORRADOR, E::EN_APROBACION, E::APROBADA, E::RECLUTANDO, E::EN_CURSO, E::EN_REVISION] as $estado) {
            $this->assertTrue(E::permitida($estado, E::CANCELADA), "no se puede cancelar desde {$estado}");
        }
    }

    /**
     * Y de una terminada no se sale. Cancelar una campaña terminada no es
     * cancelar: es negar los documentos que cuelgan de ella.
     */
    public function test_los_estados_terminales_no_tienen_salida(): void
    {
        $this->assertSame([], E::desde(E::TERMINADA));
        $this->assertSame([], E::desde(E::CANCELADA));
        $this->assertFalse(E::permitida(E::TERMINADA, E::CANCELADA));
        $this->assertFalse(E::permitida(E::CANCELADA, E::BORRADOR));
    }

    /**
     * Los confirmados son el complemento exacto de los iniciales.
     *
     * Se comprueba porque son tres listas que la base ya usa por separado
     * —`ck_camp_billing_entity`, `ck_camp_confirmed` y este grafo— y tres listas
     * iguales acaban siendo tres listas distintas.
     */
    public function test_confirmados_e_iniciales_no_se_pisan_ni_dejan_huecos(): void
    {
        $todos = array_keys(E::NOMBRES);

        $this->assertSame([], array_intersect(E::INICIALES, E::confirmados()));
        $this->assertEqualsCanonicalizing($todos, array_merge(E::INICIALES, E::confirmados()));
    }

    /** Todo estado del grafo tiene nombre para la pantalla, y al revés. */
    public function test_todos_los_estados_se_pueden_nombrar(): void
    {
        foreach (array_keys(E::NOMBRES) as $estado) {
            $this->assertIsArray(E::desde($estado), "{$estado} no esta en el grafo");
        }
    }

    /** El veto explica y ofrece salida, en vez de decir sólo que no. */
    public function test_el_veto_dice_que_si_se_puede_hacer(): void
    {
        $aviso = E::veto(E::BORRADOR, E::TERMINADA);

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('En aprobación', $aviso, 'tiene que ofrecer la salida real');
        $this->assertStringContainsString('Cancelada', $aviso);
    }

    public function test_el_veto_de_un_terminal_lo_dice_de_otra_forma(): void
    {
        $aviso = E::veto(E::TERMINADA, E::CANCELADA);

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('ya no cambia de estado', $aviso);
    }

    public function test_una_transicion_valida_no_tiene_veto(): void
    {
        $this->assertNull(E::veto(E::BORRADOR, E::EN_APROBACION));
    }
}
