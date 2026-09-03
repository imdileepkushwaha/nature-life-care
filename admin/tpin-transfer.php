<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tpin.php';

$pageTitle = 'Transfer T-Pin';
tpin_ensure_tables($pdo);

$adminId = (int) ($_SESSION['admin_id'] ?? 0);

// AJAX helpers
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = (string) $_GET['ajax'];

    if ($ajax === 'member') {
        $code = trim((string) ($_GET['member_id'] ?? ''));
        $member = $code !== '' ? tpin_find_member_by_code($pdo, $code) : null;
        if (!$member || ($member['status'] ?? '') === 'blocked') {
            echo json_encode(['ok' => false, 'name' => '', 'id' => 0]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'id' => (int) $member['id'],
            'name' => (string) $member['full_name'],
            'member_id' => (string) $member['member_id'],
        ]);
        exit;
    }

    if ($ajax === 'available') {
        $code = trim((string) ($_GET['member_id'] ?? ''));
        $member = $code !== '' ? tpin_find_member_by_code($pdo, $code) : null;
        if (!$member || ($member['status'] ?? '') === 'blocked') {
            echo json_encode(['ok' => false, 'packages' => []]);
            exit;
        }
        $packages = tpin_member_package_availability($pdo, (int) $member['id']);
        echo json_encode(['ok' => true, 'packages' => $packages]);
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

$errors = [];
$postFrom = trim((string) ($_POST['from_user_id'] ?? ''));
$postTo = trim((string) ($_POST['to_user_id'] ?? ''));
$postPkg = (int) ($_POST['package_id'] ?? 0);
$postQty = (int) ($_POST['qty'] ?? 0);
$postFromName = '';
$postToName = '';
$availPackages = [];

if ($postFrom !== '') {
    $fm = tpin_find_member_by_code($pdo, $postFrom);
    if ($fm && ($fm['status'] ?? '') !== 'blocked') {
        $postFromName = (string) $fm['full_name'];
        $availPackages = tpin_member_package_availability($pdo, (int) $fm['id']);
    }
}
if ($postTo !== '') {
    $tm = tpin_find_member_by_code($pdo, $postTo);
    if ($tm && ($tm['status'] ?? '') !== 'blocked') {
        $postToName = (string) $tm['full_name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from = tpin_find_member_by_code($pdo, $postFrom);
    $to = tpin_find_member_by_code($pdo, $postTo);

    if (!$from || ($from['status'] ?? '') === 'blocked') {
        $errors[] = 'From Member ID not found or blocked.';
    } elseif (!$to || ($to['status'] ?? '') === 'blocked') {
        $errors[] = 'Transfer User ID not found or blocked.';
    } elseif ($postPkg <= 0) {
        $errors[] = 'Select a pin amount / package.';
    } elseif ($postQty < 1) {
        $errors[] = 'Enter number of T-Pins to transfer.';
    } else {
        $res = tpin_admin_bulk_transfer(
            $pdo,
            (int) $from['id'],
            (int) $to['id'],
            $postPkg,
            $postQty,
            $adminId ?: null
        );
        if ($res['ok']) {
            flash(
                'success',
                $res['transferred'] . ' T-Pin(s) transferred from ' . $from['member_id'] . ' to ' . $to['member_id'] . '.'
            );
            log_activity(
                'tpin_transfer',
                $res['transferred'] . ' pins pkg#' . $postPkg . ' ' . $from['member_id'] . ' → ' . $to['member_id']
            );
            header('Location: tpin-transfer.php');
            exit;
        }
        $errors[] = $res['error'] ?? 'Transfer failed.';
        $availPackages = tpin_member_package_availability($pdo, (int) $from['id']);
    }
}

$selectedAvail = 0;
foreach ($availPackages as $ap) {
    if ((int) $ap['package_id'] === $postPkg) {
        $selectedAvail = (int) $ap['available'];
        break;
    }
}

$counts = ['unused' => 0];
try {
    $counts['unused'] = (int) $pdo->query("SELECT COUNT(*) FROM topup_pins WHERE status = 'unused'")->fetchColumn();
} catch (Throwable $e) {
    $counts['unused'] = 0;
}
$memberStock = 0;
try {
    $memberStock = (int) $pdo->query("SELECT COUNT(*) FROM topup_pins WHERE status = 'unused' AND assigned_to IS NOT NULL")->fetchColumn();
} catch (Throwable $e) {
    $memberStock = 0;
}
$transferTotal = 0;
try {
    $transferTotal = (int) $pdo->query('SELECT COUNT(*) FROM topup_pin_transfers')->fetchColumn();
} catch (Throwable $e) {
    $transferTotal = 0;
}

$recent = tpin_admin_transfers($pdo, [], 12);

require_once __DIR__ . '/../includes/header.php';
$flash = get_flash();
?>

<div class="stats-grid tpin-stats">
    <div class="stat-card accent">
        <div class="label">With members</div>
        <div class="value"><?= (int) $memberStock ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Unused total</div>
        <div class="value"><?= (int) $counts['unused'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Transfers</div>
        <div class="value"><?= (int) $transferTotal ?></div>
    </div>
</div>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Transfer T-Pin</h2>
            <p class="tpin-panel-sub">Move unused pins from one member wallet to another</p>
        </div>
        <a href="tpin-report.php" class="btn btn-outline btn-sm">View report</a>
    </div>
    <div class="panel-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <div class="tpin-transfer-steps">
            <div class="tpin-step"><span>1</span> From member</div>
            <div class="tpin-step"><span>2</span> Pin selection</div>
            <div class="tpin-step"><span>3</span> Transfer to</div>
        </div>

        <form method="post" class="tpin-gen-form" id="tpinXferForm" onsubmit="return confirm('Transfer the selected T-Pins to the receiver?');">
            <p class="tpin-panel-sub" style="margin:0">From member</p>
            <div class="tpin-gen-grid">
                <div class="form-group">
                    <label for="from_user_id">User ID *</label>
                    <input type="text" name="from_user_id" id="from_user_id" value="<?= e($postFrom) ?>" placeholder="Sender Member ID" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="from_user_name">User name</label>
                    <input type="text" id="from_user_name" value="<?= e($postFromName) ?>" readonly tabindex="-1" placeholder="Auto-filled from user ID" class="tpin-readonly">
                </div>
            </div>

            <p class="tpin-panel-sub" style="margin:0">Pin selection</p>
            <div class="tpin-gen-grid">
                <div class="form-group">
                    <label for="package_id">Select amount *</label>
                    <select name="package_id" id="package_id" required>
                        <option value="">Select amount</option>
                        <?php foreach ($availPackages as $ap): ?>
                            <option
                                value="<?= (int) $ap['package_id'] ?>"
                                data-available="<?= (int) $ap['available'] ?>"
                                data-amount="<?= e(number_format((float) $ap['amount'], 2, '.', '')) ?>"
                                <?= $postPkg === (int) $ap['package_id'] ? 'selected' : '' ?>
                            >
                                <?= e(number_format((float) $ap['amount'], 2)) ?> · <?= e($ap['name']) ?> (<?= (int) $ap['available'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="tpin-hint">Amounts load after sender Member ID is matched.</span>
                </div>
                <div class="form-group">
                    <label for="available_epin">Available T-Pin</label>
                    <input type="text" id="available_epin" value="<?= (string) (int) $selectedAvail ?>" readonly tabindex="-1" class="tpin-readonly">
                </div>
                <div class="form-group">
                    <label for="qty">No of T-Pin *</label>
                    <input type="number" name="qty" id="qty" min="1" max="500" value="<?= $postQty > 0 ? (int) $postQty : '' ?>" placeholder="Enter pins to transfer" required>
                </div>
            </div>

            <p class="tpin-panel-sub" style="margin:0">Transfer to</p>
            <div class="tpin-gen-grid">
                <div class="form-group">
                    <label for="to_user_id">Transfer user ID *</label>
                    <input type="text" name="to_user_id" id="to_user_id" value="<?= e($postTo) ?>" placeholder="Receiver Member ID" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="to_user_name">Transfer user name</label>
                    <input type="text" id="to_user_name" value="<?= e($postToName) ?>" readonly tabindex="-1" placeholder="Auto-filled from user ID" class="tpin-readonly">
                </div>
            </div>

            <div class="tpin-gen-actions">
                <button type="submit" class="btn btn-primary">Transfer T-Pin</button>
                <a href="tpin-transfer.php" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Recent transfers</h2>
            <p class="tpin-panel-sub">Latest member-to-member moves</p>
        </div>
        <a href="tpin-report.php" class="btn btn-outline btn-sm">Full report</a>
    </div>
    <?php if (!$recent): ?>
        <div class="act-empty">
            <strong>No transfers yet</strong>
            <p>When pins move between members, they will show here.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>T-Pin</th>
                <th>Package</th>
                <th>From</th>
                <th>To</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $t): ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $t['created_at']))) ?></td>
                    <td><code class="tpin-batch-code"><?= e(tpin_format_code((string) $t['pin_code'])) ?></code></td>
                    <td>
                        <strong class="tpin-pkg-name"><?= e($t['package_name']) ?></strong>
                        <span class="tpin-pkg-amt"><?= currency((float) $t['package_amount']) ?></span>
                    </td>
                    <td>
                        <strong><?= e($t['from_name']) ?></strong>
                        <span class="tpin-meta"><?= e($t['from_code']) ?></span>
                    </td>
                    <td>
                        <strong><?= e($t['to_name']) ?></strong>
                        <span class="tpin-meta"><?= e($t['to_code']) ?></span>
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
.tpin-gen-actions { gap: .65rem; align-items: center; }
.tpin-transfer-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .65rem;
    margin-bottom: 1.25rem;
}
.tpin-step {
    display: flex;
    align-items: center;
    gap: .55rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: .65rem .75rem;
    font-size: .82rem;
    font-weight: 600;
    color: #334155;
}
.tpin-step span {
    width: 22px;
    height: 22px;
    border-radius: 999px;
    display: inline-grid;
    place-items: center;
    background: #0f172a;
    color: #fff;
    font-size: .75rem;
    flex-shrink: 0;
}
@media (max-width: 700px) {
    .tpin-transfer-steps { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    const fromId = document.getElementById('from_user_id');
    const fromName = document.getElementById('from_user_name');
    const toId = document.getElementById('to_user_id');
    const toName = document.getElementById('to_user_name');
    const pkg = document.getElementById('package_id');
    const availEl = document.getElementById('available_epin');
    const qtyEl = document.getElementById('qty');
    let fromTimer = null;
    let toTimer = null;

    function fmtAmt(n) {
        return Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function setAvail(n) {
        if (availEl) availEl.value = String(Math.max(0, parseInt(n || '0', 10) || 0));
        if (qtyEl) {
            const max = Math.max(1, parseInt(n || '0', 10) || 1);
            qtyEl.max = String(max);
        }
    }

    function syncAvailFromSelect() {
        const opt = pkg && pkg.options[pkg.selectedIndex];
        setAvail(opt ? (opt.getAttribute('data-available') || '0') : '0');
    }

    function fillPackages(list, keepSelected) {
        if (!pkg) return;
        const selected = keepSelected ? String(pkg.value || '') : '';
        pkg.innerHTML = '<option value="">Select amount</option>';
        (list || []).forEach((p) => {
            const opt = document.createElement('option');
            opt.value = String(p.package_id);
            opt.setAttribute('data-available', String(p.available || 0));
            opt.setAttribute('data-amount', String(p.amount || 0));
            opt.textContent = fmtAmt(p.amount) + ' · ' + (p.name || 'Package') + ' (' + (p.available || 0) + ')';
            if (selected && selected === String(p.package_id)) opt.selected = true;
            pkg.appendChild(opt);
        });
        syncAvailFromSelect();
    }

    function lookupMember(code, nameInput, onOk) {
        code = (code || '').trim();
        if (!code) {
            if (nameInput) nameInput.value = '';
            if (onOk) onOk(null);
            return;
        }
        fetch('tpin-transfer.php?ajax=member&member_id=' + encodeURIComponent(code), {
            headers: { 'Accept': 'application/json' }
        })
            .then((r) => r.json())
            .then((data) => {
                if (nameInput) nameInput.value = (data && data.ok) ? (data.name || '') : '';
                if (onOk) onOk(data && data.ok ? data : null);
            })
            .catch(() => {
                if (nameInput) nameInput.value = '';
                if (onOk) onOk(null);
            });
    }

    function loadAvailable(code) {
        code = (code || '').trim();
        if (!code) {
            fillPackages([]);
            return;
        }
        fetch('tpin-transfer.php?ajax=available&member_id=' + encodeURIComponent(code), {
            headers: { 'Accept': 'application/json' }
        })
            .then((r) => r.json())
            .then((data) => {
                fillPackages((data && data.ok) ? (data.packages || []) : [], true);
            })
            .catch(() => fillPackages([]));
    }

    if (fromId) {
        fromId.addEventListener('input', () => {
            clearTimeout(fromTimer);
            fromTimer = setTimeout(() => {
                lookupMember(fromId.value, fromName, (ok) => {
                    if (ok) loadAvailable(fromId.value);
                    else fillPackages([]);
                });
            }, 280);
        });
        fromId.addEventListener('blur', () => {
            lookupMember(fromId.value, fromName, (ok) => {
                if (ok) loadAvailable(fromId.value);
                else fillPackages([]);
            });
        });
    }
    if (toId) {
        toId.addEventListener('input', () => {
            clearTimeout(toTimer);
            toTimer = setTimeout(() => lookupMember(toId.value, toName), 280);
        });
        toId.addEventListener('blur', () => lookupMember(toId.value, toName));
    }
    if (pkg) pkg.addEventListener('change', syncAvailFromSelect);
    syncAvailFromSelect();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
