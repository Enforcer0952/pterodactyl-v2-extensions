<?php

use PalworldTools\Support\StartupOverrides;
use PalworldTools\Support\SettingsModel;
use PHPUnit\Framework\TestCase;

final class StartupOverridesTest extends TestCase
{
    private const string EGG = './PalworldServerConfigParser; (while read cmd; do rcon -s -a "localhost:$RCON_PORT" -p "$ADMIN_PASSWORD" "$cmd";done) < /dev/stdin & /home/container/Pal/Binaries/Linux/PalServer-Linux-Shipping Pal -publiclobby -useperfthreads -NoAsyncLoadingThread -UseMultithreadForDS -port={{SERVER_PORT}} -publicport={{SERVER_PORT}} -servername="{{SERVER_NAME}}" -players={{MAX_PLAYERS}} $(if [ -n "$SERVER_PASSWORD" ]; then echo "-serverpassword=\"${SERVER_PASSWORD}\""; fi) -adminpassword="{{ADMIN_PASSWORD}}" -rcon';

    /** @return array<string, string> */
    private function env(): array
    {
        $env = [];
        foreach ((new SettingsModel())->schema()['settings'] as $setting) {
            if (isset($setting['env'])) {
                $env[$setting['key']] = $setting['env'];
            }
        }

        return $env;
    }

    public function testCommonEgg(): void
    {
        $vars = ['MAX_PLAYERS', 'ADMIN_PASSWORD', 'SERVER_NAME', 'SERVER_PASSWORD', 'SERVER_DESCRIPTION', 'RCON_PORT', 'RCON_ENABLE', 'CROSSPLAY_PLATFORMS', 'PUBLIC_IP'];
        $locks = StartupOverrides::locks(self::EGG, $this->env(), $vars);

        $this->assertSame(['kind' => 'variable', 'env' => 'SERVER_NAME'], $locks['ServerName']);
        $this->assertSame(['kind' => 'variable', 'env' => 'MAX_PLAYERS'], $locks['ServerPlayerMaxNum']);
        $this->assertSame(['kind' => 'variable', 'env' => 'ADMIN_PASSWORD'], $locks['AdminPassword']);
        $this->assertSame(['kind' => 'variable', 'env' => 'SERVER_PASSWORD'], $locks['ServerPassword']);
        $this->assertSame(['kind' => 'allocation', 'env' => 'SERVER_PORT'], $locks['PublicPort']);
        $this->assertSame(['kind' => 'variable', 'env' => 'RCON_PORT'], $locks['RCONPort']);
        $this->assertSame(['kind' => 'variable', 'env' => 'CROSSPLAY_PLATFORMS'], $locks['CrossplayPlatforms']);
        // No variable for these in the egg: the file decides.
        $this->assertArrayNotHasKey('ExpRate', $locks);
        $this->assertArrayNotHasKey('RESTAPIEnabled', $locks);
    }

    public function testExtraVariablesAreLockedOnlyWhenTheEggHasThem(): void
    {
        $locks = StartupOverrides::locks(self::EGG, $this->env(), ['EXP_RATE', 'REST_API_ENABLED']);

        $this->assertSame('EXP_RATE', $locks['ExpRate']['env']);
        $this->assertSame('variable', $locks['RESTAPIEnabled']['kind']);
        $this->assertArrayNotHasKey('PalCaptureRate', $locks);
    }

    public function testHardCodedArgumentsWinOverTheFile(): void
    {
        $locks = StartupOverrides::locks('./PalServer.sh -servername="My Server" -players=16 -port=8211', $this->env(), ['EXP_RATE']);

        $this->assertSame(['kind' => 'startup', 'env' => null], $locks['ServerName']);
        $this->assertSame(['kind' => 'startup', 'env' => null], $locks['ServerPlayerMaxNum']);
        // Without the parser tool, variables do not touch the file.
        $this->assertArrayNotHasKey('ExpRate', $locks);
    }

    public function testNothingIsLockedOnAPlainStartup(): void
    {
        $this->assertSame([], StartupOverrides::locks('./PalServer.sh -useperfthreads', $this->env(), ['SERVER_NAME']));
    }

    public function testArgumentNamesAreNotMatchedInsideOtherWords(): void
    {
        $this->assertSame([], StartupOverrides::locks('./PalServer.sh --myservername=x', $this->env(), []));
    }
}
