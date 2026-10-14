<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\DB;
use NodeResources\Support\Tables;

/** Small facts the collector keeps between runs. */
class Meta
{
    public function get(string $name, mixed $default = null): mixed
    {
        $value = DB::table(Tables::META)->where('name', $name)->value('value');
        if (! is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function put(string $name, mixed $value): void
    {
        DB::table(Tables::META)->upsert(
            [['name' => $name, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => time()]],
            ['name'],
            ['value', 'updated_at']
        );
    }
}
