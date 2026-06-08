#!/usr/bin/env bash
# nova-push.sh — Push a local file to the NovaCPX VM via double-hop
# Usage: bash nova-push.sh <local_file> <remote_path>
# Example: bash nova-push.sh panel/api/index.php /srv/novacpx/public/api/index.php

set -euo pipefail

PVE1_HOST="orbisne.fortiddns.com"
PVE1_PASS="Joker1974!!!"
VM_IP="10.48.200.110"
VM_PASS="Joker1974!!!"
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10"

LOCAL="$1"
REMOTE="$2"

[[ -f "$LOCAL" ]] || { echo "Error: $LOCAL not found"; exit 1; }

echo "Pushing $LOCAL → VM:$REMOTE"
CONTENT=$(base64 -w0 "$LOCAL")
sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
  "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP \
    \"mkdir -p \$(dirname $REMOTE) && echo '$CONTENT' | base64 -d > $REMOTE && chown www-data:www-data $REMOTE\""
echo "Done."
