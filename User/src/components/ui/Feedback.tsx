import type { ReactNode } from "react";

export function Skeleton({ className = "" }: { className?: string }) {
  return <div className={["animate-pulse rounded-md bg-surface-2", className].join(" ")} />;
}

export function EmptyState({
  title,
  body,
  action,
}: {
  title: string;
  body?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
      <p className="text-sm font-semibold text-text">{title}</p>
      {body ? <p className="max-w-sm text-xs text-muted">{body}</p> : null}
      {action ? <div className="mt-3">{action}</div> : null}
    </div>
  );
}

export function ErrorNote({ children }: { children: ReactNode }) {
  return (
    <p
      role="alert"
      className="rounded-md px-3 py-2 text-xs"
      style={{ color: "var(--neg)", background: "color-mix(in srgb, var(--neg) 12%, transparent)" }}
    >
      {children}
    </p>
  );
}
