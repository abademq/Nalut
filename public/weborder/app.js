/* موقع الطلب — نفس تطبيق الزبون من المتصفح. JavaScript عادي بدون مكتبات (الخريطة بس Leaflet). */
(() => {
'use strict';

const C = window.APP_CONFIG || {};
const BASE = C.base || '';
const view = document.getElementById('view');
const topbar = document.getElementById('topbar');
const bottomnav = document.getElementById('bottomnav');

// ================= أدوات =================
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const num = (v) => Number(v || 0);
const money = (v) => num(v).toFixed(2) + ' د.ل';
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

const LS = {
  get(k, d = null) { try { const v = localStorage.getItem('wo.' + k); return v == null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem('wo.' + k, JSON.stringify(v)); } catch {} },
  del(k) { try { localStorage.removeItem('wo.' + k); } catch {} },
};

const ICONS = {
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg>',
  orders: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5h10M9 12h10M9 19h10M4 5h.01M4 12h.01M4 19h.01"/></svg>',
  cart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.2a1 1 0 0 0 1 .8h9.7a1 1 0 0 0 1-.8L21 7H6"/></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>',
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
  share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>',
  heart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>',
  heartFill: '<svg viewBox="0 0 24 24" fill="#E53935" stroke="#E53935" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>',
  phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
  pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
  close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
};

function toast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + type;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { t.className = 'toast'; }, 2800);
}

// ================= الاتصال بالخادم =================
class ApiError extends Error {
  constructor(message, status, data) { super(message); this.status = status; this.data = data || {}; }
}

const Auth = {
  token: LS.get('token'),
  user: LS.get('user'),
  get in() { return !!this.token; },
  save(token, user) { this.token = token; this.user = user; LS.set('token', token); LS.set('user', user); },
  clear() { this.token = null; this.user = null; LS.del('token'); LS.del('user'); Cache.clear(); },
};

async function api(method, path, body, headers = {}) {
  let res;
  try {
    res = await fetch(C.api + path, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        // نفس حساب الزبون — والخادم يعرف إنه من الموقع
        'X-App': 'customer',
        'X-Client': 'web',
        ...(Auth.token ? { Authorization: 'Bearer ' + Auth.token } : {}),
        ...headers,
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new ApiError('ما نقدرش نوصل للخادم. تأكد من الإنترنت وعاود.', 0);
  }

  let data = null;
  try { data = await res.json(); } catch {}

  if (res.status === 401 && Auth.in) {
    Auth.clear();
    go('/login?next=' + encodeURIComponent(currentPath()));
    throw new ApiError('انتهت الجلسة، ادخل من جديد.', 401);
  }
  if (!res.ok) {
    let msg = data?.message || 'صار خطأ، عاود بعد شوية.';
    if (data?.errors) {
      const first = Object.values(data.errors)[0];
      if (first) msg = Array.isArray(first) ? first[0] : first;
    }
    if (res.status === 429) msg = 'محاولات كثيرة. استنى دقيقة وعاود.';
    if (res.status >= 500 && !data?.message) msg = 'الخادم فيه مشكلة توّا. عاود بعد شوية.';
    throw new ApiError(msg, res.status, data);
  }
  return data;
}
const GET = (p) => api('GET', p);
const POST = (p, b = {}, h) => api('POST', p, b, h);
const PUT = (p, b = {}) => api('PUT', p, b);
const DEL = (p) => api('DELETE', p);

/** بيانات تتجاب مرة في الجلسة */
const Cache = {
  m: {},
  async get(key, loader, ttl = 60000) {
    const c = this.m[key];
    if (c && Date.now() - c.t < ttl) return c.v;
    const v = await loader();
    this.m[key] = { v, t: Date.now() };
    return v;
  },
  drop(key) { delete this.m[key]; },
  clear() { this.m = {}; },
};
const content = () => Cache.get('content', () => GET('/app/content?app=customer'), 10 * 60000);
const opt = async (key, d) => { try { const c = await content(); return c.options?.[key] ?? d; } catch { return d; } };

// ================= السلة =================
const Cart = {
  data: LS.get('cart', { storeId: null, storeName: '', lines: [] }),
  save() { LS.set('cart', this.data); updateBadge(); },
  get lines() { return this.data.lines; },
  get isEmpty() { return this.data.lines.length === 0; },
  get count() { return this.data.lines.reduce((s, l) => s + l.qty, 0); },
  key(pid, options, note, removed) {
    const ids = Object.keys(options || {}).sort((a, b) => a - b);
    return pid + '|' + ids.map((i) => i + '×' + options[i]).join(',') + '|' + [...(removed || [])].sort().join(',') + '|' + (note || '').trim();
  },
  optionsPrice(line) {
    let s = 0;
    for (const [id, q] of Object.entries(line.options || {})) {
      const v = findValue(line.product, +id);
      if (v) s += num(v.extra_price) * q;
    }
    return s;
  },
  unit(line) { return effPrice(line.product) + this.optionsPrice(line); },
  total(line) { return this.unit(line) * line.qty; },
  get subtotal() { return this.data.lines.reduce((s, l) => s + this.total(l), 0); },
  qtyOf(pid) { return this.data.lines.filter((l) => l.product.id === pid).reduce((s, l) => s + l.qty, 0); },
  add(product, storeId, storeName, qty = 1, note = '', options = {}, removed = []) {
    const opts = {};
    for (const [k, v] of Object.entries(options || {})) if (v > 0) opts[k] = v;
    const rm = [...new Set(removed || [])].sort();
    note = (note || '').trim();
    if (this.data.storeId !== storeId) this.data = { storeId, storeName, lines: [] };
    const key = this.key(product.id, opts, note, rm);
    const ex = this.data.lines.find((l) => this.key(l.product.id, l.options, l.note, l.removed) === key);
    if (ex) ex.qty += qty; else this.data.lines.push({ product, qty, note, options: opts, removed: rm });
    this.save();
  },
  setQty(line, qty) {
    if (qty <= 0) this.data.lines = this.data.lines.filter((l) => l !== line); else line.qty = qty;
    if (!this.data.lines.length) this.data = { storeId: null, storeName: '', lines: [] };
    this.save();
  },
  clear() { this.data = { storeId: null, storeName: '', lines: [] }; this.save(); },
  apiItems() {
    return this.data.lines.map((l) => ({
      product_id: l.product.id,
      quantity: l.qty,
      ...(l.note ? { note: l.note } : {}),
      ...(Object.keys(l.options || {}).length ? { options: Object.entries(l.options).map(([id, q]) => ({ id: +id, qty: q })) } : {}),
      ...((l.removed || []).length ? { remove: l.removed } : {}),
    }));
  },
};

const effPrice = (p) => num(p.discount_price ?? p.price);
const hasDiscount = (p) => p.discount_price != null && num(p.discount_price) < num(p.price);
function findValue(product, id) {
  for (const o of product.options || []) for (const v of o.values || []) if (v.id === id) return v;
  return null;
}
function optionsText(product, options, removed) {
  const parts = [];
  if ((removed || []).length) parts.push('بدون: ' + removed.join('، '));
  for (const o of product.options || []) {
    const chosen = (o.values || []).filter((v) => options?.[v.id]).map((v) => options[v.id] > 1 ? `${v.name} ×${options[v.id]}` : v.name);
    if (chosen.length) parts.push(`${o.name}: ${chosen.join('، ')}`);
  }
  return parts.join(' · ');
}
const maxQty = (p) => {
  const lim = [];
  if (p.track_stock && p.stock_quantity != null) lim.push(num(p.stock_quantity));
  if (p.max_per_order) lim.push(num(p.max_per_order));
  return lim.length ? Math.min(...lim) : null;
};

/** نفس «CartLoader» في التطبيق: سلة جاهزة/إعادة طلب/سلة محفوظة */
async function applyCart(res, openCart = true) {
  const store = res.store || {};
  const lines = res.lines || [];
  if (!lines.length) { toast('الأصناف هذي مش متوفرة توّا', 'err'); return; }
  if (!Cart.isEmpty && Cart.data.storeId !== store.id) {
    const ok = await confirmBox('تفضية السلة؟', `سلتك فيها أصناف من ${Cart.data.storeName}. نفضّوها ونحطو الأصناف الجديدة؟`, 'نعم', 'لا');
    if (!ok) return;
  }
  if (Cart.data.storeId !== store.id) Cart.clear();
  for (const l of lines) {
    const opts = {};
    for (const o of l.options || []) opts[o.id] = o.qty || 1;
    Cart.add(l.product, store.id, store.name || '', l.quantity || 1, l.note || '', opts, l.remove || []);
  }
  if ((res.missing || []).length) toast('مش متوفر توّا: ' + res.missing.join('، '), 'err');
  if (openCart) go('/cart');
}

function updateBadge() {
  const b = $('#cart-badge');
  if (!b) return;
  const n = Cart.count;
  b.textContent = n;
  b.classList.toggle('hidden', n === 0);
}

// ================= نوافذ =================
function sheet(html, { onClose } = {}) {
  const bd = document.createElement('div');
  bd.className = 'backdrop';
  bd.innerHTML = `<div class="sheet" role="dialog" aria-modal="true"><button class="iconbtn close" data-close aria-label="إغلاق">${ICONS.close}</button>${html}</div>`;
  document.body.appendChild(bd);
  document.body.style.overflow = 'hidden';
  const close = () => {
    bd.remove();
    if (!$('.backdrop')) document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    onClose && onClose();
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', onKey);
  bd.addEventListener('click', (e) => { if (e.target === bd || e.target.closest('[data-close]')) close(); });
  return { el: bd.firstElementChild, close };
}

function confirmBox(title, text, yes = 'تمام', no = 'رجوع', danger = false) {
  return new Promise((resolve) => {
    const bd = document.createElement('div');
    bd.className = 'backdrop center';
    bd.innerHTML = `<div class="dialog"><h3 style="margin:0 0 8px">${esc(title)}</h3><p class="muted" style="margin:0 0 16px;white-space:pre-line">${esc(text)}</p>
      <div class="row" style="justify-content:flex-end"><button class="btn ghost small" data-no>${esc(no)}</button><button class="btn small ${danger ? 'danger' : ''}" data-yes>${esc(yes)}</button></div></div>`;
    document.body.appendChild(bd);
    const done = (v) => { bd.remove(); resolve(v); };
    bd.addEventListener('click', (e) => {
      if (e.target.closest('[data-yes]')) done(true);
      else if (e.target.closest('[data-no]') || e.target === bd) done(false);
    });
  });
}

function promptBox(title, { label = '', value = '', placeholder = '', type = 'text', yes = 'حفظ', multiline = false } = {}) {
  return new Promise((resolve) => {
    const bd = document.createElement('div');
    bd.className = 'backdrop center';
    const input = multiline
      ? `<textarea class="textarea" data-in placeholder="${esc(placeholder)}">${esc(value)}</textarea>`
      : `<input class="input" data-in type="${type}" value="${esc(value)}" placeholder="${esc(placeholder)}">`;
    bd.innerHTML = `<div class="dialog"><h3 style="margin:0 0 10px">${esc(title)}</h3>
      <label class="field">${label ? `<span>${esc(label)}</span>` : ''}${input}</label>
      <div class="row" style="justify-content:flex-end"><button class="btn ghost small" data-no>إلغاء</button><button class="btn small" data-yes>${esc(yes)}</button></div></div>`;
    document.body.appendChild(bd);
    const inp = $('[data-in]', bd);
    setTimeout(() => inp.focus(), 50);
    const done = (v) => { bd.remove(); resolve(v); };
    bd.addEventListener('click', (e) => {
      if (e.target.closest('[data-yes]')) done(inp.value);
      else if (e.target.closest('[data-no]') || e.target === bd) done(null);
    });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !multiline) done(inp.value); });
  });
}

/** زر يستنى: يوقف ويورّي دوّارة لين تخلص العملية */
async function busy(btn, fn) {
  if (!btn || btn.disabled) return;
  const html = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>';
  try { return await fn(); } finally { if (btn.isConnected) { btn.disabled = false; btn.innerHTML = html; } }
}

// ================= التنقّل =================
function currentPath() {
  let p = location.pathname;
  if (BASE && p.startsWith(BASE)) p = p.slice(BASE.length);
  return (p || '/') + location.search;
}
function go(path, replace = false) {
  const url = BASE + path;
  if (replace) history.replaceState({}, '', url); else history.pushState({}, '', url);
  render();
}
window.addEventListener('popstate', () => { closeAllSheets(); render(); });
function closeAllSheets() { $$('.backdrop').forEach((b) => b.remove()); document.body.style.overflow = ''; }

// روابط داخلية: data-go="/store/5"
document.addEventListener('click', (e) => {
  const a = e.target.closest('[data-go]');
  if (!a) return;
  e.preventDefault();
  go(a.getAttribute('data-go'));
});

const routes = [];
const route = (pattern, handler, opts = {}) => {
  const keys = [];
  const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '/?$');
  routes.push({ re, keys, handler, ...opts });
};

let renderToken = 0;
let pageCleanup = null;
async function render() {
  const token = ++renderToken;
  if (pageCleanup) { try { pageCleanup(); } catch {} pageCleanup = null; }
  const [path, qs] = currentPath().split('?');
  const query = Object.fromEntries(new URLSearchParams(qs || ''));
  let m = null;
  let r = routes.find((x) => (m = path.match(x.re)));
  if (!r) { r = routes[0]; m = ['/']; }
  const params = {};
  r.keys.forEach((k, i) => { params[k] = decodeURIComponent(m[i + 1]); });

  if (!r.public && !Auth.in) {
    go('/login?next=' + encodeURIComponent(currentPath()), true);
    return;
  }

  renderNav(r.tab);
  setTop(r.title ? { title: r.title, back: r.back !== false } : null);
  view.innerHTML = '<div class="loading"><span class="spinner"></span></div>';
  window.scrollTo(0, 0);
  try {
    await r.handler({ params, query, alive: () => token === renderToken, onLeave: (fn) => { pageCleanup = fn; } });
  } catch (e) {
    if (token !== renderToken) return;
    view.innerHTML = `<div class="empty"><div class="big">⚠️</div><p>${esc(e.message || 'صار خطأ')}</p><button class="btn outline" id="retry">عاود</button></div>`;
    $('#retry').onclick = () => render();
  }
}

function setTop(opts) {
  if (!opts) {
    const logo = C.logo ? `<img src="${esc(C.logo)}" alt="">` : '<span class="logo-fallback">🛵</span>';
    topbar.innerHTML = `<a class="brand" data-go="/">${logo}<span>${esc(C.name || '')}</span></a><span class="spacer"></span>`;
    return;
  }
  topbar.innerHTML = `${opts.back ? `<button class="iconbtn" id="back" aria-label="رجوع">${ICONS.back}</button>` : ''}
    <div class="title">${esc(opts.title)}</div><div id="top-actions" class="row"></div>`;
  const b = $('#back');
  if (b) b.onclick = () => (history.length > 1 ? history.back() : go('/'));
}
function topActions(html) { const el = $('#top-actions'); if (el) el.innerHTML = html; return el; }

function renderNav(tab) {
  if (!Auth.in || tab === 'none') { bottomnav.classList.add('hidden'); return; }
  bottomnav.classList.remove('hidden');
  const item = (k, path, label, icon, extra = '') =>
    `<a data-go="${path}" class="${tab === k ? 'on' : ''}">${icon}${extra}<span>${label}</span></a>`;
  bottomnav.innerHTML = item('home', '/', 'الرئيسية', ICONS.home)
    + item('orders', '/orders', 'طلباتي', ICONS.orders)
    + item('cart', '/cart', 'السلة', ICONS.cart, `<span class="badge hidden" id="cart-badge">0</span>`)
    + item('account', '/account', 'حسابي', ICONS.user);
  updateBadge();
}

// ================= بطاقات مشتركة =================
function storeCard(s) {
  const closed = !s.is_accepting;
  return `<div class="store-card ${closed ? 'closed' : ''}" data-go="/store/${s.id}">
    <div class="cover">${s.cover ? `<img src="${esc(s.cover)}" alt="" loading="lazy">` : ''}
      <span class="status-badge pill ${closed ? 'err' : 'ok'}">${closed ? 'مغلق حالياً' : 'مفتوح توّا'}</span>
      <div class="logo">${s.logo ? `<img src="${esc(s.logo)}" alt="" loading="lazy">` : '🏪'}</div>
    </div>
    <div class="body">
      <div class="name">${esc(s.name)}</div>
      <div class="meta">
        ${s.type ? `<span>${esc(s.type)}</span>` : ''}
        <span>⭐ ${num(s.rating_avg).toFixed(1)} (${s.rating_count || 0})</span>
        ${s.prep_time_minutes ? `<span>⏱ ${s.prep_time_minutes} د</span>` : ''}
        ${num(s.min_order) > 0 ? `<span>الحد الأدنى ${money(s.min_order)}</span>` : ''}
        ${s.distance_km != null ? `<span>📍 ${num(s.distance_km).toFixed(1)} كم</span>` : ''}
      </div>
    </div>
  </div>`;
}

function productCard(p, storeOpen) {
  const q = Cart.data.storeId && Cart.qtyOf(p.id);
  const off = !p.is_available;
  return `<div class="product ${off ? 'off' : ''}" data-product="${p.id}">
    <div class="ph">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : '🍽️'}
      ${off ? '<span class="tag grey">غير متوفر</span>' : ''}</div>
    <div class="pinfo">
      <div class="n">${esc(p.name)}</div>
      ${p.description ? `<div class="d">${esc(p.description)}</div>` : ''}
      ${off ? '<div class="tiny muted">غير متوفر توّا — تقدر تشوف الصور والتفاصيل</div>'
        : p.left != null ? `<div class="tiny" style="color:var(--warn);font-weight:700">متوفر ${p.left} قطع فقط</div>`
          : p.options?.length ? '<div class="tiny" style="color:var(--ok)">فيه إضافات</div>' : ''}
      <div class="bottom">
        <div><span class="price">${money(effPrice(p))}</span> ${hasDiscount(p) ? `<span class="strike">${num(p.price).toFixed(2)}</span>` : ''}</div>
        ${!off && storeOpen ? `<div class="row" style="gap:6px">${q ? `<span class="qtybadge">×${q}</span>` : ''}<button class="addbtn" aria-label="أضف">+</button></div>` : ''}
      </div>
    </div>
  </div>`;
}

const STATUS_STEPS = [
  ['pending', 'قيد الانتظار'], ['preparing', 'قيد التحضير'], ['ready', 'جاهز'],
  ['assigned', 'مع السائق'], ['picked_up', 'استلم الطلب'], ['on_the_way', 'في الطريق'], ['delivered', 'وصل'],
];
const statusPill = (o) => {
  const cls = o.status === 'delivered' ? 'ok' : ['cancelled', 'failed'].includes(o.status) ? 'err' : o.awaiting_customer ? 'warn' : 'brand';
  return `<span class="pill ${cls}">${esc(o.status_label)}</span>`;
};
const fmtDate = (s) => {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ar-LY', { day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString('ar-LY', { hour: '2-digit', minute: '2-digit' });
};

async function share(title, path) {
  const url = location.origin + BASE + path;
  try {
    if (navigator.share) { await navigator.share({ title, url }); return; }
  } catch { return; }
  try { await navigator.clipboard.writeText(url); toast('انسخ الرابط ✅', 'ok'); } catch { prompt('انسخ الرابط:', url); }
}

async function toggleFav(type, id, btn) {
  try {
    const r = await POST('/favorites/toggle', { type, id });
    btn.innerHTML = r.favorited ? ICONS.heartFill : ICONS.heart;
    toast(r.favorited ? 'انضاف للمفضلة' : 'انشال من المفضلة');
    Cache.drop('store:' + id);
  } catch (e) { toast(e.message, 'err'); }
}

// ---- الخريطة (Leaflet) تتحمّل وقت الحاجة بس ----
let leafletP = null;
// نسخة على السيرفر نفسه أول (أسرع وما تعتمدش على جهات خارجية)، وبعدها CDN
const LEAFLET_SOURCES = [
  ['/weborder/leaflet/leaflet.css', '/weborder/leaflet/leaflet.js'],
  ['https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'],
  ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js'],
];
function leaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (leafletP) return leafletP;
  const load = (i) => new Promise((resolve, reject) => {
    if (i >= LEAFLET_SOURCES.length) return reject(new Error('الخريطة ما تحمّلتش'));
    const [cssUrl, jsUrl] = LEAFLET_SOURCES[i];
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = cssUrl;
    document.head.appendChild(css);
    const js = document.createElement('script');
    js.src = jsUrl;
    js.onload = () => (window.L ? resolve(window.L) : load(i + 1).then(resolve, reject));
    js.onerror = () => { css.remove(); js.remove(); load(i + 1).then(resolve, reject); };
    document.head.appendChild(js);
  });
  leafletP = load(0).catch((e) => { leafletP = null; throw e; });
  return leafletP;
}
const tiles = (L, map) => L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19, attribution: '© OpenStreetMap',
}).addTo(map);
const emojiIcon = (L, e) => L.divIcon({ html: `<div style="font-size:30px;line-height:1">${e}</div>`, className: '', iconSize: [30, 30], iconAnchor: [15, 28] });
const NALUT = [31.8686, 10.9817];

// ================= الرئيسية =================
// ===== الإعلانات: كل إعلان ومكانه (الرئيسية / قسم / صفحة متجر / السلة) =====
const BANNERS = new Map();
const allBanners = (c) => c.all_banners || c.banners || [];
const bannersFor = (c, section, fallback) => {
  const list = allBanners(c);
  const home = list.filter((b) => (b.placement || 'home') === 'home' || b.placement === 'everywhere');
  if (!section) return home;
  const own = list.filter((b) => b.placement === 'section' && b.section_id === section);
  if (!own.length && fallback) return home;
  return [...own, ...list.filter((b) => b.placement === 'everywhere')];
};
const bannersHtml = (list) => list.length ? `<div class="banners">${list.map((b) => {
  BANNERS.set(String(b.id), b);
  return `<div class="banner" data-banner="${b.id}" style="${b.color ? `background:${esc(b.color)}` : ''}">
        ${b.image ? `<img src="${esc(b.image)}" alt="" loading="lazy">` : ''}
        ${b.title || b.subtitle ? `<div class="txt">${b.title ? `<b>${esc(b.title)}</b>` : ''}${b.subtitle ? `<span>${esc(b.subtitle)}</span>` : ''}</div>` : ''}</div>`;
}).join('')}</div>` : '';
document.addEventListener('click', (e) => {
  const el = e.target.closest?.('[data-banner]');
  if (!el) return;
  const b = BANNERS.get(el.dataset.banner);
  if (b?.store_id) { if (location.hash !== '#/store/' + b.store_id && !location.pathname.endsWith('/store/' + b.store_id)) go('/store/' + b.store_id); }
  else if (b?.url) window.open(b.url, '_blank', 'noopener');
});

const Home = { section: LS.get('home.section', null), type: null, q: '', sort: LS.get('home.sort', ''), openOnly: false };

route('/', async ({ alive }) => {
  const c = await content();
  if (!alive()) return;
  const sections = c.sections || [];
  const fallback = await opt('banners.section_fallback', true);
  const ann = c.announcements || [];
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);

  view.innerHTML = `
    ${C.notice ? `<div class="info">${esc(C.notice)}</div>` : ''}
    ${ios && !standalone && !LS.get('hide.install') ? `<div class="install-hint"><span style="font-size:24px">📲</span>
      <div class="grow small">تبيه كأنه تطبيق؟ اضغط <b>مشاركة</b> تحت في Safari، وبعدها <b>«إضافة إلى الشاشة الرئيسية»</b>.</div>
      <button class="iconbtn" id="hide-install" aria-label="إخفاء">${ICONS.close}</button></div>` : ''}
    ${ann.map((a) => `<a class="announce" ${a.link ? `href="${esc(a.link)}" target="_blank" rel="noopener"` : ''} style="${a.bg_color ? `background:${esc(a.bg_color)};` : ''}${a.text_color ? `color:${esc(a.text_color)}` : ''}">${esc(a.text)}</a>`).join('')}
    <div id="home-banners">${bannersHtml(bannersFor(c, Home.section, fallback))}</div>
    ${sections.length ? `<div class="sections">${sections.map((s) => `<div class="section-tile ${Home.section === s.id ? 'on' : ''}" data-section="${s.id}" style="${s.color ? `background:${esc(s.color)}22` : ''}">
        ${s.image ? `<img src="${esc(s.image)}" alt="">` : `<div class="em">${esc(s.emoji || '🏪')}</div>`}<b>${esc(s.name)}</b></div>`).join('')}</div>` : ''}
    <div class="search">${ICONS.search}<input class="input" id="q" type="search" placeholder="دوّر على متجر أو مطعم" value="${esc(Home.q)}"></div>
    <div class="chips" id="types"></div>
    <div class="toolbar">
      <select class="select" id="sort">
        <option value="">الترتيب: تلقائي</option><option value="nearest">الأقرب</option><option value="rating">الأعلى تقييماً</option>
        <option value="popular">الأكثر طلباً</option><option value="name">بالاسم</option>
      </select>
      <label class="chip ${Home.openOnly ? 'on' : ''}" id="open-only">المفتوح توّا</label>
    </div>
    <div id="stores" class="stores"></div>`;

  $('#sort').value = Home.sort;
  $('#hide-install')?.addEventListener('click', () => { LS.set('hide.install', 1); $('.install-hint').remove(); });

  $$('[data-section]').forEach((el) => el.onclick = () => {
    const id = +el.dataset.section;
    Home.section = Home.section === id ? null : id;
    Home.type = null;
    LS.set('home.section', Home.section);
    $$('[data-section]').forEach((x) => x.classList.toggle('on', +x.dataset.section === Home.section));
    // الإعلانات تتبدّل حسب القسم
    $('#home-banners').innerHTML = bannersHtml(bannersFor(c, Home.section, fallback));
    loadTypes(); loadStores();
  });
  $('#q').addEventListener('input', debounce((e) => { Home.q = e.target.value.trim(); loadStores(); }, 350));
  $('#sort').onchange = (e) => { Home.sort = e.target.value; LS.set('home.sort', Home.sort); loadStores(); };
  $('#open-only').onclick = (e) => { Home.openOnly = !Home.openOnly; e.currentTarget.classList.toggle('on', Home.openOnly); loadStores(); };

  async function loadTypes() {
    try {
      const r = await GET('/store-types' + (Home.section ? '?section=' + Home.section : ''));
      const types = r.data || [];
      $('#types').innerHTML = types.length > 1
        ? `<button class="chip ${!Home.type ? 'on' : ''}" data-type="">الكل</button>` + types.map((t) => `<button class="chip ${Home.type === t.id ? 'on' : ''}" data-type="${t.id}">${esc(t.icon || '')} ${esc(t.name)}</button>`).join('')
        : '';
      $$('[data-type]').forEach((el) => el.onclick = () => {
        Home.type = el.dataset.type ? +el.dataset.type : null;
        $$('[data-type]').forEach((x) => x.classList.toggle('on', (x.dataset.type ? +x.dataset.type : null) === Home.type));
        loadStores();
      });
    } catch {}
  }

  let seq = 0;
  async function loadStores() {
    const my = ++seq;
    const box = $('#stores');
    if (!box) return;
    box.innerHTML = '<div class="skeleton" style="height:190px"></div><div class="skeleton" style="height:190px"></div>';
    const q = new URLSearchParams();
    if (Home.section) q.set('section', Home.section);
    if (Home.type) q.set('type', Home.type);
    if (Home.q) q.set('q', Home.q);
    if (Home.sort) q.set('sort', Home.sort);
    const loc = await myLocation();
    if (loc) { q.set('lat', loc.lat); q.set('lng', loc.lng); }
    try {
      const r = await GET('/stores?' + q.toString());
      if (my !== seq || !alive()) return;
      let stores = r.data || [];
      if (Home.openOnly) stores = stores.filter((s) => s.is_accepting);
      box.innerHTML = stores.length ? stores.map(storeCard).join('')
        : `<div class="empty" style="grid-column:1/-1"><div class="big">🔍</div>ما لقيناش متاجر${Home.q ? ` باسم «${esc(Home.q)}»` : ''}.</div>`;
    } catch (e) {
      if (my === seq) box.innerHTML = `<div class="error" style="grid-column:1/-1">${esc(e.message)}</div>`;
    }
  }

  loadTypes();
  loadStores();
}, { tab: 'home' });

/** موقع الزبون للترتيب بالأقرب: العنوان الافتراضي (بدون ما نطلبو إذن) */
async function myLocation() {
  try {
    const list = await addresses();
    const a = list.find((x) => x.is_default) || list[0];
    return a ? { lat: a.lat, lng: a.lng } : null;
  } catch { return null; }
}
const addresses = () => Cache.get('addresses', async () => (await GET('/addresses')).data || [], 5 * 60000);

// ================= المتجر =================
async function loadStore(id) {
  return Cache.get('store:' + id, () => GET('/stores/' + id), 30000);
}

async function storePage({ params, alive }) {
  const id = +params.id;
  const res = await loadStore(id);
  if (!alive()) return;
  const s = res.data;
  const open = s.is_accepting;
  const products = s.products || [];
  // الصنف ممكن يظهر في أكثر من قسم (مثلاً «شاورما» و«العروض»)
  const secIds = (p) => (p.section_ids && p.section_ids.length) ? p.section_ids : (p.section_id ? [p.section_id] : []);
  const sections = (s.sections || []).map((sec) => ({ ...sec, items: products.filter((p) => secIds(p).includes(sec.id)) })).filter((x) => x.items.length);
  const loose = products.filter((p) => !sections.some((sec) => secIds(p).includes(sec.id)));
  if (loose.length) sections.push({ id: 0, name: sections.length ? 'أصناف أخرى' : 'القائمة', items: loose });

  setTop({ title: s.name, back: true });
  const acts = topActions(`
    <button class="iconbtn" id="fav" aria-label="المفضلة">${s.is_favorite ? ICONS.heartFill : ICONS.heart}</button>
    <button class="iconbtn" id="share" aria-label="مشاركة">${ICONS.share}</button>`);
  $('#fav', acts).onclick = (e) => toggleFav('store', s.id, e.currentTarget);
  $('#share', acts).onclick = () => share(s.name, '/store/' + s.id);

  view.innerHTML = `
    <div class="store-hero"><div class="cover">${s.cover ? `<img src="${esc(s.cover)}" alt="">` : ''}</div></div>
    <div class="store-head">
      <div class="logo">${s.logo ? `<img src="${esc(s.logo)}" alt="">` : '🏪'}</div>
      <h1>${esc(s.name)}</h1>
      <div class="row small muted" style="flex-wrap:wrap;gap:4px 12px">
        ${s.type ? `<span>${esc(s.type)}</span>` : ''}<span>⭐ ${num(s.rating_avg).toFixed(1)} (${s.rating_count || 0})</span>
        ${s.prep_time_minutes ? `<span>⏱ ${s.prep_time_minutes} دقيقة</span>` : ''}
        ${num(s.min_order) > 0 ? `<span>الحد الأدنى ${money(s.min_order)}</span>` : ''}
      </div>
      ${s.description ? `<p class="small muted" style="margin:8px 0 0">${esc(s.description)}</p>` : ''}
      <div class="row" style="margin-top:10px;flex-wrap:wrap">
        <span class="pill ${open ? 'ok' : 'err'}">${open ? 'مفتوح توّا' : 'مغلق حالياً'}</span>
        ${s.phone ? `<a class="btn ghost small" href="tel:${esc(s.phone)}">${ICONS.phone.replace('<svg', '<svg width="16" height="16"')} اتصل</a>` : ''}
        ${s.lat && s.lng ? `<a class="btn ghost small" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${s.lat},${s.lng}">${ICONS.pin.replace('<svg', '<svg width="16" height="16"')} الموقع</a>` : ''}
      </div>
    </div>
    ${!open ? '<div class="info center">المتجر مغلق توّا — تقدر تتصفح بس ما تقدرش تطلب</div>' : ''}
    ${(res.banners || []).length ? `<div style="margin-top:12px">${bannersHtml(res.banners)}</div>` : ''}
    ${(res.announcements || []).map((a) => `<div class="announce" style="margin-top:12px;${a.bg_color ? `background:${esc(a.bg_color)};` : ''}${a.text_color ? `color:${esc(a.text_color)}` : ''}">${esc(a.text)}</div>`).join('')}
    ${(res.ready_carts || []).length ? `<div class="section-title">سلات جاهزة</div><div class="ready-carts">${res.ready_carts.map((c) => `
      <div class="ready-cart" data-ready="${c.id}"><div class="bold">${esc(c.name)}</div>
      ${c.description ? `<div class="tiny muted">${esc(c.description)}</div>` : ''}
      <div class="row between" style="margin-top:6px"><span class="tiny">${c.items_count} أصناف</span><span class="price">${money(c.price)}</span></div></div>`).join('')}</div>` : ''}
    ${sections.length > 1 ? `<div class="menu-nav"><div class="chips">${sections.map((x) => `<button class="chip" data-jump="${x.id}">${esc(x.name)}</button>`).join('')}</div></div>` : ''}
    ${sections.map((x) => `<div class="section-title" id="sec-${x.id}">${esc(x.name)}</div>
      <div class="products">${x.items.map((p) => productCard(p, open)).join('')}</div>`).join('')}
    ${!products.length ? '<div class="empty">ما فيش منتجات</div>' : ''}
    <div id="float-cart"></div>`;

  $$('[data-jump]').forEach((el) => el.onclick = () => {
    const t = document.getElementById('sec-' + el.dataset.jump);
    if (t) window.scrollTo({ top: t.getBoundingClientRect().top + scrollY - 120, behavior: 'smooth' });
  });
  $$('[data-product]').forEach((el) => el.onclick = () => {
    const p = products.find((x) => x.id === +el.dataset.product);
    if (p) productSheet(p, s, () => refreshStoreBits());
  });
  $$('[data-ready]').forEach((el) => el.onclick = async () => {
    if (!open) return toast('المتجر مغلق توّا', 'err');
    try { await applyCart(await GET('/ready-carts/' + el.dataset.ready)); } catch (e) { toast(e.message, 'err'); }
  });

  function refreshStoreBits() {
    $$('[data-product]').forEach((el) => {
      const p = products.find((x) => x.id === +el.dataset.product);
      if (p) { const tmp = document.createElement('div'); tmp.innerHTML = productCard(p, open); el.replaceWith(tmp.firstElementChild); }
    });
    $$('[data-product]').forEach((el) => el.onclick = () => {
      const p = products.find((x) => x.id === +el.dataset.product);
      if (p) productSheet(p, s, () => refreshStoreBits());
    });
    floatCart();
  }
  function floatCart() {
    const box = $('#float-cart');
    if (!box) return;
    box.innerHTML = !Cart.isEmpty && Cart.data.storeId === s.id
      ? `<div class="floating-cart" data-go="/cart">🛒 ${Cart.count} · ${money(Cart.subtotal)} <span>السلة ←</span></div>` : '';
  }
  floatCart();

  if (params.pid) {
    const p = products.find((x) => x.id === +params.pid);
    if (p) productSheet(p, s, () => refreshStoreBits());
    else toast('الصنف هذا مش متوفر توّا', 'err');
  }
}
route('/store/:id', storePage, { tab: 'home', title: ' ' });
route('/store/:id/p/:pid', storePage, { tab: 'home', title: ' ' });
// روابط المشاركة من التطبيق (s/5 و s/5/p/9)
route('/s/:id', storePage, { tab: 'home', title: ' ' });
route('/s/:id/p/:pid', storePage, { tab: 'home', title: ' ' });

/** ورقة الصنف: صور، وصف، إضافات، ملاحظة، كمية — والصنف اللي نفد للتصفح بس */
function productSheet(p, store, onAdded) {
  const sel = {};
  const removed = new Set(); // المكوّنات اللي الزبون شالها
  let qty = 1;
  const inCart = Cart.data.storeId === store.id ? Cart.qtyOf(p.id) : 0;
  const max = maxQty(p) == null ? null : maxQty(p) - inCart;
  const reason = !p.is_available ? 'غير متوفر توّا'
      : !store.is_accepting ? 'المتجر مغلق توّا'
        : (max != null && max < 1) ? 'وصلت للحد المسموح من الصنف هذا' : null;
  const canOrder = !reason;

  // الخيار الإجباري الوحيد (الحجم) يتختار أول واحد متوفر
  for (const o of p.options || []) {
    if (o.type === 'single' && o.is_required) {
      const first = (o.values || []).find((v) => v.is_available);
      if (first) sel[first.id] = 1;
    }
  }
  const baseImages = p.images?.length ? p.images : (p.image ? [p.image] : []);
  const anyVariant = (p.options || []).some((o) => (o.values || []).some((v) => v.image));
  const low = p.left != null;
  // صورة الاختيار: الزبون يختار «أحمر» ← الصورة الأولى تولّي صورة الأحمر
  const variantImage = () => {
    for (const o of p.options || []) for (const v of o.values || []) if (v.image && sel[v.id]) return v.image;
    return null;
  };
  const galleryHtml = () => {
    const vi = variantImage();
    const images = [...(vi ? [vi] : []), ...baseImages.filter((u) => u !== vi)];
    return images.length ? `<div class="gallery"><div class="track">${images.map((u) => `<img src="${esc(u)}" alt="">`).join('')}</div>
      ${images.length > 1 ? `<div class="dots">${images.map((_, i) => `<i class="${i ? '' : 'on'}"></i>`).join('')}</div>` : ''}</div>` : '';
  };
  let shownVariant = null;

  const { el, close } = sheet(`
    <div id="gal">${galleryHtml()}</div>
    <div class="sheet-body ${baseImages.length || anyVariant ? '' : 'no-gallery'}">
      <div class="row between sheet-head" style="align-items:flex-start">
        <h2 style="margin:0;font-size:21px" class="grow">${esc(p.name)}</h2>
        <button class="iconbtn" data-fav>${p.is_favorite ? ICONS.heartFill : ICONS.heart}</button>
        <button class="iconbtn" data-share>${ICONS.share}</button>
      </div>
      <div class="row" style="margin-top:4px"><span class="price" style="font-size:19px">${money(effPrice(p))}</span>
        ${hasDiscount(p) ? `<span class="strike">${num(p.price).toFixed(2)}</span><span class="pill err">وفّر ${(num(p.price) - effPrice(p)).toFixed(2)}</span>` : ''}</div>
      ${p.description ? `<p style="margin:10px 0 0;white-space:pre-line">${esc(p.description)}</p>` : ''}
      <div id="ings"></div>
      <div id="opts"></div>
      ${canOrder ? `<label class="field" style="margin-top:16px"><span>ملاحظة على هذا الصنف</span>
        <textarea class="textarea" id="note" maxlength="200" placeholder="حار، مشوي أكثر..."></textarea></label>` : ''}
      ${canOrder && low ? `<div class="tiny" style="color:var(--warn);font-weight:700">متوفر ${p.left} قطع فقط</div>`
        : canOrder && p.max_per_order ? `<div class="tiny muted">أقصى كمية في الطلب: ${p.max_per_order}</div>` : ''}
    </div>
    <div class="sheet-foot" id="foot"></div>`);

  // نقاط الصور
  function bindGallery() {
    const track = $('.track', el);
    if (track && $$('.track img', el).length > 1) {
      track.addEventListener('scroll', debounce(() => {
        const i = Math.round(Math.abs(track.scrollLeft) / track.clientWidth);
        $$('.dots i', el).forEach((d, k) => d.classList.toggle('on', k === i));
      }, 60));
    }
  }
  bindGallery();
  function drawGallery() {
    const vi = variantImage();
    if (vi === shownVariant) return;
    shownVariant = vi;
    $('#gal', el).innerHTML = galleryHtml();
    bindGallery();
  }
  $('[data-fav]', el).onclick = (e) => toggleFav('product', p.id, e.currentTarget);
  $('[data-share]', el).onclick = () => share(p.name, `/store/${store.id}/p/${p.id}`);

  const missing = () => (p.options || []).find((o) => o.is_required && !(o.values || []).some((v) => sel[v.id]))?.name;
  const unit = () => effPrice(p) + Object.entries(sel).reduce((t, [id, n]) => t + num(findValue(p, +id)?.extra_price) * n, 0);

  function drawOptions() {
    $('#opts', el).innerHTML = (p.options || []).map((o) => {
      const chosen = (o.values || []).filter((v) => sel[v.id]).length;
      const limit = o.type === 'single' ? 1 : Math.max(1, o.max_choices || 1);
      const full = o.type !== 'single' && chosen >= limit;
      const tag = o.is_required ? '<span class="pill err">إجباري</span>'
        : `<span class="pill">${o.type === 'single' ? 'اختياري' : `اختياري · لحد ${limit}`}</span>`;
      const swatches = o.type === 'single' && (o.values || []).some((v) => v.image);
      const rows = (o.values || []).map((v) => {
        const n = sel[v.id] || 0;
        const can = canOrder && v.is_available;
        const pr = !v.is_available ? '<span class="tiny muted">مش متوفر</span>'
          : num(v.extra_price) <= 0 ? '<span class="pr free">مجاناً</span>' : `<span class="pr">+${num(v.extra_price).toFixed(2)} د.ل</span>`;
        const th = v.image ? `<img class="thumb" src="${esc(v.image)}" alt="">` : '';
        // وحدة بس وفيها صور (الألوان): مربعات بالصور
        if (o.type === 'single' && swatches) {
          return `<div class="swatch ${n ? 'on' : ''} ${can ? '' : 'disabled'}" data-single="${o.id}" data-v="${v.id}">
            ${v.image ? `<img src="${esc(v.image)}" alt="">` : '<span class="noimg">🖼️</span>'}<span class="nm">${esc(v.name)}</span>${num(v.extra_price) > 0 ? `<span class="pr">+${num(v.extra_price).toFixed(2)}</span>` : ''}</div>`;
        }
        if (o.type === 'single') {
          return `<div class="opt ${n ? 'on' : ''} ${can ? '' : 'disabled'}" data-single="${o.id}" data-v="${v.id}"><span class="mark round"></span>${th}<span class="nm">${esc(v.name)}</span>${pr}</div>`;
        }
        if ((v.max_qty || 1) <= 1) {
          const dis = !can || (!n && full);
          return `<div class="opt ${n ? 'on' : ''} ${dis ? 'disabled' : ''}" data-check="${v.id}"><span class="mark"></span>${th}<span class="nm">${esc(v.name)}</span>${pr}</div>`;
        }
        return `<div class="opt ${can ? '' : 'disabled'}" style="cursor:default"><span class="nm">${esc(v.name)}<div class="tiny muted">لحد ${v.max_qty}</div></span>${pr}
          <div class="stepper sm"><button data-dec="${v.id}" ${!can || !n ? 'disabled' : ''}>−</button><span>${n}</span>
          <button data-inc="${v.id}" data-max="${v.max_qty}" ${!can || n >= v.max_qty || (!n && full) ? 'disabled' : ''}>+</button></div></div>`;
      }).join('');
      return `<div class="opt-group"><div class="head"><b>${esc(o.name)}</b>${tag}</div>${swatches ? `<div class="swatches">${rows}</div>` : rows}</div>`;
    }).join('');
    drawGallery();

    $$('[data-single]', el).forEach((x) => x.onclick = () => {
      if (x.classList.contains('disabled')) return;
      const o = p.options.find((k) => k.id === +x.dataset.single);
      const vid = +x.dataset.v;
      const was = !!sel[vid];
      o.values.forEach((v) => delete sel[v.id]);
      if (!was || o.is_required) sel[vid] = 1;
      drawOptions(); drawFoot();
    });
    $$('[data-check]', el).forEach((x) => x.onclick = () => {
      if (x.classList.contains('disabled')) return;
      const vid = +x.dataset.check;
      if (sel[vid]) delete sel[vid]; else sel[vid] = 1;
      drawOptions(); drawFoot();
    });
    $$('[data-inc]', el).forEach((b) => b.onclick = () => { const v = +b.dataset.inc; sel[v] = (sel[v] || 0) + 1; drawOptions(); drawFoot(); });
    $$('[data-dec]', el).forEach((b) => b.onclick = () => { const v = +b.dataset.dec; if ((sel[v] || 0) <= 1) delete sel[v]; else sel[v]--; drawOptions(); drawFoot(); });
  }

  // المكوّنات: اللي ينشال يتضغط ويولّي «بدون ...»
  function drawIngredients() {
    const ings = p.ingredients || [];
    const box = $('#ings', el);
    if (!ings.length) { box.innerHTML = ''; return; }
    const any = canOrder && ings.some((i) => i.removable);
    box.innerHTML = `<div class="opt-group"><div class="head"><b>المكوّنات</b></div>
      ${any ? '<div class="tiny muted" style="margin:-4px 0 8px">اضغط على أي مكوّن تبيه يتشال</div>' : ''}
      <div class="ings">${ings.map((i) => {
        const can = canOrder && i.removable;
        const off = removed.has(i.name);
        return `<button type="button" class="ing ${off ? 'off' : ''} ${can ? '' : 'fixed'}" ${can ? `data-ing="${esc(i.name)}"` : 'disabled'}>${off ? '⊖ بدون ' : can ? '✓ ' : ''}${esc(i.name)}</button>`;
      }).join('')}</div>
      ${removed.size ? `<div class="tiny" style="color:var(--err);font-weight:700;margin-top:6px">بدون: ${esc([...removed].join('، '))}</div>` : ''}</div>`;
    $$('[data-ing]', box).forEach((b) => b.onclick = () => {
      const n = b.dataset.ing;
      if (removed.has(n)) removed.delete(n); else removed.add(n);
      drawIngredients();
    });
  }

  function drawFoot() {
    const foot = $('#foot', el);
    if (!canOrder) { foot.innerHTML = `<button class="btn block" disabled>🚫 ${esc(reason)}</button>`; return; }
    const miss = missing();
    foot.innerHTML = `${miss ? `<div class="tiny" style="color:var(--err);margin-bottom:6px">اختار «${esc(miss)}» أول</div>` : ''}
      <div class="row"><div class="stepper"><button id="dq" ${qty <= 1 ? 'disabled' : ''}>−</button><span>${qty}</span><button id="iq" ${max != null && qty >= max ? 'disabled' : ''}>+</button></div>
      <button class="btn grow" id="add" ${miss ? 'disabled' : ''}>أضف للسلة · ${money(unit() * qty)}</button></div>`;
    $('#dq', foot).onclick = () => { qty = Math.max(1, qty - 1); drawFoot(); };
    $('#iq', foot).onclick = () => { qty++; drawFoot(); };
    $('#add', foot).onclick = async () => {
      if (!Cart.isEmpty && Cart.data.storeId !== store.id) {
        const ok = await confirmBox('سلة من متجر ثاني', `عندك أصناف من «${Cart.data.storeName}». الطلب الواحد من متجر واحد فقط.\n\nتبي نفرّغ السلة ونبدا من جديد؟`, 'فرّغ وابدا', 'رجوع');
        if (!ok) return;
      }
      Cart.add(p, store.id, store.name, qty, $('#note', el)?.value || '', { ...sel }, [...removed]);
      close();
      toast('انضاف للسلة ✅', 'ok');
      onAdded && onAdded();
    };
  }
  drawIngredients();
  drawOptions();
  drawFoot();
}

// ================= السلة والدفع =================
const Checkout = { addressId: LS.get('addr', null), pay: 'cash', useWallet: false, usePoints: false, coupon: '', notes: '' };

route('/cart', async ({ alive }) => {
  if (Cart.isEmpty) {
    const savedOn = await opt('carts.saved_enabled', true);
    view.innerHTML = `<div class="empty"><div class="big">🛒</div><p>السلة فاضية</p>
      <button class="btn" data-go="/">تصفّح المتاجر</button>
      ${savedOn ? '<div style="margin-top:10px"><button class="btn ghost" data-go="/saved">سلاتي المحفوظة</button></div>' : ''}</div>`;
    return;
  }
  setTop({ title: 'السلة — ' + Cart.data.storeName, back: true });
  const savedOn = await opt('carts.saved_enabled', true);
  if (savedOn) {
    const a = topActions('<button class="btn ghost small" id="save-cart">💾 احفظ</button>');
    $('#save-cart', a).onclick = saveCartDialog;
  }

  let list = await addresses().catch(() => []);
  if (!alive()) return;
  if (!list.some((a) => a.id === Checkout.addressId)) Checkout.addressId = (list.find((a) => a.is_default) || list[0])?.id ?? null;

  const cartBanners = await content().then((c) => allBanners(c).filter((b) => b.placement === 'cart')).catch(() => []);
  if (!alive()) return;
  view.innerHTML = `
    ${bannersHtml(cartBanners)}
    <div class="card" id="lines"></div>
    <div class="card">
      <div class="row between"><b>عنوان التوصيل</b><button class="linkbtn" data-go="/addresses/new?back=cart">+ عنوان جديد</button></div>
      <div id="addr" style="margin-top:8px"></div>
    </div>
    <div class="card">
      <b>طريقة الدفع</b>
      <div style="margin-top:8px" id="pay"></div>
      <div id="extras"></div>
    </div>
    <div class="card">
      <b>كوبون خصم</b>
      <div class="row" style="margin-top:8px"><input class="input grow" id="coupon" placeholder="اكتب الكود" value="${esc(Checkout.coupon)}" style="text-transform:uppercase">
        <button class="btn outline small" id="apply-coupon" style="min-height:46px">تطبيق</button></div>
      <div id="coupon-msg"></div>
      <label class="field" style="margin:12px 0 0"><span>ملاحظة للطلب (اختياري)</span>
        <textarea class="textarea" id="notes" maxlength="500" placeholder="مثلاً: اتصل قبل ما توصل">${esc(Checkout.notes)}</textarea></label>
    </div>
    <div class="card" id="summary"><div class="loading" style="padding:20px"><span class="spinner"></span></div></div>
    <div class="checkout-bar"><div class="inner"><button class="btn block" id="place" disabled>تأكيد الطلب</button></div></div>`;

  let quote = null;

  function drawLines() {
    $('#lines').innerHTML = Cart.lines.map((l, i) => {
      const t = optionsText(l.product, l.options, l.removed);
      const mx = maxQty(l.product);
      return `<div class="cart-line">
        <div class="grow">
          <div class="bold">${esc(l.product.name)}</div>
          ${t ? `<div class="opts">${esc(t)}</div>` : ''}
          ${l.note ? `<div class="note">ملاحظة: ${esc(l.note)}</div>` : ''}
          <div class="tiny muted">${money(Cart.unit(l))} للواحدة</div>
          <button class="linkbtn tiny" data-note="${i}">${l.note ? 'تعديل الملاحظة' : '+ ضيف ملاحظة'}</button>
        </div>
        <div style="text-align:left">
          <div class="bold">${money(Cart.total(l))}</div>
          <div class="stepper sm" style="margin-top:6px"><button data-minus="${i}">−</button><span>${l.qty}</span>
          <button data-plus="${i}" ${mx != null && Cart.qtyOf(l.product.id) >= mx ? 'disabled' : ''}>+</button></div>
        </div></div>`;
    }).join('') + `<div style="margin-top:8px"><button class="linkbtn" data-go="/store/${Cart.data.storeId}">+ زيد أصناف من ${esc(Cart.data.storeName)}</button></div>`;
    $$('[data-minus]').forEach((b) => b.onclick = () => { const l = Cart.lines[+b.dataset.minus]; Cart.setQty(l, l.qty - 1); after(); });
    $$('[data-plus]').forEach((b) => b.onclick = () => { const l = Cart.lines[+b.dataset.plus]; Cart.setQty(l, l.qty + 1); after(); });
    $$('[data-note]').forEach((b) => b.onclick = async () => {
      const l = Cart.lines[+b.dataset.note];
      const v = await promptBox(l.product.name, { label: 'ملاحظة للمتجر', value: l.note || '', placeholder: 'بدون بصل، حار...', multiline: true });
      if (v === null) return;
      l.note = v.trim(); Cart.save(); drawLines();
    });
  }
  function after() { if (Cart.isEmpty) return render(); drawLines(); requote(); }

  function drawAddr() {
    const box = $('#addr');
    if (!list.length) {
      box.innerHTML = `<div class="info">ما عندكش عنوان محفوظ. ضيف عنوانك على الخريطة باش نعرفو وين نوصلو.</div>
        <button class="btn outline block" data-go="/addresses/new?back=cart">📍 ضيف عنوان</button>`;
      return;
    }
    box.innerHTML = list.map((a) => `<label class="radio ${a.id === Checkout.addressId ? 'on' : ''}"><input type="radio" name="addr" value="${a.id}" ${a.id === Checkout.addressId ? 'checked' : ''}>
      <div class="grow"><b>${esc(a.label || 'عنوان')}</b><div class="tiny muted">${esc(a.details)}${a.landmark ? ' — ' + esc(a.landmark) : ''}</div></div></label>`).join('');
    $$('input[name=addr]').forEach((r) => r.onchange = () => { Checkout.addressId = +r.value; LS.set('addr', Checkout.addressId); drawAddr(); requote(); });
  }

  function drawPay() {
    const bal = num(quote?.wallet_balance);
    const opts = [['cash', '💵 نقداً عند الاستلام', '']];
    if (bal > 0) opts.push(['wallet', '👛 من المحفظة', `رصيدك ${money(bal)}`]);
    if (Checkout.pay === 'wallet' && bal <= 0) Checkout.pay = 'cash';
    $('#pay').innerHTML = opts.map(([k, t, sub]) => `<label class="radio ${Checkout.pay === k ? 'on' : ''}"><input type="radio" name="pay" value="${k}" ${Checkout.pay === k ? 'checked' : ''}>
      <div class="grow">${t}${sub ? `<div class="tiny muted">${sub}</div>` : ''}</div></label>`).join('');
    $$('input[name=pay]').forEach((r) => r.onchange = () => { Checkout.pay = r.value; drawPay(); requote(); });

    let extras = '';
    if (Checkout.pay === 'cash' && bal > 0) {
      extras += `<label class="switch"><span>استعمل رصيد المحفظة (${money(bal)}) والباقي نقداً</span><input type="checkbox" id="use-wallet" ${Checkout.useWallet ? 'checked' : ''}></label>`;
    }
    if (num(quote?.points_available) > 0) {
      extras += `<label class="switch"><span>استعمل نقاطي (${quote.points_available} نقطة = خصم ${money(quote.points_available_discount)})</span><input type="checkbox" id="use-points" ${Checkout.usePoints ? 'checked' : ''}></label>`;
    }
    $('#extras').innerHTML = extras;
    $('#use-wallet') && ($('#use-wallet').onchange = (e) => { Checkout.useWallet = e.target.checked; requote(); });
    $('#use-points') && ($('#use-points').onchange = (e) => { Checkout.usePoints = e.target.checked; requote(); });
  }

  function body() {
    return {
      store_id: Cart.data.storeId,
      address_id: Checkout.addressId,
      payment_method: Checkout.pay,
      use_wallet: Checkout.pay === 'wallet' || Checkout.useWallet,
      use_points: Checkout.usePoints,
      ...(Checkout.coupon ? { coupon_code: Checkout.coupon } : {}),
      items: Cart.apiItems(),
    };
  }

  let qseq = 0;
  async function requote() {
    const my = ++qseq;
    const sum = $('#summary');
    const place = $('#place');
    place.disabled = true;
    if (!Checkout.addressId) {
      sum.innerHTML = `<div class="sum-row"><span>مجموع الأصناف</span><span>${money(Cart.subtotal)}</span></div><div class="tiny muted">اختار العنوان باش نحسبو التوصيل.</div>`;
      return;
    }
    sum.innerHTML = '<div class="loading" style="padding:20px"><span class="spinner"></span></div>';
    try {
      const q = await POST('/orders/quote', body());
      if (my !== qseq) return;
      quote = q;
      drawPay();
      $('#coupon-msg').innerHTML = q.coupon_error ? `<div class="error">${esc(q.coupon_error)}</div>`
        : (Checkout.coupon && num(q.discount) > 0 ? `<div class="success">تم تطبيق الكوبون ✅ خصم ${money(q.discount)}</div>` : '');
      sum.innerHTML = `
        <div class="sum-row"><span>مجموع الأصناف</span><span>${money(q.subtotal)}</span></div>
        <div class="sum-row"><span>رسوم التوصيل ${num(q.distance_km) ? `<span class="tiny muted">(${num(q.distance_km).toFixed(1)} كم)</span>` : ''}</span><span>${money(q.delivery_fee)}</span></div>
        ${num(q.discount) > 0 ? `<div class="sum-row minus"><span>خصم الكوبون</span><span>− ${money(q.discount)}</span></div>` : ''}
        ${num(q.points_discount) > 0 ? `<div class="sum-row minus"><span>خصم النقاط</span><span>− ${money(q.points_discount)}</span></div>` : ''}
        <div class="sum-row total"><span>الإجمالي</span><span>${money(q.total)}</span></div>
        ${num(q.wallet_paid) > 0 ? `<div class="sum-row minus"><span>من المحفظة</span><span>− ${money(q.wallet_paid)}</span></div>
          <div class="sum-row bold"><span>${num(q.cash_due) > 0 ? 'تدفع نقداً للسائق' : 'مدفوع بالكامل'}</span><span>${money(q.cash_due)}</span></div>` : ''}
        ${q.below_min_order ? `<div class="info">الحد الأدنى للطلب من المتجر هذا ${money(q.min_order)}. زيد أصناف بـ ${money(num(q.min_order) - num(q.subtotal))}.</div>` : ''}`;
      place.disabled = !!q.below_min_order;
      place.textContent = `تأكيد الطلب · ${money(q.total)}`;
    } catch (e) {
      if (my !== qseq) return;
      sum.innerHTML = `<div class="error">${esc(e.message)}</div>`;
    }
  }

  $('#apply-coupon').onclick = () => { Checkout.coupon = $('#coupon').value.trim().toUpperCase(); requote(); };
  $('#notes').oninput = (e) => { Checkout.notes = e.target.value; };
  $('#place').onclick = (e) => busy(e.currentTarget, async () => {
    try {
      const r = await POST('/orders', { ...body(), ...(Checkout.notes.trim() ? { notes: Checkout.notes.trim() } : {}) });
      Cart.clear();
      Checkout.coupon = ''; Checkout.notes = ''; Checkout.usePoints = false;
      Cache.drop('orders');
      toast('وصل طلبك للمتجر ✅', 'ok');
      go('/orders/' + r.data.id, true);
    } catch (err) { toast(err.message, 'err'); requote(); }
  });

  drawLines();
  drawAddr();
  drawPay();
  requote();
}, { tab: 'cart', title: 'السلة' });

async function saveCartDialog() {
  const name = await promptBox('احفظ السلة', { label: 'اسم السلة', value: Cart.data.storeName, placeholder: 'مثلاً: عشاء الجمعة' });
  if (name === null) return;
  if (!name.trim()) return toast('اكتب اسم للسلة', 'err');
  try {
    await POST('/saved-carts', { name: name.trim(), store_id: Cart.data.storeId, items: Cart.apiItems() });
    toast('تم حفظ السلة', 'ok');
  } catch (e) { toast(e.message, 'err'); }
}

// ================= طلباتي =================
route('/orders', async ({ alive }) => {
  const r = await GET('/orders');
  if (!alive()) return;
  const list = r.data || [];
  view.innerHTML = list.length ? list.map((o) => `
    <div class="card order-card" data-go="/orders/${o.id}">
      <div class="row between"><b>${esc(o.store?.name || '')}</b>${statusPill(o)}</div>
      <div class="row between small muted" style="margin-top:4px"><span>#${esc(o.code)} · ${fmtDate(o.created_at)}</span><b style="color:var(--text)">${money(o.total)}</b></div>
      <div class="tiny muted" style="margin-top:2px">${(o.items || []).map((i) => `${i.quantity}× ${esc(i.name)}`).join('، ')}</div>
    </div>`).join('')
    : '<div class="empty"><div class="big">🧾</div><p>ما عندكش طلبات لسه</p><button class="btn" data-go="/">اطلب توّا</button></div>';
}, { tab: 'orders', title: 'طلباتي', back: false });

route('/orders/:id', async ({ params, alive, onLeave }) => {
  const supportOn = await opt('support.enabled', true);
  const id = +params.id;
  let order = null;
  let map = null; let markers = {};
  let timers = [];
  onLeave(() => { timers.forEach(clearInterval); timers = []; if (map) { map.remove(); map = null; } });

  async function load() {
    const r = await GET('/orders/' + id);
    if (!alive()) return;
    order = r.data;
    draw();
  }

  function draw() {
    const o = order;
    const final = o.is_final;
    const idx = STATUS_STEPS.findIndex(([k]) => k === o.status);
    const cancelled = ['cancelled', 'failed'].includes(o.status);
    const canCancel = o.status === 'pending';
    const cash = num(o.cash_to_collect);
    const timeline = cancelled ? '' : `<ul class="timeline">${STATUS_STEPS.map(([k, label], i) => {
      const cls = i < idx ? 'done' : i === idx ? (k === 'delivered' ? 'done' : 'now') : '';
      return `<li class="${cls}"><span class="dot">${i < idx || k === o.status && k === 'delivered' ? '✓' : ''}</span><span>${label}</span></li>`;
    }).join('')}</ul>`;

    view.innerHTML = `
      <div class="card">
        <div class="row between"><div><b style="font-size:17px">${esc(o.store?.name || '')}</b><div class="tiny muted">#${esc(o.code)} · ${fmtDate(o.created_at)}</div></div>${statusPill(o)}</div>
        ${o.minutes_until_ready ? `<div class="info" style="margin-bottom:0">جاهز تقريباً خلال ${o.minutes_until_ready} دقيقة</div>` : ''}
        ${o.under_review ? '<div class="info" style="margin-bottom:0">طلبك قيد مراجعة الإدارة — نتواصلو معاك قريب.</div>' : ''}
        ${timeline}
      </div>
      ${o.awaiting_customer ? `<div class="card" style="border:2px solid #FDBA74;background:#FFF7ED">
        <b>بعض الأصناف مش متوفرة عند المتجر</b>
        <ul class="small" style="margin:6px 0">${(o.items || []).filter((i) => i.is_unavailable).map((i) => `<li>${i.quantity}× ${esc(i.name)}</li>`).join('')}</ul>
        <div class="tiny muted">لو ما رديتش خلال المهلة، الطلب يكمّل بدونها.</div>
        <div class="stack" style="margin-top:10px">
          <button class="btn block" data-sub="continue">كمّل بدونها</button>
          <button class="btn outline block" data-sub="edit">عدّل الطلب</button>
          <button class="btn danger block" data-sub="cancel">إلغاء الطلب</button>
        </div></div>` : ''}
      ${!final && (o.driver || o.status === 'on_the_way' || o.status === 'picked_up') ? '<div class="card" style="padding:0;overflow:hidden"><div class="map" id="map"></div></div>' : ''}
      ${o.driver ? `<div class="card"><div class="row"><div style="font-size:30px">🛵</div><div class="grow"><b>${esc(o.driver.name)}</b>
        <div class="tiny muted">⭐ ${num(o.driver.rating_avg).toFixed(1)} · ${o.driver.delivered || 0} توصيلة</div></div>
        ${o.driver.phone ? `<a class="btn ghost small" href="tel:${esc(o.driver.phone)}">اتصل</a>` : ''}</div></div>` : ''}
      <div class="card">
        <b>الأصناف</b>
        ${(o.items || []).map((i) => `<div class="cart-line" style="${i.is_unavailable ? 'opacity:.55' : ''}"><div class="grow">
          <div>${i.quantity}× ${esc(i.name)} ${i.is_unavailable ? '<span class="pill err">مش متوفر</span>' : ''}</div>
          ${i.options_text ? `<div class="opts">${esc(i.options_text)}</div>` : ''}
          ${i.note ? `<div class="note">ملاحظة: ${esc(i.note)}</div>` : ''}</div>
          <div class="bold" style="${i.is_unavailable ? 'text-decoration:line-through' : ''}">${money(i.line_total)}</div></div>`).join('')}
        <div class="divider"></div>
        <div class="sum-row"><span>مجموع الأصناف</span><span>${money(o.subtotal)}</span></div>
        <div class="sum-row"><span>رسوم التوصيل</span><span>${money(o.delivery_fee)}</span></div>
        ${num(o.discount) > 0 ? `<div class="sum-row minus"><span>خصم الكوبون</span><span>− ${money(o.discount)}</span></div>` : ''}
        ${num(o.points_discount) > 0 ? `<div class="sum-row minus"><span>خصم النقاط</span><span>− ${money(o.points_discount)}</span></div>` : ''}
        <div class="sum-row total"><span>الإجمالي</span><span>${money(o.total)}</span></div>
        ${num(o.wallet_paid) > 0 ? `<div class="sum-row minus"><span>مدفوع من المحفظة</span><span>− ${money(o.wallet_paid)}</span></div>` : ''}
        ${!cancelled ? (cash > 0 ? `<div class="sum-row bold" style="color:var(--warn)"><span>${o.status === 'delivered' ? 'دفعت نقداً' : 'تدفع نقداً للسائق'}</span><span>${money(cash)}</span></div>`
          : '<div class="success">الطلب مدفوع بالكامل — ما تدفعش شي للسائق</div>') : ''}
      </div>
      <div class="card small"><b>عنوان التوصيل</b><div class="muted">${esc(o.address?.details || '')}${o.address?.landmark ? ' — ' + esc(o.address.landmark) : ''}</div>
        ${o.notes ? `<div style="margin-top:6px"><b>ملاحظتك:</b> ${esc(o.notes)}</div>` : ''}</div>
      ${o.status === 'delivered' && !o.is_rated ? `<div class="card" id="rate">
        <b>قيّم طلبك</b>
        <div class="small muted" style="margin-top:8px">المتجر</div><div class="stars" data-stars="store">${[1, 2, 3, 4, 5].map((n) => `<button data-n="${n}">★</button>`).join('')}</div>
        ${o.driver ? `<div class="small muted" style="margin-top:8px">السائق</div><div class="stars" data-stars="driver">${[1, 2, 3, 4, 5].map((n) => `<button data-n="${n}">★</button>`).join('')}</div>` : ''}
        <textarea class="textarea" id="rate-comment" placeholder="تعليق (اختياري)" style="margin-top:10px" maxlength="400"></textarea>
        <button class="btn block" id="send-rate" style="margin-top:10px">إرسال التقييم</button></div>` : ''}
      <div class="stack">
        ${final ? '<button class="btn outline block" id="reorder">🔁 اطلب نفس الطلب</button>' : ''}
        ${canCancel ? '<button class="btn danger block" id="cancel">إلغاء الطلب</button>' : ''}
        ${supportOn ? `<button class="btn ghost block" data-go="/support/new?order=${o.id}&code=${encodeURIComponent(o.code)}">🎧 مشكلة في الطلب؟ تواصل مع الدعم</button>` : ''}
      </div>`;

    // الردود على الأصناف الناقصة
    $$('[data-sub]').forEach((b) => b.onclick = () => busy(b, async () => {
      const action = b.dataset.sub;
      if (action === 'cancel' && !(await confirmBox('إلغاء الطلب؟', 'متأكد تبي تلغي الطلب كامل؟', 'إلغاء الطلب', 'رجوع', true))) return;
      try {
        const r = await POST(`/orders/${id}/substitution`, { action });
        if (action === 'edit' && r.cart) { await applyCart(r.cart); return; }
        order = r.data; draw();
      } catch (e) { toast(e.message, 'err'); }
    }));

    // التقييم
    const rating = { store: 0, driver: 0 };
    $$('[data-stars]').forEach((box) => $$('button', box).forEach((b) => b.onclick = () => {
      rating[box.dataset.stars] = +b.dataset.n;
      $$('button', box).forEach((x) => x.classList.toggle('on', +x.dataset.n <= rating[box.dataset.stars]));
    }));
    $('#send-rate') && ($('#send-rate').onclick = (e) => busy(e.currentTarget, async () => {
      if (!rating.store && !rating.driver) return toast('اختار عدد النجوم', 'err');
      try {
        await POST(`/orders/${id}/rate`, {
          ...(rating.store ? { store_rating: rating.store } : {}),
          ...(rating.driver ? { driver_rating: rating.driver } : {}),
          ...($('#rate-comment').value.trim() ? { comment: $('#rate-comment').value.trim() } : {}),
        });
        toast('شكراً على تقييمك 🌟', 'ok');
        order.is_rated = true; draw();
      } catch (err) { toast(err.message, 'err'); }
    }));

    $('#reorder') && ($('#reorder').onclick = (e) => busy(e.currentTarget, async () => {
      try { await applyCart(await POST(`/orders/${id}/reorder`)); } catch (err) { toast(err.message, 'err'); }
    }));
    $('#cancel') && ($('#cancel').onclick = async (e) => {
      if (!(await confirmBox('إلغاء الطلب؟', 'متأكد تبي تلغي طلبك؟', 'إلغاء الطلب', 'رجوع', true))) return;
      busy(e.target, async () => {
        try { const r = await POST(`/orders/${id}/cancel`); order = r.data || order; toast('انلغى الطلب'); await load(); } catch (err) { toast(err.message, 'err'); }
      });
    });

    if ($('#map')) drawMap(); else if (map) { map.remove(); map = null; }
  }

  async function drawMap() {
    try {
      const L = await leaflet();
      const el = $('#map');
      if (!el || !alive()) return;
      if (map) { map.remove(); map = null; }
      markers = {};
      map = L.map(el, { zoomControl: false, attributionControl: false });
      tiles(L, map);
      await track(L);
    } catch {}
  }

  async function track(L) {
    L = L || window.L;
    if (!map || !L) return;
    try {
      const t = await GET(`/orders/${id}/track`);
      const pts = [];
      const put = (key, p, e) => {
        if (!p || p.lat == null) return;
        const ll = [+p.lat, +p.lng];
        pts.push(ll);
        if (markers[key]) markers[key].setLatLng(ll); else markers[key] = L.marker(ll, { icon: emojiIcon(L, e) }).addTo(map);
      };
      put('store', t.store_location, '🏪');
      put('home', t.destination, '🏠');
      put('driver', t.driver_location, '🛵');
      if (!track.fitted && pts.length) { map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 }); track.fitted = true; }
      if (t.status !== order.status) load();
    } catch {}
  }
  track.fitted = false;

  await load();
  const secs = Math.max(3, num(await opt('tracking.refresh_seconds', 5)));
  timers.push(setInterval(() => { if (order && !order.is_final && document.visibilityState === 'visible') track(); }, secs * 1000));
  timers.push(setInterval(() => { if (order && !order.is_final && document.visibilityState === 'visible') load().catch(() => {}); }, 20000));
}, { tab: 'orders', title: 'تفاصيل الطلب' });

// ================= العناوين =================
route('/addresses', async ({ alive }) => {
  Cache.drop('addresses');
  const list = await addresses();
  if (!alive()) return;
  view.innerHTML = `${list.map((a) => `<div class="card">
      <div class="row between"><b>${esc(a.label || 'عنوان')} ${a.is_default ? '<span class="pill ok">الافتراضي</span>' : ''}</b></div>
      <div class="small muted">${esc(a.details)}${a.landmark ? ' — ' + esc(a.landmark) : ''}</div>
      <div class="row" style="margin-top:8px">${!a.is_default ? `<button class="btn ghost small" data-def="${a.id}">اجعله الافتراضي</button>` : ''}
      <button class="btn danger small" data-del="${a.id}">حذف</button></div></div>`).join('')}
    ${!list.length ? '<div class="empty"><div class="big">📍</div><p>ما عندكش عناوين محفوظة</p></div>' : ''}
    <button class="btn block" data-go="/addresses/new" style="margin-top:12px">📍 ضيف عنوان على الخريطة</button>`;
  $$('[data-def]').forEach((b) => b.onclick = () => busy(b, async () => {
    try { await POST(`/addresses/${b.dataset.def}/default`); render(); } catch (e) { toast(e.message, 'err'); }
  }));
  $$('[data-del]').forEach((b) => b.onclick = async () => {
    if (!(await confirmBox('حذف العنوان؟', 'متأكد؟', 'حذف', 'رجوع', true))) return;
    try { await DEL('/addresses/' + b.dataset.del); render(); } catch (e) { toast(e.message, 'err'); }
  });
}, { tab: 'account', title: 'عناويني' });

route('/addresses/new', async ({ query, alive, onLeave }) => {
  view.innerHTML = `
    <div class="card" style="padding:0;overflow:hidden;position:relative"><div class="map tall" id="pick"></div><div class="pin-center">📍</div>
      <button class="btn ghost small" id="locate" style="position:absolute;bottom:12px;right:12px;z-index:500;background:#fff">🎯 موقعي</button></div>
    <div id="cover" class="small" style="margin:8px 2px"></div>
    <div class="card">
      <div class="chips" id="labels">${['البيت', 'الشغل', 'أخرى'].map((l, i) => `<button class="chip ${i ? '' : 'on'}" data-label="${l}">${l}</button>`).join('')}</div>
      <label class="field"><span>تفاصيل العنوان *</span><input class="input" id="details" maxlength="255" placeholder="الحي، الشارع، رقم أو لون البيت"></label>
      <label class="field"><span>علامة مميزة (اختياري)</span><input class="input" id="landmark" maxlength="255" placeholder="قريب من الجامع، جنب الصيدلية..."></label>
      <button class="btn block" id="save">حفظ العنوان</button>
    </div>`;
  let label = 'البيت';
  $$('[data-label]').forEach((b) => b.onclick = () => { label = b.dataset.label; $$('[data-label]').forEach((x) => x.classList.toggle('on', x === b)); });

  // الموقع المختار: من الخريطة، أو من GPS لو الخريطة ما تحمّلتش
  let center = { lat: NALUT[0], lng: NALUT[1] };
  let map = null;
  let covered = null;
  const check = debounce(async () => {
    try {
      const r = await GET(`/coverage?lat=${center.lat.toFixed(6)}&lng=${center.lng.toFixed(6)}`);
      covered = r.covered;
      $('#cover').innerHTML = r.covered ? `<span class="pill ok">✓ داخل منطقة التوصيل${r.zone ? ' — ' + esc(r.zone) : ''}</span>`
        : `<span class="pill err">${esc(r.message || 'المكان هذا برّا مناطق التوصيل')}</span>`;
    } catch {}
  }, 400);

  try {
    const L = await leaflet();
    if (!alive()) return;
    map = L.map('pick', { zoomControl: true, attributionControl: false }).setView(NALUT, 15);
    onLeave(() => map && map.remove());
    tiles(L, map);
    map.on('moveend', () => { const c = map.getCenter(); center = { lat: c.lat, lng: c.lng }; check(); });
  } catch {
    $('#pick').innerHTML = '<div class="center small muted" style="padding:40px 16px">الخريطة ما تحمّلتش. اضغط «🎯 موقعي» وانت في البيت باش ناخذو مكانك.</div>';
    $('.pin-center')?.remove();
  }
  check();

  const locate = () => {
    if (!navigator.geolocation) return toast('المتصفح ما يدعمش تحديد الموقع', 'err');
    navigator.geolocation.getCurrentPosition(
      (p) => {
        center = { lat: p.coords.latitude, lng: p.coords.longitude };
        if (map) map.setView([center.lat, center.lng], 17); else { check(); toast('تم تحديد موقعك ✅', 'ok'); }
      },
      () => toast('ما قدرناش نحدد موقعك — حرّك الخريطة للمكان بإيدك', 'err'),
      { enableHighAccuracy: true, timeout: 10000 },
    );
  };
  $('#locate').onclick = locate;
  locate();

  $('#save').onclick = (e) => busy(e.currentTarget, async () => {
    const details = $('#details').value.trim();
    if (!details) return toast('اكتب تفاصيل العنوان', 'err');
    if (covered === false) return toast('المكان هذا برّا مناطق التوصيل', 'err');
    const c = center;
    try {
      const r = await POST('/addresses', { label, details, landmark: $('#landmark').value.trim() || null, lat: +c.lat.toFixed(7), lng: +c.lng.toFixed(7) });
      Cache.drop('addresses');
      if (r.data?.id) { Checkout.addressId = r.data.id; LS.set('addr', r.data.id); }
      toast('تم حفظ العنوان ✅', 'ok');
      go(query.back === 'cart' ? '/cart' : '/addresses', true);
    } catch (err) { toast(err.message, 'err'); }
  });
}, { tab: 'account', title: 'عنوان جديد' });

// ================= المحفظة والنقاط =================
route('/wallet', async ({ alive }) => {
  const [w, tx] = await Promise.all([GET('/wallet'), GET('/wallet/transactions')]);
  if (!alive()) return;
  view.innerHTML = `
    <div class="card center" style="background:linear-gradient(135deg,#D84315,#FF7043);color:#fff">
      <div class="small">رصيدك</div><div style="font-size:34px;font-weight:800">${money(w.balance)}</div></div>
    <div class="card"><b>شحن بكرت</b>
      <div class="row" style="margin-top:8px"><input class="input grow ltr" id="code" inputmode="numeric" placeholder="رقم الكرت" maxlength="24">
      <button class="btn small" id="redeem" style="min-height:46px">اشحن</button></div></div>
    <div class="section-title">الحركات</div>
    <div class="card">${(tx.data || []).map((t) => `<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)">
      <div><div class="bold small">${esc(t.type_label)}</div><div class="tiny muted">${esc(t.note || '')} · ${fmtDate(t.created_at)}</div></div>
      <b style="color:${t.amount >= 0 ? 'var(--ok)' : 'var(--err)'}">${t.amount >= 0 ? '+' : ''}${num(t.amount).toFixed(2)}</b></div>`).join('') || '<div class="muted center">ما فيش حركات</div>'}</div>`;
  $('#redeem').onclick = (e) => busy(e.currentTarget, async () => {
    const code = $('#code').value.replace(/\s+/g, '');
    if (!code) return toast('اكتب رقم الكرت', 'err');
    try { const r = await POST('/wallet/redeem', { code }); toast(r.message, 'ok'); render(); } catch (err) { toast(err.message, 'err'); }
  });
}, { tab: 'account', title: 'المحفظة' });

route('/points', async ({ alive }) => {
  const p = await GET('/points');
  if (!alive()) return;
  if (!p.enabled) { view.innerHTML = '<div class="empty"><div class="big">⭐</div><p>نقاط الولاء مش مفعّلة توّا</p></div>'; return; }
  view.innerHTML = `
    <div class="card center" style="background:linear-gradient(135deg,#F59E0B,#FBBF24);color:#fff">
      <div class="small">نقاطك</div><div style="font-size:34px;font-weight:800">${p.balance}</div><div class="small">تساوي ${money(p.value)}</div></div>
    <div class="card small">${p.redeem_mode === 'wallet'
      ? `حوّل نقاطك لفلوس في محفظتك (أقل شي ${p.min_redeem} نقطة).
        <div class="row" style="margin-top:8px"><input class="input grow ltr" id="pts" type="number" min="${p.min_redeem}" max="${p.balance}" value="${p.balance >= p.min_redeem ? p.balance : ''}">
        <button class="btn small" id="convert" style="min-height:46px" ${p.balance < p.min_redeem ? 'disabled' : ''}>حوّل</button></div>`
      : `تقدر تستعمل نقاطك كخصم وقت ما تأكد الطلب (أقل شي ${p.min_redeem} نقطة).`}</div>
    <div class="section-title">السجل</div>
    <div class="card">${(p.history || []).map((h) => `<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)">
      <div><div class="bold small">${esc(h.label)}</div><div class="tiny muted">${esc(h.note || '')} · ${fmtDate(h.at)}</div></div>
      <b style="color:${h.points >= 0 ? 'var(--ok)' : 'var(--err)'}">${h.points >= 0 ? '+' : ''}${h.points}</b></div>`).join('') || '<div class="muted center">ما فيش حركات</div>'}</div>`;
  $('#convert') && ($('#convert').onclick = (e) => busy(e.currentTarget, async () => {
    try { const r = await POST('/points/convert', { points: +$('#pts').value }); toast(r.message, 'ok'); render(); } catch (err) { toast(err.message, 'err'); }
  }));
}, { tab: 'account', title: 'نقاطي' });

// ================= المفضلة والسلات المحفوظة =================
route('/favorites', async ({ alive }) => {
  const r = await GET('/favorites');
  if (!alive()) return;
  const stores = r.stores?.data || r.stores || [];
  const products = r.products || [];
  view.innerHTML = `
    ${stores.length ? `<div class="section-title">المتاجر</div><div class="stores">${stores.map(storeCard).join('')}</div>` : ''}
    ${products.length ? `<div class="section-title">الأصناف</div><div class="products">${products.map((x) => `
      <div class="product" data-go="/store/${x.store.id}/p/${x.product.id}">
        <div class="ph">${x.product.image ? `<img src="${esc(x.product.image)}" alt="" loading="lazy">` : '🍽️'}</div>
        <div class="pinfo"><div class="n">${esc(x.product.name)}</div><div class="d">${esc(x.store.name)}</div>
        <div class="bottom"><span class="price">${money(effPrice(x.product))}</span></div></div></div>`).join('')}</div>` : ''}
    ${!stores.length && !products.length ? '<div class="empty"><div class="big">❤️</div><p>ما عندكش مفضلة لسه — اضغط ❤️ على أي متجر أو صنف</p></div>' : ''}`;
}, { tab: 'account', title: 'المفضلة' });

route('/saved', async ({ alive }) => {
  const r = await GET('/saved-carts');
  if (!alive()) return;
  const list = r.data || [];
  view.innerHTML = list.length ? list.map((c) => `<div class="card">
      <div class="row between"><b>${esc(c.name)}</b><span class="price">${money(c.total)}</span></div>
      <div class="tiny muted">${esc(c.store?.name || '')} · ${c.items_count} أصناف${c.missing ? ` · <span style="color:var(--err)">${c.missing} مش متوفر توّا</span>` : ''}</div>
      <div class="tiny" style="margin-top:4px">${(c.items || []).map((i) => `${i.quantity}× ${esc(i.name)}`).join('، ')}</div>
      <div class="row" style="margin-top:10px"><button class="btn small" data-load="${c.id}">اطلبها</button><button class="btn danger small" data-del="${c.id}">مسح</button></div></div>`).join('')
    : '<div class="empty"><div class="big">💾</div><p>ما عندكش سلات محفوظة. من السلة اضغط «احفظ».</p></div>';
  $$('[data-load]').forEach((b) => b.onclick = () => busy(b, async () => {
    try { await applyCart(await GET('/saved-carts/' + b.dataset.load)); } catch (e) { toast(e.message, 'err'); }
  }));
  $$('[data-del]').forEach((b) => b.onclick = async () => {
    if (!(await confirmBox('مسح السلة؟', 'متأكد؟', 'مسح', 'رجوع', true))) return;
    try { await DEL('/saved-carts/' + b.dataset.del); render(); } catch (e) { toast(e.message, 'err'); }
  });
}, { tab: 'account', title: 'سلاتي المحفوظة' });

// ================= حسابي =================
route('/account', async () => {
  const u = Auth.user || {};
  const [pointsOn, savedOn, supportOn] = await Promise.all([opt('points.enabled', false), opt('carts.saved_enabled', true), opt('support.enabled', true)]);
  const item = (path, ic, label) => `<div class="item" data-go="${path}"><span class="ic">${ic}</span><span class="grow">${label}</span><span class="chev">‹</span></div>`;
  view.innerHTML = `
    <div class="card row"><div style="width:52px;height:52px;border-radius:50%;background:var(--brand-soft);display:grid;place-items:center;font-size:24px">👤</div>
      <div class="grow"><b style="font-size:17px">${esc(u.name || '')}</b><div class="small muted" dir="ltr" style="text-align:right">${esc(u.phone || '')}</div></div>
      <button class="btn ghost small" id="edit">تعديل</button></div>
    <div class="card menu-list">
      ${item('/orders', '🧾', 'طلباتي')}
      ${item('/addresses', '📍', 'عناويني')}
      ${item('/wallet', '👛', 'المحفظة')}
      ${pointsOn ? item('/points', '⭐', 'نقاطي') : ''}
      ${item('/favorites', '❤️', 'المفضلة')}
      ${savedOn ? item('/saved', '💾', 'سلاتي المحفوظة') : ''}
      ${supportOn ? item('/support', '🎧', 'الدعم والمساعدة') : ''}
    </div>
    <div class="card menu-list">
      <div class="item" id="password"><span class="ic">🔑</span><span class="grow">كلمة المرور</span><span class="chev">‹</span></div>
      ${C.whatsapp ? `<a class="item" href="https://wa.me/${esc(String(C.whatsapp).replace(/\D/g, '').replace(/^0/, '218'))}" target="_blank" rel="noopener"><span class="ic">💬</span><span class="grow">تواصل معانا (واتساب)</span><span class="chev">‹</span></a>` : ''}
      <div class="item" id="logout"><span class="ic">🚪</span><span class="grow">تسجيل الخروج</span></div>
      <div class="item" id="delete" style="color:var(--err)"><span class="ic" style="background:var(--err-soft);color:var(--err)">🗑️</span><span class="grow">حذف الحساب</span></div>
    </div>`;

  $('#edit').onclick = async () => {
    const name = await promptBox('اسمك', { value: u.name || '' });
    if (!name?.trim()) return;
    try { const r = await PUT('/me', { name: name.trim() }); Auth.save(Auth.token, r.user); render(); toast('تم الحفظ', 'ok'); } catch (e) { toast(e.message, 'err'); }
  };
  $('#password').onclick = async () => {
    const pw = await promptBox('كلمة مرور جديدة', { type: 'password', label: '6 حروف على الأقل — تدخل بيها من غير رمز تحقق' });
    if (pw === null) return;
    if (pw.length < 6) return toast('كلمة المرور لازم 6 حروف على الأقل', 'err');
    try { await PUT('/me', { password: pw }); toast('تم حفظ كلمة المرور ✅', 'ok'); } catch (e) { toast(e.message, 'err'); }
  };
  $('#logout').onclick = async () => {
    if (!(await confirmBox('تسجيل الخروج؟', 'تبي تطلع من حسابك في المتصفح هذا؟', 'خروج', 'رجوع'))) return;
    try { await POST('/logout'); } catch {}
    Auth.clear(); Cart.clear(); go('/login', true);
  };
  $('#delete').onclick = async () => {
    try {
      const chk = await GET('/me/delete');
      if (chk.blockers?.length) return confirmBox('ما نقدروش نحذفو الحساب توّا', chk.blockers.join('\n'), 'تمام', 'رجوع');
      const warn = `الحذف نهائي. ${num(chk.wallet) > 0 ? `رصيد المحفظة (${money(chk.wallet)}) ` : ''}${chk.points ? `ونقاطك (${chk.points}) ` : ''}${num(chk.wallet) > 0 || chk.points ? 'يضيعو.' : ''}`;
      if (!(await confirmBox('حذف الحساب؟', warn, 'كمّل', 'رجوع', true))) return;
      if (!chk.has_password) return confirmBox('نحتاجو نتأكدو إنه أنت', 'اضبط كلمة مرور من «كلمة المرور» أول، وبعدها احذف الحساب.', 'تمام', 'رجوع');
      const pw = await promptBox('أكّد بكلمة المرور', { type: 'password', yes: 'احذف الحساب' });
      if (!pw) return;
      const r = await POST('/me/delete', { password: pw });
      toast(r.message, 'ok');
      if (r.status === 'deleted') { Auth.clear(); Cart.clear(); go('/login', true); }
    } catch (e) { toast(e.message, 'err'); }
  };
}, { tab: 'account', title: 'حسابي', back: false });

// ================= الدخول والتسجيل =================
let rcP = null;
function recaptchaLib() {
  if (window.grecaptcha?.render) return Promise.resolve(window.grecaptcha);
  if (rcP) return rcP;
  rcP = new Promise((resolve) => {
    window.__rcReady = () => resolve(window.grecaptcha);
    const s = document.createElement('script');
    s.src = 'https://www.google.com/recaptcha/api.js?onload=__rcReady&render=explicit&hl=ar';
    s.async = true;
    document.head.appendChild(s);
  });
  return rcP;
}

// ================= الدعم الفني (تذاكر) =================
const ticketStatus = { open: ['تستنى رد الإدارة', 'warn'], answered: ['الدعم ردّ', 'ok'], closed: ['مقفولة', ''] };
const fileToData = (file) => new Promise((ok, bad) => {
  if (file.size > 5 * 1024 * 1024) return bad(new Error('الصورة كبيرة — لحد 5 ميغا'));
  const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = () => bad(new Error('ما قدرناش نقراو الصورة')); r.readAsDataURL(file);
});

route('/support', async ({ alive }) => {
  const r = await GET('/support/tickets');
  if (!alive()) return;
  const list = r.data || [];
  view.innerHTML = `
    <button class="btn block" data-go="/support/new" style="margin-bottom:12px">➕ تذكرة جديدة</button>
    ${list.length ? list.map((t) => `
      <div class="card order-card" data-go="/support/${t.id}">
        <div class="row between"><b ${t.unread ? '' : 'style="font-weight:600"'}>${esc(t.subject)}</b>
          <span class="pill ${ticketStatus[t.status]?.[1] || ''}">${esc(t.status_label)}</span></div>
        <div class="tiny muted" style="margin-top:4px">${esc(t.code)} · ${esc(t.category_label)}${t.order_code ? ' · طلب ' + esc(t.order_code) : ''}</div>
        ${t.last_message ? `<div class="small muted" style="margin-top:4px">${esc(t.last_message)}</div>` : ''}
        ${t.unread ? `<div class="pill err" style="margin-top:6px">${t.unread} رد جديد</div>` : ''}
      </div>`).join('')
      : '<div class="empty"><div class="big">🎧</div><p>عندك مشكلة أو سؤال؟ افتح تذكرة والدعم الفني يرد عليك هني.</p></div>'}`;
}, { tab: 'account', title: 'الدعم والمساعدة' });

route('/support/new', async ({ query, alive }) => {
  const r = await GET('/support/categories');
  if (!alive()) return;
  if (!r.enabled) { view.innerHTML = '<div class="empty"><div class="big">🎧</div><p>الدعم من الموقع موقوف حالياً.</p></div>'; return; }
  const orderId = query.order ? +query.order : null;
  let cat = orderId ? r.categories[0]?.key : null;
  let image = null;
  view.innerHTML = `
    ${orderId ? `<div class="card">🧾 بخصوص الطلب <b>${esc(query.code || '')}</b></div>` : ''}
    <div class="card">
      <b>شن نوع المشكلة؟</b>
      <div class="chips" id="cats" style="margin-top:8px;flex-wrap:wrap">${r.categories.map((c) => `<button class="chip ${c.key === cat ? 'on' : ''}" data-cat="${esc(c.key)}">${esc(c.label)}</button>`).join('')}</div>
      <label class="field" style="margin-top:12px"><span>اكتب المشكلة بالتفصيل</span>
        <textarea class="textarea" id="body" rows="6" maxlength="2000" placeholder="شن صار؟ وامتا؟ وشن كنت تبي تدير؟"></textarea></label>
      <label class="btn ghost small" style="margin-top:8px;cursor:pointer">📷 <span id="img-label">أرفق صورة (اختياري)</span><input type="file" id="img" accept="image/*" hidden></label>
      <button class="btn block" id="send" style="margin-top:14px">إرسال للدعم الفني</button>
    </div>`;
  $$('[data-cat]').forEach((b) => b.onclick = () => { cat = b.dataset.cat; $$('[data-cat]').forEach((x) => x.classList.toggle('on', x === b)); });
  $('#img').onchange = async (e) => {
    const f = e.target.files[0]; if (!f) return;
    try { image = await fileToData(f); $('#img-label').textContent = '✅ ' + f.name; } catch (err) { toast(err.message, 'err'); }
  };
  $('#send').onclick = (e) => busy(e.currentTarget, async () => {
    const body = $('#body').value.trim();
    if (!cat) return toast('اختار نوع المشكلة', 'err');
    if (body.length < 3) return toast('اكتب المشكلة بالتفصيل', 'err');
    try {
      const res = await POST('/support/tickets', { category: cat, body, ...(orderId ? { order_id: orderId } : {}), ...(image ? { image } : {}) });
      toast(res.message, 'ok');
      go('/support/' + res.data.id, true);
    } catch (err) { toast(err.message, 'err'); }
  });
}, { tab: 'account', title: 'تذكرة جديدة' });

route('/support/:id', async ({ params, alive, onLeave }) => {
  const id = params.id;
  let count = -1;
  let image = null;
  view.innerHTML = '<div id="t-head"></div><div class="card" id="t-msgs" style="max-height:60vh;overflow-y:auto"></div><div id="t-foot"></div>';

  async function load() {
    let t;
    try { t = (await GET('/support/tickets/' + id)).data; } catch (e) { if (count < 0) toast(e.message, 'err'); return; }
    if (!alive()) return;
    setTop({ title: t.code, back: true });
    $('#t-head').innerHTML = `<div class="card"><div class="row between"><b>${esc(t.subject)}</b><span class="pill ${ticketStatus[t.status]?.[1] || ''}">${esc(t.status_label)}</span></div>
      <div class="tiny muted" style="margin-top:4px">${esc(t.category_label)}${t.order_code ? ' · طلب ' + esc(t.order_code) : ''}</div></div>`;
    if (t.messages.length !== count) {
      count = t.messages.length;
      const box = $('#t-msgs');
      box.innerHTML = t.messages.map((m) => `
        <div style="display:flex;justify-content:${m.from === 'me' ? 'flex-end' : 'flex-start'};margin:6px 0">
          <div style="max-width:80%;padding:10px 12px;border-radius:14px;${m.from === 'me' ? 'background:var(--brand-soft)' : 'background:#fff;border:1px solid #E5E7EB'}">
            ${m.from === 'staff' ? '<div class="tiny" style="color:var(--ok);font-weight:700;margin-bottom:4px">🎧 الدعم الفني</div>' : ''}
            ${m.body ? `<div style="white-space:pre-wrap">${esc(m.body)}</div>` : ''}
            ${m.image ? `<a href="${esc(m.image)}" target="_blank" rel="noopener"><img src="${esc(m.image)}" alt="" style="max-width:220px;max-height:220px;border-radius:10px;margin-top:6px;display:block"></a>` : ''}
            <div class="tiny muted" style="margin-top:4px">${fmtDate(m.created_at)}</div>
          </div></div>`).join('')
        + (t.status === 'open' && t.messages.at(-1)?.from === 'me' ? '<div class="tiny muted center" style="margin:10px">وصلت رسالتك — الدعم يرد عليك هني.</div>' : '');
      box.scrollTop = box.scrollHeight;
    }
    const foot = $('#t-foot');
    if (!foot.dataset.ready) {
      foot.dataset.ready = 1;
      foot.innerHTML = `<div class="card">
        <div class="tiny muted" id="closed-note" hidden style="margin-bottom:6px">التذكرة مقفولة — لو كتبت رسالة تتفتح من جديد.</div>
        <textarea class="textarea" id="msg" rows="2" maxlength="2000" placeholder="اكتب رسالتك…"></textarea>
        <div class="row" style="margin-top:8px;flex-wrap:wrap">
          <label class="btn ghost small" style="cursor:pointer">📷 <span id="img-label">صورة</span><input type="file" id="img" accept="image/*" hidden></label>
          <button class="btn grow" id="send">إرسال</button>
          <button class="btn ghost small" id="close-t">✔️ المشكلة انحلّت</button>
        </div></div>`;
      $('#img', foot).onchange = async (e) => {
        const f = e.target.files[0]; if (!f) return;
        try { image = await fileToData(f); $('#img-label', foot).textContent = '✅ صورة'; } catch (err) { toast(err.message, 'err'); }
      };
      $('#send', foot).onclick = (e) => busy(e.currentTarget, async () => {
        const body = $('#msg', foot).value.trim();
        if (!body && !image) return;
        try {
          await POST(`/support/tickets/${id}/messages`, { ...(body ? { body } : {}), ...(image ? { image } : {}) });
          $('#msg', foot).value = ''; image = null; $('#img-label', foot).textContent = 'صورة';
          await load();
        } catch (err) { toast(err.message, 'err'); }
      });
      $('#close-t', foot).onclick = async () => {
        if (!(await confirmBox('قفل التذكرة؟', 'لو المشكلة رجعت اكتب فيها وتتفتح من جديد.', 'اقفلها', 'رجوع'))) return;
        try { await POST(`/support/tickets/${id}/close`); await load(); } catch (err) { toast(err.message, 'err'); }
      };
    }
    $('#closed-note', foot).hidden = t.status !== 'closed';
    $('#close-t', foot).hidden = t.status === 'closed';
  }

  await load();
  const timer = setInterval(load, 10000);
  onLeave(() => clearInterval(timer));
}, { tab: 'account', title: ' ' });

route('/login', async ({ query }) => {
  if (Auth.in) return go(query.next || '/', true);
  const logo = C.logo ? `<img class="logo-big" src="${esc(C.logo)}" alt="">` : '<div class="logo-big fallback">🛵</div>';
  let mode = 'password'; // password | otp | signup
  let step = 'phone';    // phone | code
  let phone = LS.get('phone', '');
  let widget = null;
  let resendAt = 0;
  let timer = null;

  function draw(err = '') {
    view.innerHTML = `<div class="auth">
      ${logo}<h1>${esc(C.name || '')}</h1><p class="center muted" style="margin:0 0 16px">اطلب من المتصفح — بدون تطبيق</p>
      <div class="card">
        ${step === 'phone' ? `<div class="tabs">
          <button data-mode="password" class="${mode === 'password' ? 'on' : ''}">كلمة المرور</button>
          <button data-mode="otp" class="${mode === 'otp' ? 'on' : ''}">رمز تحقق</button>
          <button data-mode="signup" class="${mode === 'signup' ? 'on' : ''}">حساب جديد</button></div>` : ''}
        ${err ? `<div class="error">${esc(err)}</div>` : ''}
        <form id="f" autocomplete="on">
        ${step === 'phone' ? `
          ${mode === 'signup' ? '<label class="field"><span>اسمك</span><input class="input" id="name" autocomplete="name" required maxlength="60"></label>' : ''}
          <label class="field"><span>رقم الهاتف</span><input class="input ltr" id="phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="09XXXXXXXX" maxlength="10" value="${esc(phone)}" required></label>
          ${mode === 'password' ? '<label class="field"><span>كلمة المرور</span><input class="input" id="password" type="password" autocomplete="current-password" required></label>' : ''}
          ${mode === 'signup' ? '<label class="field"><span>كلمة مرور (اختياري)</span><input class="input" id="password" type="password" autocomplete="new-password" minlength="6" placeholder="6 حروف على الأقل"></label>' : ''}
          ${mode !== 'password' && C.recaptcha ? '<div class="recaptcha" id="rc"></div>' : ''}
          <button class="btn block" type="submit">${mode === 'password' ? 'دخول' : 'ابعتلي رمز التحقق'}</button>
          ${mode === 'password' ? '<p class="center small muted" style="margin:12px 0 0">نسيت كلمة المرور؟ <button type="button" class="linkbtn" data-mode="otp">ادخل برمز تحقق</button></p>' : ''}`
        : `
          <p class="center" style="margin-top:0">بعتنالك رمز على <b dir="ltr">${esc(phone)}</b> <span id="via"></span></p>
          <label class="field"><input class="input otp-input" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" required></label>
          <button class="btn block" type="submit">تأكيد</button>
          <div class="row between small" style="margin-top:12px"><button type="button" class="linkbtn" id="change">تغيير الرقم</button>
            <span id="resend-wrap"></span></div>
          <div id="sms-wrap"></div>`}
        </form>
      </div>
      <p class="center tiny muted">بالدخول توافق على <a href="https://dar-almaqam.com.ly/terms.php" target="_blank" rel="noopener" style="text-decoration:underline">الشروط</a> و<a href="https://dar-almaqam.com.ly/privacy.php" target="_blank" rel="noopener" style="text-decoration:underline">سياسة الخصوصية</a>.</p>
    </div>`;

    $$('[data-mode]').forEach((b) => b.onclick = () => { mode = b.dataset.mode; draw(); });
    $('#f').onsubmit = (e) => { e.preventDefault(); submit(e.submitter || $('#f button[type=submit]')); };
    if (step === 'phone') {
      $('#phone').oninput = (e) => { phone = e.target.value.replace(/\D/g, ''); };
      if (mode !== 'password' && C.recaptcha) {
        recaptchaLib().then((g) => { if ($('#rc')) widget = g.render('rc', { sitekey: C.recaptcha }); });
      }
    } else {
      $('#code').focus();
      $('#change').onclick = () => { step = 'phone'; clearInterval(timer); draw(); };
      tick();
      clearInterval(timer);
      timer = setInterval(tick, 1000);
      if (window.OTPCredential && 'credentials' in navigator) {
        navigator.credentials.get({ otp: { transport: ['sms'] } }).then((o) => { if (o?.code && $('#code')) { $('#code').value = o.code; submit($('#f button[type=submit]')); } }).catch(() => {});
      }
    }
  }

  function tick() {
    const w = $('#resend-wrap');
    if (!w) { clearInterval(timer); return; }
    const left = Math.ceil((resendAt - Date.now()) / 1000);
    w.innerHTML = left > 0 ? `<span class="muted">إعادة الإرسال بعد ${left} ث</span>` : '<button type="button" class="linkbtn" id="resend">عاود ابعت الرمز</button>';
    const r = $('#resend');
    if (r) r.onclick = () => { step = 'phone'; clearInterval(timer); draw(); };
  }

  async function sendOtp(btn, channel) {
    const name = $('#name')?.value.trim();
    if (mode === 'signup' && !name) return draw('اكتب اسمك.');
    if (!/^09[1-6]\d{7}$/.test(phone)) return draw('رقم الهاتف لازم يكون 10 أرقام ويبدا بـ 09');
    let token = null;
    if (C.recaptcha) {
      token = widget !== null && window.grecaptcha ? window.grecaptcha.getResponse(widget) : '';
      if (!token) { toast('علّم على مربع «أنا لست روبوتاً»', 'err'); return; }
    }
    LS.set('phone', phone);
    login.name = name || login.name;
    login.password = $('#password')?.value || login.password;
    try {
      const r = await POST('/auth/otp', { phone, purpose: mode === 'signup' ? 'signup' : 'login', ...(channel ? { channel } : {}) },
        token ? { 'X-Recaptcha': token } : {});
      resendAt = Date.now() + num(r.resend_after || 60) * 1000;
      step = 'code';
      draw();
      $('#via').textContent = r.channel === 'whatsapp' ? 'على واتساب' : 'برسالة نصية';
      if (r.debug_code) $('#code').value = r.debug_code;
    } catch (e) {
      if (widget !== null && window.grecaptcha) window.grecaptcha.reset(widget);
      if (e.data?.sms_fallback) {
        draw(e.message);
        $('#f').insertAdjacentHTML('beforeend', '<button type="button" class="btn outline block" id="sms" style="margin-top:10px">ابعتلي رسالة نصية بدل واتساب</button>');
        $('#sms').onclick = (ev) => busy(ev.currentTarget, () => sendOtp(ev.currentTarget, 'sms'));
        return;
      }
      draw(e.message);
    }
  }
  const login = { name: '', password: '' };

  async function submit(btn) {
    await busy(btn, async () => {
      if (step === 'phone' && mode === 'password') {
        phone = $('#phone').value.replace(/\D/g, '');
        try {
          const r = await POST('/auth/login', { phone, password: $('#password').value });
          LS.set('phone', phone);
          return done(r);
        } catch (e) { return draw(e.message); }
      }
      if (step === 'phone') {
        phone = $('#phone').value.replace(/\D/g, '');
        return sendOtp(btn);
      }
      const code = $('#code').value.trim();
      if (!code) return;
      try {
        const r = await POST('/auth/verify', {
          phone, code,
          ...(mode === 'signup' ? { create_account: true, name: login.name, ...(login.password ? { password: login.password } : {}) } : {}),
        });
        done(r);
      } catch (e) {
        const msg = e.message;
        draw();
        $('.card').insertAdjacentHTML('afterbegin', `<div class="error">${esc(msg)}</div>`);
      }
    });
  }

  function done(r) {
    clearInterval(timer);
    Auth.save(r.token, r.user);
    Cache.clear();
    toast('أهلاً ' + (r.user?.name || '') + ' 👋', 'ok');
    go(query.next && query.next.startsWith('/') && !query.next.startsWith('/login') ? query.next : '/', true);
  }

  draw();
}, { public: true, tab: 'none' });

// ================= التشغيل =================
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register(BASE + '/sw.js', { scope: BASE + '/' }).catch(() => {}));
}
// نتأكدو إن الجلسة لسه صالحة + نحدّثو الاسم
if (Auth.in) GET('/me').then((r) => { if (r?.user) Auth.save(Auth.token, r.user); }).catch(() => {});
render();
})();
