<div class="criteria-version-heading">
    <div>
        <h2<?= isset($versionCloseUrl) ? ' id="criteria-version-title"' : '' ?>>Version <?= $requestedRevision ?> details</h2>
        <?php if ($version): ?>
        <p><?= htmlspecialchars($version['change_summary']) ?> · <?= htmlspecialchars($version['created_at']) ?><?= $version['author'] ? ' · ' . htmlspecialchars($version['author']) : '' ?></p>
        <?php endif; ?>
    </div>
    <?php if (isset($versionCloseUrl)): ?>
    <a class="criteria-history-view" href="<?= htmlspecialchars($versionCloseUrl) ?>" aria-label="Close version details" title="Close version details"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
    <?php endif; ?>
</div>
<?php if ($versionError): ?>
<p class="criteria-version-note" role="alert"><?= htmlspecialchars($versionError) ?></p>
<?php elseif ($version['previous_revision'] === null): ?>
<p class="criteria-version-note">Initial saved version.</p>
<?php else: ?>
<p class="criteria-version-note">Compared with <a data-version-revision="<?= $version['previous_revision'] ?>" href="<?= htmlspecialchars($versionUrl($version['previous_revision'])) ?>">version <?= $version['previous_revision'] ?></a><?= $version['changes'] ? ' · ' . count($version['changes']) . ' ' . (count($version['changes']) === 1 ? 'criterion changed' : 'criteria changed') : ' · No criteria fields changed' ?></p>
<?php foreach ($version['changes'] as $change): $criterion = $change['criterion']; ?>
<article class="criteria-version-change">
    <h3><?= htmlspecialchars($criterion['criterion_label']) ?> <span><?= $change['type'] ?></span></h3>
    <p><?= htmlspecialchars($criterion['kra_category']) ?> · <?= htmlspecialchars($criterion['position_rank'] ?? 'All ranks') ?></p>
    <?php if ($change['fields'] && !(count($change['fields']) === 1 && in_array($change['type'], ['Deactivated', 'Reactivated'], true))): ?>
    <div class="table-responsive" role="region" aria-label="Changes to <?= htmlspecialchars($criterion['criterion_label']) ?>" tabindex="0">
        <table class="criteria-version-table">
            <thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
            <tbody>
            <?php foreach ($change['fields'] as $field): ?>
                <tr><th scope="row"><?= $field['label'] ?></th><td><?= htmlspecialchars($field['before'] ?? '—') ?></td><td><?= htmlspecialchars($field['after'] ?? '—') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>
