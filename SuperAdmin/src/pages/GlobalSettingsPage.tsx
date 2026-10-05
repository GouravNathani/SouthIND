import { useEffect, useState, type FormEvent } from "react";
import SuperShell from "@/components/SuperShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconAlert } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetGlobalSettingsQuery, useUpdateGlobalSettingsMutation } from "@/services/api";
import type { GlobalSettings } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";

type FormState = {
  instagram_link: string;
  telegram_link: string;
  whatsapp_link: string;
  mask_user_phone: boolean;
  user_panel_maintenance_enabled: boolean;
  bonus_deposit_enabled: boolean;
  support_chat_enabled: boolean;
};

const toForm = (settings?: GlobalSettings | null): FormState => ({
  instagram_link: settings?.instagram_link ?? "",
  telegram_link: settings?.telegram_link ?? "",
  whatsapp_link: settings?.whatsapp_link ?? "",
  mask_user_phone: Boolean(settings?.mask_user_phone),
  user_panel_maintenance_enabled: Boolean(settings?.user_panel_maintenance_enabled),
  bonus_deposit_enabled: Boolean(settings?.bonus_deposit_enabled),
  // Chat is on unless explicitly switched off (no settings row yet = on).
  support_chat_enabled: settings?.support_chat_enabled !== false,
});

export default function GlobalSettingsPage() {
  const { data: settings, isLoading, error } = useGetGlobalSettingsQuery();
  const [saveSettings, { isLoading: isSaving }] = useUpdateGlobalSettingsMutation();
  useSessionGuard(error);

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
      await saveSettings({ body: form }).unwrap();
      setNotice("Saved. Every branch and the user app pick this up on their next poll.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save these settings."));
    }
  };

  if (isLoading) {
    return (
      <SuperShell title="Global settings">
        <Skeleton className="h-96 w-full" />
      </SuperShell>
    );
  }

  return (
    <SuperShell title="Global settings" subtitle="Applies to every branch at once">
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Card>
          <CardTitle>Availability</CardTitle>

          <label className="flex min-w-0 items-start gap-3">
            <input
              type="checkbox"
              checked={form.user_panel_maintenance_enabled}
              onChange={(event) => patch({ user_panel_maintenance_enabled: event.target.checked })}
              className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
            />
            <span className="min-w-0">
              <span className="block text-sm font-medium text-text">User panel maintenance</span>
              <span className="block text-xs text-muted">
                Turns the user app into a maintenance screen for every branch. Admin and super
                panels stay open, so you can keep working the queues while it is on.
              </span>
            </span>
          </label>

          {form.user_panel_maintenance_enabled ? (
            <p
              className="mt-3 flex min-w-0 items-center gap-2 rounded-md px-3 py-2 text-xs"
              style={{
                color: "var(--warn)",
                background: "color-mix(in srgb, var(--warn) 14%, transparent)",
              }}
            >
              <IconAlert size={14} />
              <span className="min-w-0">
                Every user is locked out the moment this is saved — including mid-deposit.
              </span>
            </p>
          ) : null}
        </Card>

        <Card>
          <CardTitle>Privacy and features</CardTitle>
          <div className="space-y-3">
            <label className="flex min-w-0 items-start gap-3">
              <input
                type="checkbox"
                checked={form.mask_user_phone}
                onChange={(event) => patch({ mask_user_phone: event.target.checked })}
                className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
              />
              <span className="min-w-0">
                <span className="block text-sm font-medium text-text">Mask user phone numbers</span>
                <span className="block text-xs text-muted">
                  Branch admins and staff see partial numbers in lists and queues.
                </span>
              </span>
            </label>

            <label className="flex min-w-0 items-start gap-3">
              <input
                type="checkbox"
                checked={form.bonus_deposit_enabled}
                onChange={(event) => patch({ bonus_deposit_enabled: event.target.checked })}
                className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
              />
              <span className="min-w-0">
                <span className="block text-sm font-medium text-text">Bonus codes on deposits</span>
                <span className="block text-xs text-muted">
                  The network-wide switch. A branch can still turn its own off.
                </span>
              </span>
            </label>

            <label className="flex min-w-0 items-start gap-3">
              <input
                type="checkbox"
                checked={form.support_chat_enabled}
                onChange={(event) => patch({ support_chat_enabled: event.target.checked })}
                className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
              />
              <span className="min-w-0">
                <span className="block text-sm font-medium text-text">Support chat</span>
                <span className="block text-xs text-muted">
                  Off: users cannot open it, branch admins do not see it and no panel polls it.
                  Old chats are kept.
                </span>
              </span>
            </label>
          </div>
        </Card>

        <Card>
          <CardTitle>Shared links</CardTitle>
          <div className="grid gap-3 sm:grid-cols-3">
            <Input
              label="WhatsApp"
              value={form.whatsapp_link}
              onChange={(event) => patch({ whatsapp_link: event.target.value })}
              placeholder="https://wa.me/…"
              hint="Used when a branch has not set its own."
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
          </div>
        </Card>

        {formError ? <ErrorNote>{formError}</ErrorNote> : null}
        {notice ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
        ) : null}

        <Button type="submit" loading={isSaving}>
          Save global settings
        </Button>
      </form>
    </SuperShell>
  );
}
