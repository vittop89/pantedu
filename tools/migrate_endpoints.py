#!/usr/bin/env python3
"""
Sostituisce URL .php hardcoded nei moduli JS con `Endpoints.X.Y`.
Aggiunge import Endpoints se mancante. Idempotent.

Run from project root:
    python3 tools/migrate_endpoints.py
"""
import os
import re
import sys

MAPPING = {
    "/save_tex_file.php":             "Endpoints.files.saveTex",
    "/save_latex_file.php":           "Endpoints.files.saveLatex",
    "/save_image.php":                "Endpoints.files.saveImage",
    "/save_pdf_file.php":             "Endpoints.files.savePdf",
    "/delete_File.php":               "Endpoints.files.deleteFile",
    "/delete_folder.php":             "Endpoints.files.deleteFolder",
    "/delete_temp.php":               "Endpoints.files.deleteTemp",
    "/list_files.php":                "Endpoints.files.list",
    "/read_nameFile.php":             "Endpoints.files.listPhp",
    "/save_new_exercise.php":         "Endpoints.exercises.saveNew",
    "/duplicate_problem.php":         "Endpoints.exercises.duplicateCollex",
    "/ensure_collex_ids.php":         "Endpoints.exercises.ensureCollexIds",
    "/clone_collex_item.php":         "Endpoints.exercises.cloneCollex",
    "/save_tikz_svg.php":             "Endpoints.tikz.saveSvg",
    "/save_new_tikz_element.php":     "Endpoints.tikz.saveNewElement",
    "/edit_tikz_element.php":         "Endpoints.tikz.editElement",
    "/delete_tikz_element.php":       "Endpoints.tikz.deleteElement",
    "/get_tikz_content.php":          "Endpoints.tikz.getContent",
    "/ensure_tikz_json.php":          "Endpoints.tikz.ensureJson",
    "/generate_tikz_json.php":        "Endpoints.tikz.generateJson",
    "/save_editor_revision.php":      "Endpoints.editor.saveRevision",
    "/load_editor_revision.php":      "Endpoints.editor.loadRevision",
    "/list_editor_revisions.php":     "Endpoints.editor.listRevisions",
    "/save_manual_snapshot.php":      "Endpoints.editor.saveSnapshot",
    "/get_verification_folders.php":  "Endpoints.verifiche.listFolders",
    "/manage_print_info.php":         "Endpoints.verifiche.managePrintInfo",
    "/save_load_scelte.php":          "Endpoints.verifiche.saveScelte",
    "/check_externalLinks.php":       "Endpoints.links.check",
    "/check_externalLinks_variation.php": "Endpoints.links.checkVariation",
    "/save_externalLinks.php":        "Endpoints.links.save",
    "/update-origins.php":            "Endpoints.links.updateOrigins",
    "/update_file.php":               "Endpoints.update.file",
    "/update_table.php":              "Endpoints.update.table",
    "/update_dsa.php":                "Endpoints.update.dsa",
    "/update_dsa_checkbox.php":       "Endpoints.update.dsaCheckbox",
    "/create_File.php":               "Endpoints.create.file",
    "/create_verFile.php":            "Endpoints.create.verFile",
    "/check_password.php":            "Endpoints.check.password",
    "/check_file_protection.php":     "Endpoints.check.fileProtection",
}

IMPORT_RELATIVE_PATH = {
    "core":         "./endpoints.js",
    "editor":       "../core/endpoints.js",
    "events":       "../core/endpoints.js",
    "print":        "../core/endpoints.js",
    "state":        "../core/endpoints.js",
    "ui":           "../core/endpoints.js",
    "integrations": "../core/endpoints.js",
    "selection":    "../core/endpoints.js",
}


def subdir(path: str) -> str:
    parts = path.replace("\\", "/").split("/")
    try:
        i = parts.index("modules")
        return parts[i + 1]
    except (ValueError, IndexError):
        return ""


def ensure_import(src: str, rel: str) -> str:
    if "from \"" + rel + "\"" in src or "from '" + rel + "'" in src:
        return src
    if "Endpoints" not in src:
        return src  # no replacements happened, skip import
    # Insert after the first JSDoc `*/` or at top
    lines = src.split("\n")
    insert_at = 0
    for i, line in enumerate(lines[:30]):
        if line.strip().startswith("*/"):
            insert_at = i + 1
            break
    imp = f'import {{ Endpoints }} from "{rel}";'
    lines.insert(insert_at, imp)
    return "\n".join(lines)


def migrate_file(path: str) -> tuple[int, bool]:
    with open(path, "r", encoding="utf-8") as f:
        src = f.read()
    before = src
    n = 0
    # Match quoted URL strings — replace with bare Endpoints reference
    for legacy, modern in MAPPING.items():
        # Only replace standalone quoted strings, not substrings of other URLs
        pattern = re.compile(r'([\"\'])' + re.escape(legacy) + r'\1')
        src, count = pattern.subn(modern, src)
        n += count
    if n > 0:
        sd = subdir(path)
        rel = IMPORT_RELATIVE_PATH.get(sd)
        if rel:
            src = ensure_import(src, rel)
        if src != before:
            with open(path, "w", encoding="utf-8") as f:
                f.write(src)
            return (n, True)
    return (0, False)


def walk_js(root: str):
    for dirpath, _, filenames in os.walk(root):
        for fn in filenames:
            if fn.endswith(".js"):
                yield os.path.join(dirpath, fn)


def main():
    root = os.path.abspath("js/modules")
    if not os.path.isdir(root):
        print("js/modules not found", file=sys.stderr)
        return 1
    total = 0
    changed = 0
    for path in walk_js(root):
        # Skip endpoints.js itself
        if path.endswith(os.path.join("core", "endpoints.js")):
            continue
        n, ok = migrate_file(path)
        if ok:
            rel = os.path.relpath(path, os.getcwd())
            print(f"  {rel}: {n} URL(s)")
            total += n
            changed += 1
    print(f"\n{total} URL migrated across {changed} file(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
