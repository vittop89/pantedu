#!/usr/bin/env bash
# Installa pre-commit hooks come symlink (Phase 25.E1+E2).
#
# Eseguibile a setup di un nuovo dev environment:
#   bash tools/git/hooks/install.sh
#
# Idempotente: rimuove eventuali link/file esistenti prima di re-installare.

set -e

GIT_ROOT="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$GIT_ROOT/.git/hooks"
SRC_DIR="$GIT_ROOT/tools/git/hooks"

cd "$GIT_ROOT"

mkdir -p "$HOOKS_DIR"

for hook in pre-commit; do
    src="$SRC_DIR/$hook"
    dst="$HOOKS_DIR/$hook"
    if [ ! -f "$src" ]; then
        echo "  - $hook source non trovato, skip"
        continue
    fi
    chmod +x "$src"
    rm -f "$dst"
    # Link relativo per portabilità tra dev environment
    ln -s "../../tools/git/hooks/$hook" "$dst"
    echo "  ✓ $hook installato (symlink)"
done

echo ""
echo "Hook installati. Testa con:"
echo "  echo 'console.log(\"test\")' > /tmp/test.js && git add /tmp/test.js && git commit"
echo "  (o aggiungi un file con un secret per verificare gitleaks)"
echo ""
echo "Per bypass d'emergenza: git commit --no-verify"
