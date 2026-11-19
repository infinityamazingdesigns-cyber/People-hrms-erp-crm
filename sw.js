/* Service worker for browser notifications (lib/push.php). The push itself is empty; the text is fetched from this
   site with the person's own sign-in. Nothing else is cached or intercepted: pages load exactly as without it. */
"use strict";
const BASE = new URL("./", self.registration.scope).href;
const STATE = "wn-push-state";
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));
async function since() { try { const r = await (await caches.open(STATE)).match(BASE + "__push_since"); return r ? parseInt(await r.text(), 10) || 0 : 0; } catch (e) { return 0; } }
async function setSince(n) { try { await (await caches.open(STATE)).put(BASE + "__push_since", new Response(String(n))); } catch (e) {} }
function show(n, quiet) {
  return self.registration.showNotification(n.title || "Notice", { body: n.body || "", tag: "wn-" + (n.id || "notice"), renotify: !quiet, silent: !!quiet,
    data: { url: BASE + (n.url || "modules/notify/") } });
}
self.addEventListener("push", (e) => e.waitUntil((async () => {
  let j = null;
  try { const r = await fetch(BASE + "modules/notify/api.php?pending=1&since=" + (await since()), { credentials: "include", cache: "no-store" }); if (r.ok) j = await r.json(); } catch (err) {}
  if (!j) return show({ title: "New notice", body: "Sign in to see it." });
  if (j.items && j.items.length) { for (const n of j.items) await show(n); await setSince(j.items[j.items.length - 1].id); return; }
  return show(j.latest || { title: "Notices", body: "Open the site to see your notices." }, true);   // every push must show something
})()));
self.addEventListener("notificationclick", (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || BASE;
  if (!url.startsWith(BASE)) return;
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const c of all) if (c.url.startsWith(BASE) && "focus" in c) { await c.focus(); if ("navigate" in c) try { await c.navigate(url); } catch (err) {} return; }
    if (self.clients.openWindow) await self.clients.openWindow(url);
  })());
});
