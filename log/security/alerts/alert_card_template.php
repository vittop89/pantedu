<?php
// Determina se l'alert è inattivo (visionato o bloccato)
$isInactive = false;
$inactiveReason = $anomaly['inactive_reason'] ?? '';

if ($anomaly['is_reviewed']) {
    $isInactive = true;
    $inactiveReason = 'reviewed';
} elseif ($anomaly['type'] === 'credential_sharing' && isCredentialsBlocked($anomaly['username'])) {
    $isInactive = true;
    $inactiveReason = 'blocked_credentials';
} elseif ($anomaly['type'] === 'excessive_access' && isIPBlocked($anomaly['ip'] ?? '', $anomaly['section'] ?? '')) {
    $isInactive = true;
    $inactiveReason = 'blocked_ip';
}

// Crea un ID unico per ogni anomalia includendo tutti i dettagli distintivi
if ($anomaly['type'] === 'credential_sharing') {
    $uniqueString = $anomaly['user_fingerprint'] . '|' . $anomaly['type'] . '|' . $anomaly['username'] . '|' . count($anomaly['ip_addresses']);
} else {
    $uniqueString = $anomaly['user_fingerprint'] . '|' . $anomaly['type'] . '|' . ($anomaly['ip'] ?? '') . '|' . ($anomaly['section'] ?? '') . '|' . ($anomaly['access_count'] ?? 0);
}
$cardId = 'alert-' . md5($uniqueString);
?>
<div class="alert-card alert-<?= strtolower($anomaly['risk_level']) ?><?= $anomaly['is_reviewed'] ? ' reviewed' : '' ?> collapsed" id="<?= $cardId ?>" style="position: relative;">
    <!-- Pulsante rimozione per card inattive -->
    <?php if ($isInactive): ?>
        <div style="position: absolute; left: -10px; top: 50%; transform: translateY(-50%); z-index: 10;">
            <?php if ($inactiveReason === 'reviewed'): ?>
                <?php if ($anomaly['type'] === 'credential_sharing'): ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="unmark_credential_reviewed">
                        <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($anomaly['user_fingerprint']) ?>">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($anomaly['username']) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($anomaly['date']) ?>">
                        <button type="submit" style="background: #dc3545; color: white; border: none; padding: 8px; border-radius: 50%; cursor: pointer; font-size: 12px; width: 32px; height: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);" title="Rimuovi Visionato">
                            👁️
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="unmark_reviewed">
                        <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($anomaly['user_fingerprint']) ?>">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($anomaly['ip']) ?>">
                        <input type="hidden" name="section" value="<?= htmlspecialchars($anomaly['section']) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($anomaly['date']) ?>">
                        <button type="submit" style="background: #dc3545; color: white; border: none; padding: 8px; border-radius: 50%; cursor: pointer; font-size: 12px; width: 32px; height: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);" title="Rimuovi Visionato">
                            👁️
                        </button>
                    </form>
                <?php endif; ?>
            <?php elseif ($inactiveReason === 'blocked_credentials'): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="unblock_credentials">
                    <input type="hidden" name="username" value="<?= htmlspecialchars($anomaly['username']) ?>">
                    <button type="submit" style="background: #6c757d; color: white; border: none; padding: 8px; border-radius: 50%; cursor: pointer; font-size: 12px; width: 32px; height: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);" title="Sblocca Credenziali">
                        🔓
                    </button>
                </form>
            <?php elseif ($inactiveReason === 'blocked_ip'): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="unblock_ip">
                    <input type="hidden" name="ip" value="<?= htmlspecialchars($anomaly['ip']) ?>">
                    <input type="hidden" name="section" value="<?= htmlspecialchars($anomaly['section']) ?>">
                    <button type="submit" style="background: #6c757d; color: white; border: none; padding: 8px; border-radius: 50%; cursor: pointer; font-size: 12px; width: 32px; height: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);" title="Sblocca IP">
                        🌐
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; margin-left: <?= $isInactive ? '25px' : '0' ?>;">
        <?php if ($anomaly['type'] === 'credential_sharing'): ?>
            <h3>🔑 Credenziali Condivise: <?= htmlspecialchars($anomaly['username']) ?> (<?= $anomaly['ip_count'] ?> IP)</h3>
        <?php else: ?>
            <h3>🚨 Accesso Eccessivo: <?= htmlspecialchars($anomaly['username'] ?? 'Anonimo') ?> (<?= htmlspecialchars($anomaly['ip'] ?? 'N/D') ?>)</h3>
        <?php endif; ?>
        
        <div class="action-buttons">
            <button class="btn btn-primary" onclick="toggleAlert('<?= $cardId ?>')">
                <span class="toggle-text">▼ Espandi</span>
            </button>
            
            <?php if ($anomaly['type'] === 'credential_sharing'): ?>
                <a href="../monitoring/viewer.php?user=<?= urlencode($anomaly['username']) ?>" 
                   class="btn btn-info details-btn" target="_blank" style="margin-left: 10px;">
                    🔍 Vedi Dettagli
                </a>
            <?php else: ?>
                <!-- Pulsante per accessi eccessivi -->
                <a href="../monitoring/viewer.php?user=<?= urlencode($anomaly['username'] ?? '') ?><?= !empty($anomaly['ip']) ? '&filter_ip=' . urlencode($anomaly['ip']) : '' ?>" 
                   class="btn btn-info details-btn" target="_blank" style="margin-left: 10px;">
                    🔍 Vedi Dettagli
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Resto del contenuto della card come prima -->
    <?php if ($anomaly['type'] === 'credential_sharing'): ?>
        <!-- Alert per condivisione credenziali -->
        <div class="alert-details">
            <div class="detail-item">
                <div class="detail-label">Username</div>
                <div class="detail-value"><?= htmlspecialchars($anomaly['username']) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Data</div>
                <div class="detail-value">
                    <?= date('d/m/Y', strtotime($anomaly['date'])) ?>
                    <?php if (($anomaly['is_incremental'] ?? false) && $anomaly['date'] === date('Y-m-d')): ?>
                        <span style="color: #ff6b35; font-weight: bold; margin-left: 10px;">⚡ OGGI</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Numero IP distinti</div>
                <div class="detail-value"><?= $anomaly['ip_count'] ?> IP</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Accessi totali</div>
                <div class="detail-value"><?= $anomaly['total_access_count'] ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Primo accesso</div>
                <div class="detail-value"><?= date('d/m/Y H:i', strtotime($anomaly['first_access'])) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Ultimo accesso</div>
                <div class="detail-value"><?= date('d/m/Y H:i', strtotime($anomaly['last_access'])) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Livello di rischio</div>
                <div class="detail-value">
                    <span class="risk-<?= strtolower($anomaly['risk_level']) ?>">
                        <?= $anomaly['risk_level'] ?>
                    </span>
                </div>
            </div>
            
            <div class="detail-item">
                <div class="detail-label">Indirizzi IP</div>
                <div class="detail-value">
                    <?php foreach (array_slice($anomaly['ip_addresses'], 0, 5) as $ip): ?>
                        <span class="ip-badge"><?= htmlspecialchars($ip) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($anomaly['ip_addresses']) > 5): ?>
                        <span class="more-indicator">... e altri <?= count($anomaly['ip_addresses']) - 5 ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="detail-item">
                <div class="detail-label">Sezioni accedute</div>
                <div class="detail-value"><?= htmlspecialchars($anomaly['section']) ?></div>
            </div>
            
            <!-- Azioni per credenziali condivise -->
            <div class="actions-section">
                <!-- Blocca credenziali -->
                <?php if (!isCredentialsBlocked($anomaly['username'])): ?>
                    <div class="action-form">
                        <form method="post" style="margin: 0;" onsubmit="return confirmBlockCredentials('<?= htmlspecialchars($anomaly['username']) ?>', this)">
                            <input type="hidden" name="action" value="block_credentials">
                            <input type="hidden" name="username" value="<?= htmlspecialchars($anomaly['username']) ?>">
                            <div style="margin-bottom: 10px;">
                                <label>🚫 Motivazione blocco:</label>
                                <input type="text" name="block_reason" placeholder="Es: Credenziali compromesse, uso non autorizzato..." required>
                            </div>
                            <button type="submit" class="btn btn-danger">🚫 Blocca Credenziali</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="blocked-notice">
                        🚫 <strong>Credenziali bloccate</strong> - L'accesso per questo utente è già stato bloccato
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($anomaly['is_reviewed']): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="unmark_credential_reviewed">
                    <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($anomaly['user_fingerprint']) ?>">
                    <input type="hidden" name="username" value="<?= htmlspecialchars($anomaly['username']) ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($anomaly['date']) ?>">
                    <button type="submit" class="btn btn-secondary">👁️ Rimuovi Visionato</button>
                </form>
            <?php else: ?>
                <!-- Form per marcare come visionato -->
                <div class="action-form">
                    <form method="post" style="margin: 0;" onsubmit="return confirmMarkCredentialReviewed(this)">
                        <input type="hidden" name="action" value="mark_credential_reviewed">
                        <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($anomaly['user_fingerprint']) ?>">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($anomaly['username']) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($anomaly['date']) ?>">
                        <div style="margin-bottom: 10px;">
                            <label>✅ Giustificazione:</label>
                            <input type="text" name="review_reason" placeholder="Es: Utente legittimo con accessi multipli, famiglia condivisa, test autorizzato..." required>
                        </div>
                        <button type="submit" class="btn btn-secondary">✅ Marca come Visionato</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
    <?php else: ?>
        <!-- Alert per accessi eccessivi tradizionali -->
        <div class="alert-details">
            <div class="detail-item">
                <div class="detail-label">Indirizzo IP</div>
                <div class="detail-value"><?= htmlspecialchars($anomaly['ip'] ?? 'N/D') ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Sezione</div>
                <div class="detail-value"><?= htmlspecialchars($anomaly['section'] ?? 'N/D') ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Numero di accessi</div>
                <div class="detail-value"><?= htmlspecialchars($anomaly['access_count'] ?? 0) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Username</div>
                <div class="detail-value"><?= htmlspecialchars($anomaly['username'] ?? 'Anonimo') ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Primo accesso</div>
                <div class="detail-value"><?= date('d/m/Y H:i', strtotime($anomaly['first_access'])) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Ultimo accesso</div>
                <div class="detail-value"><?= date('d/m/Y H:i', strtotime($anomaly['last_access'])) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Livello di rischio</div>
                <div class="detail-value">
                    <span class="risk-<?= strtolower($anomaly['risk_level']) ?>">
                        <?= $anomaly['risk_level'] ?>
                    </span>
                </div>
            </div>
            
            <!-- Azioni per accessi eccessivi -->
            <div class="actions-section">
                <!-- Blocca IP -->
                <?php if (!isIPBlocked($anomaly['ip'] ?? '', $anomaly['section'] ?? '')): ?>
                    <div class="action-form">
                        <form method="post" style="margin: 0;" onsubmit="return confirmBlockIP('<?= htmlspecialchars($anomaly['section'] ?? '') ?>', this)">
                            <input type="hidden" name="action" value="block_ip">
                            <input type="hidden" name="ip" value="<?= htmlspecialchars($anomaly['ip'] ?? '') ?>">
                            <input type="hidden" name="section" value="<?= htmlspecialchars($anomaly['section'] ?? '') ?>">
                            <div style="margin-bottom: 10px;">
                                <label>🚫 Motivazione blocco:</label>
                                <input type="text" name="block_reason" placeholder="Es: Comportamento sospetto, accessi eccessivi..." required>
                            </div>
                            <button type="submit" class="btn btn-danger">🚫 Blocca IP</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="blocked-notice">
                        🚫 <strong>IP bloccato</strong> - Questo IP è già stato bloccato per questa sezione
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($anomaly['is_reviewed']): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="unmark_reviewed">
                    <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($anomaly['user_fingerprint']) ?>">
                    <input type="hidden" name="ip" value="<?= htmlspecialchars($anomaly['ip']) ?>">
                    <input type="hidden" name="section" value="<?= htmlspecialchars($anomaly['section']) ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($anomaly['date']) ?>">
                    <button type="submit" class="btn btn-secondary">👁️ Rimuovi Visionato</button>
                </form>
            <?php else: ?>
                <!-- Form per marcare come visionato -->
                <div class="action-form">
                    <form method="post" style="margin: 0;" onsubmit="return confirmMarkReviewed(this)">
                        <input type="hidden" name="action" value="mark_reviewed">
                        <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($anomaly['user_fingerprint']) ?>">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($anomaly['ip']) ?>">
                        <input type="hidden" name="section" value="<?= htmlspecialchars($anomaly['section']) ?>">
                        <input type="hidden" name="access_count" value="<?= htmlspecialchars($anomaly['access_count']) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($anomaly['date']) ?>">
                        <div style="margin-bottom: 10px;">
                            <label>✅ Giustificazione:</label>
                            <input type="text" name="review_reason" placeholder="Es: Attività normale, test autorizzato, utente legittimo..." required>
                        </div>
                        <button type="submit" class="btn btn-secondary">✅ Marca come Visionato</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

