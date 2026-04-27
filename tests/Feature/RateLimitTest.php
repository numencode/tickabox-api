<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Rate limiters use the cache driver. Switch to 'file' so the counter
// persists across multiple requests within a single test (the default
// 'array' driver is request-scoped in some configurations). Flush before
// each test to ensure no leftover state from a previous run.
beforeEach(function () {
    config(['cache.default' => 'file']);
    Cache::flush();
});

afterEach(function () {
    Cache::flush();
    // Restore the default cache driver so it doesn't bleed into other test classes
    config(['cache.default' => 'array']);
});

/*
|--------------------------------------------------------------------------
| Auth Rate Limiting (10 req/min per email+IP)
|--------------------------------------------------------------------------
*/

it('throttles login attempts after 10 requests for the same email', function () {
    $email = 'victim@example.com';

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/login', [
        'email' => $email,
        'password' => 'wrong-password',
    ])->assertStatus(429)->assertJsonPath('message', 'Too Many Attempts.');
});

it('throttles registration attempts after 10 requests for the same email', function () {
    $email = 'spammer@example.com';

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/register', [
            'name' => 'Spammer',
            'email' => $email,
            'password' => 'SecurePass1',
        ]);
        // After the first 201 the rest return 422 (duplicate email) — all count toward the limit
    }

    $this->postJson('/api/register', [
        'name' => 'Spammer',
        'email' => $email,
        'password' => 'SecurePass1',
    ])->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| Sync Rate Limiting (60 req/min per authenticated user)
|--------------------------------------------------------------------------
*/

it('throttles the sync pull endpoint after 60 requests per user', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 60; $i++) {
        pullSync(user: $user)->assertOk();
    }

    pullSync(user: $user)->assertStatus(429);
});

it('throttles the sync push endpoint after the shared per-user limit is reached', function () {
    $user = User::factory()->create();

    // Push and pull share the same 'sync' rate limiter (60 req/min per user).
    // Exhaust the quota on pull, then confirm push is also blocked.
    for ($i = 0; $i < 60; $i++) {
        pullSync(user: $user)->assertOk();
    }

    pushSync([[
        'uuid' => (string) Str::uuid(),
        'operation' => 'created',
        'payload' => [
            'title' => 'Should be throttled',
            'last_modified_at' => now()->subSecond()->toIso8601String(),
        ],
    ]], $user)->assertStatus(429);
});
