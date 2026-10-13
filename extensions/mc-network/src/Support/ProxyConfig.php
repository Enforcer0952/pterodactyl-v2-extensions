<?php

namespace McNetwork\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A BungeeCord or Waterfall config.yml. Reads the backend list, the first
 * listener's priorities and forced hosts, and writes them back with every other key
 * kept as it was. YAML comments and the original layout are not kept.
 */
final class ProxyConfig
{
    private function __construct(private readonly \stdClass $root) {}

    public static function parse(string $yaml): self
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $exception) {
            $line = $exception->getParsedLine();
            throw new InvalidConfig('config.yml is not valid YAML'.($line > 0 ? ' (line '.$line.')' : '').'.', 0, $exception);
        }

        if (! $data instanceof \stdClass) {
            throw new InvalidConfig('config.yml is not a BungeeCord configuration.');
        }

        return new self($data);
    }

    /**
     * @return list<array{name: string, address: string, motd: string, restricted: bool}>
     */
    public function servers(): array
    {
        $servers = [];
        foreach (self::properties($this->root->servers ?? null) as $name => $entry) {
            $entry = $entry instanceof \stdClass ? $entry : new \stdClass;
            $servers[] = [
                'name' => (string) $name,
                'address' => self::text($entry->address ?? ''),
                'motd' => self::text($entry->motd ?? ''),
                'restricted' => ($entry->restricted ?? false) === true,
            ];
        }

        return $servers;
    }

    public function hasListener(): bool
    {
        return $this->listener() !== null;
    }

    /**
     * @return list<string> server names, the first is the default
     */
    public function priorities(): array
    {
        $priorities = $this->listener()?->priorities ?? [];

        return is_array($priorities) ? array_values(array_map(self::text(...), array_filter($priorities, is_scalar(...)))) : [];
    }

    /**
     * @return list<array{host: string, server: string}>
     */
    public function forcedHosts(): array
    {
        $hosts = [];
        foreach (self::properties($this->listener()?->forced_hosts ?? null) as $host => $server) {
            $hosts[] = ['host' => (string) $host, 'server' => self::text($server)];
        }

        return $hosts;
    }

    /**
     * Replaces the backend list, the priorities and the forced hosts. An entry with
     * "was" set takes over the other keys of the entry that had that name.
     *
     * @param  list<array{name: string, address: string, motd: string, restricted: bool, was?: ?string}>  $servers
     * @param  list<string>  $priorities
     * @param  list<array{host: string, server: string}>  $forcedHosts
     */
    public function replace(array $servers, array $priorities, array $forcedHosts): self
    {
        $listener = $this->listener();
        if ($listener === null && ($priorities !== [] || $forcedHosts !== [])) {
            throw new InvalidConfig('config.yml has no listener, so priorities and forced hosts cannot be saved.');
        }

        $existing = self::properties($this->root->servers ?? null);
        $new = new \stdClass;
        foreach ($servers as $server) {
            $base = $existing[$server['was'] ?? $server['name']] ?? null;
            $entry = $base instanceof \stdClass ? clone $base : new \stdClass;
            $entry->motd = $server['motd'];
            $entry->address = $server['address'];
            $entry->restricted = $server['restricted'];
            $new->{$server['name']} = $entry;
        }
        $this->root->servers = $new;

        if ($listener !== null) {
            $listener->priorities = array_values($priorities);
            $hosts = new \stdClass;
            foreach ($forcedHosts as $forced) {
                $hosts->{$forced['host']} = $forced['server'];
            }
            $listener->forced_hosts = $hosts;
        }

        return $this;
    }

    public function dump(): string
    {
        return Yaml::dump($this->root, 12, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    private function listener(): ?\stdClass
    {
        $listeners = $this->root->listeners ?? null;

        return is_array($listeners) && ($listeners[0] ?? null) instanceof \stdClass ? $listeners[0] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(mixed $value): array
    {
        if (! $value instanceof \stdClass) {
            return [];
        }

        $properties = [];
        foreach (get_object_vars($value) as $key => $item) {
            $properties[(string) $key] = $item;
        }

        return $properties;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : '';
    }
}
