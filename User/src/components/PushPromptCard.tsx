import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/Button";
import { IconBell, IconClose } from "@/components/icons";
import {
  PUSH_PERMISSION_EVENT,
  pushConfigured,
  pushSupported,
} from "@/components/PushSubscriptionManager";

const DISMISSED_KEY = "sind_push_dismissed";

/**
 * Asks for notification permission from a real button rather than on page load.
 * Browsers permanently block a site whose prompt is denied, so the ask has to be
 * something the user chose to answer.
 */
export default function PushPromptCard() {
  const { t } = useTranslation();
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    if (!pushSupported() || !pushConfigured()) return;
    if (Notification.permission !== "default") return;
    if (localStorage.getItem(DISMISSED_KEY) === "1") return;
    setVisible(true);
  }, []);

  if (!visible) return null;

  const dismiss = () => {
    localStorage.setItem(DISMISSED_KEY, "1");
    setVisible(false);
  };

  const enable = async () => {
    const permission = await Notification.requestPermission().catch(() => "denied" as const);
    if (permission === "granted") window.dispatchEvent(new Event(PUSH_PERMISSION_EVENT));
    setVisible(false);
  };

  return (
    <div className="flex min-w-0 items-center gap-3 rounded-lg border border-accent-line bg-accent-soft p-4">
      <span className="shrink-0 text-accent">
        <IconBell size={20} />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-text">{t("push.title")}</p>
        <p className="text-xs text-muted">{t("push.body")}</p>
      </div>
      <Button size="sm" onClick={enable}>
        {t("push.enable")}
      </Button>
      <button
        type="button"
        onClick={dismiss}
        aria-label={t("push.dismiss")}
        className="grid size-8 shrink-0 place-items-center rounded-full text-muted"
      >
        <IconClose size={16} />
      </button>
    </div>
  );
}
