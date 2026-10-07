import { useState } from "react";
import type { WaCampaign } from "@/types/api";
import Button from "@/components/ui/Button";
import { EmptyState, Skeleton } from "@/components/ui/Feedback";
import WaPill from "@/components/wahub/WaPill";
import { campaignProgress, campaignStatus, count } from "@/components/wahub/waFormat";
import { formatDateTime } from "@/utils/dateTime";

/** Campaign cards with a progress bar; cancellable ones get a button when allowed. */
export default function CampaignList({
  rows,
  loading,
  branchNameOf,
  onCancel,
  emptyTitle = "No campaigns yet",
  emptyBody,
}: {
  rows: WaCampaign[];
  loading?: boolean;
  /** Super admin: which branch a campaign belongs to. */
  branchNameOf?: (campaign: WaCampaign) => string | null | undefined;
  onCancel?: (campaign: WaCampaign) => void;
  emptyTitle?: string;
  emptyBody?: string;
}) {
  const [openId, setOpenId] = useState<number | null>(null);

  if (loading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 3 }, (_, i) => (
          <Skeleton key={i} className="h-28 w-full" />
        ))}
      </div>
    );
  }

  if (!rows.length) {
    return (
      <div className="rounded-lg border border-border bg-surface">
        <EmptyState title={emptyTitle} body={emptyBody} />
      </div>
    );
  }

  return (
    <ul className="space-y-2">
      {rows.map((campaign) => {
        const status = campaignStatus(campaign.status);
        const progress = campaignProgress(campaign.counts);
        const counts = campaign.counts;
        const branch = branchNameOf?.(campaign);
        const cancellable = campaign.status === "scheduled" || campaign.status === "running";
        const open = openId === campaign.id;
        const when =
          campaign.status === "scheduled"
            ? `Starts ${formatDateTime(campaign.send_at)}`
            : campaign.finished_at
              ? `Finished ${formatDateTime(campaign.finished_at)}`
              : `Started ${formatDateTime(campaign.started_at ?? campaign.created_at)}`;

        return (
          <li key={campaign.id} className="min-w-0 rounded-lg border border-border bg-surface p-3.5 sm:p-4">
            <div className="flex min-w-0 items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-text">{campaign.title || "Untitled"}</p>
                <p className="truncate text-xs text-faint">
                  {[branch, campaign.audience_label, campaign.actor?.name ? `by ${campaign.actor.name}` : null]
                    .filter(Boolean)
                    .join(" · ") || "—"}
                </p>
              </div>
              <WaPill tone={status.tone}>{status.label}</WaPill>
            </div>

            <div
              className="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-surface-2"
              role="progressbar"
              aria-label="Progress"
              aria-valuemin={0}
              aria-valuemax={progress.total}
              aria-valuenow={progress.done}
            >
              <span className="h-full bg-accent" style={{ width: `${progress.reachedPct}%` }} />
              <span
                className="h-full"
                style={{ width: `${progress.droppedPct}%`, background: "color-mix(in srgb, var(--neg) 55%, transparent)" }}
              />
            </div>

            <div className="mt-2 flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
              <span className="tabular text-muted">
                <span className="font-semibold text-text">{count(progress.done)}</span> / {count(progress.total)} done
              </span>
              <span className="tabular text-faint">
                {count(counts?.sent)} sent · {count(counts?.delivered)} delivered · {count(counts?.read)} read
                {counts?.failed ? ` · ${count(counts.failed)} failed` : ""}
                {counts?.skipped ? ` · ${count(counts.skipped)} skipped` : ""}
              </span>
            </div>

            <div className="mt-3 flex min-w-0 flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
              <span className="min-w-0 truncate text-xs text-faint">{when}</span>
              <div className="flex shrink-0 items-center gap-1">
                {campaign.body ? (
                  <Button size="sm" variant="ghost" aria-expanded={open} onClick={() => setOpenId(open ? null : campaign.id)}>
                    {open ? "Hide text" : "Show text"}
                  </Button>
                ) : null}
                {onCancel && cancellable ? (
                  <Button size="sm" variant="danger" onClick={() => onCancel(campaign)}>
                    Cancel
                  </Button>
                ) : null}
              </div>
            </div>

            {open ? (
              <p className="mt-2 rounded-md bg-surface-2 px-3 py-2 text-sm break-words whitespace-pre-wrap text-text select-text">
                {campaign.body}
              </p>
            ) : null}
          </li>
        );
      })}
    </ul>
  );
}
