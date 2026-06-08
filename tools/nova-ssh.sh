#!/usr/bin/env bash
# nova-ssh.sh — SSH into the NovaCPX VM via double-hop through PVE1
# Usage: bash nova-ssh.sh [command]        (run command on VM)
#        bash nova-ssh.sh                  (interactive shell on VM)
#        bash nova-ssh.sh --pve1 [command] (run on PVE1 instead)
#
# Hop path: local → PVE1 (10.48.200.90 via orbisne.fortiddns.com) → VM (10.48.200.110)

PVE1_HOST="orbisne.fortiddns.com"
PVE1_PASS="Joker1974!!!"
VM_IP="10.48.200.110"
VM_PASS="Joker1974!!!"
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10"

if [[ "${1:-}" == "--pve1" ]]; then
  shift
  if [[ $# -eq 0 ]]; then
    sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST
  else
    sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST "$*"
  fi
else
  CMD="$*"
  if [[ -z "$CMD" ]]; then
    # Interactive shell
    sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
      "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP"
  else
    sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
      "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP '$CMD'"
  fi
fi
