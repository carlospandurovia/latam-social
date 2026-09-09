<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;

/**
 * `ck_ds_type` seguía anotada meses después de que la borráramos (`T-112`).
 *
 * ### Cómo se encontró
 *
 * Con `tools/servidor/verificar-registro.php`, la primera vez que se corrió.
 * Sobre una base con **374 reglas anotadas y 832 disparadores**, dijo:
 *
 * > `document_series.ck_ds_type` — anotada como disparador y no tiene ninguno
 * > de sus dos disparadores: la base NO la está imponiendo.
 *
 * ### Qué pasó de verdad
 *
 * Nada roto, y por eso es interesante. `ck_ds_type` decía
 * `document_type IN ('invoice','boleta','credit_note','debit_note','other')`
 * —cinco palabras peruanas escritas en el código— y `9.12` **la mató a
 * propósito** (`DEC-228`): los tipos de comprobante son ahora un catálogo por
 * país, para que un CFDI mexicano quepa sin desplegar.
 *
 * Lo que faltó fue la limpieza. Aquella migración hace
 * `Schema::dropIfExists('document_series')` y rehace la tabla con otra forma.
 * **Soltar una tabla se lleva sus disparadores por delante, pero no toca
 * `schema_constraints`**: los disparadores desaparecieron y la fila se quedó.
 * Un papel sin regla detrás.
 *
 * ### Por qué esto y no `rehacer-reglas.php`
 *
 * Porque el verificador acierta al dar la alarma y se equivocaría al dar el
 * remedio: **rehacer** esa regla resucitaría el enum que el negocio eliminó, y
 * la base volvería a rechazar una factura mexicana. Una regla que sobra se
 * quita; una que falta se pone. Distinguirlo no lo puede hacer una herramienta
 * mirando el motor —las dos se ven exactamente igual desde ahí—, así que lo
 * decide quien sabe qué se quiso.
 *
 * ### Por qué una migración y no un comando suelto
 *
 * Porque la fila sobra en **todas** las bases: en la de desarrollo, en la de
 * pruebas —que la fabrica en cada `migrate:fresh` y la orfana acto seguido— y
 * en producción. Una migración lo arregla en todas y con fecha; un `tinker` a
 * mano arregla la que tenía delante quien lo escribió.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `quitar()` es idempotente --`DROP TRIGGER IF EXISTS` de los dos, y
        // tolera que no exista como CHECK-- asi que sobre una base donde ya no
        // este no hace nada. Lo unico que de verdad hace aqui es borrar la fila.
        Restriccion::quitar('document_series', 'ck_ds_type');
    }

    public function down(): void
    {
        // A proposito vacio. Deshacer esto seria volver a anotar una regla que
        // no existe, que es exactamente el defecto que vino a quitar.
    }
};
