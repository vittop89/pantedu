    <script>
        function confirmBlockIP(section, form) {
            const reason = form.querySelector('input[name="block_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una motivazione per il blocco.');
                return false;
            }
            return confirm(`Sei sicuro di voler bloccare questo IP per la sezione ${section}?\n\nMotivo: ${reason}`);
        }

        function confirmBlockCredentials(username, form) {
            const reason = form.querySelector('input[name="block_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una motivazione per il blocco.');
                return false;
            }
            return confirm(`Sei sicuro di voler bloccare le credenziali di ${username}?\n\nMotivo: ${reason}`);
        }

        function confirmMarkReviewed(form) {
            const reason = form.querySelector('input[name="review_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una giustificazione per marcare come visionato.');
                return false;
            }
            return confirm(`Sei sicuro di voler marcare questo alert come visionato?\n\nGiustificazione: ${reason}`);
        }

        function confirmMarkCredentialReviewed(form) {
            const reason = form.querySelector('input[name="review_reason"]').value.trim();
            if (!reason) {
                alert('È necessario inserire una giustificazione per marcare come visionato.');
                return false;
            }
            return confirm(`Sei sicuro di voler marcare questo alert di condivisione credenziali come visionato?\n\nGiustificazione: ${reason}`);
        }

        function toggleAlert(cardId) {
            const card = document.getElementById(cardId);
            if (!card) return;
            
            const details = card.querySelector('.alert-details');
            const toggleButton = card.querySelector('.toggle-text');
            
            if (card.classList.contains('expanded')) {
                card.classList.remove('expanded');
                card.classList.add('collapsed');
                details.style.display = 'none';
                toggleButton.textContent = '▼ Espandi';
            } else {
                card.classList.remove('collapsed');
                card.classList.add('expanded');
                details.style.display = 'block';
                toggleButton.textContent = '▲ Chiudi';
            }
        }

        // Auto-refresh ogni 5 minuti se necessario
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                window.location.reload();
            }, 300000);
        });
    </script>

    <?php renderContainerEnd(); ?>
</body>
</html>
