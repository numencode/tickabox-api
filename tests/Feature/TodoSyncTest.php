<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| PUSH Operations
|--------------------------------------------------------------------------
*/

it('can create a new todo via push', function () {
    $uuid = (string) Str::uuid();

    pushSync([[
        'uuid' => $uuid,
        'operation' => 'created',
        'payload' => [
            'title' => 'Finish Laravel Tests',
            'is_completed' => false,
            'last_modified_at' => now()->toIso8601String(),
        ],
    ]])->assertStatus(200);

    $this->assertDatabaseHas('todos', [
        'uuid' => $uuid,
        'title' => 'Finish Laravel Tests',
    ]);
});

it('updates existing todo only if incoming timestamp is newer (LWW)', function () {
    $user = User::factory()->create();
    $uuid = (string) Str::uuid();

    // 1. Create a todo on the server set at 12:00
    $todo = Todo::factory()->create([
        'uuid' => $uuid,
        'user_id' => $user->id,
        'title' => 'Server Version',
        'last_modified_at' => now()->subHours(2),
    ]);

    // 2. Try to push an update with an OLDER timestamp (11:00)
    pushSync([[
        'uuid' => $uuid,
        'operation' => 'updated',
        'payload' => [
            'title' => 'Stale Update',
            'last_modified_at' => now()->subHours(3),
        ],
    ]], $user);

    expect($todo->fresh()->title)->toBe('Server Version');

    // 3. Try to push an update with a NEWER timestamp (13:00)
    pushSync([[
        'uuid' => $uuid,
        'operation' => 'updated',
        'payload' => [
            'title' => 'Fresh Update',
            'last_modified_at' => now()->addHour(),
        ],
    ]], $user);

    expect($todo->fresh()->title)->toBe('Fresh Update');
});

it('can soft delete a todo via push', function () {
    $user = User::factory()->create();
    $todo = Todo::factory()->create(['user_id' => $user->id]);

    pushSync([[
        'uuid' => $todo->uuid,
        'operation' => 'deleted',
        'payload' => ['last_modified_at' => now()->toIso8601String()],
    ]], $user);

    expect($todo->fresh()->trashed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| PULL Operations
|--------------------------------------------------------------------------
*/

it('pulls only todos modified since a specific date', function () {
    $user = User::factory()->create();

    // Create an old todo
    Todo::factory()->create([
        'user_id' => $user->id,
        'updated_at' => now()->subDays(10),
        'last_modified_at' => now()->subDays(10),
    ]);

    // Create a new todo
    $newTodo = Todo::factory()->create([
        'user_id' => $user->id,
        'updated_at' => now(),
        'last_modified_at' => now(),
    ]);

    $since = now()->subDays(1)->toIso8601String();

    pullSync($since, $user)
        ->assertStatus(200)
        ->assertJsonCount(1, 'todos')
        ->assertJsonPath('todos.0.uuid', $newTodo->uuid);
});

it('does not pull todos belonging to other users', function () {
    $otherUser = User::factory()->create();
    Todo::factory()->create(['user_id' => $otherUser->id]);

    // pullSync() with no user passed creates a fresh new user automatically
    pullSync()->assertStatus(200)->assertJsonCount(0, 'todos');
});
