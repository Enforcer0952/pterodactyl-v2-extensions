import { afterEach, describe, expect, it } from 'vitest';
import { setConfig } from './config';
import { headUrl, isPremiumUuid, profileUrls, skinUrl } from './skins';

const premium = '069a79f4-44e9-4726-a5be-fca90e38aaf5';
const offline = 'b50ad385-829d-3141-a216-7e7d7539ba7f';

afterEach(() => setConfig({}));

describe('skins', () => {
    it('tells account UUIDs from offline-mode ones', () => {
        expect(isPremiumUuid(premium)).toBe(true);
        expect(isPremiumUuid(premium.replace(/-/g, ''))).toBe(true);
        expect(isPremiumUuid(offline)).toBe(false);
        expect(isPremiumUuid(null)).toBe(false);
    });

    it('uses the account UUID when there is one, else the name', () => {
        expect(skinUrl('Notch', premium)).toBe('https://mc-heads.net/skin/069a79f444e94726a5befca90e38aaf5');
        expect(skinUrl('Notch', offline)).toBe('https://mc-heads.net/skin/Notch');
        expect(headUrl('.BedrockGuy', null, 24)).toBe('https://mc-heads.net/avatar/BedrockGuy/24');
    });

    it('falls back to the default skin when names may not be looked up', () => {
        setConfig({ offline_skins: 'default' });
        expect(skinUrl('Notch', offline)).toBe('https://mc-heads.net/skin/MHF_Steve');
        setConfig({ skin_source: 'crafatar' });
        expect(skinUrl('Notch', offline)).toContain('crafatar.com/skins/00000000000000000000000000000000');
        expect(headUrl('Notch', premium, 24)).toBe(
            'https://crafatar.com/avatars/069a79f444e94726a5befca90e38aaf5?size=24&overlay&default=MHF_Steve',
        );
    });

    it('makes no outside requests when skins are off', () => {
        setConfig({ skin_source: 'off' });
        expect(skinUrl('Notch', premium)).toBeNull();
        expect(headUrl('Notch', premium, 24)).toBeNull();
    });

    it('builds profile links', () => {
        expect(profileUrls('.Steve', ['namemc', 'mcuuid'])).toEqual([
            { label: 'NameMC', url: 'https://namemc.com/profile/Steve' },
            { label: 'mcuuid.net', url: 'https://mcuuid.net/?q=Steve' },
        ]);
    });
});
