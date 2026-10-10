export default function ProjectIcon({ url, title }: { url: string | null; title: string }) {
    return url ? (
        <img src={url} alt={''} loading={'lazy'} className={'mcc:size-10 mcc:shrink-0 mcc:rounded-md mcc:bg-muted mcc:object-cover'} />
    ) : (
        <div
            aria-hidden
            className={
                'mcc:flex mcc:size-10 mcc:shrink-0 mcc:items-center mcc:justify-center mcc:rounded-md mcc:bg-muted mcc:text-sm mcc:font-semibold mcc:text-muted-foreground'
            }
        >
            {title.slice(0, 1).toUpperCase()}
        </div>
    );
}
