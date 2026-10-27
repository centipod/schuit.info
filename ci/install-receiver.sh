#!/usr/bin/env bash
# Run as root from an already-published source snapshot. Argument: public key file.
set -euo pipefail
test "$(id -u)" = 0
source_dir="$(cd "$(dirname "$0")/.." && pwd)"
key_file="${1:?Deployment public key file required}"
test "$(wc -l < "$key_file")" -eq 1
grep -Eq '^ssh-ed25519 [A-Za-z0-9+/=]+( .*)?$' "$key_file"
if ! id schuit-deploy >/dev/null 2>&1; then
  useradd --system --create-home --home-dir /var/lib/schuit-deploy --shell /bin/sh schuit-deploy
fi
install -d -m 755 -o root -g root /opt/infra/schuit/deploy-gate
install -m 644 "$source_dir/ci/deploy_policy.py" /opt/infra/schuit/deploy-gate/deploy_policy.py
install -m 644 "$source_dir/ci/image_identity.py" /opt/infra/schuit/deploy-gate/image_identity.py
install -m 755 "$source_dir/ci/receive-deploy.py" /opt/infra/schuit/deploy-gate/receive-deploy.py
install -m 755 "$source_dir/ci/scan.sh" /opt/infra/schuit/deploy-gate/scan.sh
install -m 644 "$source_dir/ci/security_policy.py" /opt/infra/schuit/deploy-gate/security_policy.py
install -d -m 755 -o root -g root /var/lib/schuit-deploy
install -d -m 755 -o root -g root /var/lib/schuit-deploy/.ssh
PYTHONPATH="$source_dir/ci" python3 - "$key_file" <<'PY'
import sys
from pathlib import Path
from deploy_policy import authorized_key
Path('/var/lib/schuit-deploy/.ssh/authorized_keys').write_text(authorized_key(Path(sys.argv[1]).read_text()))
PY
chown root:root /var/lib/schuit-deploy/.ssh/authorized_keys
chmod 644 /var/lib/schuit-deploy/.ssh/authorized_keys
# No Docker group membership, arbitrary shell, forwarding, or broad sudo grant.
printf '%s\n' 'schuit-deploy ALL=(root) NOPASSWD: /usr/bin/python3 /opt/infra/schuit/deploy-gate/receive-deploy.py *' > /etc/sudoers.d/schuit-deploy
chmod 440 /etc/sudoers.d/schuit-deploy
visudo -cf /etc/sudoers.d/schuit-deploy
sshd -t
echo 'Restricted deployment receiver installed; application containers unchanged.'
