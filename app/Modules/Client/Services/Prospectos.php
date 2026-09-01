<?php

declare(strict_types=1);

namespace App\Modules\Client\Services;

use App\Shared\Audit\Bitacora;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Los contactos que llegan por la portada de marcas (9.21c).
 *
 * ### Los estados, y qué significa cada uno
 *
 * | Estado | Qué quiere decir |
 * |---|---|
 * | `new` | llegó y nadie lo ha mirado |
 * | `contacted` | alguien le escribió o le llamó |
 * | `qualified` | encaja: hay algo que vender aquí |
 * | `discarded` | no encaja, **con el motivo escrito** |
 * | `converted` | ya es cliente, y dice cuál |
 *
 * Sólo `new` y `contacted` ocupan la puerta `uq_clead_abierto`: lo cerrado deja
 * el hueco libre, así que la misma empresa puede volver a escribir el año que
 * viene y eso será un contacto nuevo de verdad.
 *
 * ### Descartar no es borrar
 *
 * De esta tabla sale «¿de dónde salió este cliente?» y «¿cuántos descartamos y
 * por qué?». La segunda es la única forma de darse cuenta de que se está
 * descartando mal, y por eso el motivo es obligatorio y la fila se queda.
 */
final class Prospectos
{
    /** @var array<string, string> */
    public const ESTADOS = [
        'new' => 'Nuevo — nadie lo ha mirado',
        'contacted' => 'Contactado',
        'qualified' => 'Encaja — hay algo que vender',
        'discarded' => 'Descartado',
        'converted' => 'Ya es cliente',
    ];

    /** Lo mínimo que explica un descarte. «No» no explica nada. */
    private const MOTIVO_MINIMO = 10;

    /**
     * Recoge un contacto de la portada.
     *
     * @param array<string, mixed> $datos
     */
    public static function recibir(array $datos): string
    {
        $uuid = (string) Str::uuid();

        DB::table('client_leads')->insert([
            'uuid' => $uuid,
            'company_name' => trim((string) $datos['company_name']),
            'contact_name' => trim((string) $datos['contact_name']),
            'email' => mb_strtolower(trim((string) $datos['email'])),
            'phone' => ($datos['phone'] ?? '') !== '' ? (string) $datos['phone'] : null,
            'country_id' => (int) $datos['country_id'],
            'website' => ($datos['website'] ?? '') !== '' ? (string) $datos['website'] : null,
            'message' => ($datos['message'] ?? '') !== '' ? (string) $datos['message'] : null,
            'source' => 'landing',
            'status' => 'new',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public static function bandeja(string $estado = 'new'): LengthAwarePaginator
    {
        return DB::table('client_leads as l')
            ->join('countries as p', 'p.id', '=', 'l.country_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.reviewed_by_user_id')
            ->leftJoin('client_organizations as c', 'c.id', '=', 'l.client_organization_id')
            ->when($estado !== '' && $estado !== 'todos', fn ($q) => $q->where('l.status', $estado))
            ->orderByDesc('l.id')
            ->select(['l.uuid', 'l.company_name', 'l.contact_name', 'l.email', 'l.phone',
                'l.website', 'l.message', 'l.status', 'l.source', 'l.submitted_at',
                'l.reviewed_at', 'l.note', 'p.name as pais', 'u.name as revisor',
                'c.commercial_name as cliente'])
            ->paginate(25);
    }

    /** @return Collection<string, int> */
    public static function conteos(): Collection
    {
        return DB::table('client_leads')
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');
    }

    /** Cuántos esperan a que alguien los mire. Para el panel de inicio. */
    public static function sinMirar(): int
    {
        return (int) DB::table('client_leads')->where('status', 'new')->count();
    }

    /**
     * Mueve un contacto de estado.
     *
     * `qualified` y `converted` no se piden desde aquí sin más: convertir exige
     * decir **en qué cliente**, y eso lo sabe quien acaba de crearlo.
     */
    public static function mover(string $uuid, string $estado, ?string $nota, int $usuarioId): void
    {
        if (!array_key_exists($estado, self::ESTADOS) || $estado === 'new') {
            throw new RuntimeException('Ese no es un estado al que se pueda mover un contacto.');
        }

        $nota = $nota === null ? null : trim($nota);

        if ($estado === 'discarded' && ($nota === null || mb_strlen($nota) < self::MOTIVO_MINIMO)) {
            throw new RuntimeException(
                'Descartar exige decir por que: es lo unico que permite darse cuenta despues de '
                .'que se estaba descartando mal.',
            );
        }

        $fila = DB::table('client_leads')->where('uuid', $uuid)->first(['id', 'status']);

        if ($fila === null) {
            throw new RuntimeException('No existe ese contacto.');
        }

        if ($estado === 'converted') {
            throw new RuntimeException(
                'Para dar por convertido un contacto hace falta decir en que cliente: '
                .'creelo primero y enlacelo desde su ficha.',
            );
        }

        DB::table('client_leads')->where('id', $fila->id)->update([
            'status' => $estado,
            'note' => $nota === null || $nota === '' ? null : mb_substr($nota, 0, 500),
            'reviewed_by_user_id' => $usuarioId,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'client_lead.moved',
            tipoEntidad: 'client_lead',
            idEntidad: (int) $fila->id,
            cambios: ['estado' => ['antes' => (string) $fila->status, 'despues' => $estado]],
        );
    }

    /**
     * Enlaza el contacto con el cliente en que se convirtió.
     *
     * Se hace en dos pasos y no en uno a propósito: crear una organización con su
     * perfil fiscal ya es una pantalla entera con sus reglas, y duplicarla aquí
     * sería tener dos sitios donde se crea un cliente —que es como acaban
     * divergiendo—. Aquí sólo se dice cuál.
     */
    public static function convertir(string $uuid, int $clienteId, int $usuarioId): void
    {
        $fila = DB::table('client_leads')->where('uuid', $uuid)->first(['id', 'status']);

        if ($fila === null) {
            throw new RuntimeException('No existe ese contacto.');
        }

        DB::table('client_leads')->where('id', $fila->id)->update([
            'status' => 'converted',
            'client_organization_id' => $clienteId,
            'reviewed_by_user_id' => $usuarioId,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'client_lead.converted',
            tipoEntidad: 'client_lead',
            idEntidad: (int) $fila->id,
            cambios: ['cliente' => ['antes' => null, 'despues' => (string) $clienteId]],
        );
    }
}
