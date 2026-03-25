<?php

use App\Models\Todo;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Registration Tests
|--------------------------------------------------------------------------
*/

it('can register a new user', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email'],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
    ]);
});

it('prevents registration with an existing email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $response = $this->postJson('/api/register', [
        'name' => 'New User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

/*
|--------------------------------------------------------------------------
| Login Tests
|--------------------------------------------------------------------------
*/

it('can login with correct credentials', function () {
    $user = User::factory()->create([
        'password' => bcrypt($password = 'secret-password'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => $password,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['token', 'user']);
});

it('fails login with incorrect password', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes Tests
|--------------------------------------------------------------------------
*/

it('can fetch authenticated user data', function () {
    $user = User::factory()->create();

    // Using custom asUser() helper from Pest.php
    $response = asUser($user)->getJson('/api/me');

    $response->assertStatus(200)
        ->assertJson([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
});

it('can logout and revoke token', function () {
    $user = User::factory()->create();

    // Create a token manually to test physical deletion
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/logout');

    $response->assertStatus(200);

    expect($user->tokens()->count())->toBe(0);
});

it('protects routes from unauthenticated users', function () {
    $this->getJson('/api/me')->assertStatus(401);
    $this->postJson('/api/logout')->assertStatus(401);
});

it('prevents a user from updating another users todo', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $todoOfB = Todo::factory()->create(['user_id' => $userB->id]);

    // User A tries to "update" User B's todo
    pushSync([[
        'uuid' => $todoOfB->uuid,
        'operation' => 'updated',
        'payload' => ['title' => 'I hacked you'],
    ]], $userA);

    // The title should NOT have changed
    expect($todoOfB->fresh()->title)->not->toBe('I hacked you');
});
