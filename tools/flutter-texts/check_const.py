"""فحص: ما فيش const يحتوي tr( أو Texts. — ولا ملف أقواسه مكسورة."""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from tr_wrap import balanced, matching, scan  # noqa: E402

bad = 0
for root in sys.argv[1:]:
    for dp, _, fs in os.walk(root):
        for f in fs:
            if not f.endswith('.dart'):
                continue
            p = os.path.join(dp, f)
            src = open(p, encoding='utf-8').read()
            if not balanced(src):
                bad += 1
                print('UNBALANCED', p)
            toks, _ = scan(src)
            for i, t in enumerate(toks):
                if t.type == 'id' and t.text == 'const':
                    j = i + 1
                    while j < len(toks) and (toks[j].type == 'id' or toks[j].text == '.'):
                        j += 1
                    if j < len(toks) and toks[j].text == '<':
                        while toks[j].text not in ('(', '[', '{'):
                            j += 1
                    if j < len(toks) and toks[j].text in ('(', '[', '{'):
                        k = matching(toks, j)
                        if any(x.type == 'id' and x.text in ('tr', 'Texts', 'ReceiptConfig') for x in toks[j:k]):
                            bad += 1
                            print('CONST WITH RUNTIME CALL:', p, src.count('\n', 0, t.start) + 1)
                # نص عربي باقي بدون tr
                if t.type == 'str':
                    import re
                    if any(pt[0] == 'lit' and re.search(r'[؀-ۿ]', pt[1]) for pt in t.parts):
                        prev = toks[i - 1].text if i else ''
                        if prev != '(' or toks[i - 2].text != 'tr':
                            print('ARABIC NOT WRAPPED:', p, src.count('\n', 0, t.start) + 1, t.text[:40])
print('issues:', bad)
