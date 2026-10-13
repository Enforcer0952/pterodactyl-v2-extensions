<?php

namespace McVersion\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use McVersion\Support\Software;
use Pterodactyl\Contracts\Servers\UpdatesServerDockerImage;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The startup variables and Docker image this page reads and changes, with the
 * checks the Startup page makes: only variables the egg lets users see and edit,
 * the egg's own rules for the value, and only images the egg offers.
 */
class Startup
{
    /** Egg variables that hold the Minecraft version, the first one a server has is used. */
    public const array VERSION_VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION'];

    public const array BUILD_VARIABLES = ['BUILD_NUMBER'];

    private const string JAR_VARIABLE = 'SERVER_JARFILE';

    /** @var array<int, Collection<int, EggVariable>> per request */
    private array $loaded = [];

    public function __construct(
        private readonly UpdatesServerVariable $variables,
        private readonly UpdatesServerDockerImage $images,
    ) {}

    /** The first of these variables the server has and the user may see. */
    public function find(Server $server, array $names): ?EggVariable
    {
        foreach ($names as $name) {
            foreach ($this->all($server) as $variable) {
                if ($variable->env_variable === $name && $variable->user_viewable) {
                    return $variable;
                }
            }
        }

        return null;
    }

    public function value(EggVariable $variable): string
    {
        return (string) ($variable->server_value ?? $variable->default_value ?? '');
    }

    public function editable(EggVariable $variable): bool
    {
        return $variable->user_viewable && $variable->user_editable;
    }

    /**
     * Set a variable like the Startup page does. Returns false, without changing
     * anything, when the variable is read-only or the egg's rules reject the value.
     */
    public function set(Server $server, EggVariable $variable, string $value): bool
    {
        if (! $this->accepts($variable, $value)) {
            return false;
        }

        if ($this->value($variable) === $value) {
            return true;
        }

        // The core action records the change in the activity log.
        $this->variables->update($server, $variable->id, $value);
        unset($this->loaded[$server->id]);

        return true;
    }

    /** The user may edit the variable and the egg's rules allow the value. */
    public function accepts(EggVariable $variable, string $value): bool
    {
        if (! $this->editable($variable)) {
            return false;
        }

        $rules = (string) ($variable->rules ?? '');
        $rules = str_starts_with($rules, 'regex:') ? [$rules] : array_filter(explode('|', $rules));

        return ! Validator::make(['value' => $value], ['value' => $rules])->fails();
    }

    /** The jar the server starts: SERVER_JARFILE, or the file named by the startup command. */
    public function jar(Server $server): string
    {
        $variable = $this->find($server, [self::JAR_VARIABLE]);

        return Software::jarName($variable === null ? null : $this->value($variable), $server->startup);
    }

    /**
     * The images the egg offers, as label => image.
     *
     * @return array<string, string>
     */
    public function images(Server $server): array
    {
        return array_filter((array) ($server->egg?->docker_images ?? []), 'is_string');
    }

    /** An image an administrator typed in is not the egg's to change. */
    public function imageIsLocked(Server $server): bool
    {
        return ! in_array($server->image, $this->images($server), true);
    }

    public function setImage(Server $server, string $image): void
    {
        if (! in_array($image, $this->images($server), true)) {
            throw new HttpException(422, 'This server\'s egg does not offer that Docker image.');
        }
        if ($this->imageIsLocked($server)) {
            throw new HttpException(409, 'An administrator set this server\'s Docker image by hand, so it cannot be changed here.');
        }

        if ($server->image !== $image) {
            // The core action records the change in the activity log.
            $this->images->update($server, $image);
        }
    }

    /** @return Collection<int, EggVariable> */
    private function all(Server $server): Collection
    {
        return $this->loaded[$server->id] ??= $server->variables()->get();
    }
}
