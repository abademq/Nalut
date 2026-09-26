<x-filament-panels::page>
<script>
    function receiptDesigner(init) {
        let seq = 0;
        const clone = (o) => JSON.parse(JSON.stringify(o));
        const withKeys = (layout) => { layout.blocks.forEach((b) => b._k = ++seq); return layout; };
        const strip = (layout) => { const c = clone(layout); c.blocks.forEach((b) => delete b._k); return c; };

        return {
            meta: init.meta,
            layouts: { store: withKeys(clone(init.layouts.store)), customer: withKeys(clone(init.layouts.customer)) },
            saved: { store: JSON.stringify(strip(init.layouts.store)), customer: JSON.stringify(strip(init.layouts.customer)) },
            copy: 'store',
            paper: 384,
            sel: null,
            dragFrom: null,
            dragOver: null,
            history: [],
            future: [],
            busy: false,
            showHidden: true,
            samplePaid: false,
            _last: null,
            _timer: null,
            _restoring: false,
            sample: {
                store: 'مطعم الواحة', code: 'NL-10245',
                customer: 'محمد علي', phone: '0912345678',
                address: 'نالوت - الحي الجديد، قرب المسجد', landmark: 'بيت بباب أخضر',
                driver: 'سالم', notes: 'رن الجرس مرتين', payment: 'نقداً عند الاستلام',
                subtotal: 57, delivery: 5, discount: 3, wallet: 10, total: 59, cash: 49,
                items: [
                    { qty: 2, name: 'بيتزا مارجريتا وسط', price: 30, options: 'جبنة إضافية', note: 'بدون زيتون' },
                    { qty: 1, name: 'شاورما دجاج عربي', price: 12, options: '', note: '' },
                    { qty: 3, name: 'عصير برتقال طبيعي', price: 15, options: '', note: '' },
                ],
            },

            fit: 1,
            measure() {
                const w = this.$refs.desk ? this.$refs.desk.clientWidth - 24 : this.paper;
                this.fit = Math.min(1, Math.max(0.4, w / this.paper));
            },

            init() {
                // الورقة تصغر لو المساحة ضيقة (ورق 8 سم على شاشة صغيرة)
                this.$nextTick(() => this.measure());
                if (window.ResizeObserver && this.$refs.desk) new ResizeObserver(() => this.measure()).observe(this.$refs.desk);
                this.$watch('paper', () => this.$nextTick(() => this.measure()));
                this._last = JSON.stringify(this.layouts);
                // كل تعديل (حتى الكتابة) ينحفظ في «تراجع» — بعد ثانية سكون
                this.$watch('layouts', () => {
                    if (this._restoring) return;
                    clearTimeout(this._timer);
                    this._timer = setTimeout(() => this.commitHistory(), 500);
                }, { deep: true });
            },

            commitHistory() {
                const now = JSON.stringify(this.layouts);
                if (now === this._last) return;
                this.history.push(this._last);
                if (this.history.length > 80) this.history.shift();
                this.future = [];
                this._last = now;
            },

            restore(state) {
                this._restoring = true;
                this.layouts = JSON.parse(state);
                this._last = state;
                this.$nextTick(() => { this._restoring = false; });
                if (this.sel !== null && !this.blocks[this.sel]) this.sel = null;
            },

            undo() {
                this.commitHistory();
                if (!this.history.length) return;
                this.future.push(JSON.stringify(this.layouts));
                this.restore(this.history.pop());
            },

            redo() {
                if (!this.future.length) return;
                this.history.push(JSON.stringify(this.layouts));
                this.restore(this.future.pop());
            },

            get layout() { return this.layouts[this.copy]; },
            get blocks() { return this.layouts[this.copy].blocks; },

            isDirty(copy) { return JSON.stringify(strip(this.layouts[copy])) !== this.saved[copy]; },
            anyDirty() { return this.isDirty('store') || this.isDirty('customer'); },

            switchCopy(key) { this.copy = key; this.sel = null; },

            kind(b) { return this.meta.types[b.type]?.kind; },
            hasText(b) { return ['text', 'value', 'items'].includes(this.kind(b)); },
            isMoney(b) { return ['subtotal', 'delivery_fee', 'discount', 'wallet_paid', 'total', 'cash_to_collect'].includes(b.type); },

            blockName(b) {
                const base = this.meta.types[b.type]?.label || b.type;
                if (b.type === 'text' && b.text) return base + ': ' + b.text;
                return base;
            },

            newBlock(type) {
                const k = this.meta.types[type].kind;
                const base = this.layout.page.base_size;
                const b = { _k: ++seq, type, hidden: false, align: ['value', 'items'].includes(k) ? 'start' : 'center',
                    size: base, bold: false, italic: false, underline: false, boxed: false, inverted: false, margin_top: 0, margin_bottom: 0 };
                if (k === 'text') b.text = type === 'text' ? 'نص جديد' : (type === 'copy_title' ? this.meta.copies[this.copy] : '');
                if (type === 'datetime') b.format = 'date_time';
                if (k === 'value') Object.assign(b, { label: this.meta.types[type].default_label, layout: 'row', value_bold: false,
                    currency: ['total', 'cash_to_collect'].includes(type) });
                if (type === 'cash_to_collect') b.paid_text = 'مدفوع بالكامل — لا تحصّل فلوس';
                if (k === 'items') Object.assign(b, { size: base + 1, bold: true, show_price: true, show_options: true, show_notes: true,
                    note_size: base - 1, spacing: 7, qty_format: '{qty}×', row_divider: false });
                if (k === 'divider') Object.assign(b, { thickness: 2, style: 'solid', margin_top: 8, margin_bottom: 8 });
                if (k === 'spacer') b.height = 12;
                if (k === 'logo') b.height = 70;
                return b;
            },

            add(type) {
                const at = this.sel !== null ? this.sel + 1 : this.blocks.length;
                this.blocks.splice(at, 0, this.newBlock(type));
                this.sel = at;
            },
            remove(i) { this.blocks.splice(i, 1); this.sel = null; },
            duplicate(i) { const c = clone(this.blocks[i]); c._k = ++seq; this.blocks.splice(i + 1, 0, c); this.sel = i + 1; },
            move(i, d) {
                const j = i + d;
                if (j < 0 || j >= this.blocks.length) return;
                const [b] = this.blocks.splice(i, 1);
                this.blocks.splice(j, 0, b);
                this.sel = j;
            },
            dragStart(i, e) { this.dragFrom = i; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); },
            drop(i) {
                if (this.dragFrom === null || this.dragFrom === i) { this.dragFrom = this.dragOver = null; return; }
                const [b] = this.blocks.splice(this.dragFrom, 1);
                this.blocks.splice(i, 0, b);
                this.sel = i;
                this.dragFrom = this.dragOver = null;
            },

            async save() {
                this.busy = true;
                try {
                    const clean = await this.$wire.saveLayout(this.copy, strip(this.layout));
                    this.saved[this.copy] = JSON.stringify(clean);
                    this._restoring = true;
                    this.layouts[this.copy] = withKeys(clone(clean));
                    this._last = JSON.stringify(this.layouts);
                    this.$nextTick(() => { this._restoring = false; });
                } finally {
                    this.busy = false;
                }
            },

            async resetCopy() {
                if (!confirm('نرجعو ' + this.meta.copies[this.copy] + ' للتصميم الافتراضي؟ التعديلات الحالية تمشي.')) return;
                const clean = await this.$wire.resetLayout(this.copy);
                this.saved[this.copy] = JSON.stringify(clean);
                this.layouts[this.copy] = withKeys(clone(clean));
                this.sel = null;
            },

            copyFromOther() {
                const other = this.copy === 'store' ? 'customer' : 'store';
                if (!confirm('ننسخو تصميم ' + this.meta.copies[other] + ' هني؟ (تقدر تتراجع)')) return;
                const title = this.blocks.find((b) => b.type === 'copy_title')?.text;
                const next = withKeys(clone(strip(this.layouts[other])));
                if (title) next.blocks.forEach((b) => { if (b.type === 'copy_title') b.text = title; });
                this.layouts[this.copy] = next;
                this.sel = null;
            },

            // ===== المعاينة — نفس قواعد تطبيق المتجر =====
            s(v) { return (Number(v) || 0) * this.paper / 384; },
            paperStyle() {
                const p = this.layout.page;
                const w = { normal: 400, medium: 500, bold: 700 }[p.weight] || 500;
                return `width:${this.paper}px;padding:${this.s(p.padding_top)}px ${this.s(p.padding_x)}px ${this.s(p.padding_bottom)}px;` +
                    `font-size:${this.s(p.base_size)}px;line-height:${p.line_height};font-weight:${w}`;
            },
            flexAlign(b) { return { start: 'flex-start', center: 'center', end: 'flex-end' }[b.align] || 'center'; },
            cssAlign(b) { return { start: 'right', center: 'center', end: 'left' }[b.align] || 'center'; },
            textStyle(b) {
                return `font-size:${this.s(b.size)}px;font-weight:${b.bold ? 700 : 'inherit'};font-style:${b.italic ? 'italic' : 'normal'};` +
                    `text-decoration:${b.underline ? 'underline' : 'none'};`;
            },
            boxStyle(b) {
                let st = '';
                if (b.boxed) st += `border:${this.s(2)}px solid #000;padding:${this.s(3)}px ${this.s(12)}px;`;
                if (b.inverted) st += `background:#000;color:#fff;padding:${this.s(3)}px ${this.s(12)}px;`;
                return st;
            },
            dividerStyle(b) {
                const t = Math.max(1, this.s(b.thickness));
                if (b.style === 'double') return `border-top:${t}px solid #000;border-bottom:${t}px solid #000;height:${t}px`;
                return `border-top:${t}px ${b.style === 'dashed' ? 'dashed' : 'solid'} #000`;
            },
            money(v) { return Number(v).toFixed(2); },
            dateStr(format) {
                const d = new Date(), p = (n) => String(n).padStart(2, '0');
                const date = `${d.getDate()}/${d.getMonth() + 1}/${d.getFullYear()}`, time = `${p(d.getHours())}:${p(d.getMinutes())}`;
                return format === 'date' ? date : format === 'time' ? time : `${date}   ${time}`;
            },
            fill(text) {
                const x = this.sample, pieces = x.items.reduce((a, i) => a + i.qty, 0);
                const map = { '{store}': x.store, '{code}': x.code, '{date}': this.dateStr('date'), '{time}': this.dateStr('time'),
                    '{customer}': x.customer, '{phone}': x.phone, '{address}': x.address, '{driver}': x.driver,
                    '{total}': this.money(x.total), '{cash}': this.money(x.cash), '{pieces}': pieces };
                return String(text || '').replace(/\{[a-z]+\}/g, (m) => map[m] ?? m);
            },
            textOf(b) {
                switch (b.type) {
                    case 'store_name': return this.sample.store;
                    case 'order_code': return this.sample.code;
                    case 'datetime': return this.dateStr(b.format);
                    default: return this.fill(b.text);
                }
            },
            rawValue(b) {
                const x = this.sample;
                return {
                    pieces: x.items.reduce((a, i) => a + i.qty, 0), subtotal: x.subtotal, delivery_fee: x.delivery,
                    discount: x.discount, wallet_paid: x.wallet, total: x.total, cash_to_collect: x.cash,
                    payment_method: x.payment, customer_name: x.customer, customer_phone: x.phone, address: x.address,
                    landmark: x.landmark, driver: x.driver, customer_notes: x.notes,
                }[b.type];
            },
            valueText(b) {
                const v = this.rawValue(b);
                if (typeof v === 'number' && b.type !== 'pieces') return this.money(v) + (b.currency ? ' د.ل' : '');
                return String(v ?? '');
            },
            visible(b) {
                if (this.kind(b) !== 'value' || b.type === 'cash_to_collect') return true;
                const v = this.rawValue(b);
                return !(v === null || v === undefined || v === '' || v === 0);
            },
        };
    }
</script>
<style>
    .rd { --rd-bg:#fff; --rd-panel:#f8fafc; --rd-border:#e2e8f0; --rd-text:#0f172a; --rd-muted:#64748b; --rd-accent:#d97706; --rd-sel:#fef3c7; --rd-desk:#e5e7eb; }
    .dark .rd { --rd-bg:#18181b; --rd-panel:#27272a; --rd-border:#3f3f46; --rd-text:#f4f4f5; --rd-muted:#a1a1aa; --rd-accent:#f59e0b; --rd-sel:#422006; --rd-desk:#09090b; }
    .rd { color:var(--rd-text); font-size:14px; }
    .rd-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
    .rd-tabs { display:flex; border:1px solid var(--rd-border); border-radius:10px; overflow:hidden; }
    .rd-tabs button { padding:7px 14px; background:var(--rd-bg); color:var(--rd-text); border:0; cursor:pointer; }
    .rd-tabs button.on { background:var(--rd-accent); color:#fff; font-weight:700; }
    .rd-btn { padding:7px 12px; border-radius:9px; border:1px solid var(--rd-border); background:var(--rd-bg); color:var(--rd-text); cursor:pointer; display:inline-flex; gap:6px; align-items:center; }
    .rd-btn:disabled { opacity:.45; cursor:default; }
    .rd-btn.primary { background:var(--rd-accent); border-color:var(--rd-accent); color:#fff; font-weight:700; }
    .rd-btn.danger { color:#dc2626; }
    .rd-grid { display:grid; grid-template-columns: 260px minmax(0,1fr) 320px; gap:14px; align-items:start; }
    @media (max-width: 1200px) { .rd-grid { grid-template-columns: 1fr; } }
    .rd-card { background:var(--rd-bg); border:1px solid var(--rd-border); border-radius:12px; padding:12px; }
    .rd-card h3 { font-weight:700; margin:0 0 8px; font-size:14px; }
    .rd-sticky { position:sticky; top:72px; max-height:calc(100vh - 90px); overflow:auto; }
    .rd-palette { display:flex; flex-wrap:wrap; gap:6px; }
    .rd-palette button { font-size:12px; padding:4px 8px; border-radius:7px; border:1px dashed var(--rd-border); background:var(--rd-panel); color:var(--rd-text); cursor:pointer; }
    .rd-palette button:hover { border-color:var(--rd-accent); }
    .rd-list { display:flex; flex-direction:column; gap:4px; margin-top:10px; }
    .rd-item { display:flex; align-items:center; gap:4px; padding:5px 6px; border:1px solid var(--rd-border); border-radius:8px; background:var(--rd-panel); cursor:grab; font-size:13px; }
    .rd-item.sel { border-color:var(--rd-accent); background:var(--rd-sel); }
    .rd-item.over { border-top:3px solid var(--rd-accent); }
    .rd-item .nm { flex:1; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
    .rd-item.off .nm { text-decoration:line-through; opacity:.6; }
    .rd-ic { border:0; background:transparent; color:var(--rd-muted); cursor:pointer; padding:2px 4px; border-radius:5px; font-size:13px; line-height:1; }
    .rd-ic:hover { background:var(--rd-border); color:var(--rd-text); }
    .rd-desk { background:var(--rd-desk); border-radius:12px; padding:24px 12px; display:flex; justify-content:safe center; overflow:auto; }
    .rd-paper { background:#fff; color:#000; box-shadow:0 6px 24px rgba(0,0,0,.18); font-family: "Noto Sans Arabic","Noto Naskh Arabic",Tahoma,"Segoe UI",sans-serif; direction:rtl; flex:none; }
    .rd-blk { position:relative; cursor:pointer; outline:1px dashed transparent; }
    .rd-blk:hover { outline-color:#cbd5e1; }
    .rd-blk.sel { outline:2px solid #f59e0b; outline-offset:1px; }
    .rd-blk.off { opacity:.3; outline-color:#94a3b8; }
    .rd-blk.over { box-shadow: 0 -3px 0 #f59e0b; }
    .rd-field { display:flex; flex-direction:column; gap:3px; margin-bottom:9px; }
    .rd-field label { font-size:12px; color:var(--rd-muted); }
    .rd-field input[type=text], .rd-field input[type=number], .rd-field select, .rd-field textarea { width:100%; padding:6px 8px; border:1px solid var(--rd-border); border-radius:8px; background:var(--rd-bg); color:var(--rd-text); font-size:13px; }
    .rd-row2 { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
    .rd-seg { display:flex; border:1px solid var(--rd-border); border-radius:8px; overflow:hidden; }
    .rd-seg button { flex:1; padding:5px 0; border:0; background:var(--rd-bg); color:var(--rd-text); cursor:pointer; font-size:12px; }
    .rd-seg button.on { background:var(--rd-accent); color:#fff; }
    .rd-chk { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:9px; }
    .rd-chk label { display:inline-flex; align-items:center; gap:4px; font-size:12px; padding:4px 8px; border:1px solid var(--rd-border); border-radius:7px; cursor:pointer; background:var(--rd-panel); }
    .rd-range { display:flex; gap:6px; align-items:center; }
    .rd-range input[type=range] { flex:1; accent-color:var(--rd-accent); }
    .rd-range input[type=number] { width:64px; }
    .rd-hint { font-size:11px; color:var(--rd-muted); line-height:1.6; }
    .rd-vars { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
    .rd-vars button { font-size:11px; padding:2px 6px; border-radius:6px; border:1px solid var(--rd-border); background:var(--rd-panel); color:var(--rd-text); cursor:pointer; }
    .rd-dirty { font-size:12px; color:var(--rd-accent); font-weight:700; }
</style>

<div class="rd" wire:ignore
     x-data="receiptDesigner(@js(['layouts' => $layouts, 'meta' => $this->editorMeta()]))"
     x-on:beforeunload.window="if (anyDirty()) { $event.preventDefault(); $event.returnValue = ''; }">

    {{-- الشريط العلوي --}}
    <div class="rd-bar">
        <div class="rd-tabs">
            <template x-for="(label, key) in meta.copies" :key="key">
                <button type="button" :class="copy === key && 'on'" x-on:click="switchCopy(key)" x-text="label + (isDirty(key) ? ' •' : '')"></button>
            </template>
        </div>

        <div class="rd-tabs" title="عرض الورق في المعاينة">
            <button type="button" :class="paper === 384 && 'on'" x-on:click="paper = 384">5.5 سم</button>
            <button type="button" :class="paper === 576 && 'on'" x-on:click="paper = 576">8 سم</button>
        </div>

        <button type="button" class="rd-btn" x-on:click="undo()" :disabled="!history.length" title="تراجع">↶ تراجع</button>
        <button type="button" class="rd-btn" x-on:click="redo()" :disabled="!future.length" title="إعادة">↷ إعادة</button>

        <span style="flex:1"></span>
        <span class="rd-dirty" x-show="isDirty(copy)">تعديلات ما تحفظتش</span>

        <button type="button" class="rd-btn" x-on:click="copyFromOther()">نسخ من النسخة الثانية</button>
        <button type="button" class="rd-btn danger" x-on:click="resetCopy()">الافتراضي</button>
        <button type="button" class="rd-btn primary" x-on:click="save()" :disabled="busy || !isDirty(copy)">
            <span x-text="busy ? 'جاري الحفظ...' : 'حفظ التصميم'"></span>
        </button>
    </div>

    <div class="rd-grid">
        {{-- القطع --}}
        <div class="rd-card rd-sticky">
            <h3>زيد قطعة</h3>
            <div class="rd-palette">
                <template x-for="(t, key) in meta.types" :key="key">
                    <button type="button" x-on:click="add(key)" x-text="'+ ' + t.label"></button>
                </template>
            </div>

            <h3 style="margin-top:14px">ترتيب الواصل <span class="rd-hint">(اسحب للترتيب)</span></h3>
            <div class="rd-list">
                <template x-for="(b, i) in blocks" :key="b._k">
                    <div class="rd-item" :class="{ sel: sel === i, off: b.hidden, over: dragOver === i && dragFrom !== i }"
                         draggable="true"
                         x-on:dragstart="dragStart(i, $event)" x-on:dragover.prevent="dragOver = i"
                         x-on:drop.prevent="drop(i)" x-on:dragend="dragFrom = dragOver = null"
                         x-on:click="sel = i">
                        <span style="color:var(--rd-muted)">⋮⋮</span>
                        <span class="nm" x-text="blockName(b)"></span>
                        <button type="button" class="rd-ic" title="فوق" x-on:click.stop="move(i, -1)">▲</button>
                        <button type="button" class="rd-ic" title="تحت" x-on:click.stop="move(i, 1)">▼</button>
                        <button type="button" class="rd-ic" :title="b.hidden ? 'إظهار' : 'إخفاء'" x-on:click.stop="b.hidden = !b.hidden" x-text="b.hidden ? '◌' : '◉'"></button>
                        <button type="button" class="rd-ic" title="تكرار" x-on:click.stop="duplicate(i)">⧉</button>
                        <button type="button" class="rd-ic" title="حذف" style="color:#dc2626" x-on:click.stop="remove(i)">✕</button>
                    </div>
                </template>
            </div>
        </div>

        {{-- المعاينة --}}
        <div>
            <div class="rd-desk" x-ref="desk" x-on:click.self="sel = null">
                <div class="rd-paper" :style="paperStyle() + `;zoom:${fit}`">
                    <template x-for="(b, i) in blocks" :key="b._k">
                        <div class="rd-blk" :class="{ sel: sel === i, off: b.hidden, over: dragOver === i && dragFrom !== i }"
                             x-show="b.hidden ? showHidden : visible(b)"
                             draggable="true"
                             x-on:dragstart="dragStart(i, $event)" x-on:dragover.prevent="dragOver = i"
                             x-on:drop.prevent="drop(i)" x-on:dragend="dragFrom = dragOver = null"
                             x-on:click.stop="sel = i"
                             :style="{ paddingTop: s(b.margin_top) + 'px', paddingBottom: s(b.margin_bottom) + 'px' }">

                            {{-- شعار --}}
                            <template x-if="kind(b) === 'logo'">
                                <div :style="`display:flex;justify-content:${flexAlign(b)}`">
                                    <template x-if="meta.logo">
                                        <img :src="meta.logo" :style="`height:${s(b.height)}px;filter:grayscale(1) contrast(1.4)`">
                                    </template>
                                    <template x-if="!meta.logo">
                                        <div :style="`height:${s(b.height)}px;width:${s(b.height)}px;border:2px dashed #999;display:flex;align-items:center;justify-content:center;font-size:${s(11)}px;color:#777`">الشعار</div>
                                    </template>
                                </div>
                            </template>

                            {{-- نص --}}
                            <template x-if="kind(b) === 'text'">
                                <div :style="`display:flex;justify-content:${flexAlign(b)}`">
                                    <div :style="boxStyle(b) + textStyle(b) + `text-align:${cssAlign(b)};white-space:pre-wrap;` + (b.boxed || b.inverted ? '' : 'width:100%')"
                                         x-text="textOf(b)"></div>
                                </div>
                            </template>

                            {{-- قيمة بعنوان --}}
                            <template x-if="kind(b) === 'value'">
                                <div :style="`display:flex;justify-content:${flexAlign(b)}`">
                                    <div :style="boxStyle(b) + textStyle(b) + (b.boxed || b.inverted ? '' : 'width:100%')">
                                        <template x-if="b.type === 'cash_to_collect' && sample.cash <= 0">
                                            <div :style="`text-align:${cssAlign(b)}`" x-text="b.paid_text"></div>
                                        </template>
                                        <template x-if="!(b.type === 'cash_to_collect' && sample.cash <= 0)">
                                            <div>
                                                <template x-if="b.layout === 'row'">
                                                    <div style="display:flex;gap:6px;align-items:baseline">
                                                        <span style="flex:none" x-text="b.label"></span>
                                                        <span :style="'flex:1;text-align:left;' + (b.value_bold ? 'font-weight:700' : 'font-weight:inherit')" x-text="valueText(b)"></span>
                                                    </div>
                                                </template>
                                                <template x-if="b.layout === 'inline'">
                                                    <div :style="`text-align:${cssAlign(b)}`">
                                                        <span x-text="b.label ? b.label + ' ' : ''"></span><span :style="b.value_bold ? 'font-weight:700' : ''" x-text="valueText(b)"></span>
                                                    </div>
                                                </template>
                                                <template x-if="b.layout === 'stacked'">
                                                    <div :style="`text-align:${cssAlign(b)}`">
                                                        <div x-text="b.label"></div>
                                                        <div :style="b.value_bold ? 'font-weight:700' : ''" x-text="valueText(b)"></div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- الأصناف --}}
                            <template x-if="kind(b) === 'items'">
                                <div :style="textStyle(b)">
                                    <template x-for="(it, n) in sample.items" :key="n">
                                        <div :style="`padding-bottom:${s(b.spacing)}px;` + (b.row_divider && n < sample.items.length - 1 ? `border-bottom:${Math.max(1, s(1))}px dashed #000;margin-bottom:${s(b.spacing)}px;` : '')">
                                            <div style="display:flex;align-items:flex-start;gap:4px">
                                                <span :style="`min-width:${s(b.size * 1.9)}px;font-weight:700`" x-text="b.qty_format.replace('{qty}', it.qty)"></span>
                                                <span style="flex:1" x-text="it.name"></span>
                                                <span x-show="b.show_price" style="font-weight:400" x-text="money(it.price)"></span>
                                            </div>
                                            <template x-if="b.show_options && it.options">
                                                <div :style="`padding-right:${s(b.size * 1.9)}px;font-size:${s(Math.max(8, b.size - 3))}px;font-weight:400;font-style:normal;text-decoration:none`" x-text="it.options"></div>
                                            </template>
                                            <template x-if="b.show_notes && it.note">
                                                <div :style="`padding-right:${s(b.size * 1.9)}px;font-size:${s(b.note_size)}px;font-weight:700;font-style:normal;text-decoration:none`" x-text="'ملاحظة: ' + it.note"></div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- خط --}}
                            <template x-if="kind(b) === 'divider'">
                                <div :style="dividerStyle(b)"></div>
                            </template>

                            {{-- مسافة --}}
                            <template x-if="kind(b) === 'spacer'">
                                <div :style="`height:${s(b.height)}px;` + (sel === i ? 'background:repeating-linear-gradient(45deg,#fde68a55 0 6px,transparent 6px 12px)' : '')"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
            <div class="rd-hint" style="margin-top:8px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <span>المعاينة ببيانات طلب تجريبي. الطابعة أبيض وأسود بس.</span>
                <label style="display:inline-flex;gap:4px;align-items:center"><input type="checkbox" x-model="showHidden"> وري القطع المخفية</label>
                <label style="display:inline-flex;gap:4px;align-items:center"><input type="checkbox" x-model="samplePaid" x-on:change="sample.cash = samplePaid ? 0 : 49"> الطلب مدفوع بالكامل</label>
            </div>
        </div>

        {{-- الخصائص --}}
        <div class="rd-card rd-sticky">
            <template x-if="sel !== null && blocks[sel]">
                <div x-data="{}">
                    <h3 x-text="'خصائص: ' + blockName(blocks[sel])"></h3>
                    <template x-for="b in [blocks[sel]]" :key="b._k">
                        <div>
                            {{-- النص --}}
                            <template x-if="b.type === 'text' || b.type === 'copy_title'">
                                <div class="rd-field">
                                    <label>النص</label>
                                    <textarea rows="2" x-model="b.text" x-ref="txt"></textarea>
                                    <div class="rd-vars">
                                        <template x-for="(lbl, v) in meta.vars" :key="v">
                                            <button type="button" :title="lbl" x-on:click="b.text = (b.text || '') + v" x-text="lbl"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-if="b.type === 'datetime'">
                                <div class="rd-field">
                                    <label>الشكل</label>
                                    <select x-model="b.format">
                                        <option value="date_time">التاريخ والوقت</option>
                                        <option value="date">التاريخ بس</option>
                                        <option value="time">الوقت بس</option>
                                    </select>
                                </div>
                            </template>

                            <template x-if="kind(b) === 'value'">
                                <div>
                                    <div class="rd-field">
                                        <label>العنوان (يطلع قبل القيمة)</label>
                                        <input type="text" x-model="b.label">
                                    </div>
                                    <div class="rd-field">
                                        <label>طريقة العرض</label>
                                        <div class="rd-seg">
                                            <button type="button" :class="b.layout === 'row' && 'on'" x-on:click="b.layout = 'row'">العنوان ← القيمة</button>
                                            <button type="button" :class="b.layout === 'inline' && 'on'" x-on:click="b.layout = 'inline'">في سطر</button>
                                            <button type="button" :class="b.layout === 'stacked' && 'on'" x-on:click="b.layout = 'stacked'">تحت بعض</button>
                                        </div>
                                    </div>
                                    <div class="rd-chk">
                                        <label><input type="checkbox" x-model="b.value_bold"> القيمة عريضة</label>
                                        <label x-show="isMoney(b)"><input type="checkbox" x-model="b.currency"> «د.ل» بعد الرقم</label>
                                    </div>
                                    <template x-if="b.type === 'cash_to_collect'">
                                        <div class="rd-field">
                                            <label>النص لو الطلب مدفوع بالكامل</label>
                                            <input type="text" x-model="b.paid_text">
                                        </div>
                                    </template>
                                    <div class="rd-hint" style="margin-bottom:8px">القطعة تختفي لوحدها لو القيمة فاضية (مثلاً ما فيش خصم أو ما فيش سائق).</div>
                                </div>
                            </template>

                            <template x-if="kind(b) === 'items'">
                                <div>
                                    <div class="rd-chk">
                                        <label><input type="checkbox" x-model="b.show_price"> السعر</label>
                                        <label><input type="checkbox" x-model="b.show_options"> الإضافات</label>
                                        <label><input type="checkbox" x-model="b.show_notes"> ملاحظة الصنف</label>
                                        <label><input type="checkbox" x-model="b.row_divider"> خط بين الأصناف</label>
                                    </div>
                                    <div class="rd-row2">
                                        <div class="rd-field">
                                            <label>شكل الكمية</label>
                                            <select x-model="b.qty_format">
                                                <option value="{qty}×">2×</option>
                                                <option value="{qty} x">2 x</option>
                                                <option value="x{qty}">x2</option>
                                                <option value="({qty})">(2)</option>
                                            </select>
                                        </div>
                                        <div class="rd-field">
                                            <label>خط الملاحظة</label>
                                            <input type="number" min="8" max="60" x-model.number="b.note_size">
                                        </div>
                                    </div>
                                    <div class="rd-field">
                                        <label>المسافة بين الأصناف</label>
                                        <div class="rd-range"><input type="range" min="0" max="40" x-model.number="b.spacing"><input type="number" min="0" max="40" x-model.number="b.spacing"></div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="kind(b) === 'divider'">
                                <div>
                                    <div class="rd-field">
                                        <label>شكل الخط</label>
                                        <div class="rd-seg">
                                            <button type="button" :class="b.style === 'solid' && 'on'" x-on:click="b.style = 'solid'">متصل</button>
                                            <button type="button" :class="b.style === 'dashed' && 'on'" x-on:click="b.style = 'dashed'">متقطع</button>
                                            <button type="button" :class="b.style === 'double' && 'on'" x-on:click="b.style = 'double'">مزدوج</button>
                                        </div>
                                    </div>
                                    <div class="rd-field">
                                        <label>السُمك</label>
                                        <div class="rd-range"><input type="range" min="1" max="10" x-model.number="b.thickness"><input type="number" min="1" max="10" x-model.number="b.thickness"></div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="kind(b) === 'spacer' || kind(b) === 'logo'">
                                <div class="rd-field">
                                    <label x-text="kind(b) === 'logo' ? 'ارتفاع الشعار' : 'ارتفاع المسافة'"></label>
                                    <div class="rd-range"><input type="range" :min="kind(b) === 'logo' ? 16 : 0" max="240" x-model.number="b.height"><input type="number" min="0" max="240" x-model.number="b.height"></div>
                                </div>
                            </template>

                            {{-- التنسيق العام --}}
                            <template x-if="hasText(b)">
                                <div>
                                    <div class="rd-field">
                                        <label>حجم الخط</label>
                                        <div class="rd-range"><input type="range" min="8" max="72" x-model.number="b.size"><input type="number" min="8" max="72" x-model.number="b.size"></div>
                                    </div>
                                    <div class="rd-chk">
                                        <label><input type="checkbox" x-model="b.bold"> <b>عريض</b></label>
                                        <label><input type="checkbox" x-model="b.italic"> <i>مائل</i></label>
                                        <label><input type="checkbox" x-model="b.underline"> <u>تحته خط</u></label>
                                        <label><input type="checkbox" x-model="b.boxed"> داخل إطار</label>
                                        <label><input type="checkbox" x-model="b.inverted"> أبيض على أسود</label>
                                    </div>
                                </div>
                            </template>

                            <template x-if="kind(b) !== 'divider' && kind(b) !== 'spacer' && kind(b) !== 'items'">
                                <div class="rd-field">
                                    <label>المحاذاة</label>
                                    <div class="rd-seg">
                                        <button type="button" :class="b.align === 'start' && 'on'" x-on:click="b.align = 'start'">يمين</button>
                                        <button type="button" :class="b.align === 'center' && 'on'" x-on:click="b.align = 'center'">وسط</button>
                                        <button type="button" :class="b.align === 'end' && 'on'" x-on:click="b.align = 'end'">يسار</button>
                                    </div>
                                </div>
                            </template>

                            <div class="rd-row2">
                                <div class="rd-field">
                                    <label>مسافة فوق</label>
                                    <input type="number" min="0" max="80" x-model.number="b.margin_top">
                                </div>
                                <div class="rd-field">
                                    <label>مسافة تحت</label>
                                    <input type="number" min="0" max="80" x-model.number="b.margin_bottom">
                                </div>
                            </div>

                            <div class="rd-chk">
                                <label><input type="checkbox" x-model="b.hidden"> مخفية (ما تنطبعش)</label>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="sel === null || !blocks[sel]">
                <div class="rd-hint" style="margin-bottom:12px">اضغط على أي جزء في الواصل أو في القائمة باش تعدّله.</div>
            </template>

            <h3 style="margin-top:12px;border-top:1px solid var(--rd-border);padding-top:12px">الورقة كاملة</h3>
            <div class="rd-row2">
                <div class="rd-field">
                    <label>الهامش الجانبي</label>
                    <input type="number" min="0" max="60" x-model.number="layout.page.padding_x">
                </div>
                <div class="rd-field">
                    <label>حجم الخط الأساسي</label>
                    <input type="number" min="8" max="40" x-model.number="layout.page.base_size">
                </div>
                <div class="rd-field">
                    <label>هامش فوق</label>
                    <input type="number" min="0" max="120" x-model.number="layout.page.padding_top">
                </div>
                <div class="rd-field">
                    <label>هامش تحت (قبل القص)</label>
                    <input type="number" min="0" max="200" x-model.number="layout.page.padding_bottom">
                </div>
            </div>
            <div class="rd-field">
                <label>تباعد الأسطر</label>
                <div class="rd-range"><input type="range" min="0.9" max="2.5" step="0.05" x-model.number="layout.page.line_height"><input type="number" min="0.9" max="2.5" step="0.05" x-model.number="layout.page.line_height"></div>
            </div>
            <div class="rd-field">
                <label>سُمك الخط العادي</label>
                <div class="rd-seg">
                    <button type="button" :class="layout.page.weight === 'normal' && 'on'" x-on:click="layout.page.weight = 'normal'">رفيع</button>
                    <button type="button" :class="layout.page.weight === 'medium' && 'on'" x-on:click="layout.page.weight = 'medium'">متوسط</button>
                    <button type="button" :class="layout.page.weight === 'bold' && 'on'" x-on:click="layout.page.weight = 'bold'">عريض</button>
                </div>
            </div>
            <div class="rd-hint">الأرقام بالنقاط على ورق 5.5 سم — على ورق 8 سم كل شي يكبر بنفس النسبة.</div>
        </div>
    </div>
</div>


</x-filament-panels::page>
