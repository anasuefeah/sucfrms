<?php
// What's New page — pulls articles from help_articles (category = 'whats_new')
$releases = [];
try {
    $stmt = $pdo->query(
        "SELECT article_id, title, content, created_at
           FROM help_articles
          WHERE category = 'whats_new' AND is_published = 1
          ORDER BY article_id DESC"
    );
    $releases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}
?>

<div class="container py-4" style="max-width:820px;">

    <!-- Page header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <div style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#475569,#a07830);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-stars" style="color:#fff;font-size:1.25rem;"></i>
        </div>
        <div>
            <h4 class="mb-0 fw-bold" style="color:#1a3a6b;">What's New</h4>
            <p class="mb-0 text-muted" style="font-size:0.82rem;">
                Latest updates and improvements to SUCFRMS
            </p>
        </div>
    </div>

    <?php if (empty($releases)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-stars d-block mb-2" style="font-size:2.5rem;"></i>
        No release notes yet. Check back soon.
    </div>
    <?php else: ?>

    <!-- Release notes list -->
    <div class="d-flex flex-column gap-3">
        <?php foreach ($releases as $rel): ?>
        <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
            <div style="height:4px;background:linear-gradient(90deg,#1e4d8c,#475569);"></div>
            <div class="card-body p-4">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <h5 class="mb-0 fw-bold" style="color:#1a3a6b;font-size:0.95rem;">
                        <i class="bi bi-megaphone-fill me-2" style="color:#475569;"></i>
                        <?= htmlspecialchars($rel['title']) ?>
                    </h5>
                    <span style="font-size:0.72rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;">
                        <?= date('M j, Y', strtotime($rel['created_at'])) ?>
                    </span>
                </div>
                <div style="font-size:0.84rem;color:#374151;white-space:pre-line;line-height:1.7;">
                    <?= htmlspecialchars($rel['content']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>
