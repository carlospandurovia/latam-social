<?php

declare(strict_types=1);

namespace App\Shared\Eventos;

/**
 * Un hecho de negocio que ya pasó (`T-10`, 4.13).
 *
 * ### Por qué el payload es un array y no un objeto de dominio
 *
 * Porque `deptrac.yaml` dice `Communication: [Framework, Shared, Core, Identity]`
 * — y **Creator no está en esa lista**. Es deliberado, y está escrito en
 * `docs/03`: *«Communication no conoce el negocio: los datos llegan en el payload
 * del evento»*. Así un fallo del correo no arrastra al módulo de creadores, y el
 * grafo de dependencias sigue siendo acíclico.
 *
 * Si el payload fuera un `CreatorTaxProfile`, Communication tendría que importar
 * Creator y CI lo rechazaría. Con razón: el día que Finance quiera avisar de algo,
 * Communication tendría que conocer también Finance, y así hasta que conozca todo.
 *
 * El precio es real y conviene decirlo: **el payload no lo comprueba nadie**. Un
 * emisor que se olvide de una clave produce un correo con un `{{ }}` sin
 * sustituir — y por eso `Plantillas::renderizar()` revienta en vez de dejarlo
 * pasar.
 */
final class EventoOcurrido
{
    /**
     * @param array<string, string|int|float> $payload lo que necesita quien reacciona
     */
    public function __construct(
        public readonly string $nombre,
        public readonly string $tipoEntidad,
        public readonly int $idEntidad,
        public readonly array $payload = [],
    ) {}
}
