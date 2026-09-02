<?php

declare(strict_types=1);

namespace App\Shared\Config;

use App\Shared\Audit\Bitacora;

/**
 * ¿Qué instalación es ésta, y qué se le deja hacer de verdad? (9.22a, `DEC-029`)
 *
 * ### De dónde sale
 *
 * De haber cerrado `9.9e` una hora antes. Hasta entonces el sistema **no podía
 * mandar nada**: armaba un XML y lo dejaba en la base. Desde `9.9e` sí puede, y
 * el día que puede aparece el fallo que `DEC-029` llamaba *«el más caro posible
 * de este diseño»*:
 *
 * > una conexión de producción resolviéndose fuera de producción.
 *
 * El camino es de lo más normal: se restaura una copia de la base de producción
 * en un servidor de pruebas —para reproducir un fallo, para enseñar el sistema,
 * para probar una migración—, alguien abre una factura que ya existía, ve el
 * botón «Mandar a la administración» y lo pulsa. **SUNAT recibe un comprobante
 * fiscal de verdad**, con su serie y su correlativo, emitido desde una máquina
 * que nadie considera real. No se deshace: se anula con una comunicación de baja
 * y el correlativo se quema.
 *
 * Y no hacía falta ningún descuido raro para llegar ahí. Sólo una copia y un
 * clic.
 *
 * ### Por qué esto vive en la máquina y no en la base
 *
 * Es la excepción a `DEC-190`, y la razón es exactamente la contraria a la que
 * parece. `DEC-190` saca la configuración de la máquina **para poder cambiarla
 * sin entrar por SSH**. Aquí no se guarda un ajuste: se guarda la **identidad de
 * la máquina**, que es precisamente lo que no puede viajar con una copia de los
 * datos.
 *
 * Puesto en la base, `entorno = 'production'` viajaría dentro del volcado y la
 * barrera se abriría sola en el único momento en que hace falta que esté
 * cerrada. Una barrera que la copia trae desactivada no es una barrera.
 *
 * ### El límite, dicho antes de que alguien lo descubra solo
 *
 * Esto protege de **copiar la base**, que es lo que se hace todas las semanas.
 * NO protege de clonar el servidor entero, que se lleva también el archivo de
 * entorno. Para eso haría falta atar la identidad a algo del propio servidor, y
 * eso es otra iteración —y otra discusión—.
 */
final class Instalacion
{
    /** Los nombres que se le enseñan a una persona. */
    private const NOMBRES = [
        'production' => 'Producción',
        'staging' => 'Preproducción',
        'testing' => 'Pruebas',
        'local' => 'Desarrollo',
    ];

    /** Qué dice ser esta instalación. */
    public static function entorno(): string
    {
        $entorno = config('instalacion.entorno');

        return is_string($entorno) && trim($entorno) !== '' ? trim($entorno) : 'production';
    }

    public static function esProduccion(): bool
    {
        return self::entorno() === 'production';
    }

    /**
     * En palabras.
     *
     * Un nombre que no está en la lista se enseña **tal cual y no como
     * "Desconocido"**: quien puso «qa-lima» en su servidor quiere leer
     * «qa-lima», y taparlo con una etiqueta genérica esconde justo el dato que
     * dice de qué máquina se está hablando.
     */
    public static function nombre(): string
    {
        return self::NOMBRES[self::entorno()] ?? self::entorno();
    }

    /** ¿Está abierta la anulación de `DEC-029`? */
    public static function anulacionAbierta(): bool
    {
        return (bool) config('instalacion.permitir_conexiones_de_produccion', false);
    }

    /**
     * ¿Puede esta instalación hablar con el entorno `$entornoConexion`?
     *
     * La pregunta se hace sobre el entorno que se PIDE, no sobre la conexión que
     * se encuentre. Es a propósito: en una máquina de pruebas no se debe ni
     * buscar una conexión de producción, y hacerlo al revés —buscar primero y
     * comprobar después— deja el secreto ya descifrado en memoria antes de saber
     * si se podía mirar.
     */
    public static function puedeUsar(string $entornoConexion): bool
    {
        return self::porQueNoPuedeUsar($entornoConexion) === null;
    }

    /**
     * `null` si puede; el motivo en palabras si no.
     *
     * Devuelve una frase y no un booleano porque esto se **enseña en pantalla
     * antes de pulsar el botón**, igual que `porQueNoPuede()` en `9.9e`: un
     * botón que se puede pulsar y luego explota es peor que un botón que no
     * está, y bastante peor que un botón acompañado del motivo.
     */
    public static function porQueNoPuedeUsar(string $entornoConexion): ?string
    {
        if ($entornoConexion !== 'production') {
            // Contra un entorno de pruebas manda cualquiera, produccion
            // incluida: no hay nada que proteger al otro lado.
            return null;
        }

        if (self::esProduccion() || self::anulacionAbierta()) {
            return null;
        }

        return sprintf(
            'Esta instalación es «%s», no producción, y lo que se iba a usar es una conexión de PRODUCCIÓN. '
            .'Lo que salga de aquí llegaría igual de real que desde el servidor de verdad, '
            .'y un comprobante fiscal emitido por error no se borra: se anula, y el correlativo se pierde. '
            .'Si esta máquina sí es la de verdad, corrija APP_ENV; si de verdad hace falta mandar desde aquí, '
            .'se abre con PERMITIR_CONEXIONES_DE_PRODUCCION.',
            self::nombre(),
        );
    }

    /**
     * Deja escrito que la anulación se ejerció.
     *
     * `DEC-029` pedía que fuese *«temporal, permisionada y auditada»*. De las
     * tres, **hoy sólo está la tercera**, y se dice en vez de dejar creer que
     * están las tres: quien puede editar el archivo del servidor puede abrirla,
     * y no caduca sola. Lo que sí queda es el rastro, que es lo que contesta a
     * «¿por qué salió esto desde la máquina de pruebas?».
     */
    public static function anotarAnulacion(string $proposito, string $entornoConexion): void
    {
        if (!self::anulacionAbierta() || self::esProduccion() || $entornoConexion !== 'production') {
            return;
        }

        Bitacora::registrar('integration.production_override', 'installation', null, [
            'entorno' => ['antes' => self::entorno(), 'despues' => $entornoConexion],
            'proposito' => ['antes' => null, 'despues' => $proposito],
        ]);
    }

    /**
     * La franja de todas las pantallas del panel.
     *
     * Ámbar y no rojo cuando la instalación no es producción: no está roto nada,
     * sólo conviene saber dónde se está antes de pulsar algo. **Rojo cuando la
     * anulación está abierta**, porque ahí sí: la barrera está levantada y
     * cualquier cosa que se mande sale de verdad.
     */
    public static function aviso(): ?Aviso
    {
        if (self::esProduccion()) {
            // En la maquina de verdad no se dice nada: un aviso permanente que
            // ve todo el mundo todos los dias deja de leerse, y entonces
            // tampoco se lee el que importa.
            return null;
        }

        if (self::anulacionAbierta()) {
            return Aviso::rojo(sprintf(
                'Esta instalación es «%s», pero la barrera de entorno está ABIERTA: puede hablar con '
                .'servicios de producción y lo que mande saldrá de verdad. Se cierra quitando '
                .'PERMITIR_CONEXIONES_DE_PRODUCCION del entorno del servidor.',
                self::nombre(),
            ));
        }

        return Aviso::ambar(sprintf(
            'Esta instalación es «%s». Nada de lo que se haga aquí sale a servicios de producción.',
            self::nombre(),
        ));
    }
}
