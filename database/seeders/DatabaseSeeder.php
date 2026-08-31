<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CimientosSeeder::class,
            UsuarioAdminSeeder::class,
            // 4.13: sin plantilla no sale el aviso, y `Correo::enviar()` revienta
            // a proposito cuando no la encuentra. Dejarlo a que alguien corra
            // `correos:publicar` en cada entorno es el modo de fallo que ya
            // demostro `DEC-085`.
            PlantillasDeCorreoSeeder::class,
            // 9.16: el texto de partida de los terminos. Va DESPUES de
            // `UsuarioAdminSeeder` porque publicar exige un responsable
            // (`ck_terms_publicada`); sin usuarios se quedaria en borrador y el
            // sistema arrancaria igual, pero sin poder activar creadores.
            TerminosBaseSeeder::class,
        ]);
    }
}
