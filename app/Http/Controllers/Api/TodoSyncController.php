<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TodoSyncController extends Controller
{
    public function push(Request $request)
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'max:100'],
            'operations.*.uuid' => ['required', 'uuid'],
            'operations.*.operation' => ['required', 'in:created,updated,deleted'],
            'operations.*.payload' => ['nullable', 'array'],
            'operations.*.payload.title' => ['required_if:operations.*.operation,created', 'string', 'min:1', 'max:255'],
            'operations.*.payload.is_completed' => ['sometimes', 'boolean'],
            'operations.*.payload.last_modified_at' => ['required_if:operations.*.operation,updated', 'nullable', 'date_format:Y-m-d\TH:i:s\Z,Y-m-d\TH:i:sP', 'before_or_equal:now'],
        ]);

        $user = $request->user();
        $serverNow = now();

        $results = [];

        foreach ($data['operations'] as $operation) {
            $uuid = $operation['uuid'];
            $type = $operation['operation'];
            $payload = $operation['payload'] ?? [];

            try {
                $result = DB::transaction(function () use ($user, $uuid, $type, $payload, $serverNow) {
                    $incomingModifiedAt = $this->parseModifiedAt($payload);

                    $todo = Todo::withTrashed()
                        ->where('user_id', $user->id)
                        ->where('uuid', $uuid)
                        ->lockForUpdate()
                        ->first();

                    if ($type === 'deleted') {
                        if ($todo && $todo->last_modified_at && $incomingModifiedAt->lt($todo->last_modified_at)) {
                            // Server version is newer — reject delete, return current state so client reconciles
                            return [
                                'uuid' => $todo->uuid,
                                'status' => 'ok',
                                'title' => $todo->title,
                                'is_completed' => (bool) $todo->is_completed,
                                'last_modified_at' => $todo->last_modified_at->toIso8601String(),
                                'deleted_at' => $todo->deleted_at?->toIso8601String(),
                            ];
                        }

                        // Apply the delete; use server time as authoritative timestamp
                        if ($todo) {
                            $todo->last_modified_at = $serverNow;
                            $todo->save();
                            $todo->delete();
                        }

                        return [
                            'uuid' => $uuid,
                            'status' => 'ok',
                            'title' => $todo?->title,
                            'is_completed' => $todo ? (bool) $todo->is_completed : false,
                            'last_modified_at' => $serverNow->toIso8601String(),
                            'deleted_at' => $serverNow->toIso8601String(),
                        ];
                    }

                    if (! $todo) {
                        $title = $this->payloadString($payload, 'title', '');

                        if ($title === '') {
                            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'Title is required for created operations.'];
                        }

                        $todo = Todo::create([
                            'uuid' => $uuid,
                            'user_id' => $user->id,
                            'title' => $title,
                            'is_completed' => $this->payloadBool($payload, 'is_completed', false),
                            'last_modified_at' => $serverNow,
                        ]);
                    } else {
                        $hasFields = array_key_exists('title', $payload) || array_key_exists('is_completed', $payload);

                        if ($hasFields && (! $todo->last_modified_at || $incomingModifiedAt->gte($todo->last_modified_at))) {
                            $todo->title = $this->payloadString($payload, 'title', $todo->title);
                            $todo->is_completed = $this->payloadBool($payload, 'is_completed', (bool) $todo->is_completed);
                            $todo->last_modified_at = $serverNow;
                            $todo->save();

                            if ($todo->trashed()) {
                                $todo->restore();
                            }
                        }
                    }

                    return [
                        'uuid' => $todo->uuid,
                        'status' => 'ok',
                        'title' => $todo->title,
                        'is_completed' => (bool) $todo->is_completed,
                        'last_modified_at' => $todo->last_modified_at->toIso8601String(),
                        'deleted_at' => $todo->deleted_at?->toIso8601String(),
                    ];
                });

                $results[] = $result;
            } catch (\Throwable $e) {
                Log::error('Todo push operation failed', [
                    'uuid' => $uuid,
                    'operation' => $type,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'uuid' => $uuid,
                    'status' => 'error',
                    'message' => 'Operation failed.',
                ];
            }
        }

        return response()->json([
            'results' => $results,
            'server_time' => $serverNow->toIso8601String(),
        ]);
    }

    public function pull(Request $request)
    {
        $data = $request->validate([
            'since' => ['nullable', 'date_format:Y-m-d\TH:i:s\Z,Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'since_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $serverNow = now();
        $since = isset($data['since']) ? Carbon::parse($data['since'], 'UTC') : null;
        $sinceId = (int) ($data['since_id'] ?? 0);
        $limit = 1000;

        $query = Todo::withTrashed()->where('user_id', $user->id);

        if ($since) {
            $query->where(function ($q) use ($since, $sinceId) {
                $q->where('last_modified_at', '>', $since)
                    ->orWhere(function ($q2) use ($since, $sinceId) {
                        $q2->where('last_modified_at', $since)
                            ->where('id', '>', $sinceId);
                    });
            });
        }

        $todos = $query->orderBy('last_modified_at')->orderBy('id')->limit($limit + 1)->get();

        $hasMore = $todos->count() > $limit;
        if ($hasMore) {
            $todos = $todos->take($limit);
        }

        $lastTodo = $todos->last();

        return response()->json([
            'todos' => $todos->map(fn (Todo $todo) => [
                'uuid' => $todo->uuid,
                'title' => $todo->title,
                'is_completed' => (bool) $todo->is_completed,
                'last_modified_at' => ($todo->last_modified_at ?? $todo->updated_at)->toIso8601String(),
                'deleted_at' => $todo->deleted_at?->toIso8601String(),
            ])->values(),
            'has_more' => $hasMore,
            'next_since' => $hasMore ? ($lastTodo->last_modified_at ?? $lastTodo->updated_at)?->toIso8601String() : null,
            'next_since_id' => $hasMore ? $lastTodo->id : null,
            'server_time' => $serverNow->toIso8601String(),
        ]);
    }

    protected function parseModifiedAt(array $payload): Carbon
    {
        if (array_key_exists('last_modified_at', $payload) && ! empty($payload['last_modified_at'])) {
            return Carbon::parse($payload['last_modified_at'], 'UTC');
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
        if (! array_key_exists($key, $payload)) {
            return $default;
        }
        $value = $payload[$key];
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
