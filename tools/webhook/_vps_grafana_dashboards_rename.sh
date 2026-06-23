#!/bin/bash
# Rename Grafana dashboards + Loki/Promtail labels fismapant -> pantedu.
set -e

echo "=== 1. Rename dashboard provisioning yaml ==="
SRC_PROV=/etc/grafana/provisioning/dashboards/fismapant.yaml
DST_PROV=/etc/grafana/provisioning/dashboards/pantedu.yaml
if [ -f "$SRC_PROV" ]; then
    sed -e "s/name: 'fismapant'/name: 'pantedu'/" \
        -e "s/folder: 'Fismapant'/folder: 'Pantedu'/" \
        "$SRC_PROV" > "$DST_PROV"
    chown root:grafana "$DST_PROV"
    chmod 0640 "$DST_PROV"
    rm -f "$SRC_PROV"
    echo "  renamed -> $DST_PROV"
fi

echo "=== 2. Rename dashboard JSON files + rewrite content ==="
DASH_DIR=/var/lib/grafana/dashboards
for f in "$DASH_DIR"/*fismapant*.json; do
    [ -f "$f" ] || continue
    base=$(basename "$f")
    new=$(echo "$base" | sed 's/fismapant/pantedu/g')
    sed -i \
        -e 's/fismapant-mysql/pantedu-mysql/g' \
        -e 's/"fismapant"/"pantedu"/g' \
        -e 's/Fismapant/Pantedu/g' \
        -e 's/fismapant —/pantedu —/g' \
        "$f"
    mv "$f" "$DASH_DIR/$new"
    echo "  rewrote + renamed $base -> $new"
done

# Also rewrite content of any remaining .json (e.g. authority-forensics.json)
for f in "$DASH_DIR"/*.json; do
    [ -f "$f" ] || continue
    if grep -q 'fismapant' "$f" 2>/dev/null; then
        sed -i \
            -e 's/fismapant-mysql/pantedu-mysql/g' \
            -e 's/"fismapant"/"pantedu"/g' \
            -e 's/Fismapant/Pantedu/g' \
            "$f"
        echo "  rewrote (in place) $(basename $f)"
    fi
done

echo "=== 3. Promtail labels ==="
if [ -f /etc/promtail/config.yml ]; then
    if grep -q 'fismapant' /etc/promtail/config.yml; then
        cp /etc/promtail/config.yml /etc/promtail/config.yml.bak.$(date +%Y%m%d_%H%M%S)
        sed -i 's/fismapant/pantedu/g' /etc/promtail/config.yml
        echo "  promtail config rewritten (backup .bak)"
        systemctl restart promtail
    else
        echo "  promtail: no fismapant refs"
    fi
fi

echo "=== 4. Loki labels (config) ==="
if [ -f /etc/loki/config.yml ]; then
    if grep -q 'fismapant' /etc/loki/config.yml; then
        cp /etc/loki/config.yml /etc/loki/config.yml.bak.$(date +%Y%m%d_%H%M%S)
        sed -i 's/fismapant/pantedu/g' /etc/loki/config.yml
        echo "  loki config rewritten"
        systemctl restart loki
    else
        echo "  loki: no fismapant refs"
    fi
fi

echo "=== 5. Restart grafana to reload provisioning ==="
systemctl restart grafana-server
sleep 4
systemctl is-active grafana-server

echo "=== 6. Final state ==="
echo "--- dashboard provisioning ---"
ls /etc/grafana/provisioning/dashboards/
echo "--- dashboards ---"
ls /var/lib/grafana/dashboards/
echo "--- services ---"
for s in loki promtail grafana-server suricata; do echo "$s: $(systemctl is-active $s)"; done

echo "=== DONE ==="
