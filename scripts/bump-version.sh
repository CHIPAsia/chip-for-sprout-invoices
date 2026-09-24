#!/usr/bin/env bash
#
# Bump the plugin version across every file that carries it.
#
# Usage:
#   ./scripts/bump-version.sh 1.2.0
#   ./scripts/bump-version.sh --yes 1.2.0
#   ./scripts/bump-version.sh --yes --changelog-file entry.md 1.2.0
#
# Updates:
#   - chip-for-sprout-invoices.php  (Version header + SA_ADDON_CHIP_VERSION)
#   - readme.txt                    (Stable tag + the latest changelog entry)
#   - changelog.txt                 (prepends the new entry)
#
# The script asserts its own output and exits non-zero without staging
# anything when a file did not take the change.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "$PROJECT_ROOT"

PLUGIN_FILE="chip-for-sprout-invoices.php"

# ─── Parse options ───

YES=false
CHANGELOG_FILE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y)
            YES=true
            shift
            ;;
        --changelog-file)
            if [[ -n "${2:-}" ]]; then
                CHANGELOG_FILE="$2"
                shift 2
            else
                echo "Error: --changelog-file requires a file path"
                exit 1
            fi
            ;;
        -*)
            echo "Unknown option: $1"
            echo "Usage: $0 [--yes] [--changelog-file FILE] <version>"
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

if [ $# -ne 1 ]; then
    echo "Usage: $0 [--yes] [--changelog-file FILE] <version>"
    echo "Example: $0 1.2.0"
    exit 1
fi

NEW_VERSION="$1"

# Validate semver-ish format: X.Y.Z
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: Version must be in X.Y.Z format (e.g., 1.2.0)"
    exit 1
fi

CURRENT_VERSION=$(grep "^ \* Version:" "$PLUGIN_FILE" | head -1 | awk '{print $3}')

echo "🔢 Current version: $CURRENT_VERSION"
echo "🔢 New version:     $NEW_VERSION"

if ! $YES; then
    read -r -p "Continue? [y/N] " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi
fi

# ─── Update version strings ───

echo "📝 Updating version strings..."

sed -i.bak "s/^ \* Version: ${CURRENT_VERSION}/ * Version: ${NEW_VERSION}/" "$PLUGIN_FILE"
rm -f "${PLUGIN_FILE}.bak"

sed -i.bak "s/define( 'SA_ADDON_CHIP_VERSION', '${CURRENT_VERSION}' )/define( 'SA_ADDON_CHIP_VERSION', '${NEW_VERSION}' )/" "$PLUGIN_FILE"
rm -f "${PLUGIN_FILE}.bak"

sed -i.bak "s/^Stable tag: ${CURRENT_VERSION}/Stable tag: ${NEW_VERSION}/" readme.txt
rm -f readme.txt.bak

# ─── Changelog entry ───

if [ -n "$CHANGELOG_FILE" ] && [ -f "$CHANGELOG_FILE" ]; then
    echo "📝 Using the supplied changelog from $CHANGELOG_FILE..."
    CHANGELOG_ENTRY=$(cat "$CHANGELOG_FILE")

    # A changelog entry that carries only the version header, or nothing but
    # blank lines, bumps the version and releases with an empty changelog: the
    # entry passes every check below because those look for the header. Reject
    # it here so no caller can ship a release that announces nothing.
    if ! printf '%s\n' "$CHANGELOG_ENTRY" | grep -qE "^= ${NEW_VERSION} "; then
        echo "❌ Changelog entry in $CHANGELOG_FILE has no '= ${NEW_VERSION} <date> =' header"
        echo "   Expected the whole entry, header included."
        exit 1
    fi

    if [ -z "$(printf '%s\n' "$CHANGELOG_ENTRY" | sed '/^[[:space:]]*$/d' | tail -n +2)" ]; then
        echo "❌ Changelog entry in $CHANGELOG_FILE has no content below the header"
        exit 1
    fi
else
    TODAY=$(date +%Y-%m-%d)
    CHANGELOG_ENTRY="= ${NEW_VERSION} ${TODAY} =
* [Add your changelog entry here]"
fi

if grep -q "^= ${NEW_VERSION} " changelog.txt; then
    echo "⚠️  Changelog entry for ${NEW_VERSION} already exists. Skipping."
else
    echo "📝 Adding the changelog entry (above existing entries)..."
    export AWK_ENTRY="$CHANGELOG_ENTRY"
    awk '
        NR==1 { print; next }
        NR==2 { print; print ENVIRON["AWK_ENTRY"]; print ""; next }
        { print }
    ' changelog.txt > changelog.txt.tmp
    mv changelog.txt.tmp changelog.txt
fi

# readme.txt carries only the current release.
echo "📝 Updating the readme.txt changelog (current release only)..."
export AWK_ENTRY="$CHANGELOG_ENTRY"
awk '
    /^== Changelog ==/ {
        print
        print ""
        print ENVIRON["AWK_ENTRY"]
        skip = 1
        next
    }
    skip && /^== / {
        # The blank line separating the changelog from the next section is
        # consumed by the skip above, so re-emit it here.
        skip = 0
        print ""
    }
    skip { next }
    { print }
' readme.txt > readme.txt.tmp
mv readme.txt.tmp readme.txt

# ─── Verify our own output before staging anything ───

echo "🔍 Verifying the bump..."

FAIL=0

if ! grep -q "^ \* Version: ${NEW_VERSION}$" "$PLUGIN_FILE"; then
    echo "❌ Version header did not take ${NEW_VERSION}"
    FAIL=1
fi

if ! grep -q "SA_ADDON_CHIP_VERSION', '${NEW_VERSION}'" "$PLUGIN_FILE"; then
    echo "❌ SA_ADDON_CHIP_VERSION did not take ${NEW_VERSION}"
    FAIL=1
fi

if ! grep -q "^Stable tag: ${NEW_VERSION}$" readme.txt; then
    echo "❌ Stable tag did not take ${NEW_VERSION}"
    FAIL=1
fi

if ! grep -q "^= ${NEW_VERSION} " changelog.txt; then
    echo "❌ changelog.txt has no entry for ${NEW_VERSION}"
    FAIL=1
fi

if ! grep -q "^= ${NEW_VERSION} " readme.txt; then
    echo "❌ readme.txt has no entry for ${NEW_VERSION}"
    FAIL=1
fi

# The header and the constant must agree, whatever they are set to.
HEADER_VERSION=$(grep "^ \* Version:" "$PLUGIN_FILE" | head -1 | awk '{print $3}')
CONSTANT_VERSION=$(grep -o "SA_ADDON_CHIP_VERSION', '[^']*'" "$PLUGIN_FILE" | sed "s/.*'\(.*\)'/\1/")

if [ "$HEADER_VERSION" != "$CONSTANT_VERSION" ]; then
    echo "❌ Header version (${HEADER_VERSION}) and SA_ADDON_CHIP_VERSION (${CONSTANT_VERSION}) disagree"
    FAIL=1
fi

# readme.txt must carry exactly one version entry.
ENTRY_COUNT=$(grep -c "^= [0-9]" readme.txt || true)
if [ "$ENTRY_COUNT" -ne 1 ]; then
    echo "❌ readme.txt should carry exactly one release entry, found ${ENTRY_COUNT}"
    FAIL=1
fi

if [ "$FAIL" -ne 0 ]; then
    echo "❌ Verification failed. Nothing was staged; review the files above."
    exit 1
fi

# ─── Stage ───

echo "📦 Staging changes..."
git add -A

echo ""
echo "✅ Version bumped to ${NEW_VERSION}"

if ! $YES; then
    echo ""
    echo "Next steps:"
    echo "  1. Review the changelog entry in changelog.txt"
    echo "  2. git commit -m \"Bump version to ${NEW_VERSION}\""
    echo "  3. git tag v${NEW_VERSION}"
    echo "  4. git push origin main --tags"
fi
