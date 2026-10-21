/** Hosts project images may load from: Modrinth's CDN and GitHub's raw files. */
export const IMAGE_HOSTS = ['cdn.modrinth.com', 'raw.githubusercontent.com'];

/** Whether a URL is an https image on an allowed host. */
export function isAllowedImage(url: string | null | undefined): boolean {
    if (!url) return false;
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'https:' && IMAGE_HOSTS.includes(parsed.hostname.toLowerCase());
    } catch {
        return false;
    }
}
