import SuperShell from "@/components/SuperShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Badge from "@/components/ui/Badge";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconAlert } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetWalletDailyQuery, useGetWalletQuery } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";

const coins = (value?: number | null) =>
  value == null ? "—" : `${value.toLocaleString("en-IN", { maximumFractionDigits: 2 })} coins`;

/** Where the rates came from — a stale or stood-in wallet bills differently. */
const SOURCE_COPY: Record<string, { tone: "pos" | "warn" | "neg"; label: string; note: string }> = {
  live: { tone: "pos", label: "Live", note: "Rates are current from Control." },
  stale: {
    tone: "warn",
    label: "Stale",
    note: "Control did not answer recently — the last known rates are being used.",
  },
  self: {
    tone: "neg",
    label: "Standing in",
    note: "No wallet is connected. Usage is booked locally and owed once one is.",
  },
};

export default function WalletPage() {
  const { data, isLoading, error } = useGetWalletQuery();
  const daily = useGetWalletDailyQuery({ month: new Date().toISOString().slice(0, 7) });
  useSessionGuard(error, daily.error);

  if (isLoading) {
    return (
      <SuperShell title="Messaging wallet">
        <Skeleton className="h-96 w-full" />
      </SuperShell>
    );
  }

  if (error || !data) {
    return (
      <SuperShell title="Messaging wallet">
        <Card>
          {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load the wallet.")}</ErrorNote> : null}
          <EmptyState
            title="Wallet unavailable"
            body="Control did not answer. Outbound messaging keeps running on the last known rates."
          />
        </Card>
      </SuperShell>
    );
  }

  const wallet = data.wallet;
  const source = SOURCE_COPY[data.rate_source] ?? SOURCE_COPY.self;
  const owedMonths = Object.entries(data.owed_by_month ?? {});

  return (
    <SuperShell title="Messaging wallet" subtitle={wallet?.name ?? undefined}>
      <div className="space-y-4">
        {wallet?.is_expired ? (
          <ErrorNote>
            This wallet expired{wallet.expires_at ? ` on ${formatDateTime(wallet.expires_at)}` : ""}.
            Outbound WhatsApp and push are paused for every branch until it is renewed.
          </ErrorNote>
        ) : null}

        <Card>
          <CardTitle hint={<Badge tone={source.tone}>{source.label}</Badge>}>Balance</CardTitle>
          <p className="tabular text-3xl font-semibold text-accent">
            {/* A stood-in wallet has no Control balance — the local ledger is the
                only number that means anything, and it runs negative. */}
            {data.is_self ? coins(data.local_balance) : coins(wallet?.balance)}
          </p>
          <p className="mt-1 text-xs text-muted">{source.note}</p>

          <dl className="mt-4 grid grid-cols-2 gap-4 border-t border-border pt-3 sm:grid-cols-4">
            <Stat label="Owed" value={coins(data.owed_coins)} tone={data.owed_coins > 0 ? "neg" : undefined} />
            <Stat label="Booked today" value={coins(data.pending_today_coins)} />
            <Stat label="Status" value={wallet?.status ?? (data.is_self ? "standing in" : "—")} />
            <Stat
              label="Expires"
              value={wallet?.expires_at ? formatDateTime(wallet.expires_at) : "—"}
            />
          </dl>

          {owedMonths.length ? (
            <div
              className="mt-3 flex min-w-0 items-start gap-2 rounded-md px-3 py-2 text-xs"
              style={{ color: "var(--warn)", background: "color-mix(in srgb, var(--warn) 14%, transparent)" }}
            >
              <IconAlert size={14} />
              <span className="min-w-0">
                Unsettled months:{" "}
                {owedMonths.map(([month, amount]) => `${month} (${coins(amount)})`).join(", ")}
              </span>
            </div>
          ) : null}
        </Card>

        <Card>
          <CardTitle hint={data.usage?.month}>This month's usage</CardTitle>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <Usage label="Support in" entry={data.usage?.support_in} />
            <Usage label="Support out" entry={data.usage?.support_out} />
            <Usage label="Media in" entry={data.usage?.media_in} />
            <Usage label="Media out" entry={data.usage?.media_out} />
            <Usage label="WhatsApp in" entry={data.usage?.whatsapp_in} />
            <Usage label="WhatsApp out" entry={data.usage?.whatsapp_out} />
          </div>
        </Card>

        <Card>
          <CardTitle hint={`${daily.data?.data?.length ?? 0} days`}>Nightly billing</CardTitle>
          {daily.isLoading ? (
            <Skeleton className="h-32 w-full" />
          ) : daily.data?.data?.length ? (
            <ul className="min-w-0">
              {daily.data.data.slice(0, 14).map((row) => (
                <li
                  key={row.date}
                  className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
                >
                  <div className="min-w-0 flex-1">
                    <p className="tabular truncate text-sm font-medium text-text">{row.date}</p>
                    <p className="truncate text-xs text-faint">
                      {row.messages_count} messages · {coins(row.messages_coins)}
                      {row.unbilled_count ? ` · ${row.unbilled_count} unbilled` : ""}
                    </p>
                  </div>
                  {row.payout_status ? (
                    <Badge tone={row.payout_status === "settled" ? "pos" : "warn"}>
                      {row.payout_status}
                    </Badge>
                  ) : (
                    <Badge tone="neutral">not billed yet</Badge>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState title="Nothing billed yet" body="Usage appears here after the first nightly run." />
          )}
        </Card>
      </div>
    </SuperShell>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: "neg" }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-xs text-muted">{label}</p>
      <p
        className="tabular truncate text-sm font-semibold"
        style={tone === "neg" ? { color: "var(--neg)" } : undefined}
      >
        {value}
      </p>
    </div>
  );
}

function Usage({ label, entry }: { label: string; entry?: { count: number; coins: number } }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-xs text-muted">{label}</p>
      <p className="tabular truncate text-sm font-semibold">{entry?.count ?? 0}</p>
      <p className="tabular truncate text-xs text-faint">{coins(entry?.coins)}</p>
    </div>
  );
}
