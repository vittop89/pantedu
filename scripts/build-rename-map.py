#!/usr/bin/env python3
"""Auto-build rename map for legacy classes.

For each class in input list:
- camelCase / CamelCase → kebab-case
- snake_case → kebab-case
- Add fm- prefix
- Skip classes already starting with fm-
- Skip "stateful" classes (active, error, content, generic state names)
- Skip classes that conflict with existing BEM modules
"""
import re
import sys
import os
from pathlib import Path

ROOT = Path("C:/Users/vitto/progetti_vscode/pantedu")

SKIP_CLASSES = {
    "active", "content", "error", "open", "closed", "selected", "hidden",
    "visible", "disabled", "loading", "checked",
    "selection",  # also too generic
    "origin",  # too generic
}


def camel_or_snake_to_kebab(name):
    # Replace _ with -
    s = name.replace("_", "-")
    # Insert hyphen before uppercase letters (skip first char)
    # CamelCase → camel-case
    s = re.sub(r"([a-z0-9])([A-Z])", r"\1-\2", s)
    # Handle consecutive uppercases: PDFConverter → pdf-converter
    s = re.sub(r"([A-Z]+)([A-Z][a-z])", r"\1-\2", s)
    return s.lower()


def main():
    classes = []
    for line in sys.stdin:
        cls = line.strip()
        if not cls:
            continue
        classes.append(cls)

    # Build map
    map_out = []
    skipped = []
    for cls in classes:
        if cls.startswith("fm-"):
            continue
        if cls in SKIP_CLASSES:
            skipped.append(f"{cls}  (state/generic)")
            continue
        if len(cls) < 4:
            skipped.append(f"{cls}  (too short)")
            continue
        kebab = camel_or_snake_to_kebab(cls)
        target = f"fm-{kebab}"
        # Check conflict with existing fm-* class in modules (NOT _legacy files)
        # Quick grep
        existing = False
        for p in (ROOT / "css").rglob("*.css"):
            if "_legacy" in p.name or "main.bundle.css" in p.name:
                continue
            try:
                text = p.read_text(encoding="utf-8", errors="ignore")
                if re.search(r"\." + re.escape(target) + r"\b", text):
                    existing = True
                    break
            except Exception:
                continue
        if existing:
            skipped.append(f"{cls}  (target .{target} conflicts existing)")
            continue
        map_out.append((cls, target))

    print("old_name\tnew_name")
    for old, new in map_out:
        print(f"{old}\t{new}")

    if skipped:
        print("\n# Skipped:", file=sys.stderr)
        for s in skipped:
            print(f"#   {s}", file=sys.stderr)
    print(f"\n# Total map: {len(map_out)} renames, {len(skipped)} skipped", file=sys.stderr)


if __name__ == "__main__":
    main()
