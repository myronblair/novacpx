#!/usr/bin/env bash
# nova-logs.sh — Stream/view NovaCPX logs from VM
# Usage: bash nova-logs.sh [apache|access|install|fail2ban|all]
#   (no arg) : apache error log (default)

PVE1_HOST="orbisne.fortiddns.com"
PVE1_PASS="Joker1974!!!"
VM_IP="10.48.200.110"
VM_PASS="Joker1974!!!"
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10"

TARGET="${1:-apache}"

case "$TARGET" in
  apache)   LOG_CMD="tail -f /var/log/apache2/error.log" ;;
  access)   LOG_CMD="tail -f /var/log/novacpx/access.log" ;;
  install)  LOG_CMD="tail -100 /var/log/novacpx-install.log" ;;
  fail2ban) LOG_CMD="tail -f /var/log/fail2ban.log" ;;
  all)      LOG_CMD="tail -f /var/log/apache2/error.log /var/log/novacpx/access.log" ;;
  *)        echo "Unknown log: $TARGET. Options: apache|access|install|fail2ban|all"; exit 1 ;;
esac

echo "Streaming $TARGET logs from VM $VM_IP..."
echo "(Ctrl+C to stop)"
echo ""

sshpass -p "$PVE1_PASS" ssh -t $SSH_OPTS root@$PVE1_HOST \
  "sshpass -p '$VM_PASS' ssh -t $SSH_OPTS root@$VM_IP '$LOG_CMD'"
