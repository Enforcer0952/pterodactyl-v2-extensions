<?php

namespace ValheimTools\Support;

/**
 * Joins and leaves from Valheim's console output. The dedicated server has no list
 * command, so presence is read from these lines (as logged by 1.0.x servers):
 *
 *   Got handshake from client 76561198051014133                 (Steam transport, SteamID64)
 *   PlayFab socket with remote ID playfab/D3E2D552844E42EA received local Platform ID Steam_76561198051014133
 *   Got handshake from client playfab/D3E2D552844E42EA          (crossplay transport)
 *   Got character ZDOID from Big Bob : -438505887:1             (the player's character: name, owner:id)
 *   Got character ZDOID from Big Bob : 0:0                      (character died, not a join)
 *   Destroying abandoned non persistent zdo 1305773203:5 owner 1305773203   (the player left)
 *   Closing socket 76561198051014133                            (a connection ended)
 *   Game server connected                                       (the server process started)
 *
 * Each line may start with a "MM/DD/YYYY HH:MM:SS: " timestamp. The ID line comes
 * before the character line; the owner number in the character line is what the
 * leave line names. Lists need a Steam ID: other platforms log an ID that is not
 * the one the access lists take, so those players are returned without one.
 */
final class LogParser
{
    /** @var array<string, string> transport handle (playfab/...) => platform ID */
    private array $handles = [];

    private ?string $pending = null;

    /** @var array<string, string> owner => name */
    private array $roster = [];

    /** @var array<string, ?string> owner => platform ID of the connection the character came in on */
    private array $ids = [];

    /**
     * @param  list<string>  $lines  oldest first
     * @return list<array{type: 'join'|'leave'|'start', name?: string, owner?: string, id?: ?string, steam?: bool}>
     */
    public function feed(array $lines): array
    {
        $events = [];
        foreach ($lines as $line) {
            $event = $this->line($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array<string, string> owner => name of everyone who has joined and not left
     */
    public function roster(): array
    {
        return $this->roster;
    }

    /**
     * Everyone who has joined and not left, with the ID their connection used.
     *
     * @return list<array{owner: string, name: string, id: ?string, steam: bool}>
     */
    public function players(): array
    {
        $players = [];
        foreach ($this->roster as $owner => $name) {
            $id = $this->ids[$owner] ?? null;
            $players[] = ['owner' => (string) $owner, 'name' => $name, 'id' => $id, 'steam' => $id !== null && PlayerIds::isSteam64($id)];
        }

        return $players;
    }

    /**
     * The parser's memory, to carry on from the next read of the console.
     *
     * @return array{handles: array<string, string>, pending: ?string, roster: array<string, string>, ids: array<string, ?string>}
     */
    public function export(): array
    {
        return ['handles' => $this->handles, 'pending' => $this->pending, 'roster' => $this->roster, 'ids' => $this->ids];
    }

    /**
     * @param  array<string, mixed>  $state  from export(); anything unexpected is ignored
     */
    public static function import(array $state): self
    {
        $parser = new self;
        foreach (['handles', 'roster', 'ids'] as $key) {
            $value = $state[$key] ?? [];
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $k => $v) {
                    if ((is_string($v) || ($key === 'ids' && $v === null)) && (is_string($k) || is_int($k))) {
                        $clean[(string) $k] = $v;
                    }
                }
                $parser->{$key} = $clean;
            }
        }
        $parser->pending = is_string($state['pending'] ?? null) ? $state['pending'] : null;

        return $parser;
    }

    /** One line of console output with colour codes, control characters and the timestamp removed. */
    public static function clean(string $line): string
    {
        $text = preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $line) ?? $line;
        $text = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text) ?? $text);

        return preg_replace('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}:\s*#', '', $text) ?? $text;
    }

    /** @return array{type: 'join'|'leave'|'start', name?: string, owner?: string, id?: ?string, steam?: bool}|null */
    public function line(string $raw): ?array
    {
        $line = self::clean($raw);

        if (preg_match('/PlayFab socket with remote ID (\S+) received local Platform ID (\S+)/', $line, $m) === 1) {
            $this->handles[$m[1]] = rtrim($m[2], '.,');

            return null;
        }

        if (preg_match('/Got connection SteamID (\d{10,})/', $line, $m) === 1 || preg_match('/Got handshake from client (\S+)/', $line, $m) === 1) {
            $this->pending = $this->resolve($m[1]);

            return null;
        }

        if (preg_match('/^Closing socket (\S+)$/', $line, $m) === 1) {
            if ($this->pending !== null && $this->pending === $this->resolve($m[1])) {
                $this->pending = null;
            }

            return null;
        }

        if (preg_match('/Got character ZDOID from (.+) : (-?\d+):(\d+)$/', $line, $m) === 1) {
            [, $name, $owner] = $m;
            if ($owner === '0') {
                return null;
            }
            if (isset($this->roster[$owner])) {
                $this->roster[$owner] = $name;

                return null;
            }
            $id = $this->pending;
            $this->pending = null;
            $this->roster[$owner] = $name;
            $this->ids[$owner] = $id;

            return ['type' => 'join', 'name' => $name, 'owner' => $owner, 'id' => $id, 'steam' => $id !== null && PlayerIds::isSteam64($id)];
        }

        if (preg_match('/Destroying abandoned non persistent zdo \S+ owner (-?\d+)/', $line, $m) === 1) {
            if (isset($this->roster[$m[1]])) {
                $name = $this->roster[$m[1]];
                unset($this->roster[$m[1]], $this->ids[$m[1]]);

                return ['type' => 'leave', 'name' => $name, 'owner' => $m[1]];
            }

            return null;
        }

        if (str_contains($line, 'Game server connected')) {
            $this->roster = [];
            $this->ids = [];
            $this->pending = null;
            $this->handles = [];

            return ['type' => 'start'];
        }

        return null;
    }

    /** A transport handle or ID as one ID: a Steam player is the plain SteamID64, others keep their prefix. */
    private function resolve(string $value): ?string
    {
        $id = $this->handles[$value] ?? $value;
        $steam = PlayerIds::steamId($id);
        if ($steam !== null) {
            return $steam;
        }

        // An unresolved playfab/ handle is not an ID anyone can list.
        return str_starts_with($id, 'playfab/') || ! PlayerIds::isValid($id) ? null : $id;
    }
}
