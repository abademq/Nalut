#!/usr/bin/env python3
"""
يلفّ كل النصوص العربية في كود Flutter بـ tr('...') باش الإدارة تقدر تعدّلها.

- النص مع متغيرات: 'رقم ${o.code}' → tr('رقم {code}', {'code': o.code})
- const اللي يمنع استدعاء tr ينشال (const Text('..') → Text(tr('..')))
- const declarations / case / default params ما تتلمسش (لازم تكون ثابتة)
- يطلع كتالوج JSON بكل النصوص للسيرفر.

الاستعمال: tr_wrap.py <lib_dir> <app> <catalog_out.json> [--dry]
"""
import json
import os
import re
import sys

ARABIC = re.compile(r'[\u0600-\u06FF]')
IDENT_START = re.compile(r'[A-Za-z_$]')
IDENT_CHAR = re.compile(r'[A-Za-z0-9_$]')

EXPR_PREV = {'(', ',', '[', ':', '=', '=>', 'return', '?', '??', '||', '&&', '+', '-', '!',
             '==', '!=', 'in', 'yield', 'await', '...', '...?', '..', '?..', '{', 'else', ')'}
# '{' handled specially (block vs map literal) — see classify


class Tok:
    __slots__ = ('type', 'start', 'end', 'text', 'parts', 'raw', 'depth')

    def __init__(self, type_, start, end, text, parts=None, raw=False):
        self.type = type_
        self.start = start
        self.end = end
        self.text = text
        self.parts = parts
        self.raw = raw

    def __repr__(self):
        return f'{self.type}:{self.text!r}'


PUNCTS = ['...?', '?..', '...', '??=', '>>>=', '<<=', '>>=', '=>', '==', '!=', '<=', '>=', '&&', '||',
          '??', '?.', '..', '++', '--', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '~/', '<<']


class LexError(Exception):
    pass


def scan(src, pos=0, stop_brace=False):
    """يرجع (tokens, end). لو stop_brace: يوقف عند } مش مفتوح."""
    toks = []
    n = len(src)
    depth = 0
    while pos < n:
        c = src[pos]
        if c in ' \t\r\n':
            pos += 1
            continue
        if src.startswith('//', pos):
            e = src.find('\n', pos)
            pos = n if e < 0 else e + 1
            continue
        if src.startswith('/*', pos):
            d, p = 1, pos + 2
            while p < n and d:
                if src.startswith('/*', p):
                    d += 1
                    p += 2
                elif src.startswith('*/', p):
                    d -= 1
                    p += 2
                else:
                    p += 1
            pos = p
            continue
        # strings (with optional r prefix)
        raw = False
        q = pos
        if c in 'rR' and pos + 1 < n and src[pos + 1] in '\'"':
            raw = True
            q = pos + 1
        if src[q] in '\'"':
            tok, pos = scan_string(src, pos, q, raw)
            toks.append(tok)
            continue
        if IDENT_START.match(c):
            e = pos + 1
            while e < n and IDENT_CHAR.match(src[e]):
                e += 1
            toks.append(Tok('id', pos, e, src[pos:e]))
            pos = e
            continue
        if c.isdigit():
            m = re.match(r'0[xX][0-9a-fA-F]+|\d+(\.\d+)?([eE][+-]?\d+)?', src[pos:])
            toks.append(Tok('num', pos, pos + m.end(), m.group(0)))
            pos += m.end()
            continue
        if c == '}' and stop_brace and depth == 0:
            return toks, pos
        if c in '({[':
            depth += 1
        elif c in ')}]':
            depth -= 1
        for p in PUNCTS:
            if src.startswith(p, pos):
                toks.append(Tok('p', pos, pos + len(p), p))
                pos += len(p)
                break
        else:
            toks.append(Tok('p', pos, pos + 1, c))
            pos += 1
    if stop_brace:
        raise LexError('unterminated interpolation')
    return toks, pos


def scan_string(src, start, q, raw):
    n = len(src)
    quote = src[q]
    triple = src.startswith(quote * 3, q)
    delim = quote * 3 if triple else quote
    pos = q + len(delim)
    parts = []
    lit_start = pos
    while True:
        if pos >= n:
            raise LexError(f'unterminated string at {start}')
        if src.startswith(delim, pos):
            if pos > lit_start:
                parts.append(('lit', src[lit_start:pos]))
            pos += len(delim)
            break
        c = src[pos]
        if not triple and c == '\n':
            raise LexError(f'newline in string at {start}')
        if c == '\\' and not raw:
            pos += 2
            continue
        if c == '$' and not raw:
            if pos > lit_start:
                parts.append(('lit', src[lit_start:pos]))
            if pos + 1 < n and src[pos + 1] == '{':
                _, e = scan(src, pos + 2, stop_brace=True)
                parts.append(('expr', pos + 2, e))
                pos = e + 1
            else:
                e = pos + 1
                while e < n and re.match(r'[A-Za-z0-9_]', src[e]):
                    e += 1
                parts.append(('ident', pos + 1, e))
                pos = e
            lit_start = pos
            continue
        pos += 1
    return Tok('str', start, pos, src[start:pos], parts, raw), pos


ESC = {'n': '\n', 'r': '\r', 't': '\t', 'b': '\b', 'f': '\f', 'v': '\v'}


def decode(lit, raw):
    if raw:
        return lit
    out, i = [], 0
    while i < len(lit):
        c = lit[i]
        if c == '\\' and i + 1 < len(lit):
            d = lit[i + 1]
            if d in ESC:
                out.append(ESC[d]); i += 2
            elif d == 'x':
                out.append(chr(int(lit[i + 2:i + 4], 16))); i += 4
            elif d == 'u':
                if lit[i + 2] == '{':
                    e = lit.index('}', i)
                    out.append(chr(int(lit[i + 3:e], 16))); i = e + 1
                else:
                    out.append(chr(int(lit[i + 2:i + 6], 16))); i += 6
            else:
                out.append(d); i += 2
        else:
            out.append(c); i += 1
    return ''.join(out)


def encode(s):
    return "'" + (s.replace('\\', '\\\\').replace("'", "\\'").replace('$', '\\$')
                  .replace('\n', '\\n').replace('\r', '\\r').replace('\t', '\\t')) + "'"


SKIP_NAMES = {'toStringAsFixed', 'toString', 'padLeft', 'padRight', 'length', 'trim', 'toInt', 'round',
              'toUpperCase', 'toLowerCase', 'isEmpty', 'isNotEmpty', 'null', 'true', 'false', 'this',
              'widget', 'toDouble', 'abs', 'ceil', 'floor', 'toLocal', 'join', 'map', 'where', 'toList',
              'format', 'clamp', 'split', 'substring', 'replaceAll', 'context', 'of'}


def var_name(expr, used):
    ids = [i for i in re.findall(r'[A-Za-z_]\w*', expr) if i not in SKIP_NAMES and len(i.lstrip('_')) > 1]
    name = ids[-1].lstrip('_') if ids else 'value'
    base, k = name, 2
    while name in used:
        name = f'{base}{k}'
        k += 1
    used.add(name)
    return name


def matching(toks, i):
    """index of bracket matching toks[i]"""
    pairs = {'(': ')', '[': ']', '{': '}'}
    o = toks[i].text
    c = pairs[o]
    d = 0
    for j in range(i, len(toks)):
        t = toks[j].text if toks[j].type == 'p' else None
        if t == o:
            d += 1
        elif t == c:
            d -= 1
            if d == 0:
                return j
    raise LexError('unbalanced')


class Transformer:
    def __init__(self, app, group):
        self.app = app
        self.group = group
        self.catalog = []
        self.skipped = []

    def transform(self, src, base=0):
        toks, _ = scan(src)
        n = len(toks)

        # bracket owner for each token (innermost open bracket index)
        owner = [None] * n
        stack = []
        for i, t in enumerate(toks):
            owner[i] = stack[-1] if stack else None
            if t.type == 'p' and t.text in '([{':
                stack.append(i)
            elif t.type == 'p' and t.text in ')]}':
                if stack:
                    stack.pop()

        const_spans = []     # (start_tok, end_tok) expression consts
        frozen = []          # (start_tok, end_tok) regions that must stay const

        for i, t in enumerate(toks):
            if t.type != 'id':
                continue
            if t.text == 'case':
                j = i + 1
                while j < n and not (toks[j].type == 'p' and toks[j].text == ':'):
                    j += 1
                frozen.append((i, j))
                continue
            if t.text != 'const':
                continue
            prev = toks[i - 1].text if i > 0 else None
            is_expr = prev in EXPR_PREV
            if prev == '{' and i > 0:
                # { بعد ( أو , = map/set/named params — غير هيك بلوك
                o = i - 1
                pp = toks[o - 1].text if o > 0 else None
                is_expr = pp in ('(', ',', '=', ':', '=>', 'return', '[')
                # لكن داخل بلوك دالة: const x = ... declaration
                if is_expr and i + 2 < n and toks[i + 1].type == 'id' and toks[i + 2].text == '=':
                    is_expr = False
            if is_expr:
                j = i + 1
                while j < n and (toks[j].type == 'id' or toks[j].text == '.'):
                    j += 1
                if j < n and toks[j].text == '<':
                    d = 0
                    while j < n:
                        if toks[j].text == '<':
                            d += 1
                        elif toks[j].text == '>':
                            d -= 1
                            if d == 0:
                                j += 1
                                break
                        elif toks[j].text == '>>':
                            d -= 2
                            if d <= 0:
                                j += 1
                                break
                        j += 1
                if j < n and toks[j].text in ('(', '[', '{'):
                    const_spans.append((i, matching(toks, j)))
                continue
            # declaration: constructor declaration → nothing; otherwise freeze until ';'
            j = i + 1
            while j < n and (toks[j].type == 'id' or toks[j].text == '.'):
                j += 1
            if j < n and toks[j].text == '(':
                continue  # const ClassName(...) / const ClassName.named(...) — تعريف constructor
            d = 0
            k = i
            while k < n:
                x = toks[k].text if toks[k].type == 'p' else None
                if x in ('(', '[', '{'):
                    d += 1
                elif x in (')', ']', '}'):
                    d -= 1
                    if d < 0:
                        break
                elif x == ';' and d == 0:
                    break
                k += 1
            frozen.append((i, k))

        def in_spans(idx, spans):
            return [s for s in spans if s[0] < idx <= s[1]]

        edits = []
        remove_const = set()
        i = 0
        while i < n:
            t = toks[i]
            if t.type != 'str':
                i += 1
                continue
            j = i
            while j + 1 < n and toks[j + 1].type == 'str':
                j += 1
            group = toks[i:j + 1]
            gstart, gend = group[0].start, group[-1].end
            has_ar = any(p[0] == 'lit' and ARABIC.search(p[1]) for g in group for p in g.parts)
            prev = toks[i - 1].text if i > 0 else None
            reason = None
            if prev in ('import', 'export', 'part') or (i > 0 and toks[i - 1].text == '@'):
                reason = 'directive'
            elif in_spans(i, frozen):
                reason = 'const-declaration/case'
            elif prev == '=' and owner[i] is not None and toks[owner[i]].text in '{[' and owner[i] > 0 \
                    and toks[owner[i] - 1].text in ('(', ','):
                reason = 'default-parameter'

            if has_ar and reason is None:
                edits.append((gstart, gend, self.wrap(src, group)))
                for s in in_spans(i, const_spans):
                    remove_const.add(s[0])
            else:
                if has_ar:
                    line = src.count('\n', 0, gstart) + 1
                    self.skipped.append((self.group, line, reason, src[gstart:gend][:60]))
                if reason is None:
                    # نصوص عربية ممكن تكون داخل ${...}
                    for g in group:
                        for p in g.parts:
                            if p[0] == 'expr':
                                inner = src[p[1]:p[2]]
                                new = self.transform(inner)
                                if new != inner:
                                    edits.append((p[1], p[2], new))
            i = j + 1

        for ci in remove_const:
            t = toks[ci]
            e = t.end
            while e < len(src) and src[e] in ' \t':
                e += 1
            edits.append((t.start, e, ''))

        edits.sort(key=lambda e: e[0], reverse=True)
        out = src
        last = None
        for s, e, r in edits:
            if last is not None and e > last:
                raise LexError(f'overlapping edits at {s}')
            out = out[:s] + r + out[e:]
            last = s
        return out

    def wrap(self, src, group):
        text = []
        args = []
        used = set()
        for g in group:
            for p in g.parts:
                if p[0] == 'lit':
                    text.append(decode(p[1], g.raw))
                else:
                    expr = src[p[1]:p[2]]
                    name = var_name(expr, used)
                    code = self.transform(expr) if p[0] == 'expr' else expr
                    args.append((name, code.strip()))
                    text.append('{' + name + '}')
        s = ''.join(text)
        self.catalog.append({'text': s, 'group': self.group,
                             'vars': ' '.join('{' + a[0] + '}' for a in args) or None})
        if not args:
            return f'tr({encode(s)})'
        amap = ', '.join(f"'{a}': {c}" for a, c in args)
        return f'tr({encode(s)}, {{{amap}}})'


def balanced(src):
    toks, _ = scan(src)
    d = []
    pairs = {')': '(', ']': '[', '}': '{'}
    for t in toks:
        if t.type == 'p' and t.text in '([{':
            d.append(t.text)
        elif t.type == 'p' and t.text in ')]}':
            if not d or d.pop() != pairs[t.text]:
                return False
    return not d


def main():
    lib, app, out = sys.argv[1], sys.argv[2], sys.argv[3]
    dry = '--dry' in sys.argv
    catalog, skipped, changed = [], [], []
    for root, _, files in os.walk(lib):
        for f in sorted(files):
            if not f.endswith('.dart') or f == 'texts.dart':
                continue
            path = os.path.join(root, f)
            src = open(path, encoding='utf-8').read()
            if not balanced(src):
                print('!! unbalanced BEFORE', path)
            tf = Transformer(app, f[:-5])
            new = tf.transform(src)
            catalog += tf.catalog
            skipped += tf.skipped
            if new != src:
                if not balanced(new):
                    raise SystemExit(f'!! unbalanced AFTER {path}')
                rel = os.path.relpath(lib, root)
                imp = 'texts.dart' if rel == '.' else '/'.join(['..'] * rel.count('..')) + '/texts.dart'
                if f"import '{imp}';" not in new:
                    imports = list(re.finditer(r"^import [^\n]+;\n", new, re.M))
                    pos = imports[-1].end() if imports else 0
                    new = new[:pos] + f"import '{imp}';\n" + new[pos:]
                changed.append((path, len(tf.catalog)))
                if not dry:
                    open(path, 'w', encoding='utf-8').write(new)
    # كتالوج بدون تكرار
    seen, uniq = set(), []
    for c in catalog:
        if c['text'] in seen:
            continue
        seen.add(c['text'])
        uniq.append(c)
    if not dry:
        json.dump(uniq, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    for p, k in changed:
        print(f'{k:4d}  {p}')
    print(f'total texts: {len(uniq)} (wrapped {len(catalog)})')
    for s in skipped:
        print('SKIP', s)


if __name__ == '__main__':
    main()
