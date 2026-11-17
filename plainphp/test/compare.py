#!/usr/bin/python3 -I
"""compare.py <out dir A> <out dir B>: the records of two runs of golden.py
(A: the nophp version or the expected/ directory, B: the plain PHP version),
ignoring what may differ: names of submit buttons, empty lines and
indentation of the HTML, "&" / "&amp;" in the form action, the empty path
segment of nophp's redirects (.../index.php//page.html)."""
import sys, os, re, difflib

def norm(s):
    head, _, rest = s.partition("\n---- body\n")
    body, _, tail = rest.partition("\n---- outbox (")
    body = re.sub(r'(<input type="?submit"?) name="[^"]*"', r"\1", body, flags=re.I)
    body = re.sub(r'action="\?([^"]*)"', lambda m: 'action="?%s"' % m.group(1).replace("&amp;", "&"), body)
    lines = [l.strip() for l in body.split("\n")]
    body = "\n".join(l for l in lines if l != "")
    head = head.replace("/index.php//", "/index.php/nofmt/")
    return head + "\n---- body\n" + body + "\n---- outbox (" + tail

a, b = sys.argv[1], sys.argv[2]
names = sorted(set(os.listdir(a)) | set(os.listdir(b)))
bad = 0
for n in names:
    pa, pb = os.path.join(a, n), os.path.join(b, n)
    if not (os.path.exists(pa) and os.path.exists(pb)):
        print("ONLY ONE SIDE:", n); bad += 1; continue
    sa, sb = norm(open(pa).read()), norm(open(pb).read())
    if sa == sb:
        continue
    bad += 1
    d = list(difflib.unified_diff(sa.split("\n"), sb.split("\n"), "A/" + n, "B/" + n, lineterm="", n=1))
    print("\n".join(l[:230] for l in d[:int(os.environ.get("MAXLINES", "14"))]))
print("%d file(s), %d differ" % (len(names), bad))
sys.exit(1 if bad else 0)
