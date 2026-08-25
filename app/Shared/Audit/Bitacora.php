<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Escribe en `audit_logs`. La tabla existía desde la iteración 2.4 y **nadie
 * escribía en ella**: una bitácora vacía es peor que no tenerla, porque da la
 * impresión de que hay rastro.
 *
 * Tres decisiones:
 *
 * 1. **El actor se congela.** Se guarda `actor_label` con el nombre y correo del
 *    usuario tal como eran en ese momento, además de su id. Si mañana esa
 *    persona cambia de nombre o se desactiva, la entrada de ayer sigue diciendo
 *    quién fue. Es el mismo criterio de los snapshots de factura (`BR-LE-005`).
 *
 * 2. **Solo se registra lo que cambió.** Guardar la fila entera en cada edición
 *    convierte la bitácora en ruido donde nadie encuentra nada. `cambios` lleva
 *    el antes y el después de los campos que de verdad se movieron.
 *
 * 3. **La IP va empaquetada**, no como texto. La columna es `VARBINARY(16)`:
 *    `inet_pton()` da 4 bytes para IPv4 y 16 para IPv6, y así una IPv6 entra
 *    entera en lugar de quedarse truncada a 45 caracteres de texto.
 *
 * 4. **Nada sensible entra, aunque se lo pasen.** Regla del cliente: «no guardar
 *    información sensible innecesariamente en logs». Confiar en que cada quien
 *    recuerde no auditar la columna equivocada no es una política; es una
 *    esperanza. `REDACTAR` es la red: si el nombre del campo huele a secreto, se
 *    registra QUE cambió pero no a qué. Un `account_number_encrypted` en claro
 *    dentro de la bitácora anularía el cifrado de la tabla de origen.
 *
 * Y lo que NO hace: no se puede editar ni borrar lo escrito. Eso lo impiden dos
 * disparadores en la propia base, no esta clase — ver la migración de
 * trazabilidad. Una bitácora que la aplicación puede reescribir no es evidencia.
 */
final class Bitacora
{
    /**
     * Fragmentos que, si aparecen en el nombre de un campo, hacen que su valor
     * NO se escriba. Se comparan en minúsculas y por contención, para que
     * `account_number_encrypted` y `holder_account_number` caigan los dos.
     *
     * @var list<string>
     */
    private const REDACTAR = [
        'password', 'secret', 'token', 'api_key', 'private_key',
        'account_number', 'card', 'cvv', 'encrypted', 'fingerprint',
        // Documentos de identidad y fiscales. La red cubria la cuenta bancaria
        // y no el DNI ni el RUC, que son el mismo tipo de dato: en
        // `creator_payment_methods` el numero de cuenta se enmascara a cuatro
        // digitos, y a la vez `client_tax_profile.created` escribia
        // «RUC 20512345678» entero. Y la bitacora NO se puede corregir: lo que
        // entra ahi ya no se saca.
        //
        // `ruc` y `dni` NO estan en la lista aunque parezcan lo obvio: se
        // comparan por contencion, y `str_contains('estructura', 'ruc')` es
        // cierto. Una entrada demasiado corta esconde campos legitimos en
        // silencio, que es el mismo pecado al reves.
        'document_number', 'tax_id', 'identidad_fiscal',
    ];

    private const OCULTO = '[redactado]';

    /**
     * @param array<string, array{antes: mixed, despues: mixed}> $cambios
     */
    public static function registrar(
        string $accion,
        string $tipoEntidad,
        ?int $idEntidad = null,
        array $cambios = [],
    ): void {
        $usuario = Auth::user();
        $peticion = request();

        $ip = $peticion->ip();
        $empaquetada = is_string($ip) ? inet_pton($ip) : false;

        DB::table('audit_logs')->insert([
            'actor_user_id' => $usuario?->getAuthIdentifier(),
            'actor_label' => self::etiquetaDe($usuario),
            'action' => $accion,
            'entity_type' => $tipoEntidad,
            'entity_id' => $idEntidad,
            // La columna tiene un CHECK de JSON_VALID: null o JSON de verdad.
            'changes' => $cambios === [] ? null : json_encode(self::redactar($cambios), JSON_UNESCAPED_UNICODE),
            'ip_address' => $empaquetada === false ? null : $empaquetada,
            'user_agent' => mb_substr((string) $peticion->userAgent(), 0, 255) ?: null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Compara dos conjuntos de valores y devuelve solo lo que se movió.
     *
     * La comparación es laxa a propósito: los valores que vienen del formulario
     * son cadenas y los de la base pueden ser enteros, así que un `===` marcaría
     * como cambiado un `30` que sigue siendo `30`. Se comparan como texto, que es
     * lo que se va a guardar y lo que un humano va a leer.
     *
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $despues
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    public static function diferencias(array $antes, array $despues): array
    {
        $cambios = [];

        foreach ($despues as $campo => $nuevo) {
            $viejo = $antes[$campo] ?? null;

            if (self::comoTexto($viejo) !== self::comoTexto($nuevo)) {
                $cambios[$campo] = ['antes' => $viejo, 'despues' => $nuevo];
            }
        }

        return $cambios;
    }

    /**
     * Sustituye el valor de los campos sensibles, conservando que cambiaron.
     * Saber que alguien tocó la cuenta bancaria es información de auditoría;
     * saber cuál era, no.
     *
     * @param array<string, array{antes: mixed, despues: mixed}> $cambios
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    public static function redactar(array $cambios): array
    {
        foreach ($cambios as $campo => $valores) {
            if (self::hueleASecreto((string) $campo)) {
                $cambios[$campo] = ['antes' => self::OCULTO, 'despues' => self::OCULTO];

                continue;
            }

            // Y hacia dentro. La version anterior solo miraba el primer nivel,
            // asi que un `['completitud' => ['despues' => ['document_number' =>
            // '40000001']]]` salia intacto — y ya hay llamadores que pasan
            // arrays anidados. `json_encode()` lo escribe entero, y la bitacora
            // no se puede corregir despues.
            if (is_array($valores)) {
                $cambios[$campo] = self::redactarHondo($valores);
            }
        }

        return $cambios;
    }

    /**
     * @param array<mixed> $valores
     * @return array<mixed>
     */
    private static function redactarHondo(array $valores): array
    {
        foreach ($valores as $clave => $valor) {
            if (is_string($clave) && self::hueleASecreto($clave)) {
                $valores[$clave] = self::OCULTO;

                continue;
            }

            if (is_array($valor)) {
                $valores[$clave] = self::redactarHondo($valor);
            }
        }

        return $valores;
    }

    private static function hueleASecreto(string $campo): bool
    {
        $nombre = mb_strtolower($campo);

        foreach (self::REDACTAR as $fragmento) {
            if (str_contains($nombre, $fragmento)) {
                return true;
            }
        }

        return false;
    }

    private static function comoTexto(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return is_scalar($valor) ? (string) $valor : json_encode($valor);
    }

    private static function etiquetaDe(mixed $usuario): ?string
    {
        if ($usuario === null) {
            return null;
        }

        $nombre = is_object($usuario) && isset($usuario->name) ? (string) $usuario->name : '';
        $correo = is_object($usuario) && isset($usuario->email) ? (string) $usuario->email : '';
        $etiqueta = trim($nombre.' <'.$correo.'>');

        return $etiqueta === '<>' ? null : mb_substr($etiqueta, 0, 120);
    }
}
