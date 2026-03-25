<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
function asUser(?User $user = null)
{
    return test()->actingAs($user ?? User::factory()->create(), 'sanctum');
}

/**
 * A helper to quickly push sync operations.
 */
function pushSync(array $operations, ?User $user = null)
{
    return asUser($user)->postJson('/api/sync/push', [
        'operations' => $operations,
    ]);
}

/**
 * A helper to quickly pull sync data.
 */
function pullSync(?string $since = null, ?User $user = null)
{
    // Wrap $since in urlencode() to handle the ISO8601 special characters
    $url = '/api/sync/pull'.($since ? '?since='.urlencode($since) : '');

    return asUser($user)->getJson($url);
}
