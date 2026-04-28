<?php

use App\Models\Todo;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

it('can register a new user', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'SecurePass1',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'token',
            'expires_at',
            'user' => ['id', 'name', 'email'],
        ]);

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

it('prevents registration with an existing email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $this->postJson('/api/register', [
        'name' => 'New User',
        'email' => 'test@example.com',
        'password' => 'SecurePass1',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects registration with a weak password', function () {
    // Too short — fails min(8)
    $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'a@example.com',
        'password' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    // Simple lowercase-only passwords are now accepted (policy is min(8) only)
    $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'b@example.com',
        'password' => 'password',
    ])->assertStatus(201);
});

it('normalises email to lowercase on registration', function () {
    $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'TEST@EXAMPLE.COM',
        'password' => 'SecurePass1',
    ])->assertStatus(201);

    // Read back via Eloquent — the stored value must be lowercase
    expect(User::first()->email)->toBe('test@example.com');
});

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

it('can login with correct credentials', function () {
    // UserFactory default password is 'password'
    $user = User::factory()->create();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email']]);
});

it('fails login with incorrect password', function () {
    $user = User::factory()->create();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('fails login for an inactive account', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('accepts email in any case on login', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->postJson('/api/login', [
        'email' => 'USER@EXAMPLE.COM',
        'password' => 'password',
    ])->assertOk()->assertJsonPath('user.email', 'user@example.com');
});

it('invalidates all previous tokens on login', function () {
    $user = User::factory()->create();
    $user->createToken('old-device-one');
    $user->createToken('old-device-two');

    expect($user->tokens()->count())->toBe(2);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    // Only the new token from this login should exist
    expect($user->tokens()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Protected Routes — Me
|--------------------------------------------------------------------------
*/

it('can fetch the authenticated user profile', function () {
    $user = User::factory()->create();

    asUser($user)->getJson('/api/me')
        ->assertOk()
        ->assertJson(['user' => ['id' => $user->id, 'email' => $user->email]]);
});

/*
|--------------------------------------------------------------------------
| Protected Routes — Logout
|--------------------------------------------------------------------------
*/

it('can logout and revoke the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('can logout from all devices at once', function () {
    $user = User::factory()->create();
    $user->createToken('device-one');
    $activeToken = $user->createToken('device-two')->plainTextToken;

    expect($user->tokens()->count())->toBe(2);

    $this->withHeader('Authorization', 'Bearer '.$activeToken)
        ->postJson('/api/logout/all')
        ->assertOk()
        ->assertJson(['message' => 'Signed out from all devices.']);

    expect($user->tokens()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Active User Enforcement
|--------------------------------------------------------------------------
*/

it('blocks inactive users from all protected routes', function () {
    $user = User::factory()->create(['is_active' => false]);
    $token = $user->createToken('mobile')->plainTextToken;

    // Every protected endpoint must return 403
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/logout')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/sync/pull')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Unauthenticated Access
|--------------------------------------------------------------------------
*/

it('protects all authenticated routes from unauthenticated access', function () {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->postJson('/api/logout')->assertUnauthorized();
    $this->postJson('/api/logout/all')->assertUnauthorized();
    $this->postJson('/api/sync/push', ['operations' => []])->assertUnauthorized();
    $this->getJson('/api/sync/pull')->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Cross-User Isolation
|--------------------------------------------------------------------------
*/

it('cannot modify another users todo via push', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $todoOfB = Todo::factory()->create([
        'user_id' => $userB->id,
        'title' => 'Original Title',
    ]);

    // UserA sends a valid update targeting UserB's todo UUID
    pushSync([[
        'uuid' => $todoOfB->uuid,
        'operation' => 'updated',
        'payload' => [
            'title' => 'Hacked Title',
            'last_modified_at' => now()->subSecond()->toIso8601String(),
        ],
    ]], $userA)->assertOk();

    // UserB's todo must be completely unchanged
    expect($todoOfB->fresh()->title)->toBe('Original Title');
});
