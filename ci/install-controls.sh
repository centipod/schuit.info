#!/usr/bin/env bash
# Install only SCHUIT root-owned controls from an already published source snapshot.
set -euo pipefail
test "$(id -u)" = 0
source_dir="$(cd "$(dirname "$0")/.." && pwd)"
install -d -m 755 -o root -g root /opt/infra/schuit
# Dedicated, hash-locked runtime; do not change packages used by other hosted services.
python3 -m venv /opt/infra/schuit/backup-venv
/opt/infra/schuit/backup-venv/bin/pip install --require-hashes --only-binary=:all: -r "$source_dir/deployment/backup-requirements.txt"
systemctl stop schuit-gedcom.timer
# Do not interrupt an active import. Re-enable the timer if the installer must be retried.
if systemctl is-active --quiet schuit-gedcom.service; then
  systemctl start schuit-gedcom.timer
  echo 'GEDCOM worker is busy; retry after completion.' >&2
  exit 1
fi
install -d -m 755 -o root -g root /opt/infra/schuit/deploy-gate
for name in deploy_policy.py image_identity.py receive-deploy.py scan.sh security_policy.py; do
  install -m 644 "$source_dir/ci/$name" "/opt/infra/schuit/deploy-gate/$name"
done
install -m 644 "$source_dir/scripts/safe_queue.py" /opt/infra/schuit/deploy-gate/safe_queue.py
for name in safe_queue.py gedcom-worker.py worker-broker.py; do
  install -m 644 "$source_dir/scripts/$name" "/opt/infra/schuit/$name"
done
install -d -m 750 -o root -g www-data /srv/schuit/privacy-controls
if ! test -e /srv/schuit/privacy-controls/excluded-xrefs.json; then
  # Initial empty register only: never replace existing exclusions.
  printf '%s\n' '{"version":1,"excluded_xrefs":[]}' > /srv/schuit/privacy-controls/excluded-xrefs.json
fi
chown root:www-data /srv/schuit/privacy-controls/excluded-xrefs.json
chmod 640 /srv/schuit/privacy-controls/excluded-xrefs.json
for name in schuit-worker-broker.socket schuit-worker-broker@.service schuit-gedcom.service schuit-security.service schuit-security.timer schuit-backup.service schuit-restore.service; do
  install -m 644 "$source_dir/deployment/$name" "/etc/systemd/system/$name"
done
install -m 644 "$source_dir/deployment/schuit-worker.conf" /etc/tmpfiles.d/schuit-worker.conf
systemd-tmpfiles --create /etc/tmpfiles.d/schuit-worker.conf
systemctl daemon-reload
systemctl enable --now schuit-worker-broker.socket
systemctl start schuit-gedcom.timer
# Enable schuit-security.timer after the published candidate has deployed.
echo 'SCHUIT queue privilege separation and release controls installed.'
