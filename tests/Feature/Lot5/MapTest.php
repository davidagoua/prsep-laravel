<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['sigpro.geoserver.internal_url' => 'http://geoserver.test/geoserver', 'sigpro.geoserver.url' => 'http://localhost:8081/geoserver', 'sigpro.geoserver.workspace' => 'sigpro']);
    cache()->forget('geoserver.layers');
    $this->user = tap(User::factory()->create())->assignRole(Role::Coordination->value);
});

function capabilities(string ...$layers): string
{
    $xml = collect($layers)->map(fn ($l) => "<Layer queryable=\"1\"><Name>{$l}</Name><Title>Couche {$l}</Title><EX_GeographicBoundingBox><westBoundLongitude>-8.6</westBoundLongitude><eastBoundLongitude>-2.5</eastBoundLongitude><southBoundLatitude>4.3</southBoundLatitude><northBoundLatitude>10.7</northBoundLatitude></EX_GeographicBoundingBox></Layer>")->implode('');

    return '<WMS_Capabilities xmlns="http://www.opengis.net/wms" version="1.3.0"><Capability><Layer><Title>sigpro</Title>'.$xml.'</Layer></Capability></WMS_Capabilities>';
}

test('la carte propose les couches publiées dans l\'espace de travail', function () {
    Http::fake(['geoserver.test/geoserver/sigpro/wms*' => Http::response(capabilities('drenas', 'localites'))]);

    $this->actingAs($this->user)->get(route('map'))
        ->assertOk()
        ->assertSee('Couche drenas')
        ->assertSee('Couche localites')
        ->assertSee('localhost:8081', false)
        ->assertSee('geoserver', false)
        ->assertDontSee('Aucune couche publiée');
});

test('message clair quand aucune couche n\'est publiée', function () {
    Http::fake(['*' => Http::response(capabilities())]);

    $this->actingAs($this->user)->get(route('map'))->assertSee('Aucune couche publiée pour');
});

test('le fond de carte reste affiché si GeoServer est injoignable', function () {
    Http::fake(['*' => Http::response('', 503)]);

    $this->actingAs($this->user)->get(route('map'))->assertOk()->assertSee('GeoServer ne répond pas');
});

test('le relais GetFeatureInfo n\'interroge que les couches publiées', function () {
    Http::fake([
        'geoserver.test/geoserver/sigpro/wms?*GetCapabilities*' => Http::response(capabilities('drenas')),
        'geoserver.test/geoserver/sigpro/wms?*GetFeatureInfo*' => Http::response(['type' => 'FeatureCollection', 'features' => [
            ['id' => 'drenas.4', 'properties' => ['nom' => 'DRENA Bouaké 1', 'code' => 'BK1'], 'geometry' => null],
        ]]),
    ]);
    $params = ['bbox' => '-6,7,-5,8', 'width' => 400, 'height' => 300, 'x' => 200, 'y' => 150];

    $this->actingAs($this->user)->getJson(route('map.feature-info', $params + ['layers' => ['drenas']]))
        ->assertOk()
        ->assertJsonPath('features.0.layer', 'drenas')
        ->assertJsonPath('features.0.properties.nom', 'DRENA Bouaké 1');

    $this->actingAs($this->user)->getJson(route('map.feature-info', $params + ['layers' => ['autre:couche_privee']]))->assertStatus(422);
    $this->actingAs($this->user)->getJson(route('map.feature-info', ['layers' => ['drenas'], 'bbox' => '1;DROP'] + $params))->assertStatus(422);
});

test('la carte est accessible aux rôles métier mais pas au gestionnaire de parc', function () {
    Http::fake(['*' => Http::response(capabilities())]);
    $parc = tap(User::factory()->create())->assignRole(Role::GestionnaireParc->value);

    $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('map'), false);
    $this->actingAs($parc)->get(route('dashboard'))->assertDontSee(route('map'), false);
});
