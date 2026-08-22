<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Shared\Database\Restriccion;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PanelController
{
    public function __invoke(): View
    {
        return view('panel.inicio', [
            'tarjetas' => $this->tarjetas(),
            'motor' => $this->motor(),
            'restricciones' => $this->cuentaRestricciones(),
            'cobertura' => $this->cobertura(),
        ]);
    }

    /** @return list<array{titulo: string, valor: int, nota: string}> */
    private function tarjetas(): array
    {
        return [
            ['titulo' => 'Creadores', 'valor' => $this->cuenta('creators'), 'nota' => 'registrados'],
            ['titulo' => 'Clientes', 'valor' => $this->cuenta('client_organizations'), 'nota' => 'grupos'],
            ['titulo' => 'Campañas', 'valor' => $this->cuenta('campaigns'), 'nota' => 'creadas'],
            ['titulo' => 'Tablas', 'valor' => $this->cuentaTablas(), 'nota' => 'en el esquema'],
        ];
    }

    private function cuenta(string $tabla): int
    {
        return DB::getSchemaBuilder()->hasTable($tabla) ? DB::table($tabla)->count() : 0;
    }

    private function cuentaTablas(): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [DB::connection()->getDatabaseName(), 'BASE TABLE']
        )->n;
    }

    private function cuentaRestricciones(): int
    {
        return DB::getSchemaBuilder()->hasTable('schema_constraints')
            ? DB::table('schema_constraints')->count()
            : 0;
    }

    /**
     * Sonda el motor en caliente, igual que `php artisan esquema:verificar`.
     * No se fía del número de versión: lo comprueba.
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    private function motor(): array
    {
        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
        $charset = (string) DB::selectOne('SELECT @@character_set_database AS cs')->cs;

        return [
            'Versión del servidor' => [true, $version],
            'Aplica los CHECK de forma nativa' => [
                Restriccion::motorAplicaCheck(),
                Restriccion::motorAplicaCheck() ? 'sí' : 'no, se usan TRIGGER',
            ],
            'Soporta CTE (WITH)' => $this->soporta('WITH x AS (SELECT 1 AS a) SELECT a FROM x'),
            'Soporta funciones de ventana' => $this->soporta('SELECT ROW_NUMBER() OVER (ORDER BY 1) AS r'),
            'Juego de caracteres' => [str_starts_with($charset, 'utf8mb4'), $charset],
        ];
    }

    /** @return array{0: bool, 1: string} */
    private function soporta(string $sql): array
    {
        try {
            DB::select($sql);

            return [true, 'sí'];
        } catch (\Throwable) {
            return [false, 'no'];
        }
    }

    private function cobertura(): \Illuminate\Support\Collection
    {
        if (! DB::getSchemaBuilder()->hasTable('legal_entity_countries')) {
            return collect();
        }

        return DB::table('legal_entity_countries as lec')
            ->join('countries as c', 'c.id', '=', 'lec.country_id')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->whereNull('lec.valid_to')
            ->orderBy('c.name')
            ->get([
                'c.name as pais',
                'le.code as sociedad',
                'lec.coverage_basis as motivo',
            ]);
    }
}
