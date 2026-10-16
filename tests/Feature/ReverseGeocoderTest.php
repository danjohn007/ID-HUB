<?php

namespace Tests\Feature;

use App\Services\ReverseGeocoder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReverseGeocoderTest extends TestCase
{
    public function test_maps_nominatim_address_to_form_fields(): void
    {
        Http::fake(['*/reverse*' => Http::response(['address' => [
            'road' => 'Calle Hidalgo', 'house_number' => '12', 'neighbourhood' => 'Centro – Histórico',
            'city' => 'Santiago de Querétaro', 'state' => 'Querétaro',
        ]])]);

        $r = app(ReverseGeocoder::class)->lookup(20.5931, -100.3920);

        $this->assertSame('Calle Hidalgo 12', $r['address']);
        $this->assertSame('Centro - Histórico', $r['colonia']);
        $this->assertSame('Santiago de Querétaro', $r['community']);
        $this->assertSame('Calle Hidalgo 12, Centro - Histórico, Santiago de Querétaro, Querétaro', $r['full']);
        Http::assertSent(fn ($req) => str_contains($req->header('User-Agent')[0] ?? '', 'ID-Hub'));
    }

    public function test_returns_null_when_service_fails(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertNull(app(ReverseGeocoder::class)->lookup(1.23456, 2.34567));
    }
}
