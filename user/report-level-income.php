<?php
/**
 * Level Wise Income Report — sponsor L1–L5 members + each member's binary income.
 */
$pageTitle = 'Level Wise Income Report';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/income.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$maxLevel = 5;

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$memberQ = strtoupper(trim((string) ($_GET['member_id'] ?? '')));
$levelFilter = (int) ($_GET['level'] ?? 0);
if ($levelFilter < 0 || $levelFilter > $maxLevel) {
    $levelFilter = 0;
}

$downline = team_collect_sponsor_downline($pdo, $uid, $maxLevel);
$groupedAll = team_group_by_level($downline);
$levelCounts = [];
for ($lv = 1; $lv <= $maxLevel; $lv++) {
    $levelCounts[$lv] = count($groupedAll[$lv] ?? []);
}
$totalTeam = count($downline);

$ids = [];
foreach ($downline as $m) {
    $ids[] = (int) $m['id'];
}
$ids = array_values(array_unique(array_filter($ids)));

$binaryMap = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $binStmt = $pdo->prepare("
        SELECT member_id, COALESCE(SUM(amount), 0) AS binary_income
        FROM commissions
        WHERE type = 'binary'
          AND status = 'paid'
          AND DATE(created_at) BETWEEN ? AND ?
          AND member_id IN ($placeholders)
        GROUP BY member_id
    ");
    $binStmt->execute(array_merge([$from, $to], $ids));
    foreach ($binStmt->fetchAll() as $row) {
        $binaryMap[(int) $row['member_id']] = (float) $row['binary_income'];
    }
}

$rows = [];
foreach ($downline as $m) {
    $lv = (int) ($m['level'] ?? 0);
    if ($levelFilter > 0 && $lv !== $levelFilter) {
        continue;
    }
    if ($memberQ !== '') {
        $mid = strtoupper((string) ($m['member_id'] ?? ''));
        $uname = strtoupper((string) ($m['username'] ?? ''));
        if ($mid !== $memberQ && !str_contains($mid, $memberQ) && !str_contains($uname, $memberQ)) {
            continue;
        }
    }
    $midPk = (int) $m['id'];
    $m['binary_income'] = $binaryMap[$midPk] ?? 0.0;
    $rows[] = $m;
}

usort($rows, static function (array $a, array $b): int {
    $la = (int) ($a['level'] ?? 0);
    $lb = (int) ($b['level'] ?? 0);
    if ($la !== $lb) {
        return $la <=> $lb;
    }
    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
});

$totalFiltered = count($rows);
$periodBinaryTotal = 0.0;
foreach ($rows as $r) {
    $periodBinaryTotal += (float) ($r['binary_income'] ?? 0);
}
$showLimit = 400;
$shown = array_slice($rows, 0, $showLimit);
?>
<div class="up-page-head">
    <div>
        <h1>Level Wise Income Report</h1>
        <p>Your sponsor team (Level 1–5) with each member’s paid binary income for the selected dates.</p>
    </div>
    <a href="level-team.php" class="up-btn up-btn-outline">Level Team</a>
</div>

<div class="inc-stats" style="grid-template-columns:repeat(5,minmax(0,1fr))">
    <?php for ($lv = 1; $lv <= $maxLevel; $lv++): ?>
    <article class="inc-stat <?= ['g-blue','g-green','g-orange','g-purple','g-blue'][$lv - 1] ?>">
        <div>
            <span class="inc-stat-label">Level <?= $lv ?></span>
            <strong><?= (int) ($levelCounts[$lv] ?? 0) ?></strong>
            <small>member<?= ($levelCounts[$lv] ?? 0) === 1 ? '' : 's' ?></small>
        </div>
    </article>
    <?php endfor; ?>
</div>

<div class="inc-stats">
    <article class="inc-stat g-purple">
        <div>
            <span class="inc-stat-label">Total (L1–L5)</span>
            <strong><?= (int) $totalTeam ?></strong>
            <small>in your 5 levels</small>
        </div>
    </article>
    <article class="inc-stat g-green">
        <div>
            <span class="inc-stat-label">Period Binary Total</span>
            <strong class="is-sm"><?= currency($periodBinaryTotal) ?></strong>
            <small><?= (int) $totalFiltered ?> member<?= $totalFiltered === 1 ? '' : 's' ?> in view</small>
        </div>
    </article>
    <article class="inc-stat g-blue">
        <div>
            <span class="inc-stat-label">Date Range</span>
            <strong class="is-sm" style="font-size:1rem"><?= e(date('d M Y', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?></strong>
            <small>binary income filter</small>
        </div>
    </article>
    <article class="inc-stat g-orange">
        <div>
            <span class="inc-stat-label">Showing</span>
            <strong><?= count($shown) ?></strong>
            <small><?= $totalFiltered > count($shown) ? 'of ' . $totalFiltered . ' rows' : 'rows' ?></small>
        </div>
    </article>
</div>

<section class="inc-card">
    <div class="inc-banner is-purple">
        <div class="inc-banner-main">
            <span class="inc-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
            </span>
            <div>
                <span class="inc-kicker">Reports</span>
                <h2>Level Wise Income</h2>
                <p>Filter by date, member ID, or level. Binary amount = paid commissions in the date range.</p>
            </div>
        </div>
    </div>

    <form method="get" class="inc-filter-bar">
        <div class="inc-filter-field">
            <label for="lwirFrom">From</label>
            <input type="date" id="lwirFrom" name="from" value="<?= e($from) ?>" required>
        </div>
        <div class="inc-filter-field">
            <label for="lwirTo">To</label>
            <input type="date" id="lwirTo" name="to" value="<?= e($to) ?>" required>
        </div>
        <div class="inc-filter-field">
            <label for="lwirMember">Member ID</label>
            <input type="text" id="lwirMember" name="member_id" value="<?= e($memberQ) ?>" placeholder="e.g. BS000012" autocomplete="off" maxlength="20" spellcheck="false" style="text-transform:uppercase">
        </div>
        <div class="inc-filter-field">
            <label for="lwirLevel">Level</label>
            <select id="lwirLevel" name="level">
                <option value="0" <?= $levelFilter === 0 ? 'selected' : '' ?>>All levels (1–5)</option>
                <?php for ($lv = 1; $lv <= $maxLevel; $lv++): ?>
                <option value="<?= $lv ?>" <?= $levelFilter === $lv ? 'selected' : '' ?>>
                    Level <?= $lv ?> (<?= (int) ($levelCounts[$lv] ?? 0) ?>)
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="inc-filter-actions">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <a href="report-level-income.php" class="up-btn up-btn-outline">Reset</a>
        </div>
    </form>

    <div class="inc-table-wrap">
        <table class="inc-table">
            <thead>
            <tr>
                <th>Level</th>
                <th>Member ID</th>
                <th>Name</th>
                <th>Username</th>
                <th>Sponsor</th>
                <th>Status</th>
                <th>Join Date</th>
                <th>Binary Income</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$shown): ?>
                <tr><td colspan="8" class="inc-empty">No members found for these filters.</td></tr>
            <?php else: foreach ($shown as $r):
                $eff = member_effective_status($r);
                $isActive = $eff === 'active';
                ?>
                <tr>
                    <td><strong>L<?= (int) ($r['level'] ?? 0) ?></strong></td>
                    <td><?= e((string) ($r['member_id'] ?? '')) ?></td>
                    <td><?= e((string) ($r['full_name'] ?? '')) ?></td>
                    <td>@<?= e((string) ($r['username'] ?? '')) ?></td>
                    <td><?= !empty($r['sponsor_mid']) ? e(($r['sponsor_name'] ?? '') . ' · ' . $r['sponsor_mid']) : '—' ?></td>
                    <td>
                        <span class="inc-pill <?= $isActive ? 'is-ok' : 'is-wait' ?>"><?= e(ucfirst($eff)) ?></span>
                    </td>
                    <td><?= !empty($r['join_date']) ? e(date('d M Y', strtotime((string) $r['join_date']))) : '—' ?></td>
                    <td><strong><?= currency((float) ($r['binary_income'] ?? 0)) ?></strong></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalFiltered > count($shown)): ?>
        <p style="margin:0.85rem 1rem 1rem;color:var(--up-muted,#64748b);font-size:0.85rem;font-weight:600">
            Showing first <?= count($shown) ?> of <?= (int) $totalFiltered ?> members. Narrow with Member ID or Level filter.
        </p>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
