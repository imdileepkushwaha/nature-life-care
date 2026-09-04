<?php
/**
 * Unified transaction report helpers (income credits + withdrawals).
 */

function txn_type_label(string $source, string $type): string
{
    if ($source === 'withdrawal') {
        return 'Withdrawal';
    }
    if ($source === 'wallet') {
        $t = strtolower($type);
        if (str_contains($t, 'topup')) {
            return str_contains($t, 'debit') ? 'Topup Debit' : 'Topup Credit';
        }
        if (str_contains($t, 'shopping')) {
            return str_contains($t, 'debit') ? 'Shopping Debit' : 'Shopping Credit';
        }
        if (str_contains($t, 'income')) {
            return str_contains($t, 'debit') ? 'Income Debit' : 'Income Credit';
        }
        return 'Wallet';
    }
    $map = [
        'binary' => 'Matching Income',
        'referral' => 'Referral Income',
        'matching' => 'Matching Income',
        'dsi' => 'DSI Income',
        'level' => 'Level Income',
        'rank' => 'Rank Incentive',
        'reward' => 'Reward Benefit',
        'other' => 'Other Income',
    ];
    return $map[strtolower($type)] ?? (ucfirst($type) . ' Income');
}

function txn_status_pill(string $status): string
{
    $s = strtolower($status);
    $cls = match ($s) {
        'paid', 'approved' => 'is-ok',
        'pending' => 'is-wait',
        'cancelled', 'rejected' => 'is-bad',
        default => 'is-muted',
    };
    return '<span class="txn-pill ' . $cls . '">' . e(ucfirst($s)) . '</span>';
}

/**
 * Fetch unified transactions for a member with filters + pagination.
 *
 * @return array{rows:array,total:int,total_pages:int,page:int,credit_sum:float,debit_sum:float}
 */
function txn_fetch(PDO $pdo, int $memberId, string $kind = '', string $status = '', int $page = 1, int $perPage = 15): array
{
    $page = max(1, $page);
    $perPage = max(5, min(50, $perPage));
    $kind = in_array($kind, ['income', 'withdrawal', 'wallet'], true) ? $kind : '';
    $status = in_array($status, ['pending', 'paid', 'approved', 'cancelled', 'rejected'], true) ? $status : '';

    $unions = [];
    $params = [];

    if ($kind === '' || $kind === 'income') {
        require_once __DIR__ . '/income_tables.php';
        income_tables_ensure($pdo);

        $cWhere = ['member_id = ?'];
        $cParams = [$memberId];
        if ($status !== '') {
            $cStatus = match ($status) {
                'approved' => 'paid',
                'rejected' => 'cancelled',
                default => $status,
            };
            if (in_array($cStatus, ['pending', 'paid', 'cancelled'], true)) {
                $cWhere[] = 'status = ?';
                $cParams[] = $cStatus;
            }
        }
        $unions[] = "
            SELECT id,
                   'commission' AS source,
                   type,
                   amount,
                   description,
                   status,
                   created_at AS txn_at,
                   'in' AS direction
            FROM commissions
            WHERE " . implode(' AND ', $cWhere);
        $params = array_merge($params, $cParams);

        $mWhere = ['member_id = ?'];
        $mParams = [$memberId];
        if ($status !== '') {
            $mStatus = match ($status) {
                'approved' => 'paid',
                'rejected' => 'cancelled',
                default => $status,
            };
            if (in_array($mStatus, ['pending', 'paid', 'cancelled'], true)) {
                $mWhere[] = 'status = ?';
                $mParams[] = $mStatus;
            }
        }
        $unions[] = "
            SELECT id,
                   'commission' AS source,
                   'matching' AS type,
                   amount,
                   description,
                   status,
                   created_at AS txn_at,
                   'in' AS direction
            FROM income_matching
            WHERE " . implode(' AND ', $mWhere);
        $params = array_merge($params, $mParams);

        $dWhere = ['member_id = ?', "status <> 'pending'"];
        $dParams = [$memberId];
        if ($status !== '') {
            $dStatus = match ($status) {
                'approved' => 'paid',
                'rejected' => 'cancelled',
                default => $status,
            };
            if ($dStatus === 'pending') {
                $dWhere[] = '1=0';
            } elseif (in_array($dStatus, ['paid', 'cancelled'], true)) {
                $dWhere = ['member_id = ?', 'status = ?'];
                $dParams = [$memberId, $dStatus];
            }
        }
        $unions[] = "
            SELECT id,
                   'commission' AS source,
                   'dsi' AS type,
                   amount,
                   description,
                   status,
                   created_at AS txn_at,
                   'in' AS direction
            FROM income_dsi
            WHERE " . implode(' AND ', $dWhere);
        $params = array_merge($params, $dParams);
    }

    if ($kind === '' || $kind === 'wallet') {
        require_once __DIR__ . '/wallet.php';
        wallet_ensure_schema($pdo);
        // Wallet moves that are not already represented as income commission rows.
        $lWhere = [
            'member_id = ?',
            "(ref_type IS NULL OR ref_type NOT IN ('commission','income_dsi','income_matching'))",
        ];
        $lParams = [$memberId];
        if ($status === 'pending') {
            $lWhere[] = '1=0';
        } elseif (in_array($status, ['cancelled', 'rejected'], true)) {
            $lWhere[] = '1=0';
        }
        $unions[] = "
            SELECT id,
                   'wallet' AS source,
                   CONCAT(wallet_type, '_', direction) AS type,
                   amount,
                   COALESCE(NULLIF(note, ''), CONCAT(UPPER(SUBSTRING(wallet_type,1,1)), SUBSTRING(wallet_type,2), ' ', direction)) AS description,
                   'paid' AS status,
                   created_at AS txn_at,
                   CASE WHEN direction = 'credit' THEN 'in' ELSE 'out' END AS direction
            FROM wallet_ledger
            WHERE " . implode(' AND ', $lWhere);
        $params = array_merge($params, $lParams);
    }

    if ($kind === '' || $kind === 'withdrawal') {
        require_once __DIR__ . '/withdrawal.php';
        wd_ensure_columns($pdo);
        $wWhere = ['member_id = ?'];
        $wParams = [$memberId];
        if ($status !== '') {
            $wStatus = match ($status) {
                'cancelled' => 'rejected',
                default => $status,
            };
            if (in_array($wStatus, ['pending', 'approved', 'paid', 'rejected'], true)) {
                $wWhere[] = 'status = ?';
                $wParams[] = $wStatus;
            }
        }
        $netExpr = wd_net_sql_expr('w');
        $unions[] = "
            SELECT w.id,
                   'withdrawal' AS source,
                   'withdrawal' AS type,
                   ({$netExpr}) AS amount,
                   COALESCE(NULLIF(w.account_details, ''), w.payment_method) AS description,
                   w.status,
                   w.requested_at AS txn_at,
                   'out' AS direction
            FROM withdrawals w
            WHERE " . implode(' AND ', $wWhere);
        $params = array_merge($params, $wParams);
    }

    if (!$unions) {
        return [
            'rows' => [],
            'total' => 0,
            'total_pages' => 1,
            'page' => 1,
            'credit_sum' => 0.0,
            'debit_sum' => 0.0,
        ];
    }

    $unionSql = implode(' UNION ALL ', $unions);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($unionSql) AS t");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;

    $sumStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN direction = 'in' AND status NOT IN ('cancelled','rejected') THEN amount ELSE 0 END), 0) AS credit_sum,
            COALESCE(SUM(CASE WHEN direction = 'out' AND status IN ('approved','paid') THEN amount ELSE 0 END), 0) AS debit_sum
        FROM ($unionSql) AS t
    ");
    $sumStmt->execute($params);
    $sums = $sumStmt->fetch() ?: ['credit_sum' => 0, 'debit_sum' => 0];

    $listStmt = $pdo->prepare("
        SELECT * FROM ($unionSql) AS t
        ORDER BY txn_at DESC, id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $listStmt->execute($params);

    return [
        'rows' => $listStmt->fetchAll(),
        'total' => $total,
        'total_pages' => $totalPages,
        'page' => min($page, $totalPages),
        'credit_sum' => (float) $sums['credit_sum'],
        'debit_sum' => (float) $sums['debit_sum'],
    ];
}
