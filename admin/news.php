<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'News Add';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'news', (int) $_GET['toggle']);
    header('Location: news.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'news', (int) $_GET['delete']);
    header('Location: news.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $published = trim($_POST['published_at'] ?? '') ?: null;
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($title === '') $errors[] = 'Title is required.';
    if ($content === '') $errors[] = 'Content is required.';

    if (!$errors) {
        if ($id > 0) {
            $pdo->prepare('UPDATE news SET title=?, content=?, published_at=?, status=? WHERE id=?')
                ->execute([$title, $content, $published, $status, $id]);
            flash('success', 'News updated.');
        } else {
            $pdo->prepare('INSERT INTO news (title, content, published_at, status) VALUES (?,?,?,?)')
                ->execute([$title, $content, $published ?: date('Y-m-d'), $status]);
            flash('success', 'News added.');
        }
        log_activity($id ? 'news_edit' : 'news_add', $title);
        header('Location: news.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM news WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT * FROM news ORDER BY id DESC')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit News' : 'Add News' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" value="<?= e($edit['title'] ?? $_POST['title'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Publish Date</label>
                    <input type="date" name="published_at" value="<?= e($edit['published_at'] ?? $_POST['published_at'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-top:1rem">
                <label>Content *</label>
                <textarea name="content" rows="5" required><?= e($edit['content'] ?? $_POST['content'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add News' ?></button>
                <?php if ($edit): ?><a href="news.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>News List (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Title</th><th>Published</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="4">No news yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <strong><?= e($r['title']) ?></strong><br>
                        <small style="color:var(--ink-muted)"><?= e(mb_strimwidth($r['content'], 0, 80, '...')) ?></small>
                    </td>
                    <td><?= $r['published_at'] ? date('d M Y', strtotime($r['published_at'])) : '—' ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this news?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
