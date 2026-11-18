# Deploying Wyncrest (single host, SQLite)

The verified launch configuration: one Amazon Linux 2023 host, nginx serving
`frontend/dist` and proxying `/api`, `/sanctum`, `/storage`, `/up` to php-fpm,
SQLite in WAL mode, and three systemd units from this folder. Full runbook,
preflight and recovery: `docs/OPERATIONS.md`.

## Install once (as root)

```bash
cp deploy/systemd/wyncrest-*.service deploy/systemd/wyncrest-*.timer /etc/systemd/system/
mkdir -p /var/backups/wyncrest && chown apache:apache /var/backups/wyncrest && chmod 700 /var/backups/wyncrest
dnf install -y sqlite                      # the backup script uses the sqlite3 CLI
systemctl daemon-reload
systemctl enable --now wyncrest-scheduler.timer wyncrest-queue.service wyncrest-backup.timer
```

The units run as `apache` (the php-fpm user) so logs and the SQLite file stay
writable by the web app. If your php-fpm user differs, edit `User=`/`Group=`.

## Check they work

```bash
systemctl list-timers 'wyncrest-*'
journalctl -u wyncrest-scheduler -n 20     # one tick per minute
journalctl -u wyncrest-queue -n 20
sudo -u apache php artisan ops:scheduler-status   # exit 0 = billing jobs ran in time
tail -n 5 storage/logs/scheduler-runs.log
sudo systemctl start wyncrest-backup && ls -l /var/backups/wyncrest/
```

`ops:scheduler-status` stays unhealthy until the first 01:00 rent run has
happened; that first scheduled run is the evidence the scheduler works.
