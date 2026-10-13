<?php

namespace McPlayers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McPlayers\Services\McBans;
use Pterodactyl\Services\Extensions\ExtensionManager;

/** A player's MCBans record, when the admin has the lookup turned on. */
class McBansController extends Controller
{
    public function __construct(
        private readonly McBans $mcbans,
        private readonly ExtensionManager $extensions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($this->extensions->settings('mc-players')->get('mcbans', 'on') === 'on', 404, 'MCBans lookups are turned off.');
        $uuid = (string) $request->validate(['uuid' => ['required', 'string', 'max:36']])['uuid'];

        return new JsonResponse($this->mcbans->lookup($uuid));
    }
}
