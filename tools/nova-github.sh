#!/usr/bin/env bash
# nova-github.sh — Quick GitHub push for NovaCPX repo
# Usage: bash nova-github.sh "commit message"   (add all, commit, push)
#        bash nova-github.sh --status            (git status + diff --stat)
#        bash nova-github.sh --log               (last 10 commits)
#
# Requires: git, GitHub PAT already set on remote

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

cd "$REPO_ROOT" || { echo "Cannot cd to repo root: $REPO_ROOT"; exit 1; }

case "${1:-}" in
  --status)
    git status
    echo ""
    git diff --stat
    ;;
  --log)
    git log --oneline -10
    ;;
  "")
    echo "Usage: $0 \"commit message\""
    echo "       $0 --status"
    echo "       $0 --log"
    exit 1
    ;;
  *)
    MSG="$*"
    # PHP syntax check before commit
    echo "Running PHP syntax check..."
    if ! bash "$SCRIPT_DIR/nova-phpcheck.sh" > /dev/null 2>&1; then
      echo -e "${RED}[✗]${NC} PHP syntax errors found. Run: bash tools/nova-phpcheck.sh --fix"
      exit 1
    fi
    echo -e "${GREEN}[✓]${NC} PHP OK"

    git add -A
    git status --short
    echo ""
    git commit -m "$MSG"
    git push origin main
    echo ""
    echo -e "${GREEN}[✓]${NC} Pushed. Auto-deploy will trigger within ~1 min."
    ;;
esac
