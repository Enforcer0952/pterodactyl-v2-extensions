<?php

namespace NodeResources\Support;

/** The things worth telling an admin about a node. */
final class Warnings
{
    public const int DISK_PERCENT = 85;

    public const int FORECAST_DAYS = 7;

    /**
     * @param  array{ram: array<string, mixed>, disk: array<string, mixed>}  $capacity  from Capacity::summarize()
     * @param  array{days: ?float, growth_per_day: float}|null  $forecast
     * @return list<array{id: string, level: 'warning'|'danger', text: string}>
     */
    public static function forNode(array $capacity, ?array $forecast, int $diskPercent = self::DISK_PERCENT, int $forecastDays = self::FORECAST_DAYS): array
    {
        $out = [];
        $disk = $capacity['disk'];
        $ram = $capacity['ram'];

        if ($disk['host_total'] !== null && $disk['host_used'] !== null && $disk['host_total'] > 0) {
            $percent = $disk['host_used'] / $disk['host_total'] * 100;
            if ($percent >= $diskPercent) {
                $out[] = [
                    'id' => 'disk-high',
                    'level' => $percent >= 95 ? 'danger' : 'warning',
                    'text' => sprintf('The disk is %d%% full. %s left.', (int) floor($percent), Format::bytes($disk['host_free'])),
                ];
            }
        }

        if ($forecast !== null && $forecast['days'] !== null && $forecast['days'] <= $forecastDays) {
            $out[] = [
                'id' => 'disk-forecast',
                'level' => $forecast['days'] <= 2 ? 'danger' : 'warning',
                'text' => $forecast['days'] < 1
                    ? 'At the current pace the disk is full in less than a day.'
                    : 'At the current pace the disk is full in about '.Format::days($forecast['days']).'.',
            ];
        }

        if ($ram['overcommitted'] === true) {
            $out[] = [
                'id' => 'ram-overcommitted',
                'level' => 'warning',
                'text' => sprintf(
                    'Servers can use up to %s of memory, but the host has %s.',
                    Format::bytes($ram['committed']),
                    Format::bytes($ram['host_total'])
                ),
            ];
        }

        return $out;
    }
}
