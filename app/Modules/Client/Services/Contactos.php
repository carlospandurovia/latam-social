<?php

declare(strict_types=1);

namespace App\Modules\Client\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Contactos del cliente, y el puesto de principal (iteración 4.3).
 *
 * ### Qué garantiza la base y qué no
 *
 * `contacts` tiene una columna puerta y una única compuesta:
 *
 * ```sql
 * primary_gate TINYINT UNSIGNED GENERATED ALWAYS AS
 *   (CASE WHEN is_primary = 1 AND status = 'active' THEN 1 ELSE NULL END) STORED
 * UNIQUE KEY uq_contacts_primary (primary_gate, client_organization_id, contact_type)
 * ```
 *
 * O sea: **un principal activo por cliente y por tipo**. Que la puerta incluya
 * `status` es deliberado y está bien pensado —desactivar al principal libera el
 * puesto sin tener que acordarse de bajarle la marca antes—, pero deja tres
 * situaciones que la base resuelve con un `1062` y que un operador no debería
 * ver nunca en pantalla. Las tres se comprobaron contra el motor antes de
 * escribir este archivo:
 *
 * 1. **El relevo.** Marcar a B como principal mientras A lo es choca. Y el
 *    orden importa: subir a B antes de bajar a A da
 *    `Duplicate entry '1-1-commercial' for key 'uq_contacts_primary'`. Primero
 *    se baja, después se sube.
 * 2. **La reactivación.** Un contacto desactivado conserva `is_primary = 1`
 *    —la puerta lo excluye por `status`, no le borra la marca—. Si mientras
 *    tanto otro ocupó el puesto, volver a activarlo choca.
 * 3. **El cambio de tipo.** Mover al principal comercial a «facturación»
 *    cuando facturación ya tiene principal choca igual.
 *
 * Las tres son la misma situación mirada desde tres sitios: *esta fila va a
 * quedarse con `primary_gate = 1` y el puesto está ocupado*. Por eso hay **una**
 * comprobación, no tres, y vive en `actualizar()`.
 *
 * ### Por qué el relevo se hace y no se rechaza (`DEC-075`)
 *
 * La alternativa era negarse: *«ya hay un principal comercial, quítaselo
 * primero»*. Se descartó porque obliga a una maniobra de dos pasos y **entre los
 * dos pasos el cliente se queda sin principal de ese tipo**. Una regla que exige
 * pasar por un estado peor que el de partida está mal puesta. Se releva en una
 * transacción y se dice a quién se relevó.
 */
final class Contactos
{
    public const TIPOS = [
        'commercial' => 'Comercial',
        'billing' => 'Facturación',
        'legal' => 'Legal',
        'operations' => 'Operaciones',
    ];

    /**
     * Da de alta un contacto y devuelve su id.
     *
     * Si viene marcado como principal, releva al que hubiera.
     *
     * @param array<string, mixed> $datos
     */
    public static function crear(int $clienteId, array $datos): int
    {
        $principal = (bool) ($datos['is_primary'] ?? false);
        $tipo = (string) $datos['contact_type'];
        $estado = (string) ($datos['status'] ?? 'active');

        // Se baja al anterior ANTES de insertar. Si se hiciera después, la
        // inserción sería la que chocase, y con ella se pierde la fila entera.
        if ($principal && $estado === 'active') {
            self::bajarPrincipal($clienteId, $tipo);
        }

        return (int) DB::table('contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_organization_id' => $clienteId,
            'full_name' => $datos['full_name'],
            'contact_email' => $datos['contact_email'],
            'phone' => $datos['phone'] ?? null,
            'position' => $datos['position'] ?? null,
            'contact_type' => $tipo,
            'is_primary' => $principal,
            'status' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Guarda los cambios de un contacto existente.
     *
     * @param array<string, mixed> $datos
     */
    public static function actualizar(object $contacto, array $datos): void
    {
        $principal = (bool) ($datos['is_primary'] ?? false);
        $tipo = (string) $datos['contact_type'];
        $estado = (string) $datos['status'];
        $clienteId = (int) $contacto->client_organization_id;

        // Tres cosas distintas pueden meter esta fila en el puesto: subirle la
        // marca, reactivarla teniéndola ya, o moverla a un tipo donde el puesto
        // esté ocupado. Las tres se cubren con la misma comprobación: si al
        // terminar esta fila va a tener `primary_gate = 1`, el puesto tiene que
        // estar libre antes de tocarla.
        if ($principal && $estado === 'active') {
            self::bajarPrincipal($clienteId, $tipo, excepto: (int) $contacto->id);
        }

        DB::table('contacts')->where('id', $contacto->id)->update([
            'full_name' => $datos['full_name'],
            'contact_email' => $datos['contact_email'],
            'phone' => $datos['phone'] ?? null,
            'position' => $datos['position'] ?? null,
            'contact_type' => $tipo,
            'is_primary' => $principal,
            'status' => $estado,
            'updated_at' => now(),
        ]);
    }

    /**
     * Quién ocupa el puesto de principal, si alguien.
     *
     * Devuelve la fila, no un booleano, porque quien pregunta casi siempre
     * necesita el nombre para decir a quién se está relevando.
     */
    public static function principal(int $clienteId, string $tipo, ?int $excepto = null): ?object
    {
        $consulta = DB::table('contacts')
            ->where('client_organization_id', $clienteId)
            ->where('contact_type', $tipo)
            ->where('is_primary', 1)
            ->where('status', 'active');

        if ($excepto !== null) {
            $consulta->where('id', '!=', $excepto);
        }

        return $consulta->first(['id', 'uuid', 'full_name']);
    }

    /**
     * Tipos que tienen contactos activos pero ninguno principal.
     *
     * No es un error —la base no lo exige— pero sí es un aviso: cuando llegue
     * la facturación habrá que saber a quién se le manda la factura. La ficha
     * del cliente lo marca en ámbar, igual que una marca sin categorías.
     *
     * @return list<string>
     */
    public static function tiposSinPrincipal(int $clienteId): array
    {
        /** @var list<string> $conActivos */
        $conActivos = DB::table('contacts')
            ->where('client_organization_id', $clienteId)
            ->where('status', 'active')
            ->distinct()->pluck('contact_type')->all();

        /** @var list<string> $conPrincipal */
        $conPrincipal = DB::table('contacts')
            ->where('client_organization_id', $clienteId)
            ->where('status', 'active')
            ->where('is_primary', 1)
            ->distinct()->pluck('contact_type')->all();

        return array_values(array_diff($conActivos, $conPrincipal));
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Libera el puesto de principal de un tipo, **serializando por cliente**.
     *
     * Le quita la marca al que lo ocupe, si lo ocupa alguien. No toca a los
     * inactivos: esos ya no están en la puerta, y quitarles la marca borraría
     * el dato de que en su día fueron el principal.
     *
     * ### El bloqueo, y por qué el `UPDATE` solo no bastaba (`T-17`)
     *
     * Cuando el puesto **está ocupado**, este `UPDATE` bloquea la fila del que
     * lo ocupa y dos peticiones simultáneas se ponen en fila solas. Pero cuando
     * el puesto está **libre** —el caso normal: el primer contacto de un tipo—
     * el `UPDATE` afecta a cero filas y **no toma ningún bloqueo**. Dos
     * peticiones que suban a dos contactos distintos del mismo cliente y tipo
     * pasan las dos de largo, insertan las dos, y la segunda se estrella contra
     * `uq_contacts_primary` con un `1062` en crudo.
     *
     * Un `UPDATE` que no encuentra nada es exactamente igual de silencioso que
     * un `DELETE` que no encuentra nada —la lección de `3.12`, otra vez—.
     *
     * Se bloquea la fila del **cliente**, que siempre existe, en vez de intentar
     * bloquear un puesto que puede no tener fila. Serializa sólo lo que hay que
     * serializar: los cambios de principal de ESE cliente. Dos clientes
     * distintos no se esperan.
     *
     * El bloqueo dura hasta que confirma la transacción, no hasta que termina
     * esta función. De ahí `exigirTransaccion()`: en autoconfirmación cada
     * sentencia es su propia transacción, el bloqueo se soltaría al instante y
     * esto parecería resuelto sin estarlo.
     */
    private static function bajarPrincipal(int $clienteId, string $tipo, ?int $excepto = null): void
    {
        self::exigirTransaccion();

        DB::table('client_organizations')->where('id', $clienteId)->lockForUpdate()->first(['id']);

        $consulta = DB::table('contacts')
            ->where('client_organization_id', $clienteId)
            ->where('contact_type', $tipo)
            ->where('is_primary', 1)
            ->where('status', 'active');

        if ($excepto !== null) {
            $consulta->where('id', '!=', $excepto);
        }

        $consulta->update(['is_primary' => 0, 'updated_at' => now()]);
    }

    /**
     * Sin transacción, el bloqueo de arriba no sirve para nada.
     *
     * Se comprueba en vez de comentarse. Un comentario que dice «esto tiene que
     * ir en una transacción» y no lo impide es una nota, no una regla —es la
     * misma lección de `T-24`—, y el síntoma de saltárselo no es un error: es
     * que la carrera vuelve, en silencio, meses después.
     */
    private static function exigirTransaccion(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'El relevo de contacto principal tiene que ir dentro de una transaccion: '
                .'fuera de ella el bloqueo del cliente se suelta antes del INSERT y la '
                .'carrera de T-17 vuelve.',
            );
        }
    }
}
