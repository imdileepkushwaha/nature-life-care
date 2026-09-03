<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'My Matrix';

$width = matrix_width();
members_ensure_flexible_position($pdo);
$user = current_user($pdo);
if (!$user) {
    header('Location: login.php');
    exit;
}

function user_matrix_build(PDO $pdo, array $node, int $depth, int $maxDepth = 3): array
{
    $children = [];
    if ($depth < $maxDepth) {
        foreach (matrix_children($pdo, (int) $node['id']) as $ch) {
            $children[] = user_matrix_build($pdo, $ch, $depth + 1, $maxDepth);
        }
    }
    return ['node' => $node, 'children' => $children];
}

$tree = user_matrix_build($pdo, $user, 0, 3);
require __DIR__ . '/includes/header.php';

$render = static function (array $branch) use (&$render, $width): void {
    $n = $branch['node'];
    $kids = $branch['children'];
    echo '<div class="mtx-node">';
    echo '<div class="mtx-card">';
    echo '<strong>' . e($n['full_name']) . '</strong>';
    echo '<span>' . e($n['member_id']) . '</span>';
    if (!empty($n['position'])) {
        echo '<em>Slot ' . e((string) $n['position']) . '</em>';
    }
    echo '</div>';
    if ($kids) {
        echo '<div class="mtx-row" style="--mtx-cols:' . (int) max(count($kids), 1) . '">';
        foreach ($kids as $ch) {
            $render($ch);
        }
        echo '</div>';
    }
    echo '</div>';
};
?>
<div class="up-page-head">
    <div>
        <h1>Matrix Tree</h1>
        <p>Your <?= (int) $width ?>× matrix downline (spillover placement)</p>
    </div>
</div>
<div class="up-card">
    <div class="mtx-tree">
        <?php $render($tree); ?>
    </div>
</div>
<style>
.mtx-tree { overflow-x: auto; padding: 1rem 0; }
.mtx-node { display: flex; flex-direction: column; align-items: center; gap: .75rem; min-width: 120px; }
.mtx-card {
    min-width: 140px; padding: .65rem .8rem; border-radius: 12px;
    background: #111827; border: 1px solid rgba(255,255,255,.08); text-align: center; color: #fff;
}
.mtx-card strong { display:block; font-size:.9rem; }
.mtx-card span { display:block; font-size:.75rem; color:#94a3b8; }
.mtx-card em { display:block; font-size:.7rem; color:#fbbf24; font-style:normal; margin-top:.2rem; }
.mtx-row {
    display: grid;
    grid-template-columns: repeat(var(--mtx-cols, 3), minmax(120px, 1fr));
    gap: 1rem;
    width: max-content;
    padding-top: .5rem;
    border-top: 1px dashed rgba(255,255,255,.12);
}
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
