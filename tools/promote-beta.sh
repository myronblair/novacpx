#!/usr/bin/env bash
# Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
# promote-beta.sh - release what has been tested on the beta channel to stable (main).
#   Usage: bash tools/promote-beta.sh        (run inside the repo; pushes nothing, you review and push)
# Merges origin/beta into main keeping beta's VERSION (X.Y.Z-beta.N); the version-bump workflow then turns it into the
# release X.Y.Z when main is pushed.
set -euo pipefail
git fetch -q origin
git checkout -q main
git merge -q --ff-only origin/main
git merge --no-commit --no-ff origin/beta || true
git checkout --theirs VERSION 2>/dev/null || true
git add VERSION
if git diff --name-only --diff-filter=U | grep -q .; then
  echo "Conflicts remain in: $(git diff --name-only --diff-filter=U | tr '\n' ' ')- resolve them, then commit."; exit 1
fi
V=$(tr -d '[:space:]' < VERSION)
git commit -q -m "Promote beta to stable (${V%%-*})"
echo "Merged beta into main as ${V%%-*}. Review with 'git log --oneline -5', then push main to origin and gitea."
