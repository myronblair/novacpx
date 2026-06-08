#!/usr/bin/env bash
# nova-db.sh — Quick DB access on NovaCPX VM
# Usage: bash nova-db.sh [query]           (run query and return result)
#        bash nova-db.sh                   (open interactive MySQL shell)
#        bash nova-db.sh --tables          (list all tables with row counts)
#        bash nova-db.sh --users           (list all panel users)
#        bash nova-db.sh --reset-admin <newpass>  (reset admin password)

PVE1_HOST="orbisne.fortiddns.com"
PVE1_PASS="Joker1974!!!"
VM_IP="10.48.200.110"
VM_PASS="Joker1974!!!"
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10"
DB="novacpx"

vm_mysql() {
  sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
    "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP 'mysql $DB -e \"$1\"'"
}

case "${1:-}" in
  --tables)
    echo "Tables in $DB:"
    vm_mysql "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema='$DB' ORDER BY table_name;" 2>&1
    ;;
  --users)
    echo "Panel users:"
    vm_mysql "SELECT id, username, email, role, status, created_at FROM users ORDER BY id;" 2>&1
    ;;
  --reset-admin)
    [[ -z "${2:-}" ]] && { echo "Usage: $0 --reset-admin <newpassword>"; exit 1; }
    NEWPASS="$2"
    HASH=$(php8.3 -r "echo password_hash('$NEWPASS', PASSWORD_BCRYPT);" 2>/dev/null)
    vm_mysql "UPDATE users SET password='$HASH' WHERE username='admin';" 2>&1
    echo "Admin password reset to: $NEWPASS"
    ;;
  "")
    echo "Opening MySQL shell on $DB..."
    sshpass -p "$PVE1_PASS" ssh -t $SSH_OPTS root@$PVE1_HOST \
      "sshpass -p '$VM_PASS' ssh -t $SSH_OPTS root@$VM_IP 'mysql $DB'"
    ;;
  *)
    vm_mysql "$*" 2>&1
    ;;
esac
