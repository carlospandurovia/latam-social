<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * El certificado con el que firma cada sociedad (9.9c).
 *
 * ### La lectura va partida en dos, como en `9.17d`
 *
 * `vigente()` devuelve **lo que se le enseña a una persona**: de quién es, quién
 * lo emitió, hasta cuándo vale. `material()` devuelve el PEM en claro y lo llama
 * únicamente quien firma. Es `DEC-226` otra vez: una pantalla que puede pedir un
 * secreto acaba enseñándolo, aunque sea en un log.
 *
 * ### El PKCS#12 de SUNAT y OpenSSL 3
 *
 * Esto costó descubrirlo y va a pasarle a quien despliegue: **los `.pfx` que
 * emite SUNAT usan cifrado antiguo** —`pbeWithSHA1And40BitRC2-CBC`— y OpenSSL 3
 * se niega a leerlo si el proveedor `legacy` no está activo. El error que
 * devuelve PHP es `digital envelope routines::unsupported`, que no dice nada a
 * quien lo lee.
 *
 * Así que se distingue: si el fallo es ése, el mensaje **dice la orden exacta**
 * para convertir el archivo. Si es la contraseña, lo dice también. Un «no se
 * pudo leer el certificado» habría mandado a alguien a probar contraseñas
 * durante una tarde.
 */
final class Certificados
{
    /** @var array<string, string> */
    public const ENTORNOS = ['sandbox' => 'Pruebas', 'production' => 'Producción'];

    /** @var array<string, string> */
    public const ESTADOS = [
        'active' => 'En uso',
        'replaced' => 'Reemplazado por uno más nuevo',
        'revoked' => 'Revocado',
    ];

    /**
     * Con cuánta antelación avisa el panel de que un certificado caduca.
     *
     * Es un umbral de AVISO: no bloquea nada y no cambia ningún dato. Por eso
     * vive aquí y no en una tabla —mismo criterio que `HORAS_COLGADO` en
     * `9.12`—. Treinta días es lo que tarda en la práctica renovar uno.
     */
    private const DIAS_DE_AVISO = 30;

    /** Lo mínimo que explica una revocación. */
    private const MOTIVO_MINIMO = 10;

    // ------------------------------------------------------------- lecturas

    /**
     * El certificado en uso de una sociedad, **sin el material**.
     *
     * Devuelve `null` y no lanza: una sociedad sin certificado es el estado
     * normal de una instalación recién montada, y quien llama tiene que poder
     * decirlo con palabras en vez de estrellarse.
     */
    public static function vigente(int $sociedadId, string $entorno = 'production'): ?object
    {
        if (!Schema::hasTable('signing_certificates')) {
            return null;
        }

        return DB::table('signing_certificates')
            ->where('legal_entity_id', $sociedadId)
            ->where('environment', $entorno)
            ->where('status', 'active')
            ->first(self::COLUMNAS_VISIBLES);
    }

    /**
     * Todos, con su sociedad, para la pantalla. Nunca el material.
     *
     * @return Collection<int, \stdClass>
     */
    public static function todos(): Collection
    {
        return DB::table('signing_certificates as sc')
            ->join('legal_entities as le', 'le.id', '=', 'sc.legal_entity_id')
            ->leftJoin('users as u', 'u.id', '=', 'sc.uploaded_by_user_id')
            ->orderBy('le.code')->orderBy('sc.environment')->orderByDesc('sc.valid_to')
            ->get(array_merge(
                array_map(static fn (string $c): string => 'sc.'.$c, self::COLUMNAS_VISIBLES),
                ['le.code as sociedad', 'le.legal_name as sociedad_nombre',
                    'le.tax_id_number as sociedad_ruc', 'u.name as autor'],
            ));
    }

    /**
     * El PEM en claro. **Lo llama quien firma, y nadie más.**
     *
     * No pasa por ninguna pantalla y no entra en la bitácora: la bitácora anota
     * QUÉ cambió, no qué valor (`DEC-226`).
     */
    public static function material(int $id): string
    {
        $cifrado = DB::table('signing_certificates')->where('id', $id)->value('pem_cipher');

        if ($cifrado === null) {
            throw new RuntimeException('No existe ese certificado.');
        }

        return Crypt::decryptString((string) $cifrado);
    }

    // ------------------------------------------------------------- escritura

    /**
     * Carga un certificado y deja el anterior como reemplazado.
     *
     * @param string $contenido Los bytes del `.pfx`, o el PEM tal cual.
     * @param string|null $clave La del `.pfx`. **No se guarda**: se usa aquí y se olvida.
     */
    public static function cargar(
        int $sociedadId,
        string $entorno,
        string $contenido,
        ?string $clave,
        int $usuarioId,
    ): string {
        [$pem, $certificado, $origen] = self::abrir($contenido, $clave);
        $datos = self::leerMetadatos($certificado);

        if ($datos['valid_to'] <= now()) {
            throw new RuntimeException(sprintf(
                'Ese certificado caduco el %s: uno vencido no firma nada.',
                $datos['valid_to']->format('d/m/Y'),
            ));
        }

        $uuid = (string) Str::uuid();

        DB::transaction(static function () use (
            $uuid, $sociedadId, $entorno, $pem, $origen, $datos, $usuarioId,
        ): void {
            // Se cierra el anterior ANTES de abrir el nuevo: `uq_cert_activo`
            // solo admite uno activo por sociedad y entorno, asi que hacerlo al
            // reves lo rechaza la base. Misma leccion que la cobertura de `4.5`.
            DB::table('signing_certificates')
                ->where('legal_entity_id', $sociedadId)
                ->where('environment', $entorno)
                ->where('status', 'active')
                ->update(['status' => 'replaced', 'replaced_at' => now(), 'updated_at' => now()]);

            DB::table('signing_certificates')->insert([
                'uuid' => $uuid,
                'legal_entity_id' => $sociedadId,
                'environment' => $entorno,
                'subject_name' => $datos['subject'],
                'issuer_name' => $datos['issuer'],
                'serial_number' => $datos['serial'],
                'tax_id_number' => $datos['ruc'],
                'valid_from' => $datos['valid_from'],
                'valid_to' => $datos['valid_to'],
                'fingerprint_sha256' => $datos['huella'],
                'pem_cipher' => Crypt::encryptString($pem),
                'source' => $origen,
                'status' => 'active',
                'uploaded_by_user_id' => $usuarioId,
                'uploaded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Ni el PEM ni la clave del `.pfx` entran aqui (`DEC-226`). Lo que
            // se anota es QUE certificado se puso, que es lo que hace falta
            // para contestar «.con cual se firmo aquello?».
            Bitacora::registrar(
                accion: 'signing_certificate.uploaded',
                tipoEntidad: 'signing_certificate',
                idEntidad: null,
                cambios: [
                    'sociedad' => ['antes' => null, 'despues' => (string) $sociedadId],
                    'entorno' => ['antes' => null, 'despues' => $entorno],
                    'huella' => ['antes' => null, 'despues' => $datos['huella']],
                    'caduca' => ['antes' => null, 'despues' => $datos['valid_to']->toDateString()],
                ],
            );
        });

        return $uuid;
    }

    public static function revocar(string $uuid, string $motivo): void
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new RuntimeException(
                'El motivo tiene que explicar que paso: una revocacion muda no se puede defender.',
            );
        }

        $fila = DB::table('signing_certificates')->where('uuid', $uuid)->first(['id', 'status']);

        if ($fila === null) {
            throw new RuntimeException('No existe ese certificado.');
        }

        if ($fila->status === 'revoked') {
            throw new RuntimeException('Ese certificado ya estaba revocado.');
        }

        DB::table('signing_certificates')->where('id', $fila->id)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_reason' => mb_substr($motivo, 0, 255),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'signing_certificate.revoked',
            tipoEntidad: 'signing_certificate',
            idEntidad: (int) $fila->id,
            cambios: [
                'estado' => ['antes' => (string) $fila->status, 'despues' => 'revoked'],
                'motivo' => ['antes' => null, 'despues' => mb_substr($motivo, 0, 255)],
            ],
        );
    }

    // --------------------------------------------------------------- avisos

    /** @return list<Aviso> */
    public static function avisos(): array
    {
        if (!Schema::hasTable('signing_certificates')) {
            return [];
        }

        $avisos = [];

        // Una sociedad activa que no tiene con que firmar. Rojo: el dia que se
        // emita, no se emite --y eso lo descubre el cliente esperando su
        // factura, no nosotros--.
        $sinCertificado = DB::table('legal_entities as le')
            ->where('le.status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('signing_certificates as sc')
                ->whereColumn('sc.legal_entity_id', 'le.id')
                ->where('sc.status', 'active'))
            ->pluck('le.code');

        if ($sinCertificado->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Sin certificado de firma: %s. Esa sociedad no puede emitir comprobantes electrónicos.',
                $sinCertificado->implode(', '),
            ));
        }

        // Ya caducado y todavia marcado como el que se usa.
        $caducados = DB::table('signing_certificates as sc')
            ->join('legal_entities as le', 'le.id', '=', 'sc.legal_entity_id')
            ->where('sc.status', 'active')
            ->where('sc.valid_to', '<=', now())
            ->pluck('le.code');

        if ($caducados->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'Certificado caducado y todavía en uso: %s. Lo que se firme con él lo rechaza la administración.',
                $caducados->implode(', '),
            ));
        }

        // A punto de caducar. Ambar: hoy funciona, y renovarlo lleva dias.
        $porCaducar = DB::table('signing_certificates as sc')
            ->join('legal_entities as le', 'le.id', '=', 'sc.legal_entity_id')
            ->where('sc.status', 'active')
            ->where('sc.valid_to', '>', now())
            ->where('sc.valid_to', '<=', now()->addDays(self::DIAS_DE_AVISO))
            ->get(['le.code', 'sc.valid_to']);

        foreach ($porCaducar as $fila) {
            $avisos[] = Aviso::ambar(sprintf(
                'El certificado de %s caduca el %s. Renovarlo lleva días: pídalo antes de que pare la facturación.',
                (string) $fila->code,
                (string) $fila->valid_to,
            ));
        }

        // El RUC de dentro no es el de la sociedad. No lo impide la base a
        // proposito (`Q-66`): no me consta que no exista un caso legitimo, y
        // `docs/00 §56` prohibe implementar un supuesto legal sin revisarlo.
        $ruc = DB::table('signing_certificates as sc')
            ->join('legal_entities as le', 'le.id', '=', 'sc.legal_entity_id')
            ->where('sc.status', 'active')
            ->whereColumn('sc.tax_id_number', '<>', 'le.tax_id_number')
            ->pluck('le.code');

        if ($ruc->isNotEmpty()) {
            $avisos[] = Aviso::rojo(sprintf(
                'El certificado de %s está a nombre de otro contribuyente. Compruébelo antes de emitir.',
                $ruc->implode(', '),
            ));
        }

        return $avisos;
    }

    // ------------------------------------------------------------- privados

    /** @var list<string> */
    private const COLUMNAS_VISIBLES = [
        'id', 'uuid', 'legal_entity_id', 'environment', 'subject_name', 'issuer_name',
        'serial_number', 'tax_id_number', 'valid_from', 'valid_to', 'fingerprint_sha256',
        'source', 'status', 'uploaded_at', 'replaced_at', 'revoked_at', 'revoked_reason',
    ];

    /**
     * Abre el material y devuelve `[pem, certificado, origen]`.
     *
     * @return array{0:string,1:string,2:string}
     */
    private static function abrir(string $contenido, ?string $clave): array
    {
        // Un PEM se reconoce por su cabecera. Se acepta porque es lo que ya
        // tiene quien convirtio su `.pfx` a mano --que despues de lo de abajo
        // va a ser mucha gente--.
        if (str_contains($contenido, '-----BEGIN')) {
            $certificado = self::certificadoDelPem($contenido);

            if (!str_contains($contenido, 'PRIVATE KEY')) {
                throw new RuntimeException(
                    'Ese PEM lleva el certificado pero no la clave privada: sin ella no se puede firmar.',
                );
            }

            return [$contenido, $certificado, 'pem'];
        }

        // Se vacia la pila de errores de OpenSSL antes de preguntar: si viniera
        // sucia de otra llamada, el diagnostico de abajo acusaria a la anterior.
        while (openssl_error_string() !== false) {
            // Descartar.
        }

        $partes = [];

        if (!openssl_pkcs12_read($contenido, $partes, (string) $clave)) {
            throw new RuntimeException(self::porQueNoAbre());
        }

        $pem = (string) ($partes['cert'] ?? '').(string) ($partes['pkey'] ?? '');

        foreach ((array) ($partes['extracerts'] ?? []) as $extra) {
            $pem .= (string) $extra;
        }

        if (($partes['pkey'] ?? '') === '') {
            throw new RuntimeException(
                'Ese archivo lleva el certificado pero no la clave privada: sin ella no se puede firmar.',
            );
        }

        return [$pem, (string) $partes['cert'], 'pkcs12'];
    }

    /**
     * Por qué OpenSSL no pudo abrirlo, en palabras que sirvan para algo.
     *
     * El caso importante es el primero, y es el normal con SUNAT.
     */
    private static function porQueNoAbre(): string
    {
        $errores = '';

        while (($linea = openssl_error_string()) !== false) {
            $errores .= $linea.' ';
        }

        if (str_contains($errores, 'unsupported') || str_contains($errores, 'RC2')) {
            return 'Ese certificado usa el cifrado antiguo que OpenSSL 3 ya no abre '
                .'--es lo normal en los que emite SUNAT--. Conviertalo una vez con: '
                .'openssl pkcs12 -legacy -in suyo.pfx -nodes -out convertido.pem '
                .'y suba el .pem que sale.';
        }

        if (str_contains($errores, 'mac verify failure') || str_contains($errores, 'invalid password')) {
            return 'La contrasena del certificado no es correcta.';
        }

        return 'No se pudo abrir el certificado. OpenSSL dijo: '.trim($errores);
    }

    private static function certificadoDelPem(string $pem): string
    {
        if (preg_match('~-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----~s', $pem, $c) !== 1) {
            throw new RuntimeException('Ese archivo no lleva ningun certificado dentro.');
        }

        return $c[0];
    }

    /**
     * Lo que dice el propio certificado.
     *
     * @return array{subject:string,issuer:string,serial:string,ruc:string,valid_from:Carbon,valid_to:Carbon,huella:string}
     */
    private static function leerMetadatos(string $certificado): array
    {
        $datos = openssl_x509_parse($certificado);

        if ($datos === false) {
            throw new RuntimeException('El certificado no se puede leer: .es el archivo correcto?');
        }

        $huella = openssl_x509_fingerprint($certificado, 'sha256');

        if ($huella === false) {
            throw new RuntimeException('No se pudo calcular la huella del certificado.');
        }

        /** @var array<string, mixed> $sujeto */
        $sujeto = (array) ($datos['subject'] ?? []);

        return [
            'subject' => mb_substr(self::enTexto($sujeto), 0, 255),
            'issuer' => mb_substr(self::enTexto((array) ($datos['issuer'] ?? [])), 0, 255),
            'serial' => mb_substr((string) ($datos['serialNumberHex'] ?? $datos['serialNumber'] ?? ''), 0, 80),
            // El RUC viaja en el CN o en el serialNumber del sujeto, segun quien
            // lo emita. Se coge el primer numero largo que aparezca, y si no hay
            // ninguno se guarda el CN: `ck_cert_ruc` solo exige que no este
            // vacio, porque inventarse un formato por pais seria `DEC-190` roto.
            'ruc' => mb_substr(self::rucDe($sujeto), 0, 40),
            'valid_from' => now()->parse('@'.(int) ($datos['validFrom_time_t'] ?? 0)),
            'valid_to' => now()->parse('@'.(int) ($datos['validTo_time_t'] ?? 0)),
            'huella' => strtolower(str_replace(':', '', $huella)),
        ];
    }

    /** @param array<string, mixed> $partes */
    private static function enTexto(array $partes): string
    {
        $trozos = [];

        foreach ($partes as $clave => $valor) {
            $trozos[] = $clave.'='.(is_array($valor) ? implode('/', array_map('strval', $valor)) : (string) $valor);
        }

        return implode(', ', $trozos);
    }

    /** @param array<string, mixed> $sujeto */
    private static function rucDe(array $sujeto): string
    {
        foreach (['serialNumber', 'CN', 'OU', 'O'] as $campo) {
            $valor = $sujeto[$campo] ?? null;
            $texto = is_array($valor) ? implode(' ', array_map('strval', $valor)) : (string) $valor;

            if (preg_match('/\d{8,15}/', $texto, $c) === 1) {
                return $c[0];
            }
        }

        return mb_substr((string) ($sujeto['CN'] ?? 'sin identificar'), 0, 40);
    }
}
