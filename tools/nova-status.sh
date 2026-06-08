#!/usr/bin/env bash
# nova-status.sh — Check NovaCPX VM health: SSH, panel ports, services, logs
# Usage: bash nova-status.sh [--full]
#   (no flags) : quick port check
#   --full     : also show recent error logs

PVE1_HOST="orbisne.fortiddns.com"
PVE1_PASS="Joker1974!!!"
VM_IP="10.48.200.110"
VM_PASS="Joker1974!!!"
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=8"
FULL=false
[[ "${1:-}" == "--full" ]] && FULL=true

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[✓]${NC} $*"; }
fail() { echo -e "${RED}[✗]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }

echo "=== NovaCPX VM Status ==="
echo "VM: $VM_IP"

# SSH reachability
if sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
     "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP 'echo ok'" 2>/dev/null | grep -q ok; then
  ok "SSH reachable via PVE1"
else
  fail "SSH NOT reachable via PVE1 → $VM_IP"
fi

# Panel port checks via curl through DO
echo ""
echo "Panel ports:"
for PORT in 8880 8881 8882; do
  LABEL="user"
  [[ $PORT -eq 8881 ]] && LABEL="reseller"
  [[ $PORT -eq 8882 ]] && LABEL="admin"
  STATUS=$(curl -sk --max-time 5 -o /dev/null -w "%{http_code}" "https://$VM_IP:$PORT/" 2>/dev/null || echo "ERR")
  if [[ "$STATUS" =~ ^[23] ]]; then
    ok "Port $PORT ($LABEL): HTTP $STATUS"
  elif [[ "$STATUS" == "401" || "$STATUS" == "403" ]]; then
    ok "Port $PORT ($LABEL): HTTP $STATUS (auth required — panel is up)"
  else
    fail "Port $PORT ($LABEL): HTTP $STATUS"
  fi
done

# API endpoint
API_STATUS=$(curl -sk --max-time 5 -o /dev/null -w "%{http_code}" -X POST \
  "https://$VM_IP:8882/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"probe","password":"probe"}' 2>/dev/null || echo "ERR")
if [[ "$API_STATUS" == "401" || "$API_STATUS" == "200" ]]; then
  ok "API auth endpoint: HTTP $API_STATUS (responding)"
else
  fail "API auth endpoint: HTTP $API_STATUS"
fi

if $FULL; then
  echo ""
  echo "=== Recent error logs ==="
  sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
    "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP \
      'tail -20 /var/log/apache2/error.log 2>/dev/null; echo ---; tail -20 /var/log/novacpx/access.log 2>/dev/null'" 2>/dev/null || \
    warn "Could not read logs (SSH unavailable)"
fi

echo ""
echo "Panel URL: https://$VM_IP:8882 (admin)"
