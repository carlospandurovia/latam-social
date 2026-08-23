<?php

declare(strict_types=1);

namespace App\Modules\Creator\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Evalúa `BR-CREATOR-006`: qué le falta a un creador para poder trabajar.
 *
 * Hasta la iteración 3.5 esta regla era una frase en `docs/06` y nada más. La
 * 3.4 daba de alta al creador en `pending` y no había ninguna puerta que llevara
 * a `active`: todos los creadores del sistema se quedaban en la sala de espera.
 *
 * La regla enumera cinco condiciones. Dos de ellas —identidad verificada y
 * aceptación de términos— **no tenían dónde registrarse** hasta esta iteración;
 * ver la migración 000460. Se comprobaban tres de cinco, así que en la práctica
 * la regla decía otra cosa de la que estaba escrita.
 *
 * Aquí se comprueban las cinco, más la tutela de los menores
 * (`BR-CREATOR-010`), que no está en la lista de la 006 pero es un requisito
 * anterior: si el creador es menor, el que cobra es su tutor, y activarlo sin
 * tutela acreditada crea un creador al que no se le puede pagar.
 *
 * **Devuelve la lista completa, no un booleano.** Un `false` obliga al operador
 * a adivinar qué falta; una lista le dice exactamente qué pedirle al creador.
 * Ese fue el motivo de que la clase exista en vez de un `if` en el controlador.
 */
final class CompletitudOperativa
{
    public const IDENTIDAD = 'identidad';

    public const RED_SOCIAL = 'red_social';

    public const FISCAL = 'fiscal';

    public const MEDIO_PAGO = 'medio_pago';

    public const TERMINOS = 'terminos';

    public const TUTELA = 'tutela';

    /**
     * @return list<Requisito> En el orden en que se le piden al creador.
     */
    public static function revisar(int $creadorId): array
    {
        $creador = DB::table('creators')->where('id', $creadorId)->first([
            'id', 'birth_date', 'status',
            'identity_verified_at', 'identity_document_file_id',
        ]);

        if ($creador === null) {
            throw new \InvalidArgumentException("No existe el creador {$creadorId}.");
        }

        $esMenor = self::esMenor((string) $creador->birth_date);

        return [
            self::identidad($creador),
            self::redSocial($creadorId),
            self::fiscal($creadorId),
            self::tutela($creadorId, $esMenor),
            self::medioDePago($creadorId, $esMenor),
            self::terminos($creadorId),
        ];
    }

    /**
     * @param list<Requisito> $requisitos
     */
    public static function completa(array $requisitos): bool
    {
        foreach ($requisitos as $r) {
            if (!$r->cumple) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lo que falta, en una línea, para el mensaje de error y para la bitácora.
     *
     * @param list<Requisito> $requisitos
     * @return list<string>
     */
    public static function pendientes(array $requisitos): array
    {
        $faltan = [];
        foreach ($requisitos as $r) {
            if (!$r->cumple) {
                $faltan[] = $r->titulo;
            }
        }

        return $faltan;
    }

    // ----------------------------------------------------- las seis condiciones

    private static function identidad(object $creador): Requisito
    {
        $ok = $creador->identity_verified_at !== null;

        return new Requisito(
            codigo: self::IDENTIDAD,
            titulo: 'Identidad verificada',
            cumple: $ok,
            detalle: $ok
                ? 'Verificada el '.$creador->identity_verified_at.' con documento adjunto.'
                : 'Falta que un revisor coteje el documento de identidad y lo adjunte.',
            regla: 'BR-CREATOR-006',
        );
    }

    private static function redSocial(int $creadorId): Requisito
    {
        $n = DB::table('social_accounts')
            ->where('creator_id', $creadorId)
            ->where('verification_status', 'verified')
            ->where('is_active', 1)
            ->count();

        return new Requisito(
            codigo: self::RED_SOCIAL,
            titulo: 'Al menos una red social validada',
            cumple: $n > 0,
            detalle: $n > 0
                ? $n.' cuenta(s) verificada(s) y activa(s).'
                : 'No hay ninguna cuenta con la propiedad comprobada.',
            regla: 'BR-CREATOR-006',
        );
    }

    /**
     * `BR-CREATOR-013`: no existe el pago informal. Sin perfil tributario
     * aprobado y vigente no se activa, no se invita y no se liquida.
     */
    private static function fiscal(int $creadorId): Requisito
    {
        $perfil = DB::table('creator_tax_profiles as p')
            ->join('countries as c', 'c.id', '=', 'p.country_id')
            ->where('p.creator_id', $creadorId)
            ->where('p.status', 'approved')
            ->whereNull('p.valid_to')
            ->orderByDesc('p.id')
            ->first(['p.tax_regime_code', 'p.withholding_status', 'c.name as pais']);

        return new Requisito(
            codigo: self::FISCAL,
            titulo: 'Datos fiscales vigentes y aprobados',
            cumple: $perfil !== null,
            detalle: $perfil !== null
                ? "Régimen {$perfil->tax_regime_code} en {$perfil->pais}; retención: {$perfil->withholding_status}."
                : 'No hay perfil tributario aprobado y vigente (BR-CREATOR-013).',
            regla: 'BR-CREATOR-013',
        );
    }

    /**
     * `BR-CREATOR-010`: el menor cobra a través de su tutor, y la tutela activa
     * exige autorización firmada y prueba del parentesco —eso último lo impone
     * `ck_creator_guardians_docs` en la base, aquí solo se comprueba que exista.
     */
    private static function tutela(int $creadorId, bool $esMenor): Requisito
    {
        if (!$esMenor) {
            return new Requisito(
                codigo: self::TUTELA,
                titulo: 'Tutela acreditada (solo menores)',
                cumple: true,
                detalle: 'No aplica: es mayor de edad.',
                regla: 'BR-CREATOR-010',
            );
        }

        $tutor = DB::table('creator_guardians')
            ->where('creator_id', $creadorId)
            ->where('status', 'active')
            ->first(['full_name']);

        return new Requisito(
            codigo: self::TUTELA,
            titulo: 'Tutela acreditada (solo menores)',
            cumple: $tutor !== null,
            detalle: $tutor !== null
                ? "Tutela activa de {$tutor->full_name}, con autorización y parentesco acreditados."
                : 'Es menor de edad y no tiene una tutela activa. Sin tutor no hay a quién pagarle.',
            regla: 'BR-CREATOR-010',
        );
    }

    /**
     * `BR-FIN-003` y `BR-FIN-006`: verificado **y** fuera del periodo de
     * enfriamiento.
     *
     * `eligible_from IS NULL` cuenta como NO elegible, y es deliberado. La
     * columna admite NULL y ninguna restricción obliga a rellenarla al
     * verificar, así que «no hay enfriamiento» y «nadie ha fijado desde cuándo»
     * son hoy el mismo valor: exactamente el fallo que DEC-048 corrigió en la
     * retención. Mientras eso no se cierre en el modelo, el silencio no da
     * permiso. Ver `H-02` en docs/fase-3/3.5-ACTIVACION.md.
     */
    private static function medioDePago(int $creadorId, bool $esMenor): Requisito
    {
        $consulta = DB::table('creator_payment_methods')
            ->where('creator_id', $creadorId)
            ->where('status', 'verified')
            ->whereNotNull('eligible_from')
            ->where('eligible_from', '<=', now());

        // El pago del menor se emite a nombre del tutor (BR-CREATOR-010), así
        // que el medio de pago tiene que ser del tutor, no suyo.
        if ($esMenor) {
            $consulta->where('owner_type', 'guardian');
        }

        $medio = $consulta->orderByDesc('is_default')->first(['account_number_masked', 'bank_name', 'owner_type']);

        $titulo = $esMenor
            ? 'Medio de pago verificado a nombre del tutor'
            : 'Al menos un medio de pago verificado y elegible';

        if ($medio !== null) {
            return new Requisito(
                codigo: self::MEDIO_PAGO,
                titulo: $titulo,
                cumple: true,
                // Se enseña la MÁSCARA. El número real no sale de la base ni
                // aquí ni en ningún otro sitio.
                detalle: trim(($medio->bank_name ?? '').' '.$medio->account_number_masked),
                regla: 'BR-FIN-003',
            );
        }

        $hayPendiente = DB::table('creator_payment_methods')
            ->where('creator_id', $creadorId)
            ->whereIn('status', ['pending', 'verified'])
            ->exists();

        return new Requisito(
            codigo: self::MEDIO_PAGO,
            titulo: $titulo,
            cumple: false,
            detalle: $hayPendiente
                ? 'Hay un medio registrado, pero no está verificado o sigue en el periodo de enfriamiento (BR-FIN-006).'
                : 'No hay ningún medio de pago registrado.',
            regla: 'BR-FIN-003',
        );
    }

    /**
     * DEC-059: lo vigente es la aceptación de la versión vigente. No hace falta
     * revocar nada: publicar unos términos nuevos deja a todo el mundo pendiente
     * de aceptarlos, que es justo lo que se quiere.
     */
    private static function terminos(int $creadorId): Requisito
    {
        $codigo = (string) config('latam.terminos.creador', 'creator_terms');

        $version = DB::table('terms_versions')
            ->where('audience', 'creator')
            ->where('code', $codigo)
            ->whereNull('effective_to')
            ->first(['id', 'version']);

        if ($version === null) {
            // No es culpa del creador: no hay términos publicados. Se dice así
            // para que el operador sepa a quién reclamar.
            return new Requisito(
                codigo: self::TERMINOS,
                titulo: 'Aceptación vigente de los términos',
                cumple: false,
                detalle: "No hay ninguna versión vigente de «{$codigo}» publicada. Es un asunto de la plataforma, no del creador.",
                regla: 'BR-CREATOR-006',
            );
        }

        $aceptacion = DB::table('terms_acceptances')
            ->where('subject_type', 'creator')
            ->where('subject_id', $creadorId)
            ->where('terms_version_id', $version->id)
            ->first(['accepted_at', 'channel']);

        return new Requisito(
            codigo: self::TERMINOS,
            titulo: 'Aceptación vigente de los términos',
            cumple: $aceptacion !== null,
            detalle: $aceptacion !== null
                ? "Versión {$version->version} aceptada el {$aceptacion->accepted_at} (vía {$aceptacion->channel})."
                : "No consta que haya aceptado la versión vigente ({$version->version}).",
            regla: 'BR-CREATOR-006',
        );
    }

    /**
     * Menor de 18 el día de hoy. Se calcula en PHP y no en SQL a propósito: la
     * base y la aplicación pueden estar en husos distintos, y aquí la fecha que
     * manda es la del negocio.
     */
    private static function esMenor(string $fechaNacimiento): bool
    {
        return CarbonImmutable::parse($fechaNacimiento)->age < 18;
    }
}
