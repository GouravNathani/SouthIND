/// <reference lib="webworker" />

import { cleanupOutdatedCaches, precacheAndRoute } from "workbox-precaching";

declare const self: ServiceWorkerGlobalScope & {
  __WB_MANIFEST: Array<string | { url: string; revision?: string }>;
};

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// Take over immediately on update: without these, a freshly built worker sits in
// "waiting" and the old cached app keeps serving until every tab is closed.
// skipWaiting activates the new worker at once; clients.claim() lets it control
// already-open pages, so the autoUpdate registration can reload them.
self.addEventListener("install", () => {
  self.skipWaiting();
});
self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});

const DEFAULT_ICON = "/conf/icon-192.png";

const parsePushPayload = (event: PushEvent) => {
  if (!event.data) return undefined;
  try {
    return event.data.json() as { title?: string; body?: string; url?: string };
  } catch {
    return { title: "Notification", body: event.data.text(), url: "/" };
  }
};

self.addEventListener("push", (event) => {
  const payload = parsePushPayload(event);
  event.waitUntil(
    self.registration.showNotification(payload?.title ?? "Notification", {
      body: payload?.body ?? "You have a new update.",
      icon: DEFAULT_ICON,
      badge: DEFAULT_ICON,
      data: { url: payload?.url ?? "/" },
    })
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = (event.notification.data as { url?: string } | undefined)?.url ?? "/";

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if ("focus" in client) return client.focus();
      }
      return self.clients.openWindow?.(target) ?? undefined;
    })
  );
});
