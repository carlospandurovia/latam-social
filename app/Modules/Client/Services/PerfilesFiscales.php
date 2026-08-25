<?php

declare(strict_types=1);

namespace App\Modules\Client\Services;

use App\Shared\Database\Vigencia;
use Illuminate\Support\Facades\DB;

/**
 * La identidad fiscal del cliente, por país y con vigencia (iteración 4.4).
 *
 * De aquí salen `receiver_legal_name_snapshot`, `receiver_tax_id_snapshot` y
 * `receiver_address_snapshot` de `invoices`. O sea: **el nombre y el RUC que se
 * imprimen en una factura**.
 *
 * ### `valid_to` es INCLUSIVO. Es el defecto que más veces ha aparecido
 *
 * Un periodo `2026-01-01 → 2026-05-31` incluye el 31 de mayo. Cerrar el anterior
 * con el `valid_from` del siguiente los deja **solapados un día**, y ese día la
 * pregunta *«¿con qué RUC se factura hoy?»* tiene dos respuestas.
 *
 * Este mismo error apareció en seis sitios distintos de este proyecto —tarifas
 * (`H-16`), el perfil fiscal del creador (`T-12`), sus pruebas, su suite, la
 * publicación de términos y la suite de activación—. Se cierra con
 * `valid_from - 1 día` y no de otra forma, y el cálculo vive en
 * `Shared\Database\Vigencia`: en 4.5 hizo falta otra vez, y un `subDay()` por
 * cada tabla con periodos es como se llegó a seis.
 *
 * Desde 3.10 el disparador `tg_ctxp_sin_solape_*` lo rechaza en la base, así que
 * hoy el error saldría como un `45000` en vez de como un dato malo silencioso.
 * Eso no lo convierte en aceptable: un rechazo de la base delante del operador
 * sigue siendo un fallo de esta capa.
 *
 * ### Lo que la base impone y esta clase traduce
 *
 * | Regla | Qué prohíbe | Cómo llega al operador |
 * |---|---|---|
 * | `tg_ctxp_sin_solape_*` | dos identidades del mismo cliente y país solapadas | `45000` → se evita cerrando bien |
 * | `uq_ctxp_current` | dos vigentes del mismo cliente y país | lo cubre el anterior |
 * | `uq_ctxp_taxid` | el mismo documento vigente en **dos clientes** del mismo país | `1062` crudo → `chocaConOtroCliente()` |
 * | `ck_ctxp_dates` | `valid_to` anterior a `valid_from` | se evita con la guarda `DEC-071` |
 * | `no_delete` | borrar un perfil | no se ofrece borrar |
 */
final class PerfilesFiscales
{
    /**
     * El perfil vigente de un cliente en un país, si lo hay.
     *
     * «Vigente» es `valid_to IS NULL`, que es lo que mira `current_gate`. No se
     * compara contra la fecha de hoy: un periodo cerrado ayer sigue cerrado.
     */
    public static function vigente(int $clienteId, int $paisId): ?object
    {
        return DB::table('client_tax_profiles')
            ->where('client_organization_id', $clienteId)
            ->where('country_id', $paisId)
            ->whereNull('valid_to')
            ->first();
    }

    /**
     * Fecha con la que hay que cerrar el periodo anterior.
     *
     * El día ANTES de que empiece el nuevo, porque `valid_to` es inclusivo.
     *
     * El cálculo NO vive aquí: vive en `Vigencia`, en `Shared`. Esta era la
     * séptima copia de un `subDay()` que ya se había hecho mal en seis sitios,
     * y en 4.5 hacía falta una octava para `legal_entity_countries`. Se queda el
     * nombre —lo llaman el controlador y sus pruebas— pero delega.
     */
    public static function cerrarElDiaAntes(string $empiezaElNuevo): string
    {
        return Vigencia::cerrarElDiaAntesDe($empiezaElNuevo);
    }

    /**
     * Otro cliente que ya use ese documento como identidad **vigente** en ese
     * país, si lo hay.
     *
     * `uq_ctxp_taxid` lo prohíbe y contesta con
     * `Duplicate entry '1-1-RUC-20123456789' for key 'uq_ctxp_taxid'`, que no le
     * dice nada a nadie. Lo que ese choque significa casi siempre es que **la
     * misma empresa está dada de alta dos veces**, y eso se arregla mirando el
     * otro cliente, no cambiando el número.
     *
     * Sólo mira los vigentes, igual que el índice: si el otro cliente cerró ese
     * periodo, el documento queda libre. Una empresa puede cambiar de manos.
     */
    public static function chocaConOtroCliente(
        int $paisId,
        string $tipo,
        string $numero,
        int $exceptoClienteId,
    ): ?object {
        return DB::table('client_tax_profiles as ctp')
            ->join('client_organizations as co', 'co.id', '=', 'ctp.client_organization_id')
            ->where('ctp.country_id', $paisId)
            ->where('ctp.tax_id_type', $tipo)
            ->where('ctp.tax_id_number', $numero)
            ->whereNull('ctp.valid_to')
            ->where('ctp.client_organization_id', '!=', $exceptoClienteId)
            ->first(['co.uuid', 'co.commercial_name', 'co.client_code']);
    }

    /**
     * Da de alta un periodo nuevo, cerrando el vigente el día antes.
     *
     * Devuelve el id del perfil nuevo. **Va siempre dentro de una transacción**
     * de quien llama: cerrar el anterior y no abrir el nuevo deja al cliente sin
     * identidad fiscal, que es peor que no haber tocado nada.
     *
     * El orden —cerrar y luego abrir— tampoco es una preferencia. `uq_ctxp_current`
     * sólo admite un vigente por cliente y país; al revés, la base lo rechaza.
     *
     * @param array<string, mixed> $datos
     */
    public static function abrirPeriodo(int $clienteId, int $paisId, array $datos, ?object $vigente): int
    {
        if ($vigente !== null) {
            DB::table('client_tax_profiles')->where('id', $vigente->id)->update([
                'valid_to' => self::cerrarElDiaAntes((string) $datos['valid_from']),
                'updated_at' => now(),
            ]);
        }

        return (int) DB::table('client_tax_profiles')->insertGetId([
            'client_organization_id' => $clienteId,
            'country_id' => $paisId,
            'legal_name' => $datos['legal_name'],
            'tax_id_type' => $datos['tax_id_type'],
            'tax_id_number' => $datos['tax_id_number'],
            'address_line1' => $datos['address_line1'],
            'address_line2' => $datos['address_line2'] ?? null,
            'city' => $datos['city'],
            'region' => $datos['region'] ?? null,
            'postal_code' => $datos['postal_code'] ?? null,
            'billing_email' => $datos['billing_email'] ?? null,
            'payment_term_days' => $datos['payment_term_days'],
            'valid_from' => $datos['valid_from'],
            'valid_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
