<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Database\Migrations;

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;

/**
 * Los dos plazos del brief, acotados en la base (`T-33`, 7.3).
 *
 * `quantity` tenía `ck_creq_quantity` desde la Fase 2. `deadline_offset_days` y
 * `permanence_days` **no tenían nada**: los acotaba sólo
 * `GuardarRequisitoRequest`, y una regla que sólo vive en el formulario se la
 * salta cualquier importación, cualquier `UPDATE` de mantenimiento y la próxima
 * pantalla que alguien escriba.
 *
 * No es teórico. `permanence_days` es *«cuánto debe seguir publicado»*: de ahí
 * sale lo que se le exige al creador y lo que se le promete al cliente. Un
 * 100.000 —273 años— entra hoy sin que nada chille, y lo que se rompe no es la
 * base, es un acuerdo que nadie puede cumplir.
 *
 * ### Por qué los topes son éstos
 *
 * | Campo | Tope | Por qué |
 * |---|---|---|
 * | `deadline_offset_days` | 365 | el plazo se cuenta desde el arranque; más de un año es otra campaña |
 * | `permanence_days` | 3650 | diez años es «para siempre» a efectos prácticos, y es redondo |
 *
 * Son los mismos que ya exigía la pantalla. Se repiten a propósito: la base
 * protege el dato y el `FormRequest` protege al operador de un error del motor
 * que nombra una restricción en vez de decirle qué número es razonable.
 *
 * Se anotó como `T-33` al escribir 7.2 en vez de arreglarlo sobre la marcha,
 * porque 7.3 iba a tocar estas mismas filas al partir los requisitos por
 * mercado. Y así ha sido.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::restricciones() as [$nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: 'campaign_requirements', nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$nombre]) {
            Restriccion::quitar('campaign_requirements', $nombre);
        }
    }

    /** @return list<array{0:string,1:string,2:list<string>,3:string}> */
    private static function restricciones(): array
    {
        return [
            ['ck_creq_deadline', 'deadline_offset_days BETWEEN 0 AND 365', ['deadline_offset_days'],
                'El plazo de entrega se cuenta desde que arranca la campana: mas de un ano es otra campana.'],
            ['ck_creq_permanence', 'permanence_days BETWEEN 0 AND 3650', ['permanence_days'],
                'La permanencia maxima son 3650 dias (diez anos): mas que eso es un plazo que nadie va a cumplir.'],
        ];
    }
};
