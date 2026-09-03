<?php
$pageTitle = 'My Rank';
require_once __DIR__ . '/../includes/plan_incentives.php';
require_once __DIR__ . '/includes/header.php';

plan_incentives_ensure($pdo);
plan_member_evaluate($pdo, (int) $user['id']);
$fresh = $pdo->prepare('SELECT rank_key, lifetime_pairs FROM members WHERE id = ? LIMIT 1');
$fresh->execute([(int) $user['id']]);
$rkRow = $fresh->fetch() ?: [];
$user['rank_key'] = $rkRow['rank_key'] ?? ($user['rank_key'] ?? '');
$user['lifetime_pairs'] = $rkRow['lifetime_pairs'] ?? ($user['lifetime_pairs'] ?? 0);
$progress = plan_member_rank_progress($pdo, $user);
$ranks = plan_ranks_list($pdo);
$currentKey = (string) ($progress['rank']['rank_key'] ?? '');
$pairs = (float) $progress['pairs'];
$next = $progress['next'];
$need = $next ? max(0, (float) $next['pairs_required'] - $pairs) : 0;
$pct = (int) round((float) $progress['progress']);
$currentTitle = (string) ($progress['rank']['title'] ?? 'Associate');
$pairsLabel = rtrim(rtrim(number_format($pairs, 2, '.', ''), '0'), '.');
$prevReq = $progress['rank'] ? (float) $progress['rank']['pairs_required'] : 0.0;
$nextReq = $next ? (float) $next['pairs_required'] : 0.0;
$into = $next ? max(0.0, $pairs - $prevReq) : 0.0;
$memberName = (string) ($user['full_name'] ?? '');
$memberCode = (string) ($user['member_id'] ?? '');
$initial = strtoupper(substr(preg_replace('/\s+/', '', $memberName) ?: 'M', 0, 1));

$rkTone = static function (string $key): string {
    return match ($key) {
        'executive', 'star', 'super_star' => 'teal',
        'bronze' => 'bronze',
        'silver' => 'silver',
        'gold' => 'gold',
        'platinum' => 'steel',
        'emerald', 'ruby' => 'gem',
        'diamond_1', 'diamond_2', 'diamond_3', 'director' => 'diamond',
        default => 'slate',
    };
};
?>
<div class="up-page-head">
    <div>
        <h1>My Rank</h1>
        <p>Titles unlock after binary closing when lifetime pairs hit each plan threshold.</p>
    </div>
    <?php if (feature_module_allowed('rewards')): ?>
    <a href="rewards.php" class="up-btn up-btn-outline">My Rewards</a>
    <?php endif; ?>
</div>

<section class="rk-hero">
    <span class="rk-hero-glow" aria-hidden="true"></span>
    <div class="rk-hero-main">
        <div class="rk-hero-id">
            <span class="rk-hero-avatar" aria-hidden="true"><?= e($initial) ?></span>
            <div>
                <p class="rk-hero-kicker">Current title</p>
                <h2><?= e($currentTitle) ?></h2>
                <p class="rk-hero-who"><?= e($memberName) ?> · <?= e($memberCode) ?></p>
            </div>
        </div>
        <div class="rk-hero-meta">
            <div>
                <small>Lifetime pairs</small>
                <strong><?= e($pairsLabel) ?></strong>
            </div>
            <div>
                <small><?= $next ? 'Next title' : 'Status' ?></small>
                <strong><?= e($next['title'] ?? 'Highest rank') ?></strong>
            </div>
            <div>
                <small><?= $next ? 'Pairs to go' : 'Ladder' ?></small>
                <strong><?= $next ? number_format($need, 0) : 'Complete' ?></strong>
            </div>
        </div>
    </div>
    <div class="rk-ring" style="--p: <?= max(0, min(100, $pct)) ?>" aria-hidden="true">
        <div class="rk-ring-inner">
            <strong><?= $pct ?>%</strong>
            <span><?= $next ? 'to ' . e($next['title']) : 'max' ?></span>
        </div>
    </div>
</section>

<?php if ($next): ?>
<div class="rk-nextbar">
    <div class="rk-nextbar-copy">
        <span>Progress to <?= e($next['title']) ?></span>
        <strong><?= e(rtrim(rtrim(number_format($into, 2, '.', ''), '0'), '.')) ?> / <?= number_format($nextReq, 0) ?> pairs in this step</strong>
    </div>
    <div class="rk-progress rk-progress-lg" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
        <span style="width: <?= max(0, min(100, (float) $progress['progress'])) ?>%"></span>
    </div>
</div>
<?php endif; ?>

<section class="rk-path-card">
    <div class="rk-path-head">
        <div>
            <span class="rk-path-kicker">Rank ladder</span>
            <h3>Executive to Director</h3>
            <p>Achieved titles stay unlocked. New titles apply when closing credits pairs.</p>
        </div>
        <span class="rk-path-count"><?= count($ranks) ?> titles</span>
    </div>
    <ol class="rk-path">
        <?php
        $foundNext = false;
        foreach ($ranks as $idx => $r):
            $req = (float) $r['pairs_required'];
            $hit = $pairs + 0.00001 >= $req;
            $isNow = (string) $r['rank_key'] === $currentKey;
            $isNext = !$hit && !$foundNext;
            if ($isNext) {
                $foundNext = true;
            }
            $tone = $rkTone((string) $r['rank_key']);
            $bonus = (float) ($r['bonus_amount'] ?? 0);
            $cls = 'rk-node rk-tone-' . $tone;
            if ($hit) {
                $cls .= ' is-done';
            }
            if ($isNow) {
                $cls .= ' is-now';
            }
            if ($isNext) {
                $cls .= ' is-next';
            }
            if (!$hit && !$isNext) {
                $cls .= ' is-locked';
            }
            $state = $isNow ? 'Current' : ($hit ? 'Achieved' : ($isNext ? 'Up next' : 'Locked'));
            $stepPct = 0;
            if ($isNext && $nextReq > $prevReq) {
                $stepPct = (int) round(min(100, max(0, $into / max(0.01, $nextReq - $prevReq) * 100)));
            } elseif ($hit) {
                $stepPct = 100;
            }
        ?>
            <li class="<?= e($cls) ?>">
                <span class="rk-node-rail" aria-hidden="true">
                    <span class="rk-node-dot"><?= $hit ? '✓' : (int) ($idx + 1) ?></span>
                </span>
                <div class="rk-node-body">
                    <div class="rk-node-top">
                        <div>
                            <strong><?= e((string) $r['title']) ?></strong>
                            <span class="rk-node-pairs"><?= number_format((int) $r['pairs_required']) ?> pairs</span>
                        </div>
                        <em class="rk-node-state"><?= e($state) ?></em>
                    </div>
                    <?php if ($bonus > 0): ?>
                        <p class="rk-node-bonus">Rank bonus <?= strip_tags(currency($bonus)) ?></p>
                    <?php endif; ?>
                    <?php if ($isNext || $isNow): ?>
                        <div class="rk-node-bar" aria-hidden="true"><i style="width: <?= $stepPct ?>%"></i></div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <p class="rk-note">Product sales, active volume and compliance still apply as written in the company plan. Rank titles update when binary closing credits new pairs.</p>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
