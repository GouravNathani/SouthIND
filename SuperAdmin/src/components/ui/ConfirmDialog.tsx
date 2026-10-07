import { useEffect, useId, useRef, type ReactNode } from "react";
import { createPortal } from "react-dom";
import Button from "@/components/ui/Button";
import { ErrorNote } from "@/components/ui/Feedback";

/**
 * A blocking yes/no for actions that cost something or can not be undone
 * (spending coins, dropping a linked number). Rendered into <body> so no
 * transformed ancestor can trap the fixed overlay; Escape and the backdrop
 * cancel unless the action is already running.
 */
export default function ConfirmDialog({
  open,
  title,
  children,
  confirmLabel,
  cancelLabel = "Cancel",
  tone = "primary",
  loading,
  error,
  onConfirm,
  onCancel,
}: {
  open: boolean;
  title: string;
  children?: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  tone?: "primary" | "danger";
  loading?: boolean;
  error?: string | null;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const titleId = useId();
  const cancelRef = useRef<HTMLButtonElement>(null);
  // Callers pass inline handlers; read the latest through a ref so a parent
  // re-render (a poll landing) never re-runs the open effect and moves focus.
  const latest = useRef({ onCancel, loading });
  latest.current = { onCancel, loading };

  useEffect(() => {
    if (!open) return;
    // Cancel takes focus: Enter on a freshly opened dialog must never spend coins.
    cancelRef.current?.focus();
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !latest.current.loading) latest.current.onCancel();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open]);

  if (!open || typeof document === "undefined") return null;

  return createPortal(
    <div className="fixed inset-0 z-[70] flex items-end justify-center p-4 sm:items-center">
      <button
        type="button"
        aria-label="Close"
        tabIndex={-1}
        onClick={() => (loading ? undefined : onCancel())}
        className="absolute inset-0 bg-black/55"
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative w-full max-w-sm min-w-0 rounded-lg border border-border bg-bg-elev p-5 shadow-lg"
      >
        <h2 id={titleId} className="text-base font-semibold text-text">
          {title}
        </h2>
        {children ? <div className="mt-2 space-y-2 text-sm text-muted">{children}</div> : null}
        {error ? (
          <div className="mt-3">
            <ErrorNote>{error}</ErrorNote>
          </div>
        ) : null}
        <div className="mt-5 flex min-w-0 flex-wrap justify-end gap-2">
          <button
            ref={cancelRef}
            type="button"
            onClick={onCancel}
            disabled={loading}
            className="inline-flex h-11 items-center justify-center rounded-full border border-border bg-surface px-5 text-sm font-semibold text-text transition-colors hover:border-border-strong disabled:opacity-55"
          >
            {cancelLabel}
          </button>
          <Button variant={tone === "danger" ? "danger" : "primary"} loading={loading} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>,
    document.body
  );
}
