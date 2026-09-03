<?php
/**
 * Shared helpers for admin report pages.
 */

function report_parse_dates(): array
{
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function report_period_label(string $from, string $to): string
{
    return date('d M Y', strtotime($from)) . ' → ' . date('d M Y', strtotime($to));
}

/**
 * Render common filter bar.
 * Extra field: ['name'=>'q','label'=>'Search','type'=>'text|select|number','value'=>'','placeholder'=>'','options'=>[]]
 * Options: ['show_dates' => true]
 */
function report_filter_form(string $action, string $from, string $to, array $extraFields = [], array $options = []): void
{
    $showDates = ($options['show_dates'] ?? true) !== false;
    ?>
    <form method="get" action="<?= e($action) ?>" class="tpin-filters rpt-page-filters" style="margin-bottom:1rem">
        <?php if ($showDates): ?>
        <div class="form-group">
            <label for="rpt_from">From</label>
            <input type="date" name="from" id="rpt_from" value="<?= e($from) ?>" required>
        </div>
        <div class="form-group">
            <label for="rpt_to">To</label>
            <input type="date" name="to" id="rpt_to" value="<?= e($to) ?>" required>
        </div>
        <?php endif; ?>
        <?php foreach ($extraFields as $f):
            $name = (string) ($f['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $label = (string) ($f['label'] ?? $name);
            $type = (string) ($f['type'] ?? 'text');
            $value = (string) ($f['value'] ?? '');
            $ph = (string) ($f['placeholder'] ?? '');
            $id = 'rpt_' . preg_replace('/[^a-z0-9_]/i', '', $name);
            ?>
            <div class="form-group<?= !empty($f['wide']) ? ' tpin-filter-search' : '' ?>">
                <label for="<?= e($id) ?>"><?= e($label) ?></label>
                <?php if ($type === 'select'): ?>
                    <select name="<?= e($name) ?>" id="<?= e($id) ?>">
                        <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                            <option value="<?= e((string) $ov) ?>" <?= (string) $ov === $value ? 'selected' : '' ?>><?= e((string) $ol) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="<?= e($type) ?>" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= e($value) ?>" placeholder="<?= e($ph) ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="tpin-filter-actions">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?= e($action) ?>" class="btn btn-outline">Reset</a>
        </div>
    </form>
    <?php if ($showDates): ?>
    <div class="rpt-period" style="margin-bottom:1rem">
        <div>
            <strong><?= e(report_period_label($from, $to)) ?></strong>
            <small style="display:block;color:#64748b;margin-top:.15rem">Filtered period</small>
        </div>
        <div class="rpt-period-actions">
            <?php
            $base = basename($action);
            $qs = $_GET;
            ?>
            <a class="rpt-chip" href="<?= e($base) ?>?<?= e(http_build_query(array_merge($qs, ['from' => date('Y-m-d'), 'to' => date('Y-m-d')]))) ?>">Today</a>
            <a class="rpt-chip" href="<?= e($base) ?>?<?= e(http_build_query(array_merge($qs, ['from' => date('Y-m-01'), 'to' => date('Y-m-d')]))) ?>">This month</a>
            <a class="rpt-chip" href="<?= e($base) ?>?<?= e(http_build_query(array_merge($qs, ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => date('Y-m-d')]))) ?>">7 days</a>
            <a class="rpt-chip" href="<?= e($base) ?>?<?= e(http_build_query(array_merge($qs, ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => date('Y-m-d')]))) ?>">30 days</a>
        </div>
    </div>
    <?php endif; ?>
    <?php
}
