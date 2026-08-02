import { useEffect, useMemo, useState } from "react";
import { useGetReferralAgentsQuery } from "@/services/api";
import { Input } from "@/components/ui/Field";
import { IconClose } from "@/components/icons";

const SEARCH_DEBOUNCE_MS = 300;
const MAX_RESULTS = 6;

/**
 * Attaches a new user to an agent's referral tree at creation time. Typeahead
 * rather than a <select>: a branch can have hundreds of agents, and the admin
 * knows the agent by name or play id, not by position in a list.
 */
export default function AgentSelect({
  value,
  branchId,
  onChange,
}: {
  value: number | null;
  /** Agents belong to a branch, so at super scope one has to be chosen first. */
  branchId: number | null;
  onChange: (agentId: number | null, label?: string) => void;
}) {
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [picked, setPicked] = useState<string | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  const { data, isFetching } = useGetReferralAgentsQuery(
    { branch_id: branchId ?? 0, search: debounced || undefined, agent_status: "active", per_page: MAX_RESULTS },
    { skip: debounced.length < 2 || branchId === null }
  );

  const results = useMemo(() => data?.data ?? [], [data]);

  if (value && picked) {
    return (
      <div className="min-w-0">
        <span className="mb-1.5 block text-xs font-medium text-muted">Agent</span>
        <span className="inline-flex max-w-full items-center gap-2 rounded-full border border-accent-line bg-accent-soft py-1.5 pr-1.5 pl-3">
          <span className="min-w-0 truncate text-sm text-accent">{picked}</span>
          <button
            type="button"
            aria-label="Remove agent"
            onClick={() => {
              onChange(null);
              setPicked(null);
              setSearch("");
            }}
            className="grid size-6 shrink-0 place-items-center rounded-full text-accent"
          >
            <IconClose size={14} />
          </button>
        </span>
      </div>
    );
  }

  return (
    <div className="min-w-0">
      <Input
        label="Agent (optional)"
        placeholder="Search agent by name or play ID"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        disabled={branchId === null}
        hint={
          branchId === null
            ? "Pick a branch first — agents belong to one."
            : debounced.length >= 2 && !isFetching && !results.length
              ? "No active agent matches that."
              : "Leave blank if this user has no referrer."
        }
      />

      {results.length ? (
        <ul className="mt-2 max-h-56 min-w-0 overflow-y-auto rounded-md border border-border bg-surface-2">
          {results.map((agent) => (
            <li key={agent.id} className="min-w-0 border-b border-border last:border-0">
              <button
                type="button"
                onClick={() => {
                  const label = `${agent.name}${agent.play_id ? ` · ${agent.play_id}` : ""}`;
                  onChange(agent.id, label);
                  setPicked(label);
                }}
                className="w-full min-w-0 px-3 py-2 text-left"
              >
                <span className="block truncate text-sm text-text">{agent.name}</span>
                <span className="block truncate text-xs text-faint">
                  {agent.play_id ?? agent.phone ?? ""}
                  {agent.referral_code ? ` · ${agent.referral_code}` : ""}
                </span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
