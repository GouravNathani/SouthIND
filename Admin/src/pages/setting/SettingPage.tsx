import { useEffect, useState, type FormEvent } from "react";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetAppSettingsQuery, useSaveAppSettingsMutation } from "@/services/api";
import { money } from "@/utils/format";
import type { AppSettings } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";

type FormState = {
  deposit_offer_text: string;
  withdrawal_offer_text: string;
  whatsapp_number: string;
  whatsapp_link: string;
  instagram_link: string;
  telegram_link: string;
  deposit_wa: string;
  withdrawal_wa: string;
  bonus_deposit_enabled: boolean;
};

const toOptionalNumber = (value: unknown): number | null => {
  const parsed = typeof value === "string" ? Number(value) : typeof value === "number" ? value : NaN;
  return Number.isFinite(parsed) ? parsed : null;
};

const toForm = (settings?: AppSettings | null): FormState => ({
  deposit_offer_text: settings?.deposit_offer_text ?? "",
  withdrawal_offer_text: settings?.withdrawal_offer_text ?? "",
  whatsapp_number: settings?.whatsapp_number ?? "",
  whatsapp_link: settings?.whatsapp_link ?? "",
  instagram_link: settings?.instagram_link ?? "",
  telegram_link: settings?.telegram_link ?? "",
  deposit_wa: settings?.deposit_wa ?? "",
  withdrawal_wa: settings?.withdrawal_wa ?? "",
  bonus_deposit_enabled: Boolean(settings?.bonus_deposit_enabled),
});

export default function SettingPage() {
  const { data: settings, isLoading, error } = useGetAppSettingsQuery();
  const [saveSettings, { isLoading: isSaving }] = useSaveAppSettingsMutation();
  useSessionGuard(error);

  // The branch endpoint omits the limits, but the public one merges in the
  // global values — read them from there so the page can still show them.
  const minDeposit = toOptionalNumber(settings?.min_deposit_amount);
  const minWithdrawal = toOptionalNumber(settings?.min_withdrawal_amount);

  const [form, setForm] = useState<FormState>(toForm());
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (settings) setForm(toForm(settings));
  }, [settings]);

  const patch = (partial: Partial<FormState>) => setForm((prev) => ({ ...prev, ...partial }));

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setNotice(null);

    try {
      await saveSettings({
        id: settings?.id,
        body: {
          ...form,
          bonus_deposit_enabled: form.bonus_deposit_enabled,
        },
      }).unwrap();
      setNotice("Settings saved. The user app picks these up on its next poll.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save these settings."));
    }
  };

  if (isLoading) {
    return (
      <AdminShell title="Settings">
        <Skeleton className="h-96 w-full" />
      </AdminShell>
    );
  }

  return (
    <AdminShell title="Settings" subtitle="What the user app shows for this branch">
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Card>
          <CardTitle>Offer text</CardTitle>
          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="Deposit offer"
              value={form.deposit_offer_text}
              onChange={(event) => patch({ deposit_offer_text: event.target.value })}
              placeholder="Upto 10% extra"
              hint="Shown on the deposit tile."
            />
            <Input
              label="Withdrawal offer"
              value={form.withdrawal_offer_text}
              onChange={(event) => patch({ withdrawal_offer_text: event.target.value })}
              placeholder="Upto 5% extra"
            />
          </div>
        </Card>

        <Card>
          <CardTitle>Limits</CardTitle>
          {/* Minimum deposit and withdrawal are global settings owned by the
              super admin — this endpoint neither returns nor accepts them, so
              they are shown read-only rather than as fields that quietly do
              nothing when saved. */}
          <dl className="grid gap-3 sm:grid-cols-2">
            <div className="min-w-0 rounded-md border border-border bg-surface-2 px-3 py-2.5">
              <dt className="text-xs text-muted">Minimum deposit</dt>
              <dd className="tabular text-sm text-text">
                {minDeposit != null ? money(minDeposit) : "Set by the super admin"}
              </dd>
            </div>
            <div className="min-w-0 rounded-md border border-border bg-surface-2 px-3 py-2.5">
              <dt className="text-xs text-muted">Minimum withdrawal</dt>
              <dd className="tabular text-sm text-text">
                {minWithdrawal != null ? money(minWithdrawal) : "Set by the super admin"}
              </dd>
            </div>
          </dl>

          <label className="mt-4 flex min-w-0 items-start gap-3">
            <input
              type="checkbox"
              checked={form.bonus_deposit_enabled}
              onChange={(event) => patch({ bonus_deposit_enabled: event.target.checked })}
              className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
            />
            <span className="min-w-0">
              <span className="block text-sm font-medium text-text">Bonus codes on deposits</span>
              <span className="block text-xs text-muted">
                Off hides the bonus code box on the user's deposit form entirely.
              </span>
            </span>
          </label>
        </Card>

        <Card>
          <CardTitle>Contact links</CardTitle>
          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="WhatsApp number"
              inputMode="numeric"
              value={form.whatsapp_number}
              onChange={(event) => patch({ whatsapp_number: event.target.value })}
              hint="Digits only. Used when no full link is set."
            />
            <Input
              label="WhatsApp link"
              value={form.whatsapp_link}
              onChange={(event) => patch({ whatsapp_link: event.target.value })}
              placeholder="https://wa.me/…"
            />
            <Input
              label="Instagram"
              value={form.instagram_link}
              onChange={(event) => patch({ instagram_link: event.target.value })}
            />
            <Input
              label="Telegram"
              value={form.telegram_link}
              onChange={(event) => patch({ telegram_link: event.target.value })}
            />
            <Input
              label="Deposit WhatsApp"
              value={form.deposit_wa}
              onChange={(event) => patch({ deposit_wa: event.target.value })}
              hint="Optional queue-specific number."
            />
            <Input
              label="Withdrawal WhatsApp"
              value={form.withdrawal_wa}
              onChange={(event) => patch({ withdrawal_wa: event.target.value })}
            />
          </div>
        </Card>

        {formError ? <ErrorNote>{formError}</ErrorNote> : null}
        {notice ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
        ) : null}

        <Button type="submit" loading={isSaving}>
          Save settings
        </Button>
      </form>
    </AdminShell>
  );
}
