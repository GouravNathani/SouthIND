import { useEffect, useMemo } from "react";
import {
  useRegisterPushSubscriptionMutation,
  useUnregisterPushSubscriptionMutation,
} from "@/services/api";
import { getAuthToken } from "@/utils/auth";

const PUSH_BUILD_KEY = "sind_push_build";

/** Fired by the prompt card once the user grants permission. */
export const PUSH_PERMISSION_EVENT = "sind-push-permission";

const supportsPush =
  typeof window !== "undefined" &&
  "Notification" in window &&
  "serviceWorker" in navigator &&
  "PushManager" in window;

const urlBase64ToUint8Array = (base64String: string) => {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(base64);
  return Uint8Array.from(raw, (char) => char.charCodeAt(0));
};

/**
 * Subscribes the logged-in user to web push (deposit / withdrawal decisions).
 * Renders nothing — mount it on an authenticated screen.
 *
 * A subscription is re-registered whenever the build id changes: a new service
 * worker invalidates the old endpoint, and a silently dead endpoint is worse
 * than no push at all.
 */
export default function PushSubscriptionManager() {
  const [registerPushSubscription] = useRegisterPushSubscriptionMutation();
  const [unregisterPushSubscription] = useUnregisterPushSubscriptionMutation();
  const publicKey = useMemo(() => (import.meta.env.VITE_VAPID_PUBLIC_KEY ?? "").trim(), []);

  useEffect(() => {
    if (!supportsPush || !publicKey || !getAuthToken()) return;

    let active = true;

    const syncSubscription = async () => {
      if (Notification.permission !== "granted") return;

      const registration = await navigator.serviceWorker.ready;
      const buildChanged = localStorage.getItem(PUSH_BUILD_KEY) !== __BUILD_VERSION__;
      let existing = await registration.pushManager.getSubscription();

      if (buildChanged && existing) {
        await unregisterPushSubscription({ endpoint: existing.endpoint }).catch(() => undefined);
        const unsubscribed = await existing.unsubscribe().catch(() => false);
        existing = unsubscribed ? null : await registration.pushManager.getSubscription();
      }

      const subscription =
        existing ??
        (await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey),
        }));

      if (!active) return;

      const payload = subscription.toJSON();
      if (!payload.endpoint || !payload.keys?.p256dh || !payload.keys?.auth) return;

      await registerPushSubscription({
        endpoint: payload.endpoint,
        keys: { p256dh: payload.keys.p256dh, auth: payload.keys.auth },
        content_encoding: "aesgcm",
      });
      localStorage.setItem(PUSH_BUILD_KEY, __BUILD_VERSION__);
    };

    void syncSubscription().catch(() => undefined);

    // Permission may be granted later from the prompt card; re-sync on that.
    const handleGranted = () => void syncSubscription().catch(() => undefined);
    window.addEventListener(PUSH_PERMISSION_EVENT, handleGranted);

    return () => {
      active = false;
      window.removeEventListener(PUSH_PERMISSION_EVENT, handleGranted);
    };
  }, [publicKey, registerPushSubscription, unregisterPushSubscription]);

  return null;
}

export const pushSupported = () => supportsPush;
export const pushConfigured = () => Boolean((import.meta.env.VITE_VAPID_PUBLIC_KEY ?? "").trim());
