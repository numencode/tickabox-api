<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

function asUser(?User $user = null): mixed
{
    return test()->actingAs($user ?? User::factory()->create(), 'sanctum');
}

function pushSync(array $operations, ?User $user = null): mixed
{
    return asUser($user)->postJson('/api/sync/push', [
        'operations' => $operations,
    ]);
}

function pullSync(?string $since = null, ?User $user = null, ?int $sinceId = null): mixed
{
    $params = array_filter([
        'since' => $since,
        'since_id' => $sinceId,
    ], fn ($v) => $v !== null);

    $url = '/api/sync/pull'.($params ? '?'.http_build_query($params) : '');

    return asUser($user)->getJson($url);
}
