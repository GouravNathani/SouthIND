import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import type { WaConnection } from "@/types/api";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Input, Textarea } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconAlert, IconLink, IconRefresh, IconSend } from "@/components/icons";
import WaPill, { WaNotice } from "@/components/wahub/WaPill";
import {
  connectionStatus,
  coinsLabel,
  count,
  isLinking,
  isReconnecting,
  maskNumber,
  platformLabel,
  skipReasonLabel,
  waErrorMessage,
} from "@/components/wahub/waFormat";
import { useWaCoinRate } from "@/components/wahub/useWaCoinRate";
import {
  useGetWaUsageQuery,
  useLinkWaConnectionMutation,
  useSendWaTestMutation,
  useUnlinkWaConnectionMutation,
  useUpdateWaConnectionMutation,
} from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";

export default function ConnectTab({
  connection,
  fetchedAt,
  refreshing,
  onRefresh,
}: {
  connection: WaConnection;
  /** When the connection last arrived — the QR countdown runs from here. */
  fetchedAt?: number;
  refreshing?: boolean;
  onRefresh: () => void;
}) {
  return (
    <div className="space-y-4">
      <StatusCard connection={connection} fetchedAt={fetchedAt} refreshing={refreshing} onRefresh={onRefresh} />
      <div className="grid gap-4 lg:grid-cols-2">
        {/* Remount on a server-side change so the fields never show stale text. */}
        <HeaderFooterCard key={`${connection.header}\u0000${connection.footer}`} connection={connection} />
        <TestMessageCard connected={connection.status === "connected"} />
      </div>
    </div>
  );
}

// ── Status ──────────────────────────────────────────────────────────────────

const IDLE_COPY: Record<string, { title: string; body: string }> = {
  not_linked: {
    title: "WhatsApp is not connected",
    body: "Link the branch's WhatsApp number so deposit, withdrawal and other updates reach users from it.",
  },
  expired: {
    title: "QR expired — try again",
    body: "The code was not scanned in time. Get a fresh one and scan it within a minute.",
  },
  logged_out: {
    title: "Logged out",
    body: "WhatsApp was logged out on the phone. Connect again to keep sending.",
  },
  banned: {
    title: "This number is banned",
    body: "WhatsApp has banned this number. Connect a different number to keep sending.",
  },
  replaced: {
    title: "Opened somewhere else",
    body: "This number was linked again on another device or panel, so it was disconnected here. Connect again to use it in this panel.",
  },
};

function StatusCard({
  connection,
  fetchedAt,
  refreshing,
  onRefresh,
}: {
  connection: WaConnection;
  fetchedAt?: number;
  refreshing?: boolean;
  onRefresh: () => void;
}) {
  const [link, linkState] = useLinkWaConnectionMutation();
  const [unlink, unlinkState] = useUnlinkWaConnectionMutation();
  const [error, setError] = useState<string | null>(null);
  const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);
  const [disconnectError, setDisconnectError] = useState<string | null>(null);

  const status = connectionStatus(connection.status);

  const handleConnect = async () => {
    setError(null);
    try {
      await link().unwrap();
    } catch (err) {
      setError(waErrorMessage(err, "Could not start linking. Try again."));
    }
  };

  const handleCancelLink = async () => {
    setError(null);
    try {
      await unlink().unwrap();
    } catch (err) {
      setError(waErrorMessage(err, "Could not cancel. Try again."));
    }
  };

  const handleDisconnect = async () => {
    setDisconnectError(null);
    try {
      await unlink().unwrap();
      setConfirmingDisconnect(false);
    } catch (err) {
      setDisconnectError(waErrorMessage(err, "Could not disconnect. Try again."));
    }
  };

  return (
    <Card>
      <div className="mb-4 flex min-w-0 items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-2">
          <h2 className="truncate text-[13px] font-semibold tracking-wide text-muted uppercase">Connection</h2>
          <WaPill tone={status.tone}>{status.label}</WaPill>
        </div>
        <Button size="sm" variant="ghost" onClick={onRefresh} disabled={refreshing} aria-label="Refresh status">
          <IconRefresh size={16} />
          <span className="hidden sm:inline">Refresh</span>
        </Button>
      </div>

      {connection.status === "connected" ? (
        <ConnectedDetails connection={connection} onDisconnect={() => setConfirmingDisconnect(true)} />
      ) : isLinking(connection) ? (
        <QrPanel
          connection={connection}
          fetchedAt={fetchedAt}
          onCancel={() => void handleCancelLink()}
          cancelling={unlinkState.isLoading}
        />
      ) : (
        <div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="min-w-0">
            <p className="text-sm font-semibold text-text">
              {(IDLE_COPY[connection.status] ?? IDLE_COPY.not_linked).title}
            </p>
            <p className="mt-1 max-w-xl text-xs text-muted">
              {(IDLE_COPY[connection.status] ?? IDLE_COPY.not_linked).body}
            </p>
          </div>
          <Button onClick={() => void handleConnect()} loading={linkState.isLoading}>
            <IconLink size={16} />
            {connection.status === "not_linked" ? "Connect WhatsApp" : "Connect again"}
          </Button>
        </div>
      )}

      {error ? (
        <div className="mt-3">
          <ErrorNote>{error}</ErrorNote>
        </div>
      ) : null}

      <ConfirmDialog
        open={confirmingDisconnect}
        title="Disconnect WhatsApp?"
        confirmLabel="Disconnect"
        tone="danger"
        loading={unlinkState.isLoading}
        error={disconnectError}
        onConfirm={() => void handleDisconnect()}
        onCancel={() => {
          setConfirmingDisconnect(false);
          setDisconnectError(null);
        }}
      >
        <p>
          Messages stop until someone scans a new QR. Events, templates and history are kept.
        </p>
      </ConfirmDialog>
    </Card>
  );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-[10.5px] tracking-wide text-faint uppercase">{label}</dt>
      <dd className="mt-0.5 min-w-0 truncate text-sm font-medium text-text">{children}</dd>
    </div>
  );
}

function ConnectedDetails({ connection, onDisconnect }: { connection: WaConnection; onDisconnect: () => void }) {
  const usage = useGetWaUsageQuery();
  const today = connection.today ?? { sent: 0, cap: null };
  const cap = today.cap ?? 0;
  const capPct = cap > 0 ? Math.min(100, (today.sent / cap) * 100) : 0;

  return (
    <div className="space-y-4">
      {isReconnecting(connection) ? (
        <WaNotice tone="info" icon={<IconRefresh size={14} />}>
          Reconnecting to WhatsApp… messages wait and go out once it is back.
        </WaNotice>
      ) : null}

      <dl className="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
        <Detail label="Number">
          <span className="tabular">{maskNumber(connection.me?.number)}</span>
        </Detail>
        <Detail label="WhatsApp name">{connection.me?.name || "—"}</Detail>
        <Detail label="Phone">{platformLabel(connection.me?.platform)}</Detail>
        <Detail label="Sent today">
          <span className="tabular">
            {count(today.sent)}{" "}
            {today.cap !== null ? <span className="text-faint">/ {count(today.cap)}</span> : null}
          </span>
          <span className="mt-1.5 block h-1.5 w-full max-w-40 overflow-hidden rounded-full bg-surface-2">
            <span
              className="block h-full rounded-full"
              style={{
                width: `${capPct}%`,
                background: capPct >= 90 ? "var(--warn)" : "var(--accent)",
              }}
            />
          </span>
        </Detail>
      </dl>

      <div className="flex min-w-0 flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="min-w-0 text-xs text-faint">
          {[
            connection.linked_at ? `Linked ${formatDateTime(connection.linked_at)}` : null,
            usage.data ? `This month ${count(usage.data.month?.sent)} sent · ${coinsLabel(usage.data.month?.coins)}` : null,
          ]
            .filter(Boolean)
            .join(" · ") || "Daily limit protects the number from being flagged."}
        </p>
        <Button size="sm" variant="danger" onClick={onDisconnect}>
          Disconnect
        </Button>
      </div>
    </div>
  );
}

const useSecondsLeft = (seconds: number, from?: number) => {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const deadline = (from ?? now) + Math.max(0, seconds) * 1000;
  return Math.max(0, Math.ceil((deadline - now) / 1000));
};

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;

function QrPanel({
  connection,
  fetchedAt,
  onCancel,
  cancelling,
}: {
  connection: WaConnection;
  fetchedAt?: number;
  onCancel: () => void;
  cancelling?: boolean;
}) {
  const secondsLeft = useSecondsLeft(connection.qr_expires_in ?? 0, fetchedAt);
  const ready = connection.status === "qr" && Boolean(connection.qr);
  const refreshing = ready && secondsLeft === 0;

  return (
    <div className="grid min-w-0 gap-5 md:grid-cols-[auto_minmax(0,1fr)] md:items-center">
      <div className="mx-auto w-full max-w-[17rem]">
        {/* QR codes need a light quiet zone to scan, whatever the skin. */}
        <div className="relative aspect-square w-full overflow-hidden rounded-lg border border-border bg-white p-3">
          {ready ? (
            <img
              src={connection.qr ?? undefined}
              alt="WhatsApp link QR code"
              className={["size-full transition-opacity", refreshing ? "opacity-25" : ""].join(" ")}
              style={{ imageRendering: "pixelated" }}
            />
          ) : (
            <div className="size-full animate-pulse rounded-md bg-neutral-200" />
          )}
          {!ready || refreshing ? (
            <div className="absolute inset-0 grid place-items-center">
              <span className="inline-flex items-center gap-2 rounded-full bg-black/70 px-3 py-1.5 text-xs font-medium text-white">
                <span
                  aria-hidden
                  className="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
                />
                {ready ? "Getting a new code…" : "Preparing QR…"}
              </span>
            </div>
          ) : null}
        </div>
        <p className="tabular mt-2 text-center text-xs text-muted" aria-live="polite">
          {ready && !refreshing ? `Code changes in ${clock(secondsLeft)}` : " "}
        </p>
      </div>

      <div className="min-w-0">
        <p className="text-sm font-semibold text-text">Scan with the branch phone</p>
        <ol className="mt-3 space-y-2.5 text-sm text-muted">
          {[
            "Open WhatsApp on the phone with the branch number.",
            "Tap Menu ⋮ (or Settings on iPhone), then Linked devices.",
            "Tap Link a device.",
            "Point the phone at this QR code.",
          ].map((step, index) => (
            <li key={step} className="flex min-w-0 items-start gap-3">
              <span className="tabular grid size-6 shrink-0 place-items-center rounded-full bg-accent-soft text-xs font-semibold text-accent">
                {index + 1}
              </span>
              <span className="min-w-0 pt-0.5">{step}</span>
            </li>
          ))}
        </ol>
        <p className="mt-3 text-xs text-faint">
          On the branch phone itself? Open this page on another screen — the phone has to scan it. Keep this page open
          until it says Connected.
        </p>
        <div className="mt-4">
          <Button size="sm" variant="ghost" onClick={onCancel} loading={cancelling}>
            Cancel
          </Button>
        </div>
      </div>
    </div>
  );
}

// ── Header / footer ─────────────────────────────────────────────────────────

function HeaderFooterCard({ connection }: { connection: WaConnection }) {
  const [header, setHeader] = useState(connection.header ?? "");
  const [footer, setFooter] = useState(connection.footer ?? "");
  const [save, { isLoading }] = useUpdateWaConnectionMutation();
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const dirty = header !== (connection.header ?? "") || footer !== (connection.footer ?? "");

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setSaved(false);
    try {
      await save({ header: header.trim(), footer: footer.trim() }).unwrap();
      setSaved(true);
    } catch (err) {
      setError(waErrorMessage(err, "Could not save."));
    }
  };

  return (
    <Card>
      <CardTitle>Header and footer</CardTitle>
      <form className="space-y-3" onSubmit={handleSubmit}>
        <Textarea
          label="Header"
          rows={2}
          maxLength={300}
          placeholder="e.g. SouthIND · Official"
          value={header}
          onChange={(event) => {
            setHeader(event.target.value);
            setSaved(false);
          }}
          hint="Added above every message. Leave blank for none."
        />
        <Textarea
          label="Footer"
          rows={2}
          maxLength={300}
          placeholder="e.g. Need help? Reply here."
          value={footer}
          onChange={(event) => {
            setFooter(event.target.value);
            setSaved(false);
          }}
          hint="Added below every message."
        />
        {error ? <ErrorNote>{error}</ErrorNote> : null}
        {saved ? <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">Saved.</p> : null}
        <Button type="submit" size="sm" loading={isLoading} disabled={!dirty}>
          Save
        </Button>
      </form>
    </Card>
  );
}

// ── Test message ────────────────────────────────────────────────────────────

function TestMessageCard({ connected }: { connected: boolean }) {
  const rate = useWaCoinRate();
  const [text, setText] = useState("");
  const [to, setTo] = useState("");
  const [sendTest, { isLoading }] = useSendWaTestMutation();
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setNotice(null);
    const number = to.replace(/\D/g, "");
    try {
      const { message } = await sendTest({ text: text.trim(), ...(number ? { to: number } : {}) }).unwrap();
      if (message?.status === "skipped") {
        setError(`Not sent: ${skipReasonLabel(message.skip_reason)}.`);
      } else {
        setNotice("Test message queued. Its ticks show up in History.");
        setText("");
      }
    } catch (err) {
      setError(waErrorMessage(err, "Could not send the test message."));
    }
  };

  return (
    <Card>
      <CardTitle hint={coinsLabel(rate)}>Send test message</CardTitle>
      <form className="space-y-3" onSubmit={handleSubmit}>
        <Textarea
          label="Message"
          rows={3}
          maxLength={1000}
          placeholder="Hello! This is a test from the branch."
          value={text}
          onChange={(event) => setText(event.target.value)}
        />
        <Input
          label="Number (optional)"
          inputMode="tel"
          placeholder="Your own number"
          value={to}
          onChange={(event) => setTo(event.target.value.replace(/[^\d+\s]/g, "").slice(0, 16))}
          hint="Blank sends it to your own phone."
        />
        {!connected ? (
          <WaNotice tone="neutral" icon={<IconAlert size={14} />}>
            Connect WhatsApp first.
          </WaNotice>
        ) : null}
        {error ? <ErrorNote>{error}</ErrorNote> : null}
        {notice ? <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p> : null}
        <Button type="submit" size="sm" loading={isLoading} disabled={!connected || !text.trim()}>
          <IconSend size={16} />
          Send test
        </Button>
      </form>
    </Card>
  );
}
