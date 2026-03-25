<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

// We need to use a real cache driver (not 'array')
// for rate limits to persist between requests in a single test execution.
beforeEach(function () {
    config(['cache.default' => 'file']);
});

it('throttles login attempts after 10 failures', function () {
    $email = 'victim@example.com';

    // 1. Simulate 10 failed login attempts
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // 2. The 11th attempt should be throttled
    $response = $this->postJson('/api/login', [
        'email' => $email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429); // 429 = Too Many Attempts
    $response->assertJsonPath('message', 'Too Many Attempts.');
});

it('throttles the sync pull endpoint based on user id', function () {
    $user = User::factory()->create();

    // AppServiceProvider allows 60 per minute for 'sync'
    for ($i = 0; $i < 60; $i++) {
        pullSync(user: $user)->assertOk();
    }

    // The 61st should fail
    pullSync(user: $user)->assertStatus(429);
});
