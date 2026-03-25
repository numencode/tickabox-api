<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TodoSyncController extends Controller
{
    public function push(Request $request)
    {
        $data = $request->validate([
            'operations' => ['required', 'array'],
            'operations.*.uuid' => ['required', 'uuid'],
            'operations.*.operation' => ['required', 'in:created,updated,deleted'],
            'operations.*.payload' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $results = [];

        foreach ($data['operations'] as $operation) {
            $uuid = $operation['uuid'];
            $type = $operation['operation'];
            $payload = $operation['payload'] ?? [];

            $incomingModifiedAt = $this->parseModifiedAt($payload);

            Log::debug('API SYNC PUSH: incoming operation', [
                'user_id' => $user->id,
                'uuid' => $uuid,
                'operation' => $type,
                'payload' => $payload,
                'incoming_modified_at' => $incomingModifiedAt->toIso8601String(),
            ]);

            $todo = Todo::withTrashed()
                ->where('user_id', $user->id)
                ->where('uuid', $uuid)
                ->first();

            if ($type === 'deleted') {
                if ($todo) {
                    if (! $todo->last_modified_at || $incomingModifiedAt->gte($todo->last_modified_at)) {
                        $todo->last_modified_at = $incomingModifiedAt;
                        $todo->save();
                        $todo->delete();
                    }
                }

                $results[] = [
                    'uuid' => $uuid,
                    'status' => 'ok',
                    'deleted_at' => $incomingModifiedAt->toIso8601String(),
                ];

                continue;
            }

            if (! $todo) {
                $todo = Todo::create([
                    'uuid' => $uuid,
                    'user_id' => $user->id,
                    'title' => $this->payloadString($payload, 'title', ''),
                    'is_completed' => $this->payloadBool($payload, 'is_completed', false),
                    'last_modified_at' => $incomingModifiedAt,
                ]);
            } else {
                if (! $todo->last_modified_at || $incomingModifiedAt->gte($todo->last_modified_at)) {
                    $todo->title = $this->payloadString($payload, 'title', $todo->title);
                    $todo->is_completed = $this->payloadBool($payload, 'is_completed', (bool) $todo->is_completed);
                    $todo->last_modified_at = $incomingModifiedAt;
                    $todo->save();

                    if ($todo->trashed()) {
                        $todo->restore();
                    }
                }
            }

            Log::debug('API SYNC PUSH: saved todo', [
                'uuid' => $todo->uuid,
                'title' => $todo->title,
                'is_completed' => (bool) $todo->is_completed,
                'last_modified_at' => optional($todo->last_modified_at)?->toIso8601String(),
            ]);

            $results[] = [
                'uuid' => $todo->uuid,
                'status' => 'ok',
                'title' => $todo->title,
                'is_completed' => (bool) $todo->is_completed,
                'last_modified_at' => optional($todo->last_modified_at)->toIso8601String(),
                'deleted_at' => optional($todo->deleted_at)->toIso8601String(),
            ];
        }

        return response()->json([
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function pull(Request $request)
    {
        $data = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $since = isset($data['since']) ? Carbon::parse($data['since']) : null;

        $query = Todo::withTrashed()->where('user_id', $user->id);

        if ($since) {
            $query->where(function ($q) use ($since) {
                $q->where('updated_at', '>', $since)
                    ->orWhere('deleted_at', '>', $since)
                    ->orWhere('last_modified_at', '>', $since);
            });
        }

        $todos = $query->orderBy('last_modified_at')->get();

        return response()->json([
            'todos' => $todos->map(fn (Todo $todo) => [
                'uuid' => $todo->uuid,
                'title' => $todo->title,
                'is_completed' => (bool) $todo->is_completed,
                'last_modified_at' => optional($todo->last_modified_at)->toIso8601String(),
                'deleted_at' => optional($todo->deleted_at)->toIso8601String(),
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    protected function parseModifiedAt(array $payload): Carbon
    {
        if (array_key_exists('last_modified_at', $payload) && ! empty($payload['last_modified_at'])) {
            return Carbon::parse($payload['last_modified_at']);
        }

        return now();
    }

    protected function payloadString(array $payload, string $key, string $default): string
    {
        return array_key_exists($key, $payload)
            ? (string) $payload[$key]
            : $default;
    }

    protected function payloadBool(array $payload, string $key, bool $default): bool
    {
        return array_key_exists($key, $payload)
            ? filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $payload[$key]
            : $default;
    }
}
