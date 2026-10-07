import { useMemo, useState } from "react";
import type { WaBranchConnection, WaUsage, WaUsageRow } from "@/types/api";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import DataTable, { type Column } from "@/components/ui/DataTable";
import Switch from "@/components/ui/Switch";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconClock } from "@/components/icons";
import WaPill from "@/components/wahub/WaPill";
import WaErrorState from "@/components/wahub/WaErrorState";
import {
  connectionStatus,
  count,
  isReconnecting,
  maskNumber,
  waErrorMessage,
} from "@/components/wahub/waFormat";
import { useSetWaConnectionEnabledMutation } from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";
import { relativeTime } from "@/utils/format";

/**
 * One row per branch: its linked number, today's sends against the daily cap,
 * and the switch that pauses everything the branch sends.
 */
export default function BranchesTab({
  rows,
  usage,
  loading,
  error,
  retrying,
  onRetry,
  onOpenHistory,
}: {
  rows: WaBranchConnection[];
  usage?: WaUsage;
  loading: boolean;
  error: unknown;
  retrying: boolean;
  onRetry: () => void;
  onOpenHistory: (row: WaBranchConnection) => void;
}) {
  const [setEnabled] = useSetWaConnectionEnabledMutation();
  const [busyBranch, setBusyBranch] = useState<number | null>(null);
  const [toggleError, setToggleError] = useState<string | null>(null);
  const [pausing, setPausing] = useState<WaBranchConnection | null>(null);
  const [pauseError, setPauseError] = useState<string | null>(null);

  const usageByKey = useMemo(() => {
    const map = new Map<string, WaUsageRow>();
    usage?.by_connection.forEach((row) => map.set(row.connection, row));
    return map;
  }, [usage]);

  const apply = async (row: WaBranchConnection, enabled: boolean): Promise<boolean> => {
    setBusyBranch(row.branch_id);
    try {
      await setEnabled({ branchId: row.branch_id, enabled }).unwrap();
      return true;
    } catch (err) {
      const message = waErrorMessage(err, `Could not switch ${row.branch_name}.`);
      if (enabled) setToggleError(message);
      else setPauseError(message);
      return false;
    } finally {
      setBusyBranch(null);
    }
  };

  const handleToggle = (row: WaBranchConnection, next: boolean) => {
    setToggleError(null);
    if (next) {
      void apply(row, true);
      return;
    }
    // Pausing stops a live branch's messages, so it asks first.
    setPauseError(null);
    setPausing(row);
  };

  const handlePause = async () => {
    if (!pausing) return;
    if (await apply(pausing, false)) setPausing(null);
  };

  if (error) {
    return (
      <WaErrorState error={error} onRetry={onRetry} retrying={retrying} fallback="Could not load branch numbers." />
    );
  }

  const columns: Column<WaBranchConnection>[] = [
    {
      key: "branch",
      header: "Branch",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-semibold text-text">{row.branch_name}</span>
          {row.me?.name ? <span className="block truncate text-xs font-normal text-faint">{row.me.name}</span> : null}
        </span>
      ),
    },
    {
      key: "number",
      header: "WhatsApp",
      render: (row) => {
        const status = connectionStatus(row.status);
        return (
          <span className="block min-w-0">
            <span className="flex min-w-0 flex-wrap items-center gap-1.5">
              <WaPill tone={status.tone}>{status.label}</WaPill>
              {isReconnecting(row) ? <span className="text-xs text-faint">Reconnecting</span> : null}
            </span>
            {row.me?.number ? (
              <span className="tabular mt-1 block text-sm whitespace-nowrap text-text">{maskNumber(row.me.number)}</span>
            ) : null}
          </span>
        );
      },
    },
    {
      key: "today",
      header: "Today",
      render: (row) => (
        <span className="block min-w-0">
          <span className="tabular block text-sm whitespace-nowrap text-text">
            {count(row.today?.sent)}
            {row.today?.cap !== null && row.today?.cap !== undefined ? (
              <span className="text-faint"> / {count(row.today.cap)}</span>
            ) : null}
          </span>
          {row.last_sent_at ? (
            <span className="block truncate text-xs text-faint" title={formatDateTime(row.last_sent_at)}>
              Last {relativeTime(row.last_sent_at)}
            </span>
          ) : null}
        </span>
      ),
    },
    {
      key: "month",
      header: "This month",
      secondary: true,
      render: (row) => {
        const month = usageByKey.get(row.key);
        if (!month) return <span className="text-sm text-faint">—</span>;
        return (
          <span className="block min-w-0 whitespace-nowrap">
            <span className="tabular block text-sm text-text">{count(month.month_sent)} sent</span>
            <span className="tabular block text-xs text-faint">{count(month.month_coins)} coins</span>
          </span>
        );
      },
    },
    {
      key: "enabled",
      header: "Enabled",
      render: (row) => (
        <Switch
          checked={row.enabled !== false}
          onChange={(next) => handleToggle(row, next)}
          disabled={busyBranch === row.branch_id}
          label={`WhatsApp for ${row.branch_name}`}
          showState
        />
      ),
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <Button size="sm" variant="ghost" onClick={() => onOpenHistory(row)} aria-label={`History of ${row.branch_name}`}>
          <IconClock size={16} />
          {/* Icon only on a narrow desktop, where the table is tightest. */}
          <span className="min-w-0 truncate lg:hidden xl:inline">History</span>
        </Button>
      ),
    },
  ];

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted">
        Each branch admin links their own number from the WhatsApp page of the branch panel. Switching a branch off
        stops everything it sends.
      </p>

      {toggleError ? <ErrorNote>{toggleError}</ErrorNote> : null}

      <DataTable
        columns={columns}
        rows={rows}
        keyOf={(row) => row.branch_id}
        loading={loading}
        emptyTitle="No branches yet"
        emptyBody="Branches appear here as soon as they are created."
      />

      <ConfirmDialog
        open={pausing !== null}
        title={`Pause WhatsApp for ${pausing?.branch_name ?? "this branch"}?`}
        confirmLabel="Pause"
        tone="danger"
        loading={pausing !== null && busyBranch === pausing.branch_id}
        error={pauseError}
        onConfirm={() => void handlePause()}
        onCancel={() => {
          setPausing(null);
          setPauseError(null);
        }}
      >
        <p>
          Nothing is sent from this branch&apos;s number — events, campaigns or tests — until you switch it back on.
          The number stays linked.
        </p>
      </ConfirmDialog>
    </div>
  );
}
