import { useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import SuperShell from "@/components/SuperShell";
import CopyRow from "@/components/CopyRow";
import QuickNotes from "@/components/QuickNotes";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import { Textarea } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconBack } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetWithdrawalsQuery, useUpdateWithdrawalStatusMutation } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime, formatDuration } from "@/utils/dateTime";
import { money } from "@/utils/format";

export default function WithdrawUpdatePage() {
  const navigate = useNavigate();
  const { id } = useParams();
  const withdrawalId = Number(id);

  const { data: withdrawals = [], isLoading, error } = useGetWithdrawalsQuery();
  const [updateStatus, { isLoading: isSaving }] = useUpdateWithdrawalStatusMutation();
  useSessionGuard(error);

  const [notes, setNotes] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  const withdrawal = useMemo(
    () => withdrawals.find((record) => record.id === withdrawalId) ?? null,
    [withdrawals, withdrawalId]
  );

  const decide = async (status: "approved" | "reject") => {
    if (!withdrawal) return;
    setFormError(null);
    try {
      await updateStatus({ id: withdrawal.id, status, notes: notes.trim() || undefined }).unwrap();
      navigate("/withdraw");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this withdrawal."));
    }
  };

  const back = (
    <Button size="sm" variant="secondary" onClick={() => navigate("/withdraw")}>
      <IconBack size={16} />
      Queue
    </Button>
  );

  if (isLoading) {
    return (
      <SuperShell title="Withdrawal" action={back}>
        <Skeleton className="h-72 w-full" />
      </SuperShell>
    );
  }

  if (!withdrawal) {
    return (
      <SuperShell title="Withdrawal" action={back}>
        <Card>
          {error ? (
            <ErrorNote>{resolveErrorMessage(error, "Could not load withdrawals.")}</ErrorNote>
          ) : null}
          <EmptyState
            title="Withdrawal not found"
            body="It may have been decided by another admin, or the id is wrong."
          />
        </Card>
      </SuperShell>
    );
  }

  const isBank = (withdrawal.destination_type ?? "").toLowerCase() === "bank";

  return (
    <SuperShell
      title={`Withdrawal #${withdrawal.id}`}
      subtitle={withdrawal.user?.name ?? undefined}
      action={back}
    >
      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="min-w-0">
          <div className="flex min-w-0 items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-xs text-muted">Amount</p>
              <p className="tabular text-3xl font-semibold text-accent">{money(withdrawal.amount)}</p>
            </div>
            <Badge tone={statusTone(withdrawal.status)}>{withdrawal.status}</Badge>
          </div>

          {/* Payout target first: this is what gets typed into the banking app,
              and a mistyped IFSC is money gone to the wrong person. */}
          <div className="mt-4 min-w-0">
            <CopyRow label="Destination" value={isBank ? "Bank transfer" : "UPI"} />
            <CopyRow label="UPI" value={withdrawal.upi_id} />
            <CopyRow label="Account name" value={withdrawal.account_name} />
            <CopyRow label="A/C" value={withdrawal.account_number} />
            <CopyRow label="IFSC" value={withdrawal.ifsc_code} />
            <CopyRow label="Play ID" value={withdrawal.play_id} />
            <CopyRow label="User" value={withdrawal.user?.name} />
            <CopyRow label="Phone" value={withdrawal.user?.phone} />
          </div>

          <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-3 text-xs">
            <div className="min-w-0">
              <dt className="text-faint">Requested</dt>
              <dd className="truncate text-text">{formatDateTime(withdrawal.created_at)}</dd>
            </div>
            {withdrawal.processed_at ? (
              <div className="min-w-0">
                <dt className="text-faint">Decided</dt>
                <dd className="truncate text-text">{formatDateTime(withdrawal.processed_at)}</dd>
              </div>
            ) : null}
            {withdrawal.processing_seconds ? (
              <div className="min-w-0">
                <dt className="text-faint">Took</dt>
                <dd className="truncate text-text">
                  {formatDuration(withdrawal.processing_seconds)}
                </dd>
              </div>
            ) : null}
          </dl>
        </Card>

        <Card className="min-w-0">
          <CardTitle>Decision</CardTitle>
          <div className="space-y-3">
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
          </div>
        </Card>
      </div>
    </SuperShell>
  );
}
