#!/bin/bash
DB=/var/lib/grafana/grafana.db
echo "=== folder table ==="
sqlite3 -header -column "$DB" "SELECT id, uid, title FROM folder;"
echo "=== dashboard rows (all) ==="
sqlite3 -header -column "$DB" "SELECT id, uid, slug, title, folder_id, is_folder FROM dashboard;"
echo "=== dashboard_provisioning ==="
sqlite3 -header -column "$DB" "SELECT * FROM dashboard_provisioning LIMIT 20;"
