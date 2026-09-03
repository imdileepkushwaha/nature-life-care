<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/procedures.php';

ensure_mlm_procedures($pdo);

$rows = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()")->fetchAll();
if (!$rows) {
    echo "No procedures installed.\n";
    exit(1);
}

echo "Installed procedures:\n";
foreach ($rows as $r) {
    echo '- ' . $r['Name'] . "\n";
}
