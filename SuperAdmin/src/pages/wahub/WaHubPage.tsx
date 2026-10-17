import { useNavigate, useSearchParams } from "react-router-dom";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Segmented, { type SegmentedOption } from "@/components/ui/Segmented";
import { Skeleton } from "@/components/ui/Feedback";
import { IconAlert, IconGlobe2 } from "@/components/icons";
import { WaNotice } from "@/components/wahub/WaPill";
import WaErrorState from "@/components/wahub/WaErrorState";
import { coinRate, coinsLabel, count, rateLabel, waErrorCode, waErrorMessage } from "@/components/wahub/waFormat";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { usePageVisible } from "@/hooks/usePageVisible";
import { useGetWaConnectionsQuery, useGetWaUsageQuery } from "@/services/api";
import type { WaBranchConnection, WaUsage } from "@/types/api";
import BranchesTab from "@/pages/wahub/BranchesTab";
import HistoryTab from "@/pages/wahub/HistoryTab";
import CampaignsTab from "@/pages/wahub/CampaignsTab";

type Tab = "branches" | "history" | "campaigns";

const TABS: readonly SegmentedOption<Tab>[] = [
  { value: "branches", label: "Branches" },
  { value: "history", label: "History" },
  { value: "campaigns", label: "Campaigns" },
];

const USAGE_POLL_MS = 60000;
const CONNECTIONS_POLL_MS = 30000;

const parseTab = (value: string | null): Tab =>
  TABS.some((option) => option.value === value) ? (value as Tab) : "branches";

/**
 * WhatsApp across the network: what every branch sent and spent, each branch's
 * linked number with its on/off switch, and the full history and campaigns.
 * The tab lives in the URL so a reload lands on the same one; the branch filter
 * is the panel-wide branch choice from the header.
 */
export default function WaHubPage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const tab = parseTab(params.get("tab"));
  const setTab = (next: Tab) => setParams(next === "branches" ? {} : { tab: next }, { replace: true });
  const { branchId, setBranchId } = useBranch();
  const visible = usePageVisible();

  const usage = useGetWaUsageQuery(undefined, {
    pollingInterval: visible ? USAGE_POLL_MS : 0,
    refetchOnMountOrArgChange: true,
  });
  const connections = useGetWaConnectionsQuery(undefined, {
    pollingInterval: visible && tab === "branches" ? CONNECTIONS_POLL_MS : 0,
    refetchOnMountOrArgChange: true,
  });
  useSessionGuard(usage.error, connections.error);

  const rows = connections.data ?? [];
  const linked = rows.filter((row) => row.status === "connected").length;
  const hubOff =
    waErrorCode(connections.error) === "wa_hub_disabled" || waErrorCode(usage.error) === "wa_hub_disabled";

  if (hubOff) {
    return (
      <SuperShell title="WhatsApp">
        <WaErrorState
          error={connections.error ?? usage.error}
          offTitle="WhatsApp Hub is turned off"
          offBody="Switch it on in Global settings so branches can link their numbers and send messages."
          offAction={
            <Button size="sm" onClick={() => navigate("/settings")}>
              <IconGlobe2 size={16} />
              Open Global settings
            </Button>
          }
        />
      </SuperShell>
    );
  }

  const subtitle = connections.data
    ? `${linked} of ${rows.length} branch${rows.length === 1 ? "" : "es"} connected`
    : undefined;

  return (
    <SuperShell title="WhatsApp" subtitle={subtitle}>
      <div className="space-y-4">
        <UsageCard
          usage={usage.data}
          loading={usage.isLoading}
          error={usage.error && !usage.data ? usage.error : null}
          connections={connections.data}
        />

        <Segmented value={tab} onChange={setTab} options={TABS} className="sm:max-w-md" />

        {tab === "history" ? (
          <HistoryTab branchId={branchId} />
        ) : tab === "campaigns" ? (
          <CampaignsTab branchId={branchId} connections={connections.data} />
        ) : (
          <BranchesTab
            rows={rows}
            usage={usage.data}
            loading={connections.isLoading}
            error={connections.error && !connections.data ? connections.error : null}
            retrying={connections.isFetching}
            onRetry={() => void connections.refetch()}
            onOpenHistory={(row) => {
              setBranchId(row.branch_id);
              setTab("history");
            }}
          />
        )}
      </div>
    </SuperShell>
  );
}

// ── Usage ───────────────────────────────────────────────────────────────────

function Stat({ label, value, detail }: { label: string; value: string; detail?: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs leading-tight text-muted">{label}</dt>
      <dd className="tabular mt-1 truncate text-xl font-semibold text-text sm:text-2xl">{value}</dd>
      {detail ? <dd className="truncate text-xs text-faint">{detail}</dd> : null}
    </div>
  );
}

function UsageCard({
  usage,
  loading,
  error,
  connections,
}: {
  usage?: WaUsage;
  loading: boolean;
  error: unknown;
  connections?: WaBranchConnection[];
}) {
  if (loading) return <Skeleton className="h-32 w-full" />;

  const total = connections?.length ?? 0;
  const linked = connections?.filter((row) => row.status === "connected").length ?? 0;
  const paused = connections?.filter((row) => row.enabled === false).length ?? 0;

  return (
    <Card>
      <CardTitle hint="All branches · IST">Usage</CardTitle>
      {error ? (
        <WaNotice tone="warn" icon={<IconAlert size={14} />}>
          {waErrorMessage(error, "Could not load usage.")}
        </WaNotice>
      ) : (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4">
          <Stat
            label="Sent today"
            value={count(usage?.today.sent)}
            detail={coinsLabel(usage?.today.coins)}
          />
          <Stat label="Sent this month" value={count(usage?.month.sent)} />
          <Stat
            label="Coins this month"
            value={coinsLabel(usage?.month.coins)}
            detail={rateLabel(coinRate(usage?.coins_per_message))}
          />
          <Stat
            label="Branches connected"
            value={connections ? `${linked} / ${total}` : "—"}
            detail={paused ? `${paused} paused` : undefined}
          />
        </dl>
      )}
    </Card>
  );
}
