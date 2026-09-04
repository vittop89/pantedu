#!/bin/bash
# Delete stale Fismapant folder from Grafana sqlite.
set -e
DB=/var/lib/grafana/grafana.db

echo "=== Tables ==="
sqlite3 "$DB" ".tables" | tr ' ' '\n' | grep -iE 'folder|dashboard'

echo "=== Schema dashboard ==="
sqlite3 "$DB" ".schema dashboard" | head -5

echo "=== Folder schema (if exists) ==="
sqlite3 "$DB" ".schema folder" 2>&1 | head -5

echo "=== Dashboard rows where is_folder=1 ==="
sqlite3 -header -column "$DB" "SELECT id, uid, title FROM dashboard WHERE is_folder = 1"

echo "=== folder table rows (newer grafana) ==="
sqlite3 -header -column "$DB" "SELECT id, uid, title, parent_uid FROM folder" 2>&1 || true

echo "=== Dashboards inside fismapant-like folders ==="
sqlite3 -header -column "$DB" "SELECT id, uid, title, folder_id FROM dashboard WHERE folder_id IN (SELECT id FROM dashboard WHERE is_folder=1 AND title LIKE '%ismapant%')" 2>&1 || true

echo "=== DELETE plan ==="
systemctl stop grafana-server
sqlite3 "$DB" <<'SQL'
-- Move any dashboards out of the fismapant folder back to "General" (folder_id=0)
UPDATE dashboard
   SET folder_id = 0
 WHERE folder_id IN (SELECT id FROM dashboard WHERE is_folder=1 AND title LIKE '%ismapant%');

-- Delete the folder rows themselves
DELETE FROM dashboard WHERE is_folder=1 AND title LIKE '%ismapant%';

-- Cleanup newer 'folder' table if present
DELETE FROM folder WHERE title LIKE '%ismapant%';
SQL
echo "  sqlite ops done"
systemctl start grafana-server
sleep 3
systemctl is-active grafana-server

echo "=== Post-state ==="
sqlite3 -header -column "$DB" "SELECT id, uid, title FROM dashboard WHERE is_folder = 1"
sqlite3 -header -column "$DB" "SELECT id, uid, title FROM folder" 2>&1 || true

echo "=== DONE ==="
