<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Correlativos;
use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Series y correlativos, desde el admin (9.12).
 *
 * ### Aquí no se emiten números
 *
 * La pantalla configura las series y **enseña** el libro; el número lo pide
 * quien emite un documento, que es `9.9`. Lo único que se puede hacer aquí con
 * un número es **anularlo**, y para eso hace falta escribir por qué: es la
 * respuesta a «¿por qué falta el 00000123?» el día que la pregunte alguien de
 * fuera.
 *
 * ### `legal_entity.manage` ya existía
 *
 * Una serie pertenece a la sociedad que emite (`BR-LE-008`), así que quien puede
 * administrar sociedades administra sus series. Un permiso nuevo para lo mismo
 * sólo añade un sitio donde olvidarse de darlo.
 */
final class SeriesController
{
    public function index(Request $peticion): View
    {
        $series = Correlativos::series();
        $verId = (int) $peticion->query('serie', (string) ($series->first()->id ?? 0));

        return view('series.index', [
            'series' => $series,
            'tipos' => Correlativos::tipos(),
            'sociedades' => DB::table('legal_entities')->where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'legal_name', 'country_id']),
            'paises' => DB::table('countries')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'iso2']),
            'entornos' => Correlativos::ENTORNOS,
            'estados' => Correlativos::ESTADOS,
            'verId' => $verId,
            'ultimos' => $verId > 0 ? Correlativos::ultimos($verId) : collect(),
            'avisos' => Correlativos::avisos(),
        ]);
    }

    public function guardarTipo(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'id' => ['nullable', 'integer', 'exists:document_types,id'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'name' => ['required', 'string', 'max:80'],
            'official_code' => ['nullable', 'string', 'max:5'],
            'series_pattern' => ['nullable', 'string', 'max:120'],
            // `ck_dtype_patron` lo exige en la base; pedirlo aqui es lo que
            // convierte un 45000 en una frase junto al campo.
            'series_label' => ['nullable', 'required_with:series_pattern', 'string', 'max:60'],
            'number_length' => ['required', 'integer', 'min:1', 'max:12'],
            'requires_customer_tax_id' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        try {
            // `$datos['id'] ?? null` y no `$datos['id']`: `validate()` devuelve
            // solo las claves QUE VINIERON, asi que un alta --sin `id`-- daba
            // «Undefined array key "id"» y ese texto acababa en el aviso de la
            // pantalla. Lo cazo la prueba del choque por defecto, que esperaba
            // una frase y encontro un error de PHP.
            Correlativos::guardarTipo(
                isset($datos['id']) ? (int) $datos['id'] : null,
                $datos,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('series.index')->with('exito', 'Tipo de comprobante guardado.');
    }

    public function guardarSerie(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'id' => ['nullable', 'integer', 'exists:document_series,id'],
            'legal_entity_id' => ['required', 'integer', 'exists:legal_entities,id'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'series' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            // Solo al crear. Una serie que ya circulaba no empieza en 1.
            'next_number' => ['nullable', 'integer', 'min:1'],
            'environment' => ['required', 'in:'.implode(',', array_keys(Correlativos::ENTORNOS))],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            Correlativos::guardarSerie(
                isset($datos['id']) ? (int) $datos['id'] : null,
                $datos,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('aviso', self::enPalabras($e));
        }

        return redirect()->route('series.index')->with('exito', 'Serie guardada.');
    }

    public function anular(Request $peticion, int $numero): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        try {
            Correlativos::anular($numero, (string) $datos['motivo'], (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return redirect()->route('series.index', ['serie' => $peticion->integer('serie')])
            ->with('exito', 'Número anulado. El hueco queda escrito con su motivo.');
    }

    /**
     * Un choque de unicidad, dicho con palabras.
     *
     * Las dos únicas de esta pantalla producen mensajes muy distintos, y un
     * `1062` en crudo no distingue: `uq_ds_series` es «esa serie ya existe» y
     * `uq_ds_default` es «ya hay otra marcada por defecto», que no se arregla
     * igual.
     */
    private static function enPalabras(Throwable $e): string
    {
        return match (Choque::indice($e)) {
            'uq_ds_series' => 'Esa sociedad ya tiene esa serie para ese tipo y ese entorno.',
            'uq_ds_default' => 'Ya hay otra serie marcada por defecto para ese tipo: quítasela antes.',
            'uq_dtype_code' => 'Ese país ya tiene un tipo de comprobante con ese código.',
            default => $e->getMessage(),
        };
    }
}
