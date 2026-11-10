#!/usr/bin/env python3
"""
WorkNest HR commit guard. Refuses a commit that would leak the reference organisation's data or any secret.

Checks every tracked / staged text file (or the paths given) for:
  - files that must never be committed (config.php, .env, raw exports, keys);
  - the reference organisation's name, domains and employee-code shapes;
  - personal / financial identifiers: tax IDs, national IDs, bank IFSC + account, GST numbers, card numbers;
  - secrets: private keys, API tokens, passwords in assignments;
  - real-looking mobile numbers and email addresses (example.com / example.org and test domains are fine);
  - any word listed in .guard-deny (local, never committed: names, codes and places to keep out).

  python3 tools/guard.py            # tracked + staged files
  python3 tools/guard.py path ...   # just these
Exit code 1 with a list when something is found.
"""
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SKIP_SELF = {"tools/guard.py"}
BLOCK_FILES = re.compile(r"(^|/)(config\.php|\.env(\..*)?|.*\.(pem|key|p12|pfx|sqlite|db|bak)|.*vault.*\.json|.*export.*\.(csv|xlsx|json))$", re.I)
TEXT_EXT = re.compile(r"\.(php|js|css|html|md|json|sql|txt|yml|yaml|py|sh|htaccess|xml|csv)$|(^|/)\.htaccess$", re.I)

RULES = [
    ("reference organisation name", re.compile(r"\bS\s?R\s?P\b|Crane\s+Controls|srpcrane|srppeople|materialhandlingsystems", re.I)),
    ("reference employee code", re.compile(r"\bSRP[A-Z_]{1,4}\d{1,5}\b", re.I)),
    ("tax ID (PAN shape)", re.compile(r"\b[A-Z]{3}[ABCFGHLJPT][A-Z]\d{4}[A-Z]\b")),
    ("GST number", re.compile(r"\b\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]\b")),
    ("national ID (12 digits)", re.compile(r"\b[2-9]\d{3}\s?\d{4}\s?\d{4}\b")),
    ("bank IFSC", re.compile(r"\b[A-Z]{4}0[A-Z0-9]{6}\b")),
    ("card number", re.compile(r"\b(?:4\d{3}|5[1-5]\d{2}|6\d{3})(?:[ -]?\d{4}){3}\b")),
    ("private key", re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----")),
    ("API token", re.compile(r"\b(sk-[A-Za-z0-9]{20,}|ghp_[A-Za-z0-9]{30,}|AIza[0-9A-Za-z_\-]{30,}|xox[bpa]-[A-Za-z0-9-]{10,})\b")),
    ("password in code", re.compile(r"""['"](db_pass|password|secret|api_key|token)['"]\s*=>\s*['"][^'"]{6,}['"]""", re.I)),
    ("mobile number", re.compile(r"(?<![\d.])(?:\+?91[ -]?)?[6-9]\d{9}(?!\d)")),
    ("email address", re.compile(r"\b[A-Za-z0-9._%+-]+@(?!example\.(?:com|org|net)\b|test\b|localhost\b)[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")),
]
# Lines that may legitimately show a pattern (placeholders in docs / samples).
ALLOW_LINE = re.compile(r"guard:allow|CHANGE_ME|XXXX|ABCDE1234F|example\.com", re.I)


def files():
    if len(sys.argv) > 1:
        return [os.path.relpath(os.path.abspath(p), ROOT) for p in sys.argv[1:]]
    try:
        out = subprocess.run(["git", "ls-files", "--cached", "--others", "--exclude-standard"], cwd=ROOT,
                             capture_output=True, text=True, check=True).stdout.split("\n")
    except Exception:
        out = [os.path.relpath(os.path.join(d, f), ROOT) for d, _, fs in os.walk(ROOT) if "/.git" not in d for f in fs]
    return [f for f in out if f]


def deny_words():
    p = os.path.join(ROOT, ".guard-deny")
    if not os.path.isfile(p):
        return None
    words = [w.strip() for w in open(p, encoding="utf-8") if w.strip() and not w.startswith("#")]
    return re.compile(r"\b(" + "|".join(re.escape(w) for w in words) + r")\b", re.I) if words else None


def main():
    deny = deny_words()
    problems = []
    for rel in files():
        rel = rel.replace(os.sep, "/")
        if rel in SKIP_SELF or rel.startswith(".git/"):
            continue
        if BLOCK_FILES.search(rel) and rel not in ("config.sample.php",):
            problems.append(f"{rel}: this file must never be committed")
            continue
        if not TEXT_EXT.search(rel):
            continue
        path = os.path.join(ROOT, rel)
        if not os.path.isfile(path) or os.path.getsize(path) > 3_000_000:
            continue
        with open(path, encoding="utf-8", errors="ignore") as fh:
            for no, line in enumerate(fh, 1):
                if ALLOW_LINE.search(line):
                    continue
                for label, rx in RULES:
                    if rx.search(line):
                        problems.append(f"{rel}:{no}: {label}")
                if deny and deny.search(line):
                    problems.append(f"{rel}:{no}: word on .guard-deny")
    if problems:
        print("guard: refused —", len(problems), "problem(s)")
        for p in problems[:200]:
            print("  " + p)
        sys.exit(1)
    print("guard: clean")


if __name__ == "__main__":
    main()
