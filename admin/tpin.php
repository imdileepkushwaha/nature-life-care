<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tpin.php';

$pageTitle = 'Add T-Pin';
tpin_ensure_tables($pdo);

// AJAX: Member name by Member ID only
if (isset($_GET['ajax']) && $_GET['ajax'] === 'member') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim((string) ($_GET['member_id'] ?? ''));
    $member = $code !== '' ? tpin_find_member_by_code($pdo, $code) : null;
    if (!$member || ($member['status'] ?? '') === 'blocked') {
        echo json_encode(['ok' => false, 'name' => '']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'name' => (string) $member['full_name'],
        'member_id' => (string) $member['member_id'],
    ]);
    exit;
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$errors = [];
$lastBatch = null;
$lastPins = [];

function tpin_admin_filter_qs(array $extra = []): string
{
    $qs = [];
    foreach (['q', 'status', 'package_id'] as $k) {
        $v = trim((string) ($_GET[$k] ?? ''));
        if ($v !== '' && !($k === 'package_id' && $v === '0')) {
            $qs[$k] = $v;
        }
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '' || ($k === 'package_id' && (string) $v === '0')) {
            unset($qs[$k]);
        } else {
            $qs[$k] = (string) $v;
        }
    }
    return $qs ? ('?' . http_build_query($qs)) : '';
}

if (isset($_GET['block'])) {
    $res = tpin_block($pdo, (int) $_GET['block'], $adminId ?: null);
    flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'T-Pin blocked.' : ($res['error'] ?? 'Block failed.'));
    header('Location: tpin.php' . tpin_admin_filter_qs());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = (int) ($_POST['package_id'] ?? 0);
    $qty = (int) ($_POST['qty'] ?? 0);
    $assignCode = trim((string) ($_POST['assign_member'] ?? ''));
    $assignId = null;

    if ($assignCode !== '') {
        $member = tpin_find_member_by_code($pdo, $assignCode);
        if (!$member) {
            $errors[] = 'Member ID not found.';
        } elseif (($member['status'] ?? '') === 'blocked') {
            $errors[] = 'Member is blocked.';
        } else {
            $assignId = (int) $member['id'];
        }
    }

    if (!$errors) {
        $res = tpin_generate($pdo, $packageId, $qty, $assignId, $adminId ?: null, '');
        if ($res['ok']) {
            $msg = $res['created'] . ' T-Pin(s) generated. Batch: ' . $res['batch'];
            if ($assignId) {
                $msg .= ' · Assigned to ' . $assignCode;
            }
            flash('success', $msg);
            $lastBatch = $res['batch'];
            $lastPins = $res['pins'];
            log_activity('tpin_generate', 'Generated ' . $res['created'] . ' pins batch ' . $res['batch'] . ($assignId ? ' for ' . $assignCode : ''));
        } else {
            $errors[] = $res['error'] ?? 'Generate failed.';
        }
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$pkgFilter = (int) ($_GET['package_id'] ?? 0);

$where = ['1=1'];
$params = [];
if (in_array($status, ['unused', 'used', 'blocked'], true)) {
    $where[] = 'tp.status = ?';
    $params[] = $status;
}
if ($pkgFilter > 0) {
    $where[] = 'tp.package_id = ?';
    $params[] = $pkgFilter;
}
if ($q !== '') {
    $where[] = '(tp.pin_code LIKE ? OR tp.batch_code LIKE ? OR am.member_id LIKE ? OR um.member_id LIKE ? OR am.full_name LIKE ? OR um.full_name LIKE ?)';
    $likeCode = '%' . tpin_normalize_code($q) . '%';
    $like = '%' . $q . '%';
    $params[] = $likeCode;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = '
    SELECT tp.*, p.name AS package_name, p.amount AS package_amount,
           am.member_id AS assigned_code, am.full_name AS assigned_name,
           um.member_id AS used_code, um.full_name AS used_name
    FROM topup_pins tp
    JOIN packages p ON p.id = tp.package_id
    LEFT JOIN members am ON am.id = tp.assigned_to
    LEFT JOIN members um ON um.id = tp.used_by
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY tp.id DESC
    LIMIT 300
';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$packages = $pdo->query("SELECT id, name, amount FROM packages WHERE status = 'active' ORDER BY amount ASC")->fetchAll();
$pkgMap = [];
foreach ($packages as $p) {
    $pkgMap[(int) $p['id']] = (float) $p['amount'];
}

$counts = ['unused' => 0, 'used' => 0, 'blocked' => 0];
try {
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM topup_pins GROUP BY status') as $c) {
        $counts[$c['status']] = (int) $c['c'];
    }
} catch (Throwable $e) {
    // ignore
}
$totalPins = $counts['unused'] + $counts['used'] + $counts['blocked'];
$hasFilter = $q !== '' || $status !== '' || $pkgFilter > 0;

$postPkgId = (int) ($_POST['package_id'] ?? 0);
$postQty = (int) ($_POST['qty'] ?? 10);
if ($postQty < 1) {
    $postQty = 10;
}
$postAmt = $postPkgId && isset($pkgMap[$postPkgId]) ? $pkgMap[$postPkgId] : 0.0;
$postTotal = $postAmt * $postQty;
$postAssign = trim((string) ($_POST['assign_member'] ?? ''));
$postMemberName = '';
if ($postAssign !== '') {
    $pm = tpin_find_member_by_code($pdo, $postAssign);
    if ($pm && ($pm['status'] ?? '') !== 'blocked') {
        $postMemberName = (string) $pm['full_name'];
    }
}

require_once __DIR__ . '/../includes/header.php';
$flash = get_flash();
?>

<div class="stats-grid tpin-stats">
    <div class="stat-card accent">
        <div class="label">Unused stock</div>
        <div class="value"><?= (int) $counts['unused'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Used</div>
        <div class="value"><?= (int) $counts['used'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Blocked</div>
        <div class="value"><?= (int) $counts['blocked'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Total pins</div>
        <div class="value"><?= (int) $totalPins ?></div>
    </div>
</div>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Generate T-Pin</h2>
            <p class="tpin-panel-sub">Type A package pins · instant activate / upgrade</p>
        </div>
    </div>
    <div class="panel-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <form method="post" class="tpin-gen-form" id="tpinGenForm">
            <div class="tpin-gen-grid">
                <div class="form-group">
                    <label for="package_id">Package *</label>
                    <select name="package_id" id="package_id" required>
                        <option value="" data-amount="0">Select package</option>
                        <?php foreach ($packages as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" data-amount="<?= e(number_format((float) $p['amount'], 2, '.', '')) ?>" <?= $postPkgId === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="package_amount">Package amount</label>
                    <input type="text" id="package_amount" value="<?= $postAmt > 0 ? e(number_format($postAmt, 2)) : '' ?>" readonly tabindex="-1" placeholder="Select package" class="tpin-readonly">
                </div>
                <div class="form-group">
                    <label for="qty">Quantity *</label>
                    <input type="number" name="qty" id="qty" min="1" max="500" value="<?= e((string) $postQty) ?>" required>
                </div>
                <div class="form-group">
                    <label for="total_amount">Total amount</label>
                    <input type="text" id="total_amount" value="<?= $postTotal > 0 ? e(number_format($postTotal, 2)) : '' ?>" readonly tabindex="-1" placeholder="Package × Qty" class="tpin-readonly">
                </div>
                <div class="form-group">
                    <label for="assign_member">Member ID <em>(optional)</em></label>
                    <input type="text" name="assign_member" id="assign_member" value="<?= e($postAssign) ?>" placeholder="Member ID only" autocomplete="off">
                    <span class="tpin-hint">Blank = company stock. Fill Member ID to generate directly for that member.</span>
                </div>
                <div class="form-group">
                    <label for="member_name">Member name</label>
                    <input type="text" id="member_name" value="<?= e($postMemberName) ?>" readonly tabindex="-1" placeholder="Auto-filled when Member ID matches" class="tpin-readonly">
                </div>
            </div>
            <div class="tpin-gen-actions">
                <button type="submit" class="btn btn-primary">Generate T-Pins</button>
            </div>
        </form>

        <?php if ($lastPins): ?>
            <div class="tpin-batch">
                <div class="tpin-batch-head">
                    <div>
                        <strong>Batch ready</strong>
                        <span><?= e((string) $lastBatch) ?> · <?= count($lastPins) ?> pin<?= count($lastPins) === 1 ? '' : 's' ?></span>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="tpinCopyAll" data-pins="<?= e(implode("\n", array_column($lastPins, 'pin_display'))) ?>">Copy all</button>
                </div>
                <div class="tpin-batch-pins">
                    <?php foreach ($lastPins as $lp): ?>
                        <button type="button" class="tpin-chip" data-copy="<?= e($lp['pin_display']) ?>" title="Click to copy"><?= e($lp['pin_display']) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>T-Pin stock</h2>
            <p class="tpin-panel-sub">Showing <?= count($rows) ?> of <?= (int) $totalPins ?> pins</p>
        </div>
        <div class="tpin-status-pills">
            <a class="tpin-pill<?= $status === '' ? ' is-on' : '' ?>" href="tpin.php<?= tpin_admin_filter_qs(['status' => null]) ?>">All</a>
            <a class="tpin-pill is-unused<?= $status === 'unused' ? ' is-on' : '' ?>" href="tpin.php<?= tpin_admin_filter_qs(['status' => 'unused']) ?>">Unused <?= (int) $counts['unused'] ?></a>
            <a class="tpin-pill is-used<?= $status === 'used' ? ' is-on' : '' ?>" href="tpin.php<?= tpin_admin_filter_qs(['status' => 'used']) ?>">Used <?= (int) $counts['used'] ?></a>
            <a class="tpin-pill is-blocked<?= $status === 'blocked' ? ' is-on' : '' ?>" href="tpin.php<?= tpin_admin_filter_qs(['status' => 'blocked']) ?>">Blocked <?= (int) $counts['blocked'] ?></a>
        </div>
    </div>
    <div class="panel-body tpin-stock-body">
        <form class="tpin-filters" method="get">
            <?php if ($status !== ''): ?>
                <input type="hidden" name="status" value="<?= e($status) ?>">
            <?php endif; ?>
            <div class="form-group tpin-filter-search">
                <label for="tpin_q">Search</label>
                <input type="text" name="q" id="tpin_q" value="<?= e($q) ?>" placeholder="Pin, batch, member ID or name">
            </div>
            <div class="form-group">
                <label for="tpin_pkg">Package</label>
                <select name="package_id" id="tpin_pkg">
                    <option value="0">All packages</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $pkgFilter === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tpin-filter-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="tpin.php" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>

    <?php if (!$rows): ?>
        <div class="act-empty">
            <div class="act-empty-visual" aria-hidden="true">
                <span class="act-empty-ring"></span>
                <span class="act-empty-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h10M15 9h2"/></svg>
                </span>
            </div>
            <strong><?= $hasFilter ? 'No matching T-Pins' : 'No T-Pins yet' ?></strong>
            <p><?= $hasFilter
                ? 'Nothing matches your search or filters. Try clearing them.'
                : 'Generate your first batch above. Pins stay in company stock until transferred from Transfer T-Pin.' ?></p>
            <?php if ($hasFilter): ?>
                <a href="tpin.php" class="btn btn-primary btn-sm">Clear filters</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>Pin</th>
                <th>Package</th>
                <th>Status</th>
                <th>Assigned</th>
                <th>Used by</th>
                <th>Batch</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $code = tpin_format_code((string) $r['pin_code']);
                $st = (string) $r['status'];
            ?>
                <tr>
                    <td>
                        <button type="button" class="tpin-code-btn" data-copy="<?= e($code) ?>" title="Copy pin"><?= e($code) ?></button>
                    </td>
                    <td>
                        <strong class="tpin-pkg-name"><?= e($r['package_name']) ?></strong>
                        <span class="tpin-pkg-amt"><?= currency((float) $r['package_amount']) ?></span>
                    </td>
                    <td><span class="tpin-badge tpin-badge-<?= e($st) ?>"><?= e($st) ?></span></td>
                    <td>
                        <?php if (!empty($r['assigned_code'])): ?>
                            <strong><?= e($r['assigned_name']) ?></strong>
                            <span class="tpin-meta"><?= e($r['assigned_code']) ?></span>
                        <?php else: ?>
                            <span class="tpin-stock-tag">Company stock</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['used_code'])): ?>
                            <strong><?= e($r['used_name']) ?></strong>
                            <span class="tpin-meta"><?= e($r['used_code']) ?><?= !empty($r['used_at']) ? ' · ' . e(date('d M Y H:i', strtotime((string) $r['used_at']))) : '' ?></span>
                        <?php else: ?>
                            <span class="tpin-dash">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="tpin-batch-code"><?= e($r['batch_code'] ?? '—') ?></span></td>
                    <td>
                        <?php if ($st === 'unused'): ?>
                            <a href="tpin.php<?= tpin_admin_filter_qs(['block' => (string) (int) $r['id']]) ?>" class="btn btn-outline btn-sm tpin-block-btn" data-confirm="Block this unused T-Pin?">Block</a>
                        <?php else: ?>
                            <span class="tpin-dash">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<style>
.tpin-readonly {
    background: #f1f5f9 !important;
    color: #0f172a !important;
    cursor: default !important;
}
.tpin-readonly:focus {
    box-shadow: none !important;
    border-color: #e2e8f0 !important;
}
</style>

<script>
(function () {
    const pkg = document.getElementById('package_id');
    const qty = document.getElementById('qty');
    const amtEl = document.getElementById('package_amount');
    const totalEl = document.getElementById('total_amount');
    const assignEl = document.getElementById('assign_member');
    const nameEl = document.getElementById('member_name');
    let lookupTimer = null;

    function fmt(n) {
        if (!isFinite(n) || n <= 0) return '';
        return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function syncAmounts() {
        const opt = pkg && pkg.options[pkg.selectedIndex];
        const price = opt ? parseFloat(opt.getAttribute('data-amount') || '0') : 0;
        const q = Math.max(0, parseInt(qty && qty.value ? qty.value : '0', 10) || 0);
        if (amtEl) amtEl.value = price > 0 ? fmt(price) : '';
        if (totalEl) totalEl.value = price > 0 && q > 0 ? fmt(price * q) : '';
    }

    function lookupMember() {
        const code = (assignEl && assignEl.value ? assignEl.value : '').trim();
        if (!code) {
            if (nameEl) nameEl.value = '';
            return;
        }
        fetch('tpin.php?ajax=member&member_id=' + encodeURIComponent(code), {
            headers: { 'Accept': 'application/json' }
        })
            .then((r) => r.json())
            .then((data) => {
                if (nameEl) nameEl.value = (data && data.ok) ? (data.name || '') : '';
            })
            .catch(() => {
                if (nameEl) nameEl.value = '';
            });
    }

    if (pkg) pkg.addEventListener('change', syncAmounts);
    if (qty) {
        qty.addEventListener('input', syncAmounts);
        qty.addEventListener('change', syncAmounts);
    }
    if (assignEl) {
        assignEl.addEventListener('input', () => {
            clearTimeout(lookupTimer);
            lookupTimer = setTimeout(lookupMember, 280);
        });
        assignEl.addEventListener('blur', lookupMember);
    }
    syncAmounts();

    function copyText(text, el) {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            if (!el) return;
            el.classList.add('is-copied');
            if (el.id === 'tpinCopyAll') {
                el.textContent = 'Copied';
                setTimeout(() => {
                    el.classList.remove('is-copied');
                    el.textContent = 'Copy all';
                }, 1100);
            } else {
                setTimeout(() => el.classList.remove('is-copied'), 900);
            }
        }).catch(() => {});
    }
    document.querySelectorAll('[data-copy]').forEach((el) => {
        el.addEventListener('click', () => copyText(el.getAttribute('data-copy') || '', el));
    });
    const allBtn = document.getElementById('tpinCopyAll');
    if (allBtn) {
        allBtn.addEventListener('click', () => copyText(allBtn.getAttribute('data-pins') || '', allBtn));
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
