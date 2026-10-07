import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import Segmented, { type SegmentedOption } from "@/components/ui/Segmented";
import { Skeleton } from "@/components/ui/Feedback";
import { IconAlert } from "@/components/icons";
import { WaNotice } from "@/components/wahub/WaPill";
import WaErrorState from "@/components/wahub/WaErrorState";
import { connectionStatus, isLinking, maskNumber } from "@/components/wahub/waFormat";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { usePageVisible } from "@/hooks/usePageVisible";
import { useGetWaConnectionQuery } from "@/services/api";
import ConnectTab from "@/pages/wahub/ConnectTab";
import EventsTab from "@/pages/wahub/EventsTab";
import SendTab from "@/pages/wahub/SendTab";
import TemplatesTab from "@/pages/wahub/TemplatesTab";
import HistoryTab from "@/pages/wahub/HistoryTab";

type Tab = "connect" | "events" | "send" | "templates" | "history";

const TABS: readonly SegmentedOption<Tab>[] = [
  { value: "connect", label: "Connect" },
  { value: "events", label: "Events" },
  { value: "send", label: "Send" },
  { value: "templates", label: "Templates" },
  { value: "history", label: "History" },
];

const QR_POLL_MS = 2000;

const parseTab = (value: string | null): Tab =>
  TABS.some((option) => option.value === value) ? (value as Tab) : "connect";

/**
 * The branch's own WhatsApp: link the number by QR, choose which events message
 * users, send campaigns, and read what went out. The tab lives in the URL so a
 * reload (or a shared link) lands on the same one.
 */
export default function WaHubPage() {
  const [params, setParams] = useSearchParams();
  const tab = parseTab(params.get("tab"));
  const setTab = (next: Tab) => setParams(next === "connect" ? {} : { tab: next }, { replace: true });

  // While a QR is up the hub moves fast (new code, scanned, connected): poll every
  // 2 s then, and not at all otherwise. Paused while the browser tab is hidden.
  const visible = usePageVisible();
  const [linking, setLinking] = useState(false);
  const connection = useGetWaConnectionQuery(undefined, {
    pollingInterval: linking && visible ? QR_POLL_MS : 0,
    refetchOnMountOrArgChange: true,
  });
  useSessionGuard(connection.error);

  const data = connection.data;
  useEffect(() => setLinking(isLinking(data)), [data]);

  const subtitle = data
    ? data.status === "connected" && data.me?.number
      ? `Connected · ${maskNumber(data.me.number)}`
      : connectionStatus(data.status).label
    : undefined;

  const body = () => {
    if (connection.isLoading) {
      return (
        <div className="space-y-3">
          <Skeleton className="h-48 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      );
    }
    if (connection.error && !data) {
      return (
        <WaErrorState
          error={connection.error}
          onRetry={() => void connection.refetch()}
          retrying={connection.isFetching}
        />
      );
    }
    if (!data) return null;

    switch (tab) {
      case "events":
        return <EventsTab />;
      case "send":
        return <SendTab connection={data} onOpenTemplates={() => setTab("templates")} />;
      case "templates":
        return <TemplatesTab />;
      case "history":
        return <HistoryTab />;
      default:
        return (
          <ConnectTab
            connection={data}
            fetchedAt={connection.fulfilledTimeStamp}
            refreshing={connection.isFetching}
            onRefresh={() => void connection.refetch()}
          />
        );
    }
  };

  return (
    <AdminShell title="WhatsApp" subtitle={subtitle}>
      <div className="space-y-4">
        {/* Five tabs need ~21rem; on the narrowest phones the row scrolls rather than truncating. */}
        <div className="-mx-4 overflow-x-auto px-4">
          <Segmented value={tab} onChange={setTab} options={TABS} className="min-w-[21rem]" />
        </div>

        {data && data.enabled === false ? (
          <WaNotice tone="warn" icon={<IconAlert size={14} />}>
            Super Admin has paused WhatsApp for this branch. Nothing is sent until it is switched back on.
          </WaNotice>
        ) : null}

        {body()}
      </div>
    </AdminShell>
  );
}
