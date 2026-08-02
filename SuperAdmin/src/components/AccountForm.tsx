import { useState, type FormEvent, type ReactNode } from "react";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { Input, Select, Textarea } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import type { AccountRecord } from "@/types/api";

export type AccountFormValues = {
  name: string;
  holder_name: string;
  type: string;
  used_for: string;
  account_number: string;
  ifsc_code: string;
  upi_id: string;
  notes: string;
  status: string;
  min_deposit: string;
  max_deposit: string;
};

export const toFormValues = (account?: AccountRecord | null): AccountFormValues => ({
  name: account?.name ?? "",
  holder_name: account?.holder_name ?? "",
  type: account?.type ?? "upi",
  used_for: account?.used_for ?? "deposit",
  account_number: account?.account_number ?? "",
  ifsc_code: account?.ifsc_code ?? "",
  upi_id: account?.upi_id ?? "",
  notes: account?.notes ?? "",
  status: account?.status ?? "active",
  min_deposit: account?.min_deposit != null ? String(account.min_deposit) : "",
  max_deposit: account?.max_deposit != null ? String(account.max_deposit) : "",
});

const IFSC_REGEX = /^[A-Z]{4}[0-9][A-Z0-9]{6}$/;

/**
 * Validates the fields the chosen rail actually needs. A UPI account with no
 * UPI id, or a bank account with a malformed IFSC, is money sent nowhere —
 * these are caught here rather than by the first user who tries to deposit.
 */
export const validateAccount = (values: AccountFormValues): string | null => {
  if (!values.name.trim()) return "Give the account a name users will recognise.";
  if (!values.holder_name.trim()) return "Holder name is required.";

  const type = values.type.toLowerCase();
  if (type === "upi" && !values.upi_id.trim()) return "A UPI account needs a UPI id.";
  if (type === "bank") {
    if (!values.account_number.trim()) return "A bank account needs an account number.";
    if (!IFSC_REGEX.test(values.ifsc_code.trim().toUpperCase())) return "That is not a valid IFSC code.";
  }

  const min = Number(values.min_deposit || 0);
  const max = Number(values.max_deposit || 0);
  if (min && max && max < min) return "Maximum deposit cannot be below the minimum.";

  return null;
};

export default function AccountForm({
  values,
  onChange,
  onSubmit,
  saving,
  error,
  submitLabel,
  logoSlot,
  footer,
}: {
  values: AccountFormValues;
  onChange: (next: AccountFormValues) => void;
  onSubmit: () => void;
  saving?: boolean;
  error?: string | null;
  submitLabel: string;
  logoSlot?: ReactNode;
  footer?: ReactNode;
}) {
  const [touchedError, setTouchedError] = useState<string | null>(null);
  const patch = (partial: Partial<AccountFormValues>) => onChange({ ...values, ...partial });

  const type = values.type.toLowerCase();

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const invalid = validateAccount(values);
    setTouchedError(invalid);
    if (!invalid) onSubmit();
  };

  return (
    <form className="space-y-4" onSubmit={handleSubmit}>
      <Card>
        <CardTitle>Account</CardTitle>
        <div className="grid gap-3 sm:grid-cols-2">
          <Input
            label="Display name"
            value={values.name}
            onChange={(event) => patch({ name: event.target.value })}
            hint="What the user sees on the deposit screen."
            required
          />
          <Input
            label="Holder name"
            value={values.holder_name}
            onChange={(event) => patch({ holder_name: event.target.value })}
            required
          />
          <Select label="Type" value={values.type} onChange={(event) => patch({ type: event.target.value })}>
            <option value="upi">UPI</option>
            <option value="bank">Bank</option>
            <option value="qr">QR</option>
          </Select>
          <Select
            label="Used for"
            value={values.used_for}
            onChange={(event) => patch({ used_for: event.target.value })}
          >
            <option value="deposit">Deposit</option>
            <option value="withdrawal">Withdrawal</option>
            <option value="both">Both</option>
          </Select>
        </div>
      </Card>

      <Card>
        <CardTitle>Payment details</CardTitle>
        <div className="grid gap-3 sm:grid-cols-2">
          {type !== "bank" ? (
            <Input
              label="UPI ID"
              value={values.upi_id}
              placeholder="name@bank"
              onChange={(event) => patch({ upi_id: event.target.value })}
            />
          ) : null}
          {type !== "upi" ? (
            <>
              <Input
                label="Account number"
                inputMode="numeric"
                value={values.account_number}
                onChange={(event) => patch({ account_number: event.target.value.replace(/\D/g, "") })}
              />
              <Input
                label="IFSC"
                value={values.ifsc_code}
                placeholder="HDFC0001234"
                onChange={(event) => patch({ ifsc_code: event.target.value.toUpperCase() })}
              />
            </>
          ) : null}
        </div>

        {logoSlot ? <div className="mt-4 border-t border-border pt-4">{logoSlot}</div> : null}
      </Card>

      <Card>
        <CardTitle>Limits and visibility</CardTitle>
        <div className="grid gap-3 sm:grid-cols-3">
          <Input
            label="Min deposit"
            type="number"
            inputMode="numeric"
            value={values.min_deposit}
            onChange={(event) => patch({ min_deposit: event.target.value })}
          />
          <Input
            label="Max deposit"
            type="number"
            inputMode="numeric"
            value={values.max_deposit}
            onChange={(event) => patch({ max_deposit: event.target.value })}
          />
          <Select
            label="Status"
            value={values.status}
            onChange={(event) => patch({ status: event.target.value })}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </div>

        <div className="mt-3">
          <Textarea
            label="Notes for the user"
            rows={2}
            value={values.notes}
            onChange={(event) => patch({ notes: event.target.value })}
            hint="Shown under the account on the deposit screen."
          />
        </div>
      </Card>

      {touchedError ? <ErrorNote>{touchedError}</ErrorNote> : null}
      {error ? <ErrorNote>{error}</ErrorNote> : null}

      <div className="flex flex-wrap items-center gap-2">
        <Button type="submit" loading={saving}>
          {submitLabel}
        </Button>
        {footer}
      </div>
    </form>
  );
}
