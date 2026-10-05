import { useMemo, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import CopyRow from "@/components/CopyRow";
import QuickNotes from "@/components/QuickNotes";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import { Textarea } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconBack } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetDepositsQuery, useUpdateDepositStatusMutation } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime, formatDuration } from "@/utils/dateTime";
import { resolveUploadUrl } from "@/utils/receipt";
import { money } from "@/utils/format";

export default function DepositUpdatePage() {
  const navigate = useNavigate();
  const { id } = useParams();
  const depositId = Number(id);

  const { data: deposits = [], isLoading, error } = useGetDepositsQuery();
  const [updateStatus, { isLoading: isSaving }] = useUpdateDepositStatusMutation();
  useSessionGuard(error);

  const [notes, setNotes] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  const deposit = useMemo(
    () => deposits.find((record) => record.id === depositId) ?? null,
    [deposits, depositId]
  );

  const receiptUrl = resolveUploadUrl(deposit?.receipt_image_url ?? deposit?.receipt_image_path);
  const decided = (deposit?.status ?? "").toLowerCase() !== "pending";

  const decide = async (status: "approved" | "reject") => {
    if (!deposit) return;
    setFormError(null);
    try {
      await updateStatus({ id: deposit.id, status, notes: notes.trim() || undefined }).unwrap();
      navigate("/deposit");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this deposit."));
    }
  };

  const back = (
    <Button size="sm" variant="secondary" onClick={() => navigate("/deposit")}>
      <IconBack size={16} />
      Queue
    </Button>
  );

  if (isLoading) {
    return (
      <AdminShell title="Deposit" action={back}>
        <Skeleton className="h-72 w-full" />
      </AdminShell>
    );
  }

  if (!deposit) {
    return (
      <AdminShell title="Deposit" action={back}>
        <Card>
          {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load deposits.")}</ErrorNote> : null}
          <EmptyState
            title="Deposit not found"
            body="It may have been decided by another admin, or the id is wrong."
          />
        </Card>
      </AdminShell>
    );
  }

  return (
    <AdminShell title={`Deposit #${deposit.id}`} subtitle={deposit.user?.name ?? undefined} action={back}>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="min-w-0 space-y-4">
          <Card>
            <div className="flex min-w-0 items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-xs text-muted">Amount</p>
                <p className="tabular text-2xl font-semibold break-words text-accent sm:text-3xl">{money(deposit.amount)}</p>
              </div>
              <Badge tone={statusTone(deposit.status)}>{deposit.status}</Badge>
            </div>

            {deposit.bonus ? (
              <p className="mt-3 rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">
                Bonus {deposit.bonus.code} · {money(deposit.bonus.amount)} ·{" "}
                {deposit.bonus.reward_label ?? deposit.bonus.status}
              </p>
            ) : null}

            <div className="mt-4 min-w-0">
              <CopyRow label="Play ID" value={deposit.play_id} />
              <CopyRow label="UTR" value={deposit.utr_number} />
              <CopyRow label="User" value={deposit.user?.name} />
              <CopyRow label="Phone" value={deposit.user?.phone} />
              <CopyRow label="Paid into" value={deposit.account?.name ?? deposit.account_name} />
              <CopyRow label="UPI" value={deposit.account?.upi_id ?? deposit.upi_id} />
              <CopyRow
                label="A/C"
                value={deposit.account?.account_number ?? deposit.account_number}
              />
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-3 text-xs">
              <div className="min-w-0">
                <dt className="text-faint">Requested</dt>
                <dd className="truncate text-text">{formatDateTime(deposit.created_at)}</dd>
              </div>
              {deposit.approved_at ? (
                <div className="min-w-0">
                  <dt className="text-faint">Decided</dt>
                  <dd className="truncate text-text">{formatDateTime(deposit.approved_at)}</dd>
                </div>
              ) : null}
              {deposit.processing_seconds ? (
                <div className="min-w-0">
                  <dt className="text-faint">Took</dt>
                  <dd className="truncate text-text">{formatDuration(deposit.processing_seconds)}</dd>
                </div>
              ) : null}
            </dl>

            {deposit.notes ? (
              <p className="mt-3 rounded-md bg-surface-2 px-3 py-2 text-xs break-words text-muted">
                {deposit.notes}
              </p>
            ) : null}
          </Card>

          <Card>
            <CardTitle>Decision</CardTitle>
            {decided ? (
              <p className="text-sm text-muted">
                Already {deposit.status.toLowerCase()}. Re-deciding is still possible if the branch
                allows it.
              </p>
            ) : null}

            <form
              className="mt-3 space-y-3"
              onSubmit={(event: FormEvent) => {
                event.preventDefault();
              }}
            >
              <Textarea
                label="Note to the user"
                rows={3}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                placeholder="Optional for approvals, expected for rejections."
              />
              <QuickNotes value={notes} onPick={setNotes} />

              {formError ? <ErrorNote>{formError}</ErrorNote> : null}

              <div className="flex flex-wrap gap-2">
                <Button type="button" loading={isSaving} onClick={() => void decide("approved")}>
                  Approve
                </Button>
                <Button
                  type="button"
                  variant="danger"
                  loading={isSaving}
                  onClick={() => void decide("reject")}
                >
                  Reject
                </Button>
              </div>
            </form>
          </Card>
        </div>

        <Card className="min-w-0">
          <CardTitle>Payment proof</CardTitle>
          {receiptUrl ? (
            <a href={receiptUrl} target="_blank" rel="noreferrer noopener" className="block min-w-0">
              <img
                src={receiptUrl}
                alt={`Deposit ${deposit.id} proof`}
                className="max-h-[70dvh] w-full rounded-md border border-border object-contain"
              />
            </a>
          ) : (
            <EmptyState
              title="No screenshot attached"
              body="Ask the user to re-send it on support chat before approving."
            />
          )}
        </Card>
      </div>
    </AdminShell>
  );
}
