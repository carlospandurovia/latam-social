<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Database\Restriccion;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `Restriccion` memoriza dos cosas caras de averiguar --si el motor
        // aplica `CHECK` y si existe `schema_constraints`-- y las dos dejan de
        // ser ciertas cuando `RefreshDatabase` rehace el esquema. Olvidarlas
        // aqui es lo que impide que una memoria de una prueba anterior decida
        // el comportamiento de la siguiente.
        Restriccion::olvidar();
    }
}
