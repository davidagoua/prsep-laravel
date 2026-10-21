<?php

use App\Enums\Role;
use App\Models\Exercice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function userWithRole(Role $role, array $attributes = []): User
{
    return tap(User::factory()->create($attributes))->assignRole($role->value);
}

test('les invités sont redirigés vers la connexion', function () {
    $this->get('/')->assertRedirect('/accueil');
    $this->get('/accueil')->assertRedirect(route('login'));
});

test("l'inscription libre n'existe pas", function () {
    $this->get('/register')->assertNotFound();
});

test("un utilisateur connecté voit l'accueil avec l'exercice en cours", function () {
    Exercice::factory()->current()->create(['year' => 2026, 'label' => 'Exercice 2026']);

    $this->actingAs(userWithRole(Role::PointFocal))
        ->get('/accueil')
        ->assertOk()
        ->assertSee('Exercice 2026')
        ->assertSee('À traiter');
});

test("le menu d'administration est réservé aux administrateurs", function () {
    $this->actingAs(userWithRole(Role::PointFocal))
        ->get('/accueil')
        ->assertDontSee('Administration')
        ->assertSee('Activités');

    $this->actingAs(userWithRole(Role::Admin))
        ->get('/accueil')
        ->assertSee('Administration')
        ->assertSee('Mise en place');
});

test('le gestionnaire de parc ne voit pas les modules métier', function () {
    $this->actingAs(userWithRole(Role::GestionnaireParc))
        ->get('/accueil')
        ->assertSee('Parc automobile')
        ->assertDontSee('Validations')
        ->assertDontSee('Suivi financier');
});

test('les pages admin refusent les autres rôles', function (string $uri) {
    $this->actingAs(userWithRole(Role::Coordination))->get($uri)->assertForbidden();
    $this->actingAs(userWithRole(Role::Admin))->get($uri)->assertOk();
})->with(['/admin/utilisateurs', '/admin/directions', '/admin/exercices']);

test('un compte désactivé ne peut pas se connecter', function () {
    $user = userWithRole(Role::PointFocal, ['is_active' => false]);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('un compte désactivé en cours de session est déconnecté', function () {
    $user = userWithRole(Role::PointFocal);
    $this->actingAs($user);
    $user->update(['is_active' => false]);

    $this->get('/accueil')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('la connexion enregistre la date de dernière connexion', function () {
    $user = userWithRole(Role::PointFocal);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test("l'utilisateur peut changer d'exercice de travail", function () {
    Exercice::factory()->current()->create(['year' => 2026, 'label' => 'Exercice 2026']);
    $ancien = Exercice::factory()->create(['year' => 2025, 'label' => 'Exercice 2025']);

    $this->actingAs(userWithRole(Role::PointFocal))
        ->from('/accueil')
        ->post(route('exercice.switch'), ['exercice_id' => $ancien->id])
        ->assertRedirect('/accueil')
        ->assertSessionHas('exercice_id', $ancien->id);

    $this->get('/accueil')->assertSeeInOrder(['Exercice de travail', 'Exercice 2025']);
});

test("le profil ne permet pas de modifier l'e-mail", function () {
    $user = userWithRole(Role::PointFocal);
    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('name', 'Awa Koné')
        ->set('phone', '0700000000')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh())
        ->name->toBe('Awa Koné')
        ->phone->toBe('0700000000')
        ->email->toBe($user->email);
});
