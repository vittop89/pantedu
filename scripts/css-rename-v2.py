#!/usr/bin/env python3
"""CSS class rename v2 — string-aware, JS-identifier-safe.

Renames CSS class names in:
- CSS files: `.classname` selectors only
- HTML/PHP/Twig: `class="..."` attribute values
- JS files: `".classname"` / `'.classname'` strings + classList args
- Test files: querySelector, classList, etc.

Does NOT rename:
- JS variable names (`const classname = ...`)
- Function names
- Object property names not in string context

Usage:
  python scripts/css-rename-v2.py --from=NAME --to=NEW --files-from=FILE
"""
import argparse
import re
from pathlib import Path


def build_patterns(name):
    """Return list of (regex, group_replacer) tuples for safe class rename."""
    esc = re.escape(name)
    pats = []
    # CSS files: selector `.classname` (followed by non-word-or-hyphen)
    pats.append((re.compile(r"\." + esc + r"(?![\w-])"), "."))
    # Quoted classname: "classname" or 'classname' or `classname` (whole string)
    pats.append((re.compile(r'(["\'\`])' + esc + r'(["\'\`])'), None))
    # Class attribute value with multi-class: class="foo classname bar"
    # Match the classname token inside class= or className= context
    pats.append((re.compile(r'(class(?:Name)?\s*=\s*["\'])((?:[^"\']*?\s)?)' + esc + r'(?![\w-])'), None))
    # querySelector(".classname") - already handled by quote pattern above with leading `.`
    # classList.add('classname') / .remove / .toggle / .contains / .replace
    pats.append((re.compile(r"(classList\s*\.\s*(?:add|remove|toggle|contains|replace)\s*\(\s*[\"'])" + esc + r"(?![\w-])"), None))
    return pats


def replace_in_text(text, name, new_name):
    """Apply class-aware rename. Returns (new_text, n_replacements)."""
    total = 0

    # 1. CSS selector: `.foo` -> `.new`
    pattern_dot = re.compile(r"\." + re.escape(name) + r"(?![\w-])")
    text, n = pattern_dot.subn("." + new_name, text)
    total += n

    # 2. Quoted whole-token: "foo" or 'foo' or `foo` (single class in string)
    # Match if entire string is just the class name
    pattern_quoted_full = re.compile(r"([\"'`])" + re.escape(name) + r"\1")
    text, n = pattern_quoted_full.subn(lambda m: m.group(1) + new_name + m.group(1), text)
    total += n

    # 3. Class attribute multi-token: class="a foo b" — token surrounded by whitespace or quote
    # Pattern matches `foo` token between (start-of-attr-value OR whitespace) and (whitespace OR end)
    def replace_class_attr(m):
        prefix = m.group(1)
        before = m.group(2)
        after_q = m.group(3)
        return f'{prefix}{before}{new_name}{after_q}'

    # Match: class="...prefix_ws|start foo ws|end_quote..."
    # Simpler: class="..." attribute, find foo as space-delimited token within
    pattern_class_attr = re.compile(
        r'(class(?:Name)?\s*=\s*"[^"]*?)' +
        r'(?<=["\s])' + re.escape(name) + r'(?=["\s])'
    )
    text, n = pattern_class_attr.subn(lambda m: m.group(1) + new_name, text)
    total += n
    pattern_class_attr_sq = re.compile(
        r"(class(?:Name)?\s*=\s*'[^']*?)" +
        r"(?<=['\s])" + re.escape(name) + r"(?=['\s])"
    )
    text, n = pattern_class_attr_sq.subn(lambda m: m.group(1) + new_name, text)
    total += n

    # 4. classList.add('foo') etc — single-token argument
    pattern_classlist = re.compile(
        r"(classList\s*\.\s*(?:add|remove|toggle|contains|replace)\s*\(\s*[\"'])" + re.escape(name) + r"(?=[\"'])"
    )
    text, n = pattern_classlist.subn(lambda m: m.group(1) + new_name, text)
    total += n

    # 5. Template literal class= within backticks: `<div class="foo">`
    # Pattern: inside backticks, find class="..." with foo as token
    # This is handled by pattern 3 above if backtick wraps regular HTML
    pattern_tl = re.compile(
        r'(class(?:Name)?\s*=\s*\\?["\'][^"\'`]*?)' +
        r'(?<=["\'\\\s])' + re.escape(name) + r'(?=["\'\\\s])'
    )
    text, n = pattern_tl.subn(lambda m: m.group(1) + new_name, text)
    total += n

    return text, total


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="from_name", required=True)
    ap.add_argument("--to", dest="to_name", required=True)
    ap.add_argument("--files-from", required=True)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    files = [l.strip() for l in Path(args.files_from).read_text().splitlines() if l.strip()]
    files = [f[2:] if f.startswith("./") else f for f in files]

    total = 0
    n_files = 0
    for f in files:
        p = Path(f)
        if not p.exists():
            print(f"  MISS {f}")
            continue
        try:
            text = p.read_text(encoding="utf-8")
        except UnicodeDecodeError as e:
            print(f"  SKIP {f}: {e}")
            continue

        new_text, n = replace_in_text(text, args.from_name, args.to_name)
        if n > 0:
            if not args.dry_run:
                p.write_text(new_text, encoding="utf-8")
            print(f"  {f}: {n} {'(dry)' if args.dry_run else ''}")
            total += n
            n_files += 1

    print(f"\n{'[dry-run]' if args.dry_run else '[applied]'} {total} replacements in {n_files}/{len(files)} files")


if __name__ == "__main__":
    main()
