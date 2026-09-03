<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Direct Member Login';

$error = '';
$q = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND status = 'active'");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if (!$member) {
        $error = 'Active member not found.';
    } else {
        // Keep admin session; open member session separately
        $_SESSION['member_id'] = $member['id'];
        $_SESSION['member_code'] = $member['member_id'];
        $_SESSION['member_name'] = $member['full_name'];
        $_SESSION['member_login_by_admin'] = true;
        $_SESSION['member_login_admin_id'] = $_SESSION['admin_id'] ?? null;
        session_touch('member');
        if (!empty($_SESSION['admin_id'])) {
            session_touch('admin');
        }

        log_activity('direct_member_login', 'Admin logged in as ' . $member['member_id']);
        header('Location: ../member/index.php');
        exit;
    }
}

$where = "status = 'active'";
$params = [];
if ($q !== '') {
    $where .= ' AND (member_id LIKE ? OR username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
}

$stmt = $pdo->prepare("SELECT id, member_id, username, full_name, email, phone, wallet_balance FROM members WHERE $where ORDER BY id DESC LIMIT 50");
$stmt->execute($params);
$members = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2>Direct Member Login</h2></div>
    <div class="panel-body">
        <p style="color:var(--ink-muted);margin-bottom:1rem;font-size:0.9rem">
            Select a member to open their dashboard as admin (impersonation). Your admin session stays active.
        </p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <form class="filters" method="get">
            <div class="form-group">
                <label>Search Member</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Member ID, name, username...">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="direct-member-login.php" class="btn btn-outline">Reset</a>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Member ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Wallet</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$members): ?>
                <tr><td colspan="6">No active members found.</td></tr>
            <?php else: foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['member_id']) ?></td>
                    <td><strong><?= e($m['full_name']) ?></strong></td>
                    <td><?= e($m['username']) ?></td>
                    <td><?= e($m['email']) ?></td>
                    <td><?= currency((float)$m['wallet_balance']) ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="btn btn-accent btn-sm" data-confirm="Login as <?= e($m['member_id']) ?>?">Login as Member</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
