<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * Una cosa que conviene atender, con su prioridad (9.17b).
 *
 * ### Por qué no se llama «bloqueo»
 *
 * Porque no lo es, y el nombre importa. `DEC-190` lo dice con las palabras del
 * negocio: *«no me digas que algo es un stopper, eso no debe ser así; ponme
 * prioridades y alguna nota en un badge en rojo o amarillo, según la
 * importancia»*. Un `Aviso` informa y enlaza al sitio donde se arregla. Nunca
 * impide nada.
 *
 * ### Los tres niveles, y el criterio para elegir uno
 *
 * - **`ROJO`** — un tercero lo va a notar, o hay dinero o una obligación legal
 *   de por medio. El correo no sale, no hay logotipo, un país con clientes no lo
 *   puede facturar nadie.
 * - **`AMBAR`** — conviene, y mientras tanto el sistema se sostiene con el valor
 *   de partida. No hay favicon propio, el pie legal está vacío.
 * - **`VERDE`** — no falta nada en esa área. No se emite: lo pone el panel
 *   cuando un área no devuelve ningún aviso, para que «sin avisos» y «sin
 *   comprobar» no se parezcan.
 *
 * Si dudas entre rojo y ámbar, la pregunta es: *¿lo va a ver alguien de fuera?*
 */
final class Aviso
{
    public const ROJO = 'rojo';

    public const AMBAR = 'ambar';

    public const VERDE = 'verde';

    /**
     * @param string $texto Qué falta y qué pasa mientras tanto. Se le enseña a
     *                      una persona que a lo mejor no construyó el sistema,
     *                      así que «`ck_pb_correo` sin valor» no vale.
     */
    public function __construct(
        public readonly string $nivel,
        public readonly string $texto,
    ) {}

    public static function rojo(string $texto): self
    {
        return new self(self::ROJO, $texto);
    }

    public static function ambar(string $texto): self
    {
        return new self(self::AMBAR, $texto);
    }

    /**
     * Adapta los avisos que ya devuelven `Marca` y `Terminos`.
     *
     * Las dos pantallas nacieron antes que este registro y cada una tiene los
     * suyos en su propio formato --`['nivel' => …, 'texto' => …]`--. Reescribir
     * las dos para que devuelvan objetos habría tocado código que funciona y
     * que sus propias pruebas ya fijan; adaptarlo aquí cuesta seis lineas y deja
     * a cada pantalla dueña de sus avisos.
     *
     * @param list<array{nivel: string, texto: string}> $avisos
     * @return list<self>
     */
    public static function desdeArrays(array $avisos): array
    {
        return array_map(
            static fn (array $aviso): self => new self($aviso['nivel'], $aviso['texto']),
            $avisos,
        );
    }
}
