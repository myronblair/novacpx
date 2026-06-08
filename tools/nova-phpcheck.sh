#!/usr/bin/env bash
# nova-phpcheck.sh — PHP static analysis on all panel PHP files
# Usage: bash nova-phpcheck.sh [path]     (default: ../panel)
#        bash nova-phpcheck.sh --fix      (show errors + line context)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-$SCRIPT_DIR/../panel}"
FIX=false
[[ "${1:-}" == "--fix" ]] && { FIX=true; TARGET="${2:-$SCRIPT_DIR/../panel}"; }

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

PHP_BIN=$(command -v php8.3 || command -v php || echo "")
[[ -z "$PHP_BIN" ]] && { echo "No PHP binary found. Install php8.3."; exit 1; }

echo "Checking PHP syntax in: $TARGET"
echo "PHP: $($PHP_BIN --version | head -1)"
echo ""

ERRORS=0; FILES=0
while IFS= read -r -d '' file; do
  FILES=$((FILES+1))
  OUTPUT=$("$PHP_BIN" -l "$file" 2>&1)
  if echo "$OUTPUT" | grep -q "No syntax errors"; then
    :
  else
    echo -e "${RED}[ERROR]${NC} $file"
    echo "  $OUTPUT"
    if $FIX; then
      # Show 3 lines of context around the error
      LINENUM=$(echo "$OUTPUT" | grep -oP 'on line \K[0-9]+' | head -1)
      if [[ -n "$LINENUM" ]]; then
        START=$(( LINENUM > 3 ? LINENUM - 3 : 1 ))
        echo ""
        sed -n "${START},$((LINENUM+2))p" "$file" | nl -ba -v"$START" | \
          awk -v err="$LINENUM" '{if(NR==err-START+4) printf "\033[31m→ %s\033[0m\n",$0; else print "  "$0}'
        echo ""
      fi
    fi
    ERRORS=$((ERRORS+1))
  fi
done < <(find "$TARGET" -name "*.php" -not -path "*/vendor/*" -print0)

echo ""
if [[ $ERRORS -eq 0 ]]; then
  echo -e "${GREEN}[✓]${NC} All $FILES PHP files OK"
  exit 0
else
  echo -e "${RED}[✗]${NC} $ERRORS error(s) in $FILES files"
  exit 1
fi
