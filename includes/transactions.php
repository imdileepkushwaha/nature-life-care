<?php
/**
 * Unified transaction report helpers (income credits + withdrawals).
 */

function txn_type_label(string $source, string $type): string
{
    if ($source === 'withdrawal') {
        return 'Withdrawal';
    }
    $map = [
        'binary' => 'Binary Income',
        'referral' => 'Referral Income',
        'matching' => 'Matching Income',
        'level' => 'Level Income',
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
    $kind = in_array($kind, ['income', 'withdrawal'], true) ? $kind : '';
    $status = in_array($status, ['pending', 'paid', 'approved', 'cancelled', 'rejected'], true) ? $status : '';

    $unions = [];
    $params = [];

    if ($kind === '' || $kind === 'income') {
        $cWhere = ['member_id = ?'];
        $cParams = [$memberId];
        if ($status !== '') {
            // Map approved → paid for commissions; rejected → cancelled
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
    }

    if ($kind === '' || $kind === 'withdrawal') {
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
        $unions[] = "
            SELECT id,
                   'withdrawal' AS source,
                   'withdrawal' AS type,
                   amount,
                   COALESCE(NULLIF(account_details, ''), payment_method) AS description,
                   status,
                   requested_at AS txn_at,
                   'out' AS direction
            FROM withdrawals
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
            COALESCE(SUM(CASE WHEN direction = 'in' AND status != 'cancelled' THEN amount ELSE 0 END), 0) AS credit_sum,
            COALESCE(SUM(CASE WHEN direction = 'out' AND status NOT IN ('rejected','cancelled') THEN amount ELSE 0 END), 0) AS debit_sum
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
