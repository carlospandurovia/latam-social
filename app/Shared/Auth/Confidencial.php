<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Support\Facades\Auth;

/**
 * Datos que sólo ve quien tiene permiso para verlos.
 *
 * ### Por qué existe
 *
 * `DEC-053` decidió que los datos fiscales y bancarios del creador viven detrás
 * de `creator.view_sensitive`, y por eso `/creadores/{uuid}/fiscal` y
 * `/creadores/{uuid}/pagos` exigen ese permiso. Pero el **listado** de creadores
 * y su **ficha** —que sólo exigen `creator.view`— imprimían el número de
 * documento **entero**, y el listado además dejaba buscar por él.
 *
 * O sea: en `creator_payment_methods` el número de cuenta se enmascara a cuatro
 * dígitos y en la bitácora se redacta, y a la vez el DNI del mismo creador
 * estaba a la vista de cualquiera con `creator.view` —que en `CimientosSeeder`
 * incluye a `content_reviewer`, un rol que no tiene nada que hacer con
 * documentos de identidad—.
 *
 * Peor que verlo: **buscar** por él. `LIKE '%40000001%'` sobre `document_number`
 * contesta «¿está esta persona en el sistema, y quién es?» a quien sólo debería
 * poder revisar contenido.
 *
 * ### Qué hace y qué no
 *
 * Enmascara **para mostrar**. No sustituye al permiso de la ruta: `/fiscal` y
 * `/pagos` siguen exigiendo `creator.view_sensitive` en el middleware, porque
 * una pantalla entera no se protege escondiendo un campo.
 */
final class Confidencial
{
    /** Cuántos caracteres finales quedan visibles. Los mismos que la máscara de cuenta bancaria. */
    private const VISIBLES = 4;

    /**
     * ¿Puede quien está mirando ver documentos de identidad completos?
     */
    public static function puedeVerDocumentos(): bool
    {
        $id = Auth::id();

        return $id !== null && Permisos::tiene((int) $id, 'creator.view_sensitive');
    }

    /**
     * El número de documento como debe verlo quien está mirando.
     *
     * Con permiso, entero. Sin permiso, los últimos cuatro. Un documento de
     * cuatro caracteres o menos se oculta del todo: enseñar «los últimos
     * cuatro» de algo que mide cuatro es enseñarlo entero.
     */
    public static function documento(?string $numero): string
    {
        $numero = trim((string) $numero);

        if ($numero === '') {
            return '—';
        }

        if (self::puedeVerDocumentos()) {
            return $numero;
        }

        if (mb_strlen($numero) <= self::VISIBLES) {
            return str_repeat('*', 8);
        }

        return str_repeat('*', 4).mb_substr($numero, -self::VISIBLES);
    }
}
