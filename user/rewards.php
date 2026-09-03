<?php
$pageTitle = 'My Rewards';
require_once __DIR__ . '/../includes/plan_incentives.php';
require_once __DIR__ . '/includes/header.php';

plan_incentives_ensure($pdo);
plan_member_evaluate($pdo, (int) $user['id']);
$fresh = $pdo->prepare('SELECT rank_key, lifetime_pairs FROM members WHERE id = ? LIMIT 1');
$fresh->execute([(int) $user['id']]);
$rkRow = $fresh->fetch() ?: [];
$user['rank_key'] = $rkRow['rank_key'] ?? ($user['rank_key'] ?? '');
$user['lifetime_pairs'] = $rkRow['lifetime_pairs'] ?? ($user['lifetime_pairs'] ?? 0);
$uid = (int) $user['id'];
$pairs = (float) ($user['lifetime_pairs'] ?? 0);
$catalog = plan_rewards_list($pdo);
$pairsLabel = rtrim(rtrim(number_format($pairs, 2, '.', ''), '0'), '.');
$memberName = (string) ($user['full_name'] ?? '');
$memberCode = (string) ($user['member_id'] ?? '');

$rwIcon = static function (string $key): string {
    $svg = match (true) {
        str_contains($key, 'bag') || str_contains($key, 'travel') => '<path d="M6 8h12l1 12H5L6 8z"/><path d="M9 8V7a3 3 0 016 0v1"/>',
        str_contains($key, 'cooker') => '<rect x="5" y="9" width="14" height="10" rx="2"/><path d="M8 9V7M16 9V7M9 14h6"/>',
        str_contains($key, 'tablet') || str_contains($key, 'phone') => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
        str_contains($key, 'ebike') || str_contains($key, 'bike') => '<circle cx="6.5" cy="16.5" r="3"/><circle cx="17.5" cy="16.5" r="3"/><path d="M6.5 16.5L12 8h4l1.5 8.5M12 8l-3 8.5"/>',
        str_contains($key, 'car') => '<path d="M4 16v2h2v-2h12v2h2v-2l-2-6H6l-2 6z"/><path d="M7 10l1.5-3h7L17 10"/><circle cx="8" cy="16" r="1.2"/><circle cx="16" cy="16" r="1.2"/>',
        str_contains($key, 'flat') || str_contains($key, 'bungalow') => '<path d="M4 20V10l8-6 8 6v10"/><path d="M10 20v-6h4v6"/>',
        str_contains($key, 'silver') || str_contains($key, 'gold') || str_contains($key, '_cr') || str_contains($key, '2l') => '<path d="M12 3l2.5 6.5L21 11l-5 4.2L17.5 21 12 17.5 6.5 21 8 15.2 3 11l6.5-1.5L12 3z"/>',
        default => '<path d="M20 12v8a2 2 0 01-2 2H6a2 2 0 01-2-2v-8"/><path d="M12 22V12"/><path d="M2 7h20v5H2z"/><path d="M12 7V5a3 3 0 016 0v2M12 7V5a3 3 0 00-6 0v2"/>',
    };
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">' . $svg . '</svg>';
};

$mine = [];
try {
    $st = $pdo->prepare('SELECT reward_key, status, pairs_at, created_at, fulfilled_at, admin_note FROM member_rewards WHERE member_id = ?');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $row) {
        $mine[(string) $row['reward_key']] = $row;
    }
} catch (Throwable $e) {
    $mine = [];
}

$items = [];
$eligible = 0;
$done = 0;
$nextGift = null;
$prevReq = 0.0;
$nextPrevReq = 0.0;
foreach ($catalog as $g) {
    $row = $mine[(string) $g['reward_key']] ?? null;
    $status = $row['status'] ?? ($pairs + 0.00001 >= (float) $g['pairs_required'] ? 'eligible' : 'locked');
    if (!$row && $status === 'eligible') {
        $status = 'locked';
    }
    $label = match ($status) {
        'eligible' => 'Eligible',
        'fulfilled' => 'Fulfilled',
        'cancelled' => 'Cancelled',
        default => 'Locked',
    };
    if ($status === 'eligible') {
        $eligible++;
    }
    if ($status === 'fulfilled') {
        $done++;
    }
    $item = [
        'gift' => $g,
        'row' => $row,
        'status' => $status,
        'label' => $label,
        'rank' => plan_rank_title($pdo, (string) $g['rank_key']),
    ];
    $items[] = $item;
    if ($status === 'locked' && $nextGift === null) {
        $nextGift = $item;
        $nextPrevReq = $prevReq;
    }
    $prevReq = (float) $g['pairs_required'];
}

$nextReq = $nextGift ? (float) $nextGift['gift']['pairs_required'] : 0.0;
$into = $nextGift ? max(0.0, $pairs - $nextPrevReq) : 0.0;
$span = $nextGift ? max(0.01, $nextReq - $nextPrevReq) : 1.0;
$pct = $nextGift ? (int) round(min(100, $into / $span * 100)) : 100;
$need = $nextGift ? max(0, $nextReq - $pairs) : 0;
$total = count($items);
$lockedLeft = max(0, $total - $eligible - $done);
?>
<div class="up-page-head">
    <div>
        <h1>My Rewards</h1>
        <p>Pair milestones from the written plan. Gifts stay subject to eligibility, tax and verification.</p>
    </div>
    <?php if (feature_module_allowed('ranks')): ?>
    <a href="rank.php" class="up-btn up-btn-outline">My Rank</a>
    <?php endif; ?>
</div>

<section class="rw-hero">
    <span class="rw-hero-glow" aria-hidden="true"></span>
    <div class="rw-hero-copy">
        <p class="rw-hero-kicker">Pair milestone rewards</p>
        <h2><?= $nextGift ? 'Next gift: ' . e((string) $nextGift['gift']['gift_label']) : 'Roadmap unlocked' ?></h2>
        <p class="rw-hero-who"><?= e($memberName) ?> · <?= e($memberCode) ?></p>
        <div class="rw-hero-pills">
            <span><b><?= e($pairsLabel) ?></b> pairs</span>
            <span><b><?= (int) $eligible ?></b> awaiting</span>
            <span><b><?= (int) $done ?></b> fulfilled</span>
        </div>
    </div>
    <div class="rw-hero-ring" style="--p: <?= max(0, min(100, $pct)) ?>">
        <div>
            <strong><?= $pct ?>%</strong>
            <small><?= $nextGift ? number_format($need, 0) . ' to go' : 'Done' ?></small>
        </div>
    </div>
</section>

<?php if ($nextGift):
    $ng = $nextGift['gift'];
    $ngCash = (float) ($ng['cash_value'] ?? 0);
?>
<article class="rw-spotlight">
    <span class="rw-spot-ico" aria-hidden="true"><?= $rwIcon((string) $ng['reward_key']) ?></span>
    <div class="rw-spot-copy">
        <span class="rw-spot-kicker">Up next</span>
        <h3><?= e((string) $ng['gift_label']) ?></h3>
        <p><?= e((string) $ng['title']) ?> · <?= e($nextGift['rank']) ?><?= $ngCash > 0 ? ' · Cash option ' . strip_tags(currency($ngCash)) : '' ?></p>
        <div class="rw-spot-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
            <i style="width: <?= max(0, min(100, $pct)) ?>%"></i>
        </div>
        <small><?= e($pairsLabel) ?> / <?= number_format($nextReq, 0) ?> pairs</small>
    </div>
</article>
<?php endif; ?>

<section class="rw-board">
    <div class="rw-board-head">
        <div>
            <span>All rewards</span>
            <h3>Gifts &amp; cash benefits</h3>
        </div>
        <em><?= (int) $lockedLeft ?> locked · <?= (int) $total ?> total</em>
    </div>
    <ol class="rw-list">
        <?php foreach ($items as $idx => $it):
            $g = $it['gift'];
            $st = $it['status'];
            $req = (float) $g['pairs_required'];
            $cash = (float) ($g['cash_value'] ?? 0);
            $isNext = $nextGift && (string) $g['reward_key'] === (string) $nextGift['gift']['reward_key'];
            $cls = 'rw-row rw-st-' . $st;
            if ($isNext) {
                $cls .= ' rw-st-next';
            }
            $barPct = $req > 0 ? (int) round(min(100, max(0, $pairs / $req * 100))) : 0;
        ?>
            <li class="<?= e($cls) ?>">
                <span class="rw-row-ico" aria-hidden="true"><?= $rwIcon((string) $g['reward_key']) ?></span>
                <div class="rw-row-main">
                    <div class="rw-row-top">
                        <strong><?= e((string) $g['gift_label']) ?></strong>
                        <em><?= e($it['label']) ?></em>
                    </div>
                    <p><?= e((string) $g['title']) ?> · <?= e($it['rank']) ?><?= $cash > 0 ? ' · ' . strip_tags(currency($cash)) . ' cash option' : '' ?></p>
                    <?php if ($st === 'locked' || $isNext): ?>
                        <div class="rw-row-bar" aria-hidden="true"><i style="width: <?= $barPct ?>%"></i></div>
                    <?php endif; ?>
                </div>
                <div class="rw-row-pairs">
                    <b><?= number_format((int) $g['pairs_required']) ?></b>
                    <span>pairs</span>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <p class="rw-foot">All rewards remain subject to tax, stock, verification and the written company terms. This screen tracks eligibility — it is not a guarantee of dispatch.</p>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
