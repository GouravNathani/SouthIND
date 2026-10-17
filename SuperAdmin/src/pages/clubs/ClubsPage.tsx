import { useEffect, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Input } from "@/components/ui/Field";
import Segmented from "@/components/ui/Segmented";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconEdit, IconPlus, IconRefresh, IconTrash } from "@/components/icons";
import { WaNotice } from "@/components/wahub/WaPill";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useAddDefaultClubsMutation,
  useCreateClubMutation,
  useDeleteClubMutation,
  useGetAllClubsQuery,
  useRefreshClubsMutation,
  useUpdateClubMutation,
  useUpdateClubWindowMutation,
} from "@/services/api";
import type { Club } from "@/types/api";
import { formatDateTime } from "@/utils/dateTime";
import { resolveErrorMessage } from "@/utils/errors";
import { inr } from "@/utils/format";

const CLUB_COLORS = ["#0ea5e9", "#f59e0b", "#64748b", "#10b981", "#6366f1", "#ec4899", "#ef4444", "#8b5cf6"];

type Scope = "branch" | "all";

type Draft = { id: number | null; branchId: number; name: string; color: string; days: string; scope: Scope };

/**
 * Clubs of every branch (or the branch picked in the header). The system sorts
 * users into them from approved deposit/withdrawal days in the club window,
 * which is set here for all branches.
 */
export default function ClubsPage() {
  const navigate = useNavigate();
  const { branchId, setBranchId } = useBranch();

  const all = useGetAllClubsQuery(undefined, { refetchOnMountOrArgChange: true });
  const [createClub, createState] = useCreateClubMutation();
  const [updateClub, updateState] = useUpdateClubMutation();
  const [deleteClub, deleteState] = useDeleteClubMutation();
  const [addDefaults, defaultsState] = useAddDefaultClubsMutation();
  const [refreshClubs, refreshState] = useRefreshClubsMutation();
  const [updateWindow, windowState] = useUpdateClubWindowMutation();
  useSessionGuard(all.error);

  const windowDays = all.data?.meta.window_days ?? 30;
  const blocks = (all.data?.data ?? []).filter((block) => branchId === null || block.branch_id === branchId);

  const [draft, setDraft] = useState<Draft | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<Club | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [windowInput, setWindowInput] = useState("");

  useEffect(() => setWindowInput(String(windowDays)), [windowDays]);

  const run = async (action: () => Promise<unknown>, done: string, failed: string) => {
    setActionError(null);
    setNotice(null);
    try {
      await action();
      setNotice(done);
    } catch (err) {
      setActionError(resolveErrorMessage(err, failed));
    }
  };

  const handleSave = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!draft) return;
    setFormError(null);
    const days = Number(draft.days);
    if (!draft.name.trim()) return setFormError("Give the club a name.");
    if (!Number.isInteger(days) || days < 1) return setFormError("Days must be a whole number, 1 or more.");

    const body = { name: draft.name.trim(), color: draft.color, min_active_days: days };
    try {
      if (draft.id) {
        await updateClub({ id: draft.id, ...body }).unwrap();
        setNotice("Club saved. Users were sorted again.");
      } else if (draft.scope === "all") {
        const result = await createClub({ ...body, all_branches: true }).unwrap();
        const skipped = result.meta?.skipped_branch_ids?.length ?? 0;
        setNotice(
          `Club added to ${result.data.length} branch${result.data.length === 1 ? "" : "es"}.` +
            (skipped ? ` ${skipped} skipped (same name or days already there).` : ""),
        );
      } else {
        await createClub({ ...body, branch_id: draft.branchId }).unwrap();
        setNotice("Club added.");
      }
      setDraft(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save the club."));
    }
  };

  const handleDelete = async () => {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await deleteClub(deleting.id).unwrap();
      setNotice(`${deleting.name} deleted.`);
      setDeleting(null);
    } catch (err) {
      setDeleteError(resolveErrorMessage(err, "Could not delete the club."));
    }
  };

  const handleWindow = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const days = Number(windowInput);
    if (!Number.isInteger(days) || days < 1 || days > 365) {
      setActionError("Window must be 1 to 365 days.");
      return;
    }
    await run(() => updateWindow(days).unwrap(), `Window set to ${days} days. Everyone was sorted again.`, "Could not save.");
  };

  const form = (block: { branch_id: number }) =>
    draft && draft.branchId === block.branch_id ? (
      <Card>
        <CardTitle hint={`1 to ${windowDays} days`}>{draft.id ? "Edit club" : "New club"}</CardTitle>
        <form className="space-y-4" onSubmit={handleSave}>
          {!draft.id ? (
            <Segmented
              label="Add to"
              value={draft.scope}
              onChange={(scope) => setDraft({ ...draft, scope })}
              options={[
                { value: "branch", label: "This branch" },
                { value: "all", label: "All branches" },
              ]}
            />
          ) : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Name"
              value={draft.name}
              maxLength={60}
              placeholder="e.g. Diamond Club"
              onChange={(event) => setDraft({ ...draft, name: event.target.value })}
            />
            <Input
              label="Active days needed"
              type="number"
              inputMode="numeric"
              min={1}
              max={windowDays}
              value={draft.days}
              placeholder="e.g. 21"
              onChange={(event) => setDraft({ ...draft, days: event.target.value })}
            />
          </div>
          <div>
            <span className="mb-1.5 block text-xs font-medium text-muted">Colour</span>
            <div role="radiogroup" aria-label="Colour" className="flex flex-wrap gap-2">
              {CLUB_COLORS.map((color) => (
                <button
                  key={color}
                  type="button"
                  role="radio"
                  aria-checked={draft.color === color}
                  aria-label={color}
                  onClick={() => setDraft({ ...draft, color })}
                  className={[
                    "h-8 w-8 rounded-full border-2 transition",
                    draft.color === color ? "scale-110 border-text" : "border-surface shadow-sm",
                  ].join(" ")}
                  style={{ backgroundColor: color }}
                />
              ))}
            </div>
          </div>
          {formError ? <ErrorNote>{formError}</ErrorNote> : null}
          <div className="flex flex-wrap gap-2">
            <Button type="submit" loading={createState.isLoading || updateState.isLoading}>
              {draft.id ? "Save club" : "Add club"}
            </Button>
            <Button variant="secondary" onClick={() => setDraft(null)}>
              Cancel
            </Button>
          </div>
        </form>
      </Card>
    ) : null;

  return (
    <SuperShell title="Clubs" subtitle="Users grouped by activity, automatically">
      <div className="space-y-4">
        <WaNotice tone="info">
          The system puts each user in the highest club of their branch they reach: days with an approved deposit or
          withdrawal in the last {windowDays} days. It updates every night and right after each approval. Users below
          the lowest club are in no club.
        </WaNotice>

        {notice ? <WaNotice tone="pos">{notice}</WaNotice> : null}
        {actionError ? <ErrorNote>{actionError}</ErrorNote> : null}

        <Card>
          <CardTitle hint="All branches">Club window</CardTitle>
          <form className="flex flex-wrap items-end gap-3" onSubmit={handleWindow}>
            <div className="w-56">
              <Input
                label="Count activity of the last (days)"
                type="number"
                inputMode="numeric"
                min={1}
                max={365}
                value={windowInput}
                onChange={(event) => setWindowInput(event.target.value)}
              />
            </div>
            <Button type="submit" loading={windowState.isLoading} disabled={windowInput === String(windowDays)}>
              Save window
            </Button>
            <Button
              variant="secondary"
              loading={defaultsState.isLoading}
              onClick={() =>
                void run(
                  () => addDefaults({ all_branches: true }).unwrap(),
                  "Missing default clubs added to every branch.",
                  "Could not add the clubs.",
                )
              }
            >
              <IconPlus size={16} />
              Defaults to all branches
            </Button>
            <Button
              variant="secondary"
              loading={refreshState.isLoading}
              onClick={() => void run(() => refreshClubs({}).unwrap(), "Every branch sorted again.", "Could not sort.")}
            >
              <IconRefresh size={16} />
              Sort all now
            </Button>
          </form>
        </Card>

        {all.isLoading ? (
          <Skeleton className="h-48 w-full" />
        ) : all.error && !all.data ? (
          <ErrorNote>{resolveErrorMessage(all.error, "Could not load clubs.")}</ErrorNote>
        ) : blocks.length === 0 ? (
          <Card padded={false}>
            <EmptyState title="No branches yet" body="Clubs belong to branches. Create a branch first." />
          </Card>
        ) : (
          blocks.map((block) => (
            <section key={block.branch_id} className="space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                <div className="min-w-0">
                  <h2 className="truncate text-sm font-semibold text-text">{block.branch_name}</h2>
                  <p className="text-xs text-faint">
                    {inr(block.meta.members_total)} of {inr(block.meta.users_total)} users in a club ·{" "}
                    {block.meta.refreshed_at ? `sorted ${formatDateTime(block.meta.refreshed_at)}` : "not sorted yet"}
                  </p>
                </div>
                {!draft ? (
                  <Button
                    size="sm"
                    onClick={() =>
                      setDraft({ id: null, branchId: block.branch_id, name: "", color: CLUB_COLORS[4], days: "", scope: "branch" })
                    }
                  >
                    <IconPlus size={16} />
                    New club
                  </Button>
                ) : null}
              </div>

              {form(block)}

              {block.data.length === 0 ? (
                <Card padded={false}>
                  <EmptyState
                    title="No clubs in this branch"
                    body="Add the four default clubs: Diamond (21 days), Gold (14), Silver (7) and Comeback (3)."
                    action={
                      <Button
                        loading={defaultsState.isLoading}
                        onClick={() =>
                          void run(
                            () => addDefaults({ branch_id: block.branch_id }).unwrap(),
                            "Default clubs added.",
                            "Could not add the clubs.",
                          )
                        }
                      >
                        <IconPlus size={16} />
                        Add default clubs
                      </Button>
                    }
                  />
                </Card>
              ) : (
                <ul className="grid gap-3 md:grid-cols-2">
                  {block.data.map((club) => (
                    <li key={club.id}>
                      <Card className="flex h-full flex-col gap-4">
                        <div className="flex min-w-0 items-start justify-between gap-3">
                          <div className="flex min-w-0 items-center gap-3">
                            <span aria-hidden className="h-10 w-10 shrink-0 rounded-lg" style={{ backgroundColor: club.color }} />
                            <div className="min-w-0">
                              <p className="truncate text-sm font-semibold text-text">{club.name}</p>
                              <p className="text-xs text-muted">
                                {club.min_active_days}+ active days in {windowDays}
                              </p>
                            </div>
                          </div>
                          <p className="shrink-0 text-right">
                            <span className="tabular block text-xl font-semibold text-text">{inr(club.members_count)}</span>
                            <span className="text-xs text-faint">users</span>
                          </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                              setBranchId(block.branch_id);
                              navigate(`/users?club=${club.id}`);
                            }}
                          >
                            View users
                          </Button>
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                              setFormError(null);
                              setDraft({
                                id: club.id,
                                branchId: block.branch_id,
                                name: club.name,
                                color: club.color,
                                days: String(club.min_active_days),
                                scope: "branch",
                              });
                            }}
                          >
                            <IconEdit size={16} />
                            Edit
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setDeleting(club)}>
                            <IconTrash size={16} />
                            Delete
                          </Button>
                        </div>
                      </Card>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))
        )}
      </div>

      <ConfirmDialog
        open={deleting !== null}
        title={`Delete ${deleting?.name ?? "club"}?`}
        confirmLabel="Delete"
        tone="danger"
        loading={deleteState.isLoading}
        error={deleteError}
        onConfirm={() => void handleDelete()}
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
      >
        <p>{inr(deleting?.members_count ?? 0)} user(s) move to the next club they reach, or to no club.</p>
      </ConfirmDialog>
    </SuperShell>
  );
}
