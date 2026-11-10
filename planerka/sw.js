// Сервис-воркер Планёрки: показывает уведомление и по нажатию открывает именно эту задачу.
// Номер задачи передаётся приложению тремя путями сразу (на iPhone какой-то из них может не сработать):
//   1) запись в Cache Storage — приложение забирает её при открытии и при возврате на экран;
//   2) сообщение уже открытому окну;
//   3) открытие окна по ссылке ?task=<id>.
var NAV_CACHE = 'planerka-nav';
var NAV_KEY = '/planerka/__open-task';

var SHELL_CACHE = 'planerka-shell-v1';
var SHELL = '/planerka/__shell';
// страница сохраняется уже при установке — без сети приложение откроется даже после первого же захода
self.addEventListener('install', function (e) {
  self.skipWaiting();
  e.waitUntil(caches.open(SHELL_CACHE).then(function (c) {
    return fetch('/planerka/', { cache: 'no-store' }).then(function (r) { if (r && r.ok) return c.put(SHELL, r); });
  }).catch(function () {}));
});
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

// Страница Планёрки без интернета: «сначала сеть» (свежая версия и её копия на устройстве),
// нет сети или она очень медленная — сохранённая копия. Обмен данными приложение догоняет само.
function timeout(p, ms) {
  return new Promise(function (ok, no) { var t = setTimeout(function () { no(new Error('timeout')); }, ms); p.then(function (r) { clearTimeout(t); ok(r); }, function (x) { clearTimeout(t); no(x); }); });
}
self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET' || r.mode !== 'navigate') return;
  var u = new URL(r.url);
  if (u.origin !== self.location.origin || u.pathname.indexOf('/planerka') !== 0 || /\/planerka\/(dial|api\.php|manifest\.json)/.test(u.pathname)) return;
  e.respondWith(caches.open(SHELL_CACHE).then(function (c) {
    return timeout(fetch(r), 5000).then(function (resp) {
      if (resp && resp.ok && (resp.headers.get('content-type') || '').indexOf('text/html') === 0) c.put(SHELL, resp.clone());
      return resp;
    }).catch(function () {
      return c.match(SHELL).then(function (m) { return m || fetch(r); });
    });
  }));
});

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { title: 'Планёрка', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Планёрка', {
    body: d.body || '',
    tag: d.tag || undefined,
    renotify: true,
    icon: '/planerka/icon-192.png',
    badge: '/planerka/badge.png',
    actions: d.actions || [],
    data: d
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var d = e.notification.data || {};
  // кнопки под уведомлением (Android): позвонить контрагенту или написать в WhatsApp
  if (e.action === 'call' && d.phone) { e.waitUntil(self.clients.openWindow('/planerka/dial?n=' + encodeURIComponent(d.phone))); return; }
  if (e.action === 'wa' && d.wa) { e.waitUntil(self.clients.openWindow(d.wa)); return; }
  var url = d.url || '/planerka/';
  e.waitUntil((async function () {
    if (d.task) {
      try {
        var c = await caches.open(NAV_CACHE);
        await c.put(NAV_KEY, new Response(JSON.stringify({ id: d.task, at: Date.now() }), { headers: { 'Content-Type': 'application/json' } }));
      } catch (x) {}
    }
    var list = [];
    try { list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true }); } catch (x) {}
    for (var i = 0; i < list.length; i++) {
      var w = list[i];
      if (w.url.indexOf('/planerka') >= 0) {
        try { w.postMessage({ type: 'open-task', id: d.task || null }); } catch (x) {}
        try { await w.focus(); return; } catch (x) {}
        try { if (w.navigate) { await w.navigate(url); return; } } catch (x) {}
      }
    }
    try { await self.clients.openWindow(url); } catch (x) {}
  })());
});
