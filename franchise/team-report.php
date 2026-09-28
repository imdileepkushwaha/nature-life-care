<?php
/**
 * Franchise Portal - My Downline Franchise Network
 */
$pageTitle = 'My Franchise Network';
require_once __DIR__ . '/includes/header.php';

$frId = (int) $currentFranchise['id'];
$team = franchise_downline($pdo, $frId);
?>

<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
        <div>
            <h2>My Downline Franchise Network (<?= count($team) ?>)</h2>
            <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
                Direct distributors and retailer counters appointed by your franchise account.
            </p>
        </div>
        <div>
            <?php if (!empty($canCreateSubFranchise)): ?>
                <a href="team-add.php" class="btn btn-primary btn-sm">+ Add Sub-Franchise</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Franchise Code</th>
                    <th>Business / Store Name</th>
                    <th>Role Type</th>
                    <th>Contact &amp; Phone</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Appointed Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($team)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:2.5rem 1rem;color:#64748b">
                        <svg style="width:40px;height:40px;stroke:#94a3b8;fill:none;margin-bottom:0.5rem" viewBox="0 0 24 24" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        </svg>
                        <br>
                        <strong>No downline franchisees appointed yet.</strong>
                        <p style="font-size:0.85rem;margin-top:0.25rem">Click "Add Sub-Franchise" above to appoint distributors or retail counters under your channel.</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($team as $idx => $r): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><strong><?= e($r['franchisee_code']) ?></strong></td>
                    <td>
                        <strong><?= e($r['name']) ?></strong>
                        <?php if (!empty($r['contact_person'])): ?>
                            <br><small style="color:#64748b"><?= e($r['contact_person']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-info"><?= e($r['type_name'] ?? 'Franchise') ?></span>
                    </td>
                    <td>
                        <strong><?= e($r['phone'] ?? '—') ?></strong>
                        <?php if (!empty($r['email'])): ?>
                            <br><small style="color:#64748b"><?= e($r['email']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['city'] ?? '') ?>, <?= e($r['state'] ?? '') ?></td>
                    <td>
                        <?php if (($r['status'] ?? '') === 'active'): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
