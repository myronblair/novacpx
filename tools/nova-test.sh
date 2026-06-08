#!/usr/bin/env bash
# nova-test.sh — NovaCPX API endpoint test suite
# Tests auth, common endpoints, and panel responses against the live VM
# Usage: bash nova-test.sh [--host IP] [--admin-pass PASS]

set -euo pipefail

HOST="${NOVACPX_HOST:-10.48.200.110}"
ADMIN_PASS="${NOVACPX_ADMIN_PASS:-bUe9JXTRmWJbyrFA}"
PORT_USER=8880; PORT_RESELLER=8881; PORT_ADMIN=8882; PORT_WEBMAIL=8883
PASS_CHECKS=0; FAIL_CHECKS=0; SKIP_CHECKS=0

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

for arg in "$@"; do
  case "$arg" in
    --host)      HOST="${2:-}"; shift ;;
    --admin-pass) ADMIN_PASS="${2:-}"; shift ;;
  esac
done

check() {
  local name="$1" expected="$2" actual="$3" detail="${4:-}"
  if [[ "$actual" == "$expected" ]]; then
    echo -e "${GREEN}[PASS]${NC} $name${detail:+ — $detail}"
    PASS_CHECKS=$((PASS_CHECKS+1))
  else
    echo -e "${RED}[FAIL]${NC} $name — expected $expected, got $actual ${detail:+($detail)}"
    FAIL_CHECKS=$((FAIL_CHECKS+1))
  fi
}

api() {
  local port="$1" method="$2" endpoint="$3" data="${4:-}"
  local url="https://$HOST:$port/api/$endpoint"
  local args=(-sk --max-time 8 -X "$method" -H "Content-Type: application/json")
  [[ -n "${TOKEN:-}" ]] && args+=(-H "Authorization: Bearer $TOKEN")
  [[ -n "$data" ]] && args+=(-d "$data")
  curl "${args[@]}" -w "\n%{http_code}" "$url" 2>/dev/null
}

http_code() { echo "$1" | tail -1; }
body()      { echo "$1" | head -n -1; }

echo ""
echo -e "${BLUE}══════════════════════════════════════════${NC}"
echo -e "${BLUE}  NovaCPX API Test Suite${NC}"
echo -e "${BLUE}  Host: $HOST${NC}"
echo -e "${BLUE}══════════════════════════════════════════${NC}"
echo ""

# ── 1. Panel ports reachable ──────────────────────────────────────────────────
echo "── Panel Ports ──"
for PORT in $PORT_USER $PORT_RESELLER $PORT_ADMIN $PORT_WEBMAIL; do
  LABEL="user"; [[ $PORT -eq 8881 ]] && LABEL="reseller"; [[ $PORT -eq 8882 ]] && LABEL="admin"; [[ $PORT -eq 8883 ]] && LABEL="webmail"
  SC=$(curl -sk --max-time 6 -o /dev/null -w "%{http_code}" "https://$HOST:$PORT/" 2>/dev/null || echo "ERR")
  if [[ "$SC" =~ ^[23] ]] || [[ "$SC" == "401" ]] || [[ "$SC" == "403" ]]; then
    echo -e "${GREEN}[PASS]${NC} Port $PORT ($LABEL): HTTP $SC"
    PASS_CHECKS=$((PASS_CHECKS+1))
  else
    echo -e "${RED}[FAIL]${NC} Port $PORT ($LABEL): HTTP $SC (not responding)"
    FAIL_CHECKS=$((FAIL_CHECKS+1))
  fi
done

# ── 2. Auth — bad credentials ─────────────────────────────────────────────────
echo ""
echo "── Auth Endpoint ──"
RESP=$(api $PORT_ADMIN POST "auth/login" '{"username":"nobody","password":"badpass"}')
check "Bad login returns 401" "401" "$(http_code "$RESP")"

# ── 3. Auth — valid login ─────────────────────────────────────────────────────
RESP=$(api $PORT_ADMIN POST "auth/login" "{\"username\":\"admin\",\"password\":\"$ADMIN_PASS\"}")
SC=$(http_code "$RESP")
check "Admin login succeeds (200)" "200" "$SC"
TOKEN=$(body "$RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('token',''))" 2>/dev/null || echo "")
if [[ -n "$TOKEN" ]]; then
  echo -e "${GREEN}[INFO]${NC} Token acquired: ${TOKEN:0:20}..."
else
  echo -e "${YELLOW}[WARN]${NC} No token in login response — subsequent tests will fail auth"
fi

# ── 4. Auth — me endpoint ─────────────────────────────────────────────────────
RESP=$(api $PORT_ADMIN GET "auth/me")
check "auth/me returns 200" "200" "$(http_code "$RESP")"
ROLE=$(body "$RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('role',''))" 2>/dev/null || echo "")
check "auth/me role=admin" "admin" "$ROLE"

# ── 5. Packages ───────────────────────────────────────────────────────────────
echo ""
echo "── Packages ──"
RESP=$(api $PORT_ADMIN GET "packages/list")
check "packages/list returns 200" "200" "$(http_code "$RESP")"

# ── 6. Domains ────────────────────────────────────────────────────────────────
echo ""
echo "── Domains ──"
RESP=$(api $PORT_ADMIN GET "domains/list")
check "domains/list returns 200" "200" "$(http_code "$RESP")"

# ── 7. System ─────────────────────────────────────────────────────────────────
echo ""
echo "── System ──"
RESP=$(api $PORT_ADMIN GET "system/status")
check "system/status returns 200" "200" "$(http_code "$RESP")"

RESP=$(api $PORT_ADMIN GET "system/services")
check "system/services returns 200" "200" "$(http_code "$RESP")"

# ── 8. Firewall ───────────────────────────────────────────────────────────────
echo ""
echo "── Firewall ──"
RESP=$(api $PORT_ADMIN GET "firewall/status")
check "firewall/status returns 200" "200" "$(http_code "$RESP")"

# ── 9. Stats ──────────────────────────────────────────────────────────────────
echo ""
echo "── Stats ──"
RESP=$(api $PORT_ADMIN GET "stats/overview")
check "stats/overview returns 200" "200" "$(http_code "$RESP")"

# ── 10. Unauthorized access ───────────────────────────────────────────────────
echo ""
echo "── Auth Enforcement ──"
TOKEN=""
RESP=$(api $PORT_ADMIN GET "packages/list")
check "packages/list without token returns 401" "401" "$(http_code "$RESP")"

RESP=$(api $PORT_ADMIN GET "firewall/status")
check "firewall/status without token returns 401" "401" "$(http_code "$RESP")"

# ── Summary ───────────────────────────────────────────────────────────────────
TOTAL=$((PASS_CHECKS + FAIL_CHECKS + SKIP_CHECKS))
echo ""
echo -e "${BLUE}══════════════════════════════════════════${NC}"
echo -e "  Results: ${GREEN}$PASS_CHECKS passed${NC}  ${RED}$FAIL_CHECKS failed${NC}  ${YELLOW}$SKIP_CHECKS skipped${NC}  / $TOTAL total"
echo -e "${BLUE}══════════════════════════════════════════${NC}"
echo ""
[[ $FAIL_CHECKS -eq 0 ]] && exit 0 || exit 1
