/* BlurHash — فك النص لصورة ضبابية صغيرة (نفس خوارزمية السيرفر) */
(function (g) {
  const C = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
  const d83 = (s) => { let v = 0; for (const ch of s) v = v * 83 + C.indexOf(ch); return v; };
  const toLin = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const toSrgb = (v) => { v = Math.max(0, Math.min(1, v)); return v <= 0.0031308 ? Math.round(v * 12.92 * 255) : Math.round((1.055 * Math.pow(v, 1 / 2.4) - 0.055) * 255); };
  const signPow = (v, e) => Math.sign(v) * Math.pow(Math.abs(v), e);

  function decode(hash, w, h, punch = 1) {
    if (!hash || hash.length < 6) return null;
    const size = d83(hash[0]); const ny = Math.floor(size / 9) + 1; const nx = (size % 9) + 1;
    if (hash.length !== 4 + 2 * nx * ny) return null;
    const maxV = (d83(hash[1]) + 1) / 166;
    const colors = [];
    const dc = d83(hash.slice(2, 6));
    colors.push([toLin(dc >> 16), toLin((dc >> 8) & 255), toLin(dc & 255)]);
    for (let i = 1; i < nx * ny; i++) {
      const v = d83(hash.slice(4 + i * 2, 6 + i * 2));
      colors.push([Math.floor(v / 361), Math.floor(v / 19) % 19, v % 19].map((q) => signPow((q - 9) / 9, 2) * maxV * punch));
    }
    const px = new Uint8ClampedArray(w * h * 4);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      let r = 0, gg = 0, b = 0;
      for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
        const basis = Math.cos((Math.PI * x * i) / w) * Math.cos((Math.PI * y * j) / h);
        const c = colors[i + j * nx]; r += c[0] * basis; gg += c[1] * basis; b += c[2] * basis;
      }
      const k = 4 * (x + y * w); px[k] = toSrgb(r); px[k + 1] = toSrgb(gg); px[k + 2] = toSrgb(b); px[k + 3] = 255;
    }
    return px;
  }

  // نفس النص يتفك مرة وحدة
  const cache = new Map();
  function toDataURL(hash) {
    if (cache.has(hash)) return cache.get(hash);
    let url = null;
    try {
      const px = decode(hash, 32, 32);
      if (px) {
        const cv = document.createElement('canvas'); cv.width = 32; cv.height = 32;
        const ctx = cv.getContext('2d'); const img = ctx.createImageData(32, 32); img.data.set(px); ctx.putImageData(img, 0, 0);
        url = cv.toDataURL();
      }
    } catch (e) { url = null; }
    cache.set(hash, url);
    return url;
  }

  /* أي <img data-bh="..."> : الضبابية تبان كخلفية لين الصورة تتحمّل، وبعدها تنشال */
  function apply(root) {
    (root || document).querySelectorAll('img[data-bh]:not([data-bh-done])').forEach((img) => {
      img.setAttribute('data-bh-done', '1');
      if (img.complete && img.naturalWidth) return;
      const url = toDataURL(img.dataset.bh);
      if (!url) return;
      img.style.backgroundImage = `url(${url})`;
      img.style.backgroundSize = 'cover';
      img.style.backgroundPosition = 'center';
      img.classList.add('bh-loading');
      const done = () => { img.style.backgroundImage = ''; img.classList.remove('bh-loading'); };
      img.addEventListener('load', done, { once: true });
      img.addEventListener('error', () => img.classList.remove('bh-loading'), { once: true });
    });
  }

  g.BlurHash = { decode, toDataURL, apply };
  if (typeof document !== 'undefined') {
    const run = () => { apply(document); new MutationObserver(() => apply(document)).observe(document.body, { childList: true, subtree: true }); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  }
})(typeof window !== 'undefined' ? window : globalThis);
