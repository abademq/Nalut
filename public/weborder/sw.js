/* موقع الطلب — Service Worker بسيط: الصفحة تفتح حتى لو النت ضعيف، والطلبات للخادم دائماً مباشرة */
const V = '__V__';
const CACHE = 'wo-' + V;
const SHELL = ['/weborder/app.css?v=' + V, '/weborder/app.js?v=' + V, '/weborder/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('wo-') && k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // الـ API والخرائط والصور: من النت دائماً
  if (url.origin !== location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/storage/')) return;

  // ملفات الموقع: من الكاش أول
  if (url.pathname.startsWith('/weborder/')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copy));
      return res;
    })));
    return;
  }

  // الصفحة نفسها: من النت، ولو ما فيش نت آخر نسخة محفوظة
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put('shell', copy));
      return res;
    }).catch(() => caches.match('shell')));
  }
});
