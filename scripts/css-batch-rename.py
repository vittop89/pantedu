#!/usr/bin/env python3
"""Batch rename multiple CSS classes — ULTRA-CONSERVATIVE patterns only.

Renames CSS class names in safe contexts:
- .css files: `.foo` selector declarations
- HTML/PHP: `class="..."` attribute values
- JS: classList.add/remove/toggle/contains/replace('foo') args
- JS: querySelector('.foo'), querySelectorAll, closest, matches, $('foo')

Does NOT rename:
- JS object properties (`obj.foo`, `foo.bar`)
- Data field names (`key === "foo"`, switch cases)
- PHP variable names (`$foo`)
- Standalone quoted strings without function context
"""
import argparse
import re
import os
import sys
from pathlib import Path

ROOT = Path("C:/Users/vitto/progetti_vscode/pantedu")
EXCLUDE_DIRS = {"node_modules", "vendor", "storage", "logs", ".git", "build", "dist"}
EXCLUDE_PATH_PARTS = ("tests/e2e/screenshots", "tests/e2e-results", "public/build")
EXTS = (".php", ".js", ".mjs", ".ts", ".html", ".htm", ".twig", ".blade.php", ".css")


def find_files_with(name):
    files = []
    rx = re.compile(r"\b" + re.escape(name) + r"\b")
    for dirpath, dirnames, filenames in os.walk(ROOT):
        dirnames[:] = [d for d in dirnames if d not in EXCLUDE_DIRS]
        rel = os.path.relpath(dirpath, ROOT).replace("\\", "/")
        if any(x in rel for x in EXCLUDE_PATH_PARTS):
            continue
        for fn in filenames:
            if not any(fn.endswith(e) for e in EXTS):
                continue
            if "main.bundle.css" in fn:
                continue
            p = Path(dirpath) / fn
            try:
                text = p.read_text(encoding="utf-8", errors="ignore")
            except Exception:
                continue
            if rx.search(text):
                files.append(p.relative_to(ROOT).as_posix())
    return files


def replace_in_css(text, name, new_name):
    """In CSS files: replace .foo selectors only."""
    esc = re.escape(name)
    pattern_dot = re.compile(r"\." + esc + r"(?![\w-])")
    return pattern_dot.subn("." + new_name, text)


def replace_in_html(text, name, new_name):
    """In HTML/PHP/Twig files: replace class= attribute tokens only."""
    total = 0
    esc = re.escape(name)

    # class="...foo..." - same line, no newlines
    pattern_dq = re.compile(
        r'(class(?:Name)?\s*=\s*"[^"\n]*?)(?<=["\s])' + esc + r'(?=["\s])'
    )
    text, n = pattern_dq.subn(lambda m: m.group(1) + new_name, text)
    total += n

    # class='...foo...'
    pattern_sq = re.compile(
        r"(class(?:Name)?\s*=\s*'[^'\n]*?)(?<=['\s])" + esc + r"(?=['\s])"
    )
    text, n = pattern_sq.subn(lambda m: m.group(1) + new_name, text)
    total += n

    return text, total


def replace_in_js(text, name, new_name):
    """In JS files: classList.X('foo'), querySelector('.foo'), $('.foo'),
    plus jQuery DOM traversal methods (.find/.siblings/.children/.parents/etc)."""
    total = 0
    esc = re.escape(name)

    # classList.add/remove/toggle/contains/replace('foo')
    pattern_cl = re.compile(
        r"(classList\s*\.\s*(?:add|remove|toggle|contains|replace)\s*\(\s*[\"'])"
        + esc + r"(?=[\"'])"
    )
    text, n = pattern_cl.subn(lambda m: m.group(1) + new_name, text)
    total += n

    # DOM API + jQuery traversal: querySelector(All), closest, matches,
    # $('selector'), .find('selector'), .children, .siblings, .parents,
    # .parent, .next, .prev, .has, .is, .not, .filter, .add (jQuery method names
    # that take selectors). Match in single/double/backtick strings.
    fn_pattern = (
        r"(?:querySelector(?:All)?|closest|matches|getElementsByClassName"
        r"|\$|\.find|\.children|\.siblings|\.parents|\.parent|\.next|\.prev"
        r"|\.has|\.is|\.not|\.filter|\.add|\.end|\.contains|\.live"
        r"|\.delegate|\.on|\.off|\.one|\.trigger|\.css|\.attr"
        r"|\.remove|\.empty|\.html|\.append|\.prepend|\.before|\.after"
        r")"
    )
    for q_open, q_close in [('"', '"'), ("'", "'"), ("`", "`")]:
        pattern = re.compile(
            r"(" + fn_pattern + r"\s*\(\s*" + re.escape(q_open) + r"[^" +
            re.escape(q_open + q_close) + r"\n]*?)\." + esc + r"(?![\w-])"
        )
        text, n = pattern.subn(lambda m: m.group(1) + "." + new_name, text)
        total += n

    # HTML-in-template-literal: class= attribute within backtick string
    pattern_class_bt = re.compile(
        r"(`[^`]*class(?:Name)?\s*=\s*[\"'][^\"'`\n]*?)(?<=[\"'\s])"
        + esc + r"(?=[\"'\s])"
    )
    text, n = pattern_class_bt.subn(lambda m: m.group(1) + new_name, text)
    total += n

    return text, total


def replace_in_text(text, name, new_name, file_ext=""):
    if file_ext == ".css":
        return replace_in_css(text, name, new_name)
    elif file_ext in (".php", ".html", ".htm", ".twig", ".blade.php"):
        # PHP/HTML can have both class= attrs (HTML output) AND
        # in PHP also has JS-like patterns (server-side rendering)
        text, n1 = replace_in_html(text, name, new_name)
        text, n2 = replace_in_js(text, name, new_name)
        return text, n1 + n2
    else:  # .js, .mjs, .ts
        text, n1 = replace_in_js(text, name, new_name)
        text, n2 = replace_in_html(text, name, new_name)
        return text, n1 + n2


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--map", required=True)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    renames = []
    with open(args.map, encoding="utf-8") as f:
        for line in f:
            line = line.rstrip("\n")
            if not line or line.startswith("#") or line.startswith("old_name"):
                continue
            parts = line.split("\t")
            if len(parts) < 2:
                continue
            renames.append((parts[0].strip(), parts[1].strip()))

    print(f"[batch] Renames to apply: {len(renames)}")

    total_files = set()
    total_replacements = 0

    for old, new in renames:
        files = find_files_with(old)
        if not files:
            print(f"  [skip] {old}: 0 files")
            continue
        n_files = 0
        rep_class = 0
        for relf in files:
            p = ROOT / relf
            try:
                text = p.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                continue
            ext = p.suffix
            if relf.endswith(".blade.php"):
                ext = ".blade.php"
            new_text, n = replace_in_text(text, old, new, file_ext=ext)
            if n > 0:
                if not args.dry_run:
                    p.write_text(new_text, encoding="utf-8")
                rep_class += n
                n_files += 1
                total_files.add(relf)
        print(f"  {old} -> {new}: {rep_class} in {n_files} files{' (dry)' if args.dry_run else ''}")
        total_replacements += rep_class

    print(f"\n{'[dry-run]' if args.dry_run else '[applied]'} {total_replacements} replacements in {len(total_files)} unique files")


if __name__ == "__main__":
    main()
