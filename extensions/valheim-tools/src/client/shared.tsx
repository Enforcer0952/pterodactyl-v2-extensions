import type { ReactNode } from 'react';

export function Empty({ children }: { children: ReactNode }) {
    return <p className={'vh:py-4 vh:text-center vh:text-sm vh:text-muted-foreground'}>{children}</p>;
}

export function Rows({ children, tall = false }: { children: ReactNode; tall?: boolean }) {
    return (
        <ul className={`vh:flex vh:flex-col vh:divide-y vh:divide-border vh:overflow-y-auto ${tall ? 'vh:max-h-[28rem]' : 'vh:max-h-80'}`}>
            {children}
        </ul>
    );
}

export function Row({ name, badge, detail, children }: { name: string; badge?: string; detail?: string; children?: ReactNode }) {
    return (
        <li className={'vh:flex vh:items-center vh:gap-3 vh:py-2'}>
            <div className={'vh:min-w-0 vh:flex-1'}>
                <div className={'vh:flex vh:items-center vh:gap-2'}>
                    <span className={'vh:truncate vh:text-sm vh:font-medium vh:text-foreground'}>{name}</span>
                    {badge && (
                        <span className={'vh:rounded vh:bg-muted vh:px-1.5 vh:py-0.5 vh:text-[11px] vh:text-muted-foreground'}>
                            {badge}
                        </span>
                    )}
                </div>
                {detail && <p className={'vh:truncate vh:text-xs vh:text-muted-foreground'}>{detail}</p>}
            </div>
            <div className={'vh:flex vh:shrink-0 vh:flex-wrap vh:items-center vh:justify-end vh:gap-1.5'}>{children}</div>
        </li>
    );
}

export function Field({
    label,
    htmlFor,
    error,
    children,
}: {
    label: string;
    htmlFor?: string;
    error?: string | null;
    children: ReactNode;
}) {
    return (
        <div className={'vh:flex vh:flex-col vh:gap-1'}>
            <label htmlFor={htmlFor} className={'vh:text-sm vh:font-medium vh:text-foreground'}>
                {label}
            </label>
            {children}
            {error && <p className={'vh:text-xs vh:text-destructive'}>{error}</p>}
        </div>
    );
}
