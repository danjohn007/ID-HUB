<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Si la base de pruebas se migró con datos demo (DemoSeeder) o vacía. */
    protected static ?bool $migratedWithSeed = null;

    /**
     * RefreshDatabase migra una sola vez por ejecución: si la primera clase no siembra datos,
     * las que usan DemoSeeder encuentran la base vacía (y al revés). Se vuelve a migrar cuando
     * la clase necesita la base en el otro estado, así el resultado no depende del orden.
     * Va en setUp() porque el refreshTestDatabase() del trait tiene prioridad sobre esta clase.
     */
    protected function setUp(): void
    {
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $wantsSeed = $this->shouldSeed();
            if (RefreshDatabaseState::$migrated && self::$migratedWithSeed !== $wantsSeed) {
                RefreshDatabaseState::$migrated = false;
            }
            self::$migratedWithSeed = $wantsSeed;
        }

        parent::setUp();
    }
}
