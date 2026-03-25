<?php

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Global Test Case Configuration
|--------------------------------------------------------------------------
*/

// This applies RefreshDatabase to every test in the Feature folder
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Custom Helpers
|--------------------------------------------------------------------------
*/

/**
 * Act as a specific user or a newly created one.
 */
function asUser(?\App\Models\User $user = null) {
    return test()->actingAs($user ?? \App\Models\User::factory()->create(), 'sanctum');
}

/**
 * A helper to quickly push sync operations.
 */
function pushSync(array $operations, ?\App\Models\User $user = null) {
    return asUser($user)->postJson('/api/sync/push', [
        'operations' => $operations
    ]);
}

/**
 * A helper to quickly pull sync data.
 */
function pullSync(?string $since = null, ?\App\Models\User $user = null) {
    // Wrap $since in urlencode() to handle the ISO8601 special characters
    $url = '/api/sync/pull' . ($since ? "?since=" . urlencode($since) : '');

    return asUser($user)->getJson($url);
}
