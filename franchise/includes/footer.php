<?php
/**
 * Franchise Footer - Closes main-wrap and includes admin.js
 */
?>
        </main>
    </div>
</div>
<script src="../assets/js/admin.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const closeBtn = document.getElementById('sidebarCloseBtn');
    const sidebar = document.getElementById('sidebar');
    if (closeBtn && sidebar) {
        closeBtn.addEventListener('click', () => {
            sidebar.classList.remove('open');
            document.querySelector('.sidebar-overlay')?.classList.remove('show');
        });
    }
});
</script>
</body>
</html>
