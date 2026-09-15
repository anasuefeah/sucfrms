<?php
// Help page — pulls articles from the help_articles table (category = 'help')
$articles = [];
try {
    $stmt = $pdo->query(
        "SELECT article_id, title, content
           FROM help_articles
          WHERE category = 'help' AND is_published = 1
          ORDER BY article_id ASC"
    );
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

// Built-in guide sections (always shown)
$built_in_guides = [
    [
        'icon'  => 'bi-house-door',
        'color' => '#1e4d8c',
        'bg'    => '#eff6ff',
        'title' => 'Getting Started',
        'steps' => [
            ['num'=>'1','title'=>'Log In','desc'=>'Go to the login page and enter your registered email and password. If you are logging in for the first time, use the temporary password sent by your administrator and change it immediately from your Profile page.'],
            ['num'=>'2','title'=>'Check Your Dashboard','desc'=>'After logging in, your Dashboard shows your current application status, the active reclassification cycle, deadline, and quick-access buttons. Review any notification banners at the top for important alerts.'],
            ['num'=>'3','title'=>'Review Active Cycle','desc'=>'The system is tied to an active cycle set by the administrator. You can only submit during an open cycle. The cycle name, start date, end date, and submission deadline are displayed on your Dashboard.'],
        ],
    ],
    [
        'icon'  => 'bi-file-earmark-person',
        'color' => '#1a3a6b',
        'bg'    => '#f0f4fb',
        'title' => 'Step 1 — Application Information',
        'steps' => [
            ['num'=>'1','title'=>'Go to Apply / KRA Entry','desc'=>'Click "Apply / KRA Entry" from the sidebar. This opens the application wizard. Step 1 asks for your personal and employment information.'],
            ['num'=>'2','title'=>'Fill in Your Details','desc'=>'Enter your full name, employee ID, campus, current rank, and other required fields. Your rank is auto-filled from your account — verify it is correct. Contact your administrator if there is a discrepancy.'],
            ['num'=>'3','title'=>'Save & Continue','desc'=>'Click "Save & Continue to Step 2" at the bottom. Your information is saved and you are moved to the KRA document uploading step. You can return to Step 1 later to update your details.'],
        ],
    ],
    [
        'icon'  => 'bi-file-earmark-arrow-up',
        'color' => '#059669',
        'bg'    => '#f0f4fb',
        'title' => 'Step 2 — KRA Document Uploading & Scoring',
        'steps' => [
            ['num'=>'1','title'=>'Select a KRA Tab','desc'=>'Step 2 has four KRA tabs: Instruction, Research, Extension, and Professional Development. Click each tab to enter entries for that area. The active tab turns blue.'],
            ['num'=>'2','title'=>'Open the Entry Table','desc'=>'Click the "+ Add / Open Entry Table" button. A modal will open with a table where you can add entries. Click the "+" button inside the table to add a new row.'],
            ['num'=>'3','title'=>'Fill in Entry Details','desc'=>'Select the criterion type from the dropdown. Fill in the required fields (e.g., title, date, contribution). The score calculates automatically based on your input and the KRA criteria.'],
            ['num'=>'4','title'=>'Upload Evidence','desc'=>'Each entry requires at least one evidence file (PDF, JPG, or PNG, max 50 MB). Click the upload icon on the entry row to attach your file. Wait for the upload to complete before closing.'],
            ['num'=>'5','title'=>'Click Done','desc'=>'Once all entries for the current tab are filled and evidence is uploaded, click "Done" to save. Repeat for each KRA tab.'],
            ['num'=>'6','title'=>'Check Your Weighted Score','desc'=>'At the bottom of Step 2, the Weighted Score panel updates automatically. You need a minimum of 41.00 to be eligible for reclassification. KRA weights are: Instruction 50%, Research 20%, Extension 20%, Professional Development 10%.'],
        ],
    ],
    [
        'icon'  => 'bi-send',
        'color' => '#475569',
        'bg'    => '#f8fafc',
        'title' => 'Submitting Your Application',
        'steps' => [
            ['num'=>'1','title'=>'Ensure All KRAs Are Filled','desc'=>'Before submitting, make sure you have entries and evidence files for all KRA tabs you intend to include. Entries without evidence files will still count but may affect your verification status.'],
            ['num'=>'2','title'=>'Check the Deadline','desc'=>'Submission is only allowed before the deadline shown on your Dashboard and Step 2 header. Once the deadline passes, the Submit button is disabled.'],
            ['num'=>'3','title'=>'Click Submit Application','desc'=>'If your weighted score is at least 41.00, the "Submit Application" button becomes active (white button in the header). Click it and confirm in the popup dialog. Your application status will change to "Submitted."'],
            ['num'=>'4','title'=>'Monitor Your Status','desc'=>'After submission, go to Application Status to track your application. It will move through statuses: Submitted → Under Review → Approved or Needs Revision.'],
        ],
    ],
    [
        'icon'  => 'bi-arrow-counterclockwise',
        'color' => '#1e293b',
        'bg'    => '#f8fafc',
        'title' => 'Handling Revision Requests',
        'steps' => [
            ['num'=>'1','title'=>'Check Your Notifications','desc'=>'If the checker requests revisions, you will see a notification on your Dashboard and an amber banner on your Application Status page indicating "Needs Revision."'],
            ['num'=>'2','title'=>'Review Flagged Entries','desc'=>'In Step 2, flagged entries are highlighted in yellow. The checker\'s note is shown below each flagged entry explaining what needs to be corrected.'],
            ['num'=>'3','title'=>'Edit Only Flagged Entries','desc'=>'During revision mode, only the flagged entries can be edited. Non-flagged entries are locked. Open the Entry Table and update the flagged rows as instructed.'],
            ['num'=>'4','title'=>'Resubmit','desc'=>'After fixing the flagged entries, return to the Dashboard and click "Submit Revision." Your application goes back to the checker for re-review.'],
        ],
    ],
    [
        'icon'  => 'bi-person-gear',
        'color' => '#475569',
        'bg'    => '#f8fafc',
        'title' => 'Managing Your Account',
        'steps' => [
            ['num'=>'1','title'=>'Update Your Profile','desc'=>'Click "Profile" in the sidebar to view and edit your name, email, and other personal information. Keep your profile up to date so your application details are accurate.'],
            ['num'=>'2','title'=>'Change Your Password','desc'=>'In the Profile page, click the "Security" tab to change your password. Always change a temporary password immediately to secure your account.'],
            ['num'=>'3','title'=>'View Activity Log','desc'=>'Click "My Activity Log" to see a history of all actions you have taken in the system — submissions, edits, file uploads, and login events.'],
        ],
    ],
];
?>

<div style="max-width:860px;margin:0 auto;padding:1.5rem 1rem 3rem;">

    <!-- Page header -->
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.75rem;">
        <div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1e4d8c,#1a3a6b);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 14px rgba(30,77,140,0.25);">
            <i class="bi bi-question-circle-fill" style="color:#fff;font-size:1.4rem;"></i>
        </div>
        <div>
            <h4 style="margin:0;font-weight:800;color:#1a3a6b;font-size:1.2rem;">Help Center</h4>
            <p style="margin:0;color:#64748b;font-size:0.82rem;">Step-by-step guides for using the Faculty Reclassification Management System</p>
        </div>
    </div>

    <!-- Quick nav pills -->
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.75rem;">
        <?php foreach ($built_in_guides as $gi => $g): ?>
        <a href="#guide-<?= $gi ?>"
           style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.75rem;font-weight:600;text-decoration:none;background:<?= $g['bg'] ?>;color:<?= $g['color'] ?>;border:1px solid <?= $g['color'] ?>22;transition:all 0.15s;">
            <i class="bi <?= $g['icon'] ?>" style="font-size:0.8rem;"></i><?= $g['title'] ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Built-in guide cards -->
    <?php foreach ($built_in_guides as $gi => $g): ?>
    <div id="guide-<?= $gi ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:1.25rem;box-shadow:0 1px 6px rgba(0,0,0,0.05);">

        <!-- Section header -->
        <div style="background:<?= $g['bg'] ?>;padding:0.75rem 1.25rem;display:flex;align-items:center;gap:0.65rem;border-bottom:1px solid <?= $g['color'] ?>22;">
            <div style="width:34px;height:34px;border-radius:9px;background:<?= $g['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi <?= $g['icon'] ?>" style="color:#fff;font-size:0.95rem;"></i>
            </div>
            <span style="font-weight:700;color:<?= $g['color'] ?>;font-size:0.95rem;"><?= $g['title'] ?></span>
        </div>

        <!-- Steps -->
        <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.85rem;">
        <?php foreach ($g['steps'] as $si => $step): ?>
            <div style="display:flex;gap:0.85rem;align-items:flex-start;">
                <div style="width:26px;height:26px;border-radius:50%;background:<?= $g['color'] ?>;color:#fff;font-size:0.72rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                    <?= $step['num'] ?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:700;color:#1e293b;font-size:0.87rem;margin-bottom:2px;"><?= htmlspecialchars($step['title']) ?></div>
                    <div style="color:#475569;font-size:0.82rem;line-height:1.55;"><?= htmlspecialchars($step['desc']) ?></div>
                </div>
            </div>
            <?php if ($si < count($g['steps']) - 1): ?>
            <div style="margin-left:13px;width:1px;height:12px;background:<?= $g['color'] ?>33;flex-shrink:0;"></div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($articles)): ?>
    <!-- DB articles -->
    <div style="margin-top:2rem;">
        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;">
            <div style="height:1px;flex:1;background:#e2e8f0;"></div>
            <span style="font-size:0.72rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;padding:0 0.5rem;">Additional Articles</span>
            <div style="height:1px;flex:1;background:#e2e8f0;"></div>
        </div>
        <div class="accordion" id="helpAccordion">
            <?php foreach ($articles as $i => $art): ?>
            <div class="accordion-item border mb-2" style="border-radius:10px;overflow:hidden;border-color:#e2e8f0!important;">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?> fw-semibold"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#helpArticle<?= $art['article_id'] ?>"
                            style="font-size:0.88rem;color:#1a3a6b;background:<?= $i === 0 ? '#f0f7ff' : '#fff' ?>;">
                        <i class="bi bi-file-text me-2" style="color:#1e4d8c;"></i>
                        <?= htmlspecialchars($art['title']) ?>
                    </button>
                </h2>
                <div id="helpArticle<?= $art['article_id'] ?>"
                     class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
                     data-bs-parent="#helpAccordion">
                    <div class="accordion-body" style="font-size:0.85rem;color:#374151;white-space:pre-line;line-height:1.7;">
                        <?= htmlspecialchars($art['content']) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contact support -->
    <div style="margin-top:1.5rem;padding:0.85rem 1.1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;display:flex;align-items:center;gap:0.75rem;">
        <i class="bi bi-envelope-fill" style="color:#334155;font-size:1rem;flex-shrink:0;"></i>
        <span style="font-size:0.82rem;color:#334155;">
            Still need help? Use the
            <a href="#" onclick="openFeedbackModal(); return false;" style="color:#334155;font-weight:700;">Send Feedback</a>
            form or contact your campus administrator.
        </span>
    </div>
</div>
