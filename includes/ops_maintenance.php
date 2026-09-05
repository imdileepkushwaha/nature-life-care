<?php
/**
 * Super Admin maintenance: reverse closing payouts / DSI, purge cancelled rows, sync package PV.
 * Scope: all closings, last closing only, or a selected run + every newer run (so BV restore is safe).
 */

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/closing.php';
require_once __DIR__ . '/income_tables.php';

/**
 * Recent closing runs for the reset UI.
 * @return list<array<string,mixed>>
 */
function ops_maintenance_list_runs(PDO $pdo, int $limit = 40): array
{
    closing_ensure_tables($pdo);
    $limit = max(1, min(100, $limit));
    try {
        return $pdo->query("
            SELECT id, members_paid, pairs_total, binary_net_total, matching_total, created_at, notes
            FROM closing_runs
            ORDER BY id DESC
            LIMIT {$limit}
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Claw income wallet (balance-limited) and reduce total_earnings.
 */
function ops_maintenance_claw(PDO $pdo, int $mid, float $amt, string $refType, ?int $refId, string $note): float
{
    if ($mid <= 0 || $amt <= 0) {
        return 0.0;
    }
    $bal = wallet_balance($pdo, $mid, 'income');
    $take = round(min($amt, max(0.0, $bal)), 2);
    if ($take <= 0) {
        return 0.0;
    }
    $res = wallet_debit($pdo, $mid, 'income', $take, $refType, $refId, $note);
    if (empty($res['ok'])) {
        return 0.0;
    }
    try {
        $pdo->prepare('UPDATE members SET total_earnings = GREATEST(0, total_earnings - ?) WHERE id = ?')
            ->execute([$take, $mid]);
    } catch (Throwable $e) {
    }
    return $take;
}

/**
 * Reverse one closing_run: binary + matching + linked DSI, restore BV, remove run rows.
 * Call newest → oldest when reversing multiple.
 *
 * @return array{lines:list<string>,binary:int,matching:int,dsi:int}
 */
function ops_reverse_one_closing_run(
    PDO $pdo,
    int $runId,
    bool $reverseBinary,
    bool $reverseMatching,
    bool $reverseDsi,
    bool $clearHistory
): array {
    $lines = [];
    $binN = 0;
    $matchN = 0;
    $dsiN = 0;

    $runSt = $pdo->prepare('SELECT * FROM closing_runs WHERE id = ? LIMIT 1');
    $runSt->execute([$runId]);
    $run = $runSt->fetch();
    if (!$run) {
        return ['lines' => ["Closing #{$runId} not found."], 'binary' => 0, 'matching' => 0, 'dsi' => 0];
    }

    $items = $pdo->prepare('SELECT * FROM closing_items WHERE closing_id = ? ORDER BY id ASC');
    $items->execute([$runId]);
    $rows = $items->fetchAll() ?: [];

    $runAt = (string) ($run['created_at'] ?? '');
    $windowStart = $runAt !== '' ? date('Y-m-d H:i:s', strtotime($runAt . ' -2 minutes')) : null;
    $windowEnd = $runAt !== '' ? date('Y-m-d H:i:s', strtotime($runAt . ' +10 minutes')) : null;

    $findBin = $pdo->prepare("
        SELECT id, member_id, amount
        FROM commissions
        WHERE member_id = ?
          AND type = 'binary' AND status = 'paid'
          AND description LIKE 'Binary closing:%'
          AND ABS(amount - ?) < 0.02
          AND created_at BETWEEN ? AND ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $findBinLoose = $pdo->prepare("
        SELECT id, member_id, amount
        FROM commissions
        WHERE member_id = ?
          AND type = 'binary' AND status = 'paid'
          AND description LIKE 'Binary closing:%'
          AND ABS(amount - ?) < 0.02
        ORDER BY id DESC
        LIMIT 1
    ");

    $binSum = 0.0;
    $matchSum = 0.0;
    $dsiSum = 0.0;
    $binIds = [];
    $usedBin = [];

    $memCode = $pdo->prepare('SELECT member_id FROM members WHERE id = ? LIMIT 1');

    foreach ($rows as $it) {
        $mid = (int) ($it['member_id'] ?? 0);
        $net = round((float) ($it['binary_net'] ?? 0), 2);
        $matchAmt = round((float) ($it['matching_amount'] ?? 0), 2);
        $matchTo = (int) ($it['matching_to'] ?? 0);

        $cid = 0;
        if ($reverseBinary && $mid > 0 && $net > 0) {
            $comm = null;
            if ($windowStart && $windowEnd) {
                $findBin->execute([$mid, $net, $windowStart, $windowEnd]);
                $comm = $findBin->fetch() ?: null;
            }
            if (!$comm) {
                $findBinLoose->execute([$mid, $net]);
                $cand = $findBinLoose->fetch() ?: null;
                if ($cand && empty($usedBin[(int) $cand['id']])) {
                    $comm = $cand;
                }
            }
            if ($comm) {
                $cid = (int) $comm['id'];
                if (empty($usedBin[$cid])) {
                    $usedBin[$cid] = true;
                    $binSum += ops_maintenance_claw(
                        $pdo,
                        $mid,
                        $net,
                        'closing_cleanup',
                        $cid,
                        'Reverse binary closing #' . $cid . ' (run #' . $runId . ')'
                    );
                    $binIds[] = $cid;
                    $binN++;
                }
            }
        }

        if ($reverseMatching && $matchAmt > 0 && $matchTo > 0) {
            $memCode->execute([$mid]);
            $code = (string) ($memCode->fetchColumn() ?: '');
            $like = $code !== '' ? ('Matching bonus on binary of ' . $code . '%') : 'Matching bonus on binary%';
            $mSt = $pdo->prepare("
                SELECT id, member_id, amount
                FROM income_matching
                WHERE member_id = ? AND status = 'paid' AND ABS(amount - ?) < 0.02
                  AND description LIKE ?
                ORDER BY id DESC
                LIMIT 1
            ");
            try {
                $mSt->execute([$matchTo, $matchAmt, $like]);
                $mrow = $mSt->fetch() ?: null;
                if ($mrow) {
                    $iid = (int) $mrow['id'];
                    $matchSum += ops_maintenance_claw(
                        $pdo,
                        $matchTo,
                        $matchAmt,
                        'closing_cleanup',
                        $iid,
                        'Reverse matching #' . $iid . ' (run #' . $runId . ')'
                    );
                    $pdo->prepare('DELETE FROM income_matching WHERE id = ?')->execute([$iid]);
                    try {
                        $pdo->prepare("DELETE FROM wallet_ledger WHERE ref_type = 'income_matching' AND ref_id = ?")->execute([$iid]);
                        $pdo->prepare("DELETE FROM wallet_ledger WHERE ref_type = 'closing_cleanup' AND ref_id = ?")->execute([$iid]);
                    } catch (Throwable $e) {
                    }
                    $matchN++;
                }
            } catch (Throwable $e) {
            }
        }

        // Restore BV to pre-closing state for this run (safe when reversing newest-first)
        if ($clearHistory && $mid > 0) {
            $pdo->prepare('UPDATE members SET left_bv = ?, right_bv = ? WHERE id = ?')->execute([
                round((float) ($it['left_bv_before'] ?? 0), 2),
                round((float) ($it['right_bv_before'] ?? 0), 2),
                $mid,
            ]);
            $pairs = round((float) ($it['pairs'] ?? 0), 2);
            if ($pairs > 0) {
                try {
                    $pdo->prepare('UPDATE members SET lifetime_pairs = GREATEST(0, ROUND(lifetime_pairs - ?, 2)) WHERE id = ?')
                        ->execute([$pairs, $mid]);
                } catch (Throwable $e) {
                }
            }
        }
    }

    if ($reverseDsi && $binIds) {
        foreach ($binIds as $cid) {
            $tag = '%[dsi:bin:c' . $cid . ']%';
            $dSt = $pdo->prepare("
                SELECT id, member_id, amount FROM income_dsi
                WHERE status = 'paid' AND description LIKE ?
            ");
            $dSt->execute([$tag]);
            foreach ($dSt->fetchAll() ?: [] as $d) {
                $iid = (int) $d['id'];
                $dsiSum += ops_maintenance_claw(
                    $pdo,
                    (int) $d['member_id'],
                    round((float) $d['amount'], 2),
                    'income_dsi',
                    $iid,
                    'DSI #' . $iid . ' removed (run #' . $runId . ')'
                );
                $pdo->prepare('DELETE FROM income_dsi WHERE id = ?')->execute([$iid]);
                try {
                    $pdo->prepare("DELETE FROM wallet_ledger WHERE ref_type = 'income_dsi' AND ref_id = ?")->execute([$iid]);
                } catch (Throwable $e) {
                }
                $dsiN++;
            }
        }
    }

    if ($binIds) {
        $in = implode(',', array_map('intval', $binIds));
        $pdo->exec("DELETE FROM commissions WHERE id IN ($in)");
        try {
            $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'commission' AND ref_id IN ($in)");
            $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'closing_cleanup' AND ref_id IN ($in)");
        } catch (Throwable $e) {
        }
    }

    if ($clearHistory) {
        $pdo->prepare('DELETE FROM closing_items WHERE closing_id = ?')->execute([$runId]);
        $pdo->prepare('DELETE FROM closing_runs WHERE id = ?')->execute([$runId]);
    }

    $when = $runAt !== '' ? $runAt : ('#' . $runId);
    $lines[] = "Run #{$runId} ({$when}): binary {$binN} (₹" . number_format($binSum, 2, '.', '')
        . '), matching ' . $matchN . ' (₹' . number_format($matchSum, 2, '.', '')
        . '), DSI ' . $dsiN . ' (₹' . number_format($dsiSum, 2, '.', '') . ')'
        . ($clearHistory ? ', history+BV restored' : '');

    return ['lines' => $lines, 'binary' => $binN, 'matching' => $matchN, 'dsi' => $dsiN];
}

/**
 * @param array{
 *   scope?:string,
 *   closing_run_id?:int,
 *   reverse_binary?:bool,
 *   reverse_matching?:bool,
 *   reverse_dsi?:bool,
 *   clear_closing_history?:bool,
 *   sync_package_pv?:bool,
 *   purge_cancelled?:bool
 * } $opts
 * @return array{ok:bool,message:string,lines:list<string>}
 */
function ops_maintenance_run(PDO $pdo, array $opts = []): array
{
    $scope = strtolower(trim((string) ($opts['scope'] ?? 'last')));
    if (!in_array($scope, ['all', 'last', 'from'], true)) {
        $scope = 'last';
    }
    $fromRunId = max(0, (int) ($opts['closing_run_id'] ?? 0));

    $reverseBinary = !empty($opts['reverse_binary']);
    $reverseMatching = !empty($opts['reverse_matching']);
    $reverseDsi = !empty($opts['reverse_dsi']);
    $clearHistory = !empty($opts['clear_closing_history']);
    $syncPv = !empty($opts['sync_package_pv']);
    $purgeCancelled = !empty($opts['purge_cancelled']);

    if (!$reverseBinary && !$reverseMatching && !$reverseDsi && !$clearHistory && !$syncPv && !$purgeCancelled) {
        return ['ok' => false, 'message' => 'Select at least one action.', 'lines' => []];
    }

    wallet_ensure_schema($pdo);
    closing_ensure_tables($pdo);
    income_tables_ensure($pdo);

    $lines = [];
    $claw = static function (PDO $pdo, int $mid, float $amt, string $refType, ?int $refId, string $note): float {
        return ops_maintenance_claw($pdo, $mid, $amt, $refType, $refId, $note);
    };

    try {
        $pdo->beginTransaction();

        if ($scope === 'all') {
            $lines[] = 'Scope: ALL closings';

            if ($reverseBinary) {
                $comms = $pdo->query("
                    SELECT id, member_id, amount
                    FROM commissions
                    WHERE type = 'binary' AND status = 'paid'
                      AND description LIKE 'Binary closing:%'
                    ORDER BY id
                ")->fetchAll();
                $n = 0;
                $sum = 0.0;
                $ids = [];
                foreach ($comms as $c) {
                    $cid = (int) $c['id'];
                    $mid = (int) $c['member_id'];
                    $amt = round((float) $c['amount'], 2);
                    $sum += $claw($pdo, $mid, $amt, 'closing_cleanup', $cid, 'Reverse binary closing #' . $cid);
                    $ids[] = $cid;
                    $n++;
                }
                if ($ids) {
                    $in = implode(',', $ids);
                    $pdo->exec("DELETE FROM commissions WHERE id IN ($in)");
                    try {
                        $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'commission' AND ref_id IN ($in)");
                        $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'closing_cleanup' AND ref_id IN ($in)");
                    } catch (Throwable $e) {
                    }
                }
                $lines[] = "Binary closing removed: {$n} · wallet clawed ≈ " . number_format($sum, 2, '.', '');
            }

            if ($reverseMatching) {
                $n = 0;
                $sum = 0.0;
                $ids = [];
                try {
                    $mrows = $pdo->query("
                        SELECT id, member_id, amount
                        FROM income_matching
                        WHERE status = 'paid' AND description LIKE 'Matching bonus on binary%'
                        ORDER BY id
                    ")->fetchAll();
                    foreach ($mrows as $m) {
                        $iid = (int) $m['id'];
                        $mid = (int) $m['member_id'];
                        $amt = round((float) $m['amount'], 2);
                        $sum += $claw($pdo, $mid, $amt, 'closing_cleanup', $iid, 'Reverse matching #' . $iid);
                        $ids[] = $iid;
                        $n++;
                    }
                    if ($ids) {
                        $in = implode(',', $ids);
                        $pdo->exec("DELETE FROM income_matching WHERE id IN ($in)");
                        try {
                            $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'income_matching' AND ref_id IN ($in)");
                            $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'closing_cleanup' AND ref_id IN ($in)");
                        } catch (Throwable $e) {
                        }
                    }
                } catch (Throwable $e) {
                }
                $lines[] = "Matching removed: {$n} · wallet clawed ≈ " . number_format($sum, 2, '.', '');
            }

            if ($reverseDsi) {
                $drows = $pdo->query("
                    SELECT id, member_id, amount
                    FROM income_dsi
                    WHERE status = 'paid'
                    ORDER BY id
                ")->fetchAll();
                $n = 0;
                $sum = 0.0;
                $ids = [];
                foreach ($drows as $d) {
                    $iid = (int) $d['id'];
                    $mid = (int) $d['member_id'];
                    $amt = round((float) $d['amount'], 2);
                    $sum += $claw($pdo, $mid, $amt, 'income_dsi', $iid, 'DSI #' . $iid . ' removed (SA maintenance)');
                    $ids[] = $iid;
                    $n++;
                }
                if ($ids) {
                    $in = implode(',', $ids);
                    $pdo->exec("DELETE FROM income_dsi WHERE id IN ($in)");
                    try {
                        $pdo->exec("DELETE FROM wallet_ledger WHERE ref_type = 'income_dsi' AND ref_id IN ($in)");
                    } catch (Throwable $e) {
                    }
                }
                $lines[] = "Paid DSI removed: {$n} · wallet clawed ≈ " . number_format($sum, 2, '.', '');
            }

            if ($clearHistory) {
                $runs = 0;
                try {
                    $runs = (int) $pdo->query('SELECT COUNT(*) FROM closing_runs')->fetchColumn();
                    $pdo->exec('DELETE FROM closing_items');
                    $pdo->exec('DELETE FROM closing_runs');
                } catch (Throwable $e) {
                }
                try {
                    $pdo->exec('UPDATE members SET lifetime_pairs = 0');
                } catch (Throwable $e) {
                }
                $lines[] = "Closing history cleared: {$runs} run(s); lifetime pairs reset.";
            }
        } else {
            // last OR from (selected run + all newer) — reverse newest first so BV restore is correct
            $runIds = [];
            try {
                if ($scope === 'last') {
                    $lid = (int) $pdo->query('SELECT id FROM closing_runs ORDER BY id DESC LIMIT 1')->fetchColumn();
                    if ($lid > 0) {
                        $runIds = [$lid];
                    }
                } else {
                    if ($fromRunId <= 0) {
                        $pdo->rollBack();
                        return ['ok' => false, 'message' => 'Select which closing run to reset from.', 'lines' => []];
                    }
                    $st = $pdo->prepare('SELECT id FROM closing_runs WHERE id >= ? ORDER BY id DESC');
                    $st->execute([$fromRunId]);
                    $runIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
                    if (!$runIds) {
                        $pdo->rollBack();
                        return ['ok' => false, 'message' => "Closing run #{$fromRunId} not found.", 'lines' => []];
                    }
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Could not load closing runs: ' . $e->getMessage(), 'lines' => []];
            }

            if (!$runIds) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'No closing runs to reset.', 'lines' => []];
            }

            if ($scope === 'last') {
                $lines[] = 'Scope: LAST closing only (#' . $runIds[0] . ')';
            } else {
                $lines[] = 'Scope: from #' . min($runIds) . ' through newest (#' . max($runIds) . ') — ' . count($runIds) . ' run(s)';
            }

            if (!$reverseBinary && !$reverseMatching && !$reverseDsi && !$clearHistory) {
                // only purge/sync may remain — skip run loop
            } else {
                foreach ($runIds as $rid) {
                    $one = ops_reverse_one_closing_run(
                        $pdo,
                        $rid,
                        $reverseBinary,
                        $reverseMatching,
                        $reverseDsi,
                        $clearHistory
                    );
                    foreach ($one['lines'] as $ln) {
                        $lines[] = $ln;
                    }
                }
            }
        }

        if ($purgeCancelled) {
            $delDsi = 0;
            $delMatch = 0;
            $delComm = 0;
            $delLed = 0;
            try {
                $delDsi = (int) $pdo->exec("DELETE FROM income_dsi WHERE status = 'cancelled'");
            } catch (Throwable $e) {
            }
            try {
                $delMatch = (int) $pdo->exec("DELETE FROM income_matching WHERE status = 'cancelled'");
            } catch (Throwable $e) {
            }
            try {
                $delComm = (int) $pdo->exec("
                    DELETE FROM commissions
                    WHERE status = 'cancelled'
                      AND (
                        type IN ('binary', 'dsi', 'matching')
                        OR description LIKE 'Binary closing:%'
                      )
                ");
            } catch (Throwable $e) {
            }
            try {
                $delLed = (int) $pdo->exec("
                    DELETE FROM wallet_ledger
                    WHERE ref_type = 'closing_cleanup'
                       OR note LIKE '%cancelled (SA maintenance)%'
                       OR note LIKE '%cancelled (live maintenance)%'
                       OR note LIKE 'Reverse binary closing%'
                       OR note LIKE 'Reverse matching%'
                       OR note LIKE 'DSI #% cancelled%'
                       OR note LIKE 'DSI #% removed%'
                       OR note LIKE '%cancelled (local cleanup)%'
                ");
            } catch (Throwable $e) {
            }
            $lines[] = "Purged cancelled: DSI {$delDsi}, matching {$delMatch}, commissions {$delComm}, ledger {$delLed}";
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Maintenance failed: ' . $e->getMessage(), 'lines' => $lines];
    }

    if ($syncPv) {
        $updated = 0;
        try {
            $pkgs = $pdo->query("SELECT id, name, bv FROM packages WHERE bv > 0 ORDER BY id")->fetchAll();
            foreach ($pkgs as $p) {
                $sync = closing_sync_package_bv($pdo, (int) $p['id'], (float) $p['bv']);
                $updated += (int) ($sync['updated'] ?? 0);
                $lines[] = 'PV sync ' . $p['name'] . ' (BV ' . number_format((float) $p['bv'], 2, '.', '') . '): '
                    . (string) ($sync['message'] ?? '');
            }
        } catch (Throwable $e) {
            $lines[] = 'PV sync error: ' . $e->getMessage();
            return ['ok' => false, 'message' => 'Payouts reverted but PV sync failed.', 'lines' => $lines];
        }
        $lines[] = "Activation lots refreshed: {$updated}";
    }

    return [
        'ok' => true,
        'message' => 'Maintenance complete.',
        'lines' => $lines,
    ];
}

/** Snapshot counts for Super Admin UI. */
function ops_maintenance_snapshot(PDO $pdo): array
{
    $out = [
        'binary_paid' => 0,
        'binary_sum' => 0.0,
        'matching_paid' => 0,
        'dsi_paid' => 0,
        'dsi_sum' => 0.0,
        'dsi_pending' => 0,
        'dsi_cancelled' => 0,
        'comm_cancelled' => 0,
        'closing_runs' => 0,
        'lots_1599' => 0,
        'starter_bv' => null,
        'last_run_id' => null,
        'last_run_at' => null,
    ];
    try {
        $r = $pdo->query("
            SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM commissions
            WHERE type = 'binary' AND status = 'paid' AND description LIKE 'Binary closing:%'
        ")->fetch();
        $out['binary_paid'] = (int) ($r['c'] ?? 0);
        $out['binary_sum'] = (float) ($r['s'] ?? 0);
    } catch (Throwable $e) {
    }
    try {
        $out['matching_paid'] = (int) $pdo->query("
            SELECT COUNT(*) FROM income_matching
            WHERE status = 'paid' AND description LIKE 'Matching bonus on binary%'
        ")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $r = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM income_dsi WHERE status = 'paid'")->fetch();
        $out['dsi_paid'] = (int) ($r['c'] ?? 0);
        $out['dsi_sum'] = (float) ($r['s'] ?? 0);
        $out['dsi_pending'] = (int) $pdo->query("SELECT COUNT(*) FROM income_dsi WHERE status = 'pending'")->fetchColumn();
        $out['dsi_cancelled'] = (int) $pdo->query("SELECT COUNT(*) FROM income_dsi WHERE status = 'cancelled'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $out['comm_cancelled'] = (int) $pdo->query("
            SELECT COUNT(*) FROM commissions
            WHERE status = 'cancelled'
              AND (type IN ('binary','dsi','matching') OR description LIKE 'Binary closing:%')
        ")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $out['closing_runs'] = (int) $pdo->query('SELECT COUNT(*) FROM closing_runs')->fetchColumn();
        $last = $pdo->query('SELECT id, created_at FROM closing_runs ORDER BY id DESC LIMIT 1')->fetch();
        if ($last) {
            $out['last_run_id'] = (int) $last['id'];
            $out['last_run_at'] = (string) $last['created_at'];
        }
    } catch (Throwable $e) {
    }
    try {
        $out['lots_1599'] = (int) $pdo->query("SELECT COUNT(*) FROM bv_lots WHERE status='eligible' AND ABS(amount-1599)<0.02")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $bv = $pdo->query("SELECT bv FROM packages WHERE name LIKE '%Starter%' OR id = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $out['starter_bv'] = $bv !== false ? (float) $bv : null;
    } catch (Throwable $e) {
    }
    return $out;
}
