<?php

namespace ValheimTools\Services;

use Throwable;
use ValheimTools\Support\PlayerIds;

/** The extension's settings, read through the binding the provider sets up. */
class Preferences
{
    public const int DEFAULT_KEEP = 5;

    /** How many backups each world keeps. */
    public function keep(): int
    {
        $value = $this->get('keep_backups');

        return is_numeric($value) ? max(1, min(50, (int) $value)) : self::DEFAULT_KEEP;
    }

    /** How Steam players are written to the lists: v (V_), steam (Steam_) or plain. */
    public function idStyle(): string
    {
        $value = $this->get('steam_id_format');

        return is_string($value) && in_array($value, PlayerIds::STYLES, true) ? $value : 'v';
    }

    private function get(string $key): mixed
    {
        try {
            return app('ext.valheim-tools.settings')->get($key);
        } catch (Throwable) {
            return null;
        }
    }
}
