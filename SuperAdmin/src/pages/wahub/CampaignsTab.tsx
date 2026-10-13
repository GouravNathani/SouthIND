import { useEffect, useMemo, useState } from "react";
import type { WaBranchConnection, WaCampaign } from "@/types/api";
import Pagination from "@/components/Pagination";
import { IconClock } from "@/components/icons";
import BranchSelect from "@/components/wahub/BranchSelect";
import CampaignList from "@/components/wahub/CampaignList";
import { WaNotice } from "@/components/wahub/WaPill";
import WaErrorState from "@/components/wahub/WaErrorState";
import { CAMPAIGN_RULES_HINT, toPaginationMeta } from "@/components/wahub/waFormat";
import { usePageVisible } from "@/hooks/usePageVisible";
import { useGetWaCampaignsQuery } from "@/services/api";

const PER_PAGE = 10;
const POLL_MS = 15000;

/** Campaigns branch admins sent or scheduled. Read-only here: branches run their own. */
export default function CampaignsTab({
  branchId,
  connections,
}: {
  branchId: number | null;
  connections?: WaBranchConnection[];
}) {
  const [page, setPage] = useState(1);
  const visible = usePageVisible();
  const [active, setActive] = useState(false);

  useEffect(() => setPage(1), [branchId]);

  const campaigns = useGetWaCampaignsQuery(
    { branch_id: branchId ?? undefined, page, per_page: PER_PAGE },
    { pollingInterval: active && visible ? POLL_MS : 0, refetchOnMountOrArgChange: true }
  );
  const rows = useMemo(() => campaigns.data?.data ?? [], [campaigns.data]);

  // Refresh progress only while something is still going out.
  useEffect(
    () => setActive(rows.some((campaign) => campaign.status === "running" || campaign.status === "scheduled")),
    [rows]
  );

  // Rows carry branch_name from the backend; the connection key is the fallback.
  const branchNames = useMemo(() => {
    const map = new Map<string, string>();
    connections?.forEach((row) => map.set(row.key, row.branch_name));
    return map;
  }, [connections]);
  const branchNameOf = (campaign: WaCampaign) => campaign.branch_name ?? branchNames.get(campaign.connection) ?? null;

  return (
    <div className="space-y-4">
      <BranchSelect className="sm:max-w-xs" />

      <WaNotice tone="neutral" icon={<IconClock size={14} />}>
        {CAMPAIGN_RULES_HINT}
      </WaNotice>

      {campaigns.error && !campaigns.data ? (
        <WaErrorState
          error={campaigns.error}
          onRetry={() => void campaigns.refetch()}
          retrying={campaigns.isFetching}
          fallback="Could not load campaigns."
        />
      ) : (
        <>
          <CampaignList
            rows={rows}
            loading={campaigns.isFetching && !campaigns.data}
            branchNameOf={branchNameOf}
            emptyTitle={branchId !== null ? "No campaigns for this branch" : "No campaigns yet"}
            emptyBody="Campaigns that branch admins send or schedule show up here with their progress."
          />
          <Pagination meta={toPaginationMeta(campaigns.data?.meta)} page={page} onPage={setPage} />
        </>
      )}
    </div>
  );
}
