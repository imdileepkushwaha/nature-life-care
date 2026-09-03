<?php
echo '</main></div></div>';
?>
<script src="../assets/js/admin.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/admin.js') ?>"></script>
<?php /* Mobile menu: handled once in admin.js (menuToggle → .sidebar.open + overlay). Do not re-bind here. */ ?>
</body>
</html>
