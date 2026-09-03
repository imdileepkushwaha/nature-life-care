<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Matrix Tree';

$rootCode = trim((string) ($_GET['member'] ?? ''));
$width = matrix_width();
members_ensure_flexible_position($pdo);

$root = null;
if ($rootCode !== '') {
    $stmt = $pdo->prepare('SELECT id, member_id, full_name, status, package_id, placement_id, position FROM members WHERE member_id = ? LIMIT 1');
    $stmt->execute([$rootCode]);
    $root = $stmt->fetch() ?: null;
}
if (!$root) {
    $root = $pdo->query('SELECT id, member_id, full_name, status, package_id, placement_id, position FROM members ORDER BY id ASC LIMIT 1')->fetch() ?: null;
}

/**
 * @return array{node:array,children:list}
 */
function matrix_tree_build(PDO $pdo, array $node, int $depth, int $maxDepth = 4): array
{
    $children = [];
    if ($depth < $maxDepth) {
        foreach (matrix_children($pdo, (int) $node['id']) as $ch) {
            $children[] = matrix_tree_build($pdo, $ch, $depth + 1, $maxDepth);
        }
    }
    return ['node' => $node, 'children' => $children];
}

$tree = $root ? matrix_tree_build($pdo, $root, 0, 4) : null;

require_once __DIR__ . '/../includes/header.php';

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
        echo '<div class="mtx-row" style="--mtx-cols:' . (int) max(count($kids), $width) . '">';
        foreach ($kids as $ch) {
            $render($ch);
        }
        echo '</div>';
    }
    echo '</div>';
};
?>
<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Matrix Tree (<?= (int) $width ?>×)</h2>
            <p class="members-sub">Spillover placement view — up to 4 levels deep</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="get" class="form-grid" style="margin-bottom:1rem;max-width:420px">
            <div class="form-group">
                <label>Root member ID</label>
                <input type="text" name="member" value="<?= e($root['member_id'] ?? $rootCode) ?>" placeholder="MLM00001">
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">View</button>
            </div>
        </form>

        <?php if (!$tree): ?>
        <div class="empty-state"><strong>No members found</strong></div>
        <?php else: ?>
        <div class="mtx-tree">
            <?php $render($tree); ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<style>
.mtx-tree { overflow-x: auto; padding: 1rem 0; }
.mtx-node { display: flex; flex-direction: column; align-items: center; gap: .75rem; min-width: 120px; }
.mtx-card {
    min-width: 140px; padding: .65rem .8rem; border-radius: 12px;
    background: #fff; border: 1px solid #e8ecf4; text-align: center;
    box-shadow: 0 4px 14px rgba(15,23,42,.05);
}
.mtx-card strong { display:block; font-size:.9rem; }
.mtx-card span { display:block; font-size:.75rem; color:#64748b; }
.mtx-card em { display:block; font-size:.7rem; color:#e11d48; font-style:normal; margin-top:.2rem; }
.mtx-row {
    display: grid;
    grid-template-columns: repeat(var(--mtx-cols, 3), minmax(120px, 1fr));
    gap: 1rem;
    width: max-content;
    padding-top: .5rem;
    border-top: 1px dashed #e2e8f0;
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
