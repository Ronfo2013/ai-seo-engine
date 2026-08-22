#!/usr/bin/env bash
# Re-sync the vendored superpowers skills from upstream.
#
#   .claude/scripts/update-superpowers.sh [git-ref]
#
# Copies skills/* from github.com/obra/superpowers into .claude/skills/ and
# refreshes .claude/skills/SUPERPOWERS-VERSION.md. Review the diff before
# committing.

set -euo pipefail

REF="${1:-main}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CLAUDE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

git clone --depth 1 --branch "$REF" https://github.com/obra/superpowers.git "$TMP/superpowers"

COMMIT="$(git -C "$TMP/superpowers" rev-parse HEAD)"
DATE="$(git -C "$TMP/superpowers" log -1 --format=%ci)"
VERSION="$(sed -n 's/.*"version": "\([^"]*\)".*/\1/p' "$TMP/superpowers/.claude-plugin/plugin.json" | head -1)"

# Drop the previously vendored skill directories, keep everything else.
for dir in "$CLAUDE_DIR"/skills/*/; do
    [ -f "${dir}SKILL.md" ] && rm -rf "$dir"
done

cp -R "$TMP/superpowers"/skills/* "$CLAUDE_DIR/skills/"
cp "$TMP/superpowers/LICENSE" "$CLAUDE_DIR/skills/LICENSE-superpowers.txt"

cat > "$CLAUDE_DIR/skills/SUPERPOWERS-VERSION.md" <<EOF
# Vendored superpowers skills

Source: https://github.com/obra/superpowers (MIT, Jesse Vincent)

- plugin version: ${VERSION}
- commit: ${COMMIT}
- commit date: ${DATE}
- vendored on: $(date -u +%Y-%m-%d)

Update with \`.claude/scripts/update-superpowers.sh\`, then review the diff.
EOF

echo "Vendored superpowers ${VERSION} (${COMMIT})."
