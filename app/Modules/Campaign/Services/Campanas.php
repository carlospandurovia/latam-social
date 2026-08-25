<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Client\Services\CoberturaFacturacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta y movimiento de campañas (7.1).
 *
 * ### La sociedad que factura se resuelve a `starts_on`
 *
 * `BR-LE-003` dice «en la fecha de la operación». Para una campaña esa fecha es
 * **cuándo empieza a prestarse el servicio** (decisión de negocio, 2026-08-25),
 * no cuándo se teclea el alta.
 *
 * La diferencia se ve con un ejemplo: una campaña creada el 20 de diciembre para
 * arrancar el 1 de febrero. Si la cobertura de ese país cambia de sociedad el 1
 * de enero, resolver «hoy» daría la sociedad vieja y habría que corregirlo en
 * enero — y corregirlo es justo lo que `BR-LE-002` impide en cuanto la campaña
 * se confirma. Resolver a `starts_on` la hace nacer bien.
 *
 * ### Y se GUARDA, no se deduce
 *
 * `BR-LE-001` es explícito: *nunca se deduce de la configuración vigente en el
 * momento de la consulta*. Dentro de dos años, «¿quién facturó esta campaña?»
 * tiene que responderlo la fila, no una consulta a la cobertura de entonces —
 * que para entonces puede decir otra cosa y sonar igual de convincente.
 */
final class Campanas
{
    /**
     * @var array<string, string>
     */
    public const OBJETIVOS = [
        'awareness' => 'Notoriedad',
        'consideration' => 'Consideración',
        'conversion' => 'Conversión',
        'ugc' => 'Contenido de usuario (UGC)',
        'launch' => 'Lanzamiento',
        'event' => 'Evento',
    ];

    /**
     * Quién factura esta campaña, según el país del CLIENTE y la fecha de inicio.
     *
     * Devuelve el veredicto entero —no sólo la sociedad— porque quien pregunta
     * necesita poder explicar el «no» tanto como usar el «sí» (`BR-LE-004`).
     */
    public static function quienFactura(int $clienteId, string $empieza): CoberturaFacturacion
    {
        $paisId = (int) DB::table('client_organizations')->where('id', $clienteId)->value('country_id');

        return CoberturaFacturacion::resolver($paisId, $empieza);
    }

    /**
     * Da de alta una campaña en borrador y devuelve su uuid.
     *
     * Nace en `draft` y **con** su sociedad si la hay. Que un borrador pueda no
     * tenerla es lo que permite empezar a teclear una campaña de un país todavía
     * sin cobertura; lo que no puede es salir de borrador así, y eso lo impone
     * `ck_camp_billing_entity` en la base, no esta clase.
     *
     * @param array<string, mixed> $datos
     */
    public static function crear(array $datos, ?int $entidadId, int $autorId): string
    {
        $uuid = (string) Str::uuid();

        DB::table('campaigns')->insert([
            'uuid' => $uuid,
            'code' => $datos['code'],
            'name' => $datos['name'],
            'client_organization_id' => $datos['client_organization_id'],
            'client_brand_id' => $datos['client_brand_id'],
            'billing_legal_entity_id' => $entidadId,
            'objective' => $datos['objective'],
            'status' => EstadosDeCampana::BORRADOR,
            'revenue_amount' => $datos['revenue_amount'] ?? 0,
            'is_gratis' => (bool) ($datos['is_gratis'] ?? false),
            'currency_code' => $datos['currency_code'],
            'included_revision_rounds' => $datos['included_revision_rounds'] ?? 2,
            'min_creator_age' => $datos['min_creator_age'] ?? 0,
            'starts_on' => $datos['starts_on'],
            'ends_on' => $datos['ends_on'],
            'publication_deadline' => $datos['publication_deadline'] ?? null,
            'briefing' => $datos['briefing'] ?? null,
            'created_by_user_id' => $autorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    /**
     * Mueve una campaña de estado.
     *
     * `confirmed_at` se pone **la primera vez** que la campaña llega a un estado
     * confirmado, y no se vuelve a tocar. De ahí cuelga el congelado de la
     * sociedad (`tg_camp_entidad_congelada`): si se reescribiera en cada
     * transición, el congelado se soltaría y volvería a soltarse, que es como no
     * tenerlo.
     *
     * `closed_at` marca el final real, terminada o cancelada. Son dos datos
     * distintos a propósito: «cuándo se comprometió» y «cuándo dejó de estar
     * viva» responden preguntas distintas y una campaña cancelada tiene las dos.
     */
    public static function transicionar(object $campana, string $hasta): void
    {
        $cambios = ['status' => $hasta, 'updated_at' => now()];

        if ($campana->confirmed_at === null && in_array($hasta, EstadosDeCampana::confirmados(), true)) {
            $cambios['confirmed_at'] = now();
        }

        if (in_array($hasta, [EstadosDeCampana::TERMINADA, EstadosDeCampana::CANCELADA], true)) {
            $cambios['closed_at'] = now();
        }

        DB::table('campaigns')->where('id', $campana->id)->update($cambios);
    }

    /**
     * Por qué esta campaña **no** puede salir de borrador todavía.
     *
     * Devuelve `null` cuando sí puede. `BR-CAMPAIGN-004` dice que una campaña no
     * pasa a `approved` sin *presupuesto, cliente, marca y brief definidos*, y
     * hasta 7.2 no lo impedía nada: la regla estaba escrita en
     * `docs/06-BUSINESS-RULES.md` y el código dejaba aprobar una campaña vacía.
     *
     * Cliente y marca los garantiza el esquema —son `NOT NULL` con foránea—, así
     * que lo que queda por comprobar es lo otro:
     *
     * | Qué | Por qué |
     * |---|---|
     * | Al menos un requisito de formato | es lo mínimo que un creador necesita para decidir si acepta |
     * | El ingreso, declarado | cero es válido, pero «regalada» y «nadie puso el precio» no son lo mismo |
     * | La sociedad que factura | `BR-LE-001`, ya en 7.1 |
     *
     * **«Brief definido» = al menos un requisito** (decisión de negocio,
     * 2026-08-25). El texto del briefing queda opcional a propósito: no se puede
     * comprobar de verdad —un espacio en blanco lo cumpliría— así que exigirlo
     * añadiría fricción sin añadir garantía. Los formatos sí se comprueban, y
     * son lo que convierte «hay una campaña» en «hay algo que entregar».
     *
     * @return list<string> los motivos, vacío si puede salir
     */
    public static function loQueFaltaParaSalirDeBorrador(object $campana): array
    {
        $faltan = [];

        if ($campana->billing_legal_entity_id === null) {
            // El motivo lo cuenta la cobertura, no esta clase. `BR-LE-004` pide
            // decir QUE hacer, y «no hay ninguna» y «hay dos» se arreglan en
            // sitios distintos: repetir aqui un texto generico habria costado
            // el «dese de alta la cobertura en Entidades legales» que la version
            // de 7.1 si daba. Lo destapo una prueba de 7.1 al ponerse roja.
            $faltan[] = 'no se sabe que sociedad la factura. '.self::quienFactura(
                (int) $campana->client_organization_id,
                (string) $campana->starts_on,
            )->explicacion;
        }

        if (self::requisitos((int) $campana->id)->isEmpty()) {
            $faltan[] = 'el brief no dice que hay que entregar: anada al menos un formato '
                .'con su cantidad y sus plazos (BR-CAMPAIGN-004)';
        }

        if ((float) $campana->revenue_amount <= 0 && !(bool) $campana->is_gratis) {
            $faltan[] = 'el ingreso es cero y nadie ha dicho si la campana es gratuita '
                .'o si falta ponerle precio: son cosas distintas y de ahi sale el margen';
        }

        return $faltan;
    }

    /**
     * Los requisitos del brief, con el nombre del formato y su red.
     *
     * @return Collection<int, \stdClass>
     */
    public static function requisitos(int $campanaId): Collection
    {
        return DB::table('campaign_requirements as r')
            ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
            ->leftJoin('platforms as p', 'p.id', '=', 'f.platform_id')
            ->where('r.campaign_id', $campanaId)
            ->orderBy('p.name')->orderBy('f.code')
            ->get([
                'r.id', 'r.content_format_id', 'r.quantity', 'r.deadline_offset_days',
                'r.permanence_days', 'r.notes',
                // `content_formats` no tiene `name`: el nombre legible del
                // formato ES su `code` (`REEL`, `POST`, `STORY`...). Lo descubrio
                // la primera ejecucion de las pruebas con un 1054.
                'f.code as formato', 'p.name as red',
            ]);
    }

    /**
     * Un código de campaña libre, derivado del nombre del cliente y el año.
     *
     * `uq_camp_code` es único globalmente y son 20 caracteres. Se sufija igual
     * que el slug de marca y **con la misma lección de `T-17`**: el que
     * reintenta le dice qué ya probó, porque volver a preguntar dentro de la
     * misma transacción devuelve la misma respuesta.
     *
     * @param list<string> $evitando
     */
    public static function codigoLibre(string $prefijo, int $anio, array $evitando = []): string
    {
        $base = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefijo) ?? '');
        $base = mb_substr($base === '' ? 'CAMP' : $base, 0, 6);
        $n = 0;

        do {
            $n++;
            $codigo = sprintf('%s-%d-%03d', $base, $anio, $n);
        } while (in_array($codigo, $evitando, true) || self::ocupado($codigo));

        return $codigo;
    }

    private static function ocupado(string $codigo): bool
    {
        return DB::table('campaigns')->where('code', $codigo)->exists();
    }
}
