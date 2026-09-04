#!/usr/bin/env python3
"""CSS class rename across stack — word-boundary aware.

Usage:
  python scripts/css-rename.py --from=DraggableContainer --to=fm-draggable-container \
      --files-from=docs/analysis/drag-files.txt
  python scripts/css-rename.py --from=foo --to=fm-foo --dry-run

Word-boundary handling:
  - `_` is treated as word char (so `foo_ver` won't be touched when renaming `foo`)
  - Uses negative lookahead `(?![_\w])` after pattern
"""
import argparse
import re
import sys
from pathlib import Path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="from_name", required=True)
    ap.add_argument("--to", dest="to_name", required=True)
    ap.add_argument("--files-from", required=True)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    files = [l.strip() for l in Path(args.files_from).read_text().splitlines() if l.strip()]
    # Strip leading ./ for Windows-friendliness
    files = [f[2:] if f.startswith("./") else f for f in files]

    # Build regex with strict class-token boundaries:
    # - Before name: NOT word char or hyphen (so `fm-rm-table` won't match `rm-table`)
    # - After name: NOT word char or hyphen (so `rm-table-view` won't match `rm-table`)
    # This treats hyphen as part of the class token, not a separator.
    pattern = re.compile(r"(?<![\w-])" + re.escape(args.from_name) + r"(?![\w-])")

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

        new_text, n = pattern.subn(args.to_name, text)
        if n > 0:
            if not args.dry_run:
                p.write_text(new_text, encoding="utf-8")
            print(f"  {f}: {n} {'(dry)' if args.dry_run else ''}")
            total += n
            n_files += 1

    print(f"\n{'[dry-run]' if args.dry_run else '[applied]'} {total} replacements in {n_files}/{len(files)} files")


if __name__ == "__main__":
    main()
