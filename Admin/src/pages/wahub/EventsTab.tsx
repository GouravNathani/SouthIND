import { useEffect, useRef, useState } from "react";
import type { WaEvent } from "@/types/api";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import Switch from "@/components/ui/Switch";
import { Textarea } from "@/components/ui/Field";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconChevronDown } from "@/components/icons";
import VariableChips from "@/components/wahub/VariableChips";
import WaPill from "@/components/wahub/WaPill";
import WaErrorState from "@/components/wahub/WaErrorState";
import { waErrorCode, waErrorMessage } from "@/components/wahub/waFormat";
import { useGetWaEventsQuery, usePreviewWaEventMutation, useUpdateWaEventMutation } from "@/services/api";

const PREVIEW_DEBOUNCE_MS = 450;

/**
 * The seven automatic messages. Each one is off until the admin switches it on,
 * and its text can be rewritten with the event's variables. One editor open at a
 * time keeps the list short on a phone.
 */
export default function EventsTab() {
  const events = useGetWaEventsQuery(undefined, { refetchOnMountOrArgChange: true });
  const [openKey, setOpenKey] = useState<string | null>(null);

  if (events.isLoading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 7 }, (_, i) => (
          <Skeleton key={i} className="h-16 w-full" />
        ))}
      </div>
    );
  }

  if (events.error && !events.data) {
    return (
      <WaErrorState
        error={events.error}
        onRetry={() => void events.refetch()}
        retrying={events.isFetching}
        fallback="Could not load events."
      />
    );
  }

  const rows = events.data ?? [];
  const onCount = rows.filter((event) => event.enabled).length;

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted">
        {onCount} of {rows.length} on. A message goes out automatically when the event happens — 1 coin each.
      </p>
      <ul className="space-y-2">
        {rows.map((event) => (
          <li key={event.key}>
            <EventCard
              event={event}
              open={openKey === event.key}
              onToggleOpen={() => setOpenKey((current) => (current === event.key ? null : event.key))}
            />
          </li>
        ))}
      </ul>
    </div>
  );
}

function EventCard({ event, open, onToggleOpen }: { event: WaEvent; open: boolean; onToggleOpen: () => void }) {
  const [update, updateState] = useUpdateWaEventMutation();
  const [toggleError, setToggleError] = useState<string | null>(null);
  const [pendingEnabled, setPendingEnabled] = useState<boolean | null>(null);

  const enabled = pendingEnabled ?? event.enabled;

  const handleToggle = async (next: boolean) => {
    setToggleError(null);
    setPendingEnabled(next);
    try {
      await update({ event: event.key, body: { enabled: next } }).unwrap();
    } catch (err) {
      setToggleError(waErrorMessage(err, "Could not switch this event."));
    } finally {
      setPendingEnabled(null);
    }
  };

  return (
    <Card padded={false} className="overflow-hidden">
      <div className="flex min-w-0 items-center gap-3 px-4 py-3 sm:px-5">
        <button
          type="button"
          onClick={onToggleOpen}
          aria-expanded={open}
          className="flex min-w-0 flex-1 items-center gap-3 rounded-md text-left"
        >
          <span className="min-w-0 flex-1">
            <span className="flex min-w-0 items-center gap-2">
              <span className="truncate text-sm font-semibold text-text">{event.label}</span>
              {event.is_custom ? <WaPill tone="accent">Custom text</WaPill> : null}
            </span>
            <span className="block truncate text-xs text-faint">{event.body.replace(/\s+/g, " ")}</span>
          </span>
          <span
            className={["shrink-0 text-muted transition-transform duration-150", open ? "rotate-180" : ""].join(" ")}
          >
            <IconChevronDown size={18} />
          </span>
        </button>
        <Switch
          checked={enabled}
          onChange={(next) => void handleToggle(next)}
          disabled={updateState.isLoading && pendingEnabled !== null}
          label={`${event.label} message`}
          showState
        />
      </div>
      {toggleError ? (
        <div className="px-4 pb-3 sm:px-5">
          <ErrorNote>{toggleError}</ErrorNote>
        </div>
      ) : null}
      {open ? <EventEditor event={event} /> : null}
    </Card>
  );
}

function EventEditor({ event }: { event: WaEvent }) {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const [draft, setDraft] = useState(event.body);
  const [update, updateState] = useUpdateWaEventMutation();
  const [requestPreview] = usePreviewWaEventMutation();
  const [preview, setPreview] = useState<{ text?: string; error?: string; code?: string; loading: boolean }>({
    loading: true,
  });
  const [saveError, setSaveError] = useState<{ message: string; code?: string } | null>(null);
  const [saved, setSaved] = useState(false);
  const [confirmingReset, setConfirmingReset] = useState(false);
  const [resetError, setResetError] = useState<string | null>(null);

  const dirty = draft !== event.body;
  const canReset = event.is_custom || draft !== event.default_body;

  // A save error is about the text that failed; editing it clears the note.
  useEffect(() => setSaveError(null), [draft]);

  // Live preview, debounced; a late answer for an older draft is dropped.
  const requestId = useRef(0);
  useEffect(() => {
    const id = ++requestId.current;
    setPreview((prev) => ({ ...prev, loading: true }));
    const timer = window.setTimeout(async () => {
      try {
        const result = await requestPreview({ event: event.key, body: draft }).unwrap();
        if (id === requestId.current) setPreview({ text: result.text, loading: false });
      } catch (err) {
        if (id === requestId.current) {
          setPreview({
            error: waErrorMessage(err, "Preview is not available right now."),
            code: waErrorCode(err),
            loading: false,
          });
        }
      }
    }, PREVIEW_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [draft, event.key, requestPreview]);

  const handleSave = async () => {
    setSaveError(null);
    setSaved(false);
    try {
      await update({ event: event.key, body: { body: draft } }).unwrap();
      setSaved(true);
    } catch (err) {
      setSaveError({ message: waErrorMessage(err, "Could not save this text."), code: waErrorCode(err) });
    }
  };

  const handleReset = async () => {
    setResetError(null);
    try {
      const result = await update({ event: event.key, body: { body: null } }).unwrap();
      setDraft(result.body);
      setConfirmingReset(false);
    } catch (err) {
      setResetError(waErrorMessage(err, "Could not reset this text."));
    }
  };

  // An unknown {{variable}} is a typing mistake, so it belongs under the field.
  const fieldError =
    saveError?.code === "unknown_variable"
      ? saveError.message
      : preview.code === "unknown_variable"
        ? (preview.error ?? null)
        : null;

  return (
    <div className="space-y-4 border-t border-border px-4 py-4 sm:px-5">
      <div className="grid min-w-0 gap-4 lg:grid-cols-2">
        <div className="min-w-0 space-y-3">
          <Textarea
            ref={textareaRef}
            label="Message"
            rows={7}
            maxLength={2000}
            value={draft}
            onChange={(e) => {
              setDraft(e.target.value);
              setSaved(false);
            }}
            error={fieldError}
          />
          <VariableChips variables={event.variables} textareaRef={textareaRef} value={draft} onChange={setDraft} />
        </div>

        <div className="min-w-0">
          <p className="mb-1.5 flex items-center justify-between gap-2 text-xs font-medium text-muted">
            <span>Preview</span>
            {preview.loading ? <span className="text-faint">Updating…</span> : null}
          </p>
          <div className="rounded-lg bg-surface-2 p-3">
            <div
              className="ml-auto w-fit max-w-full rounded-md rounded-tr-sm px-3 py-2 text-sm break-words whitespace-pre-wrap text-text shadow-sm select-text"
              style={{ background: "color-mix(in srgb, var(--accent) 12%, var(--surface-solid))" }}
            >
              {preview.text ?? (preview.loading ? "…" : "—")}
            </div>
          </div>
          {preview.error && preview.error !== fieldError ? (
            <p className="mt-1.5 text-xs text-faint">{preview.error}</p>
          ) : (
            <p className="mt-1.5 text-xs text-faint">Sample values, with your header and footer.</p>
          )}
        </div>
      </div>

      {saveError && saveError.message !== fieldError ? <ErrorNote>{saveError.message}</ErrorNote> : null}
      {saved && !dirty ? <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">Saved.</p> : null}

      <div className="flex min-w-0 flex-wrap items-center gap-2">
        <Button size="sm" onClick={() => void handleSave()} loading={updateState.isLoading} disabled={!dirty || !draft.trim()}>
          Save
        </Button>
        {dirty ? (
          <Button size="sm" variant="ghost" onClick={() => setDraft(event.body)}>
            Undo changes
          </Button>
        ) : null}
        <Button
          size="sm"
          variant="secondary"
          className="ml-auto"
          onClick={() => setConfirmingReset(true)}
          disabled={!canReset}
        >
          Reset to default
        </Button>
      </div>

      <ConfirmDialog
        open={confirmingReset}
        title="Reset to default text?"
        confirmLabel="Reset"
        loading={updateState.isLoading}
        error={resetError}
        onConfirm={() => void handleReset()}
        onCancel={() => {
          setConfirmingReset(false);
          setResetError(null);
        }}
      >
        <p>Your custom text for “{event.label}” is replaced with the original.</p>
      </ConfirmDialog>
    </div>
  );
}
