<?php
// Help page — role-based guides
$role = $_SESSION['role'] ?? 'faculty';

// ── Role label for the header ─────────────────────────────────
$role_labels = [
    'faculty'          => 'Faculty',
    'checker'          => 'Campus Checker',
    'talisay_checker'  => 'Talisay Checker',
    'admin'            => 'Administrator',
];
$role_label = $role_labels[$role] ?? 'User';

// ── DB articles ───────────────────────────────────────────────
$articles = [];
try {
    $stmt = $pdo->prepare(
        "SELECT article_id, title, content
           FROM help_articles
          WHERE category = 'help' AND is_published = 1
          ORDER BY article_id ASC"
    );
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

// ── Built-in guides per role ──────────────────────────────────

// FACULTY guides
$faculty_guides = [
    [
        'icon'  => 'bi-house-door',
        'color' => '#1e4d8c',
        'bg'    => '#eff6ff',
        'title' => 'Getting Started',
        'steps' => [
            ['num'=>'1','title'=>'Log In','desc'=>'Go to the login page and enter your registered email and password. If logging in for the first time, use the temporary password sent by your administrator and change it immediately from your Profile page.'],
            ['num'=>'2','title'=>'Check Your Dashboard','desc'=>'After logging in, your Dashboard shows your current application status, the active reclassification cycle, and quick-access buttons. Review any notification banners for important alerts.'],
            ['num'=>'3','title'=>'Review the Active Cycle','desc'=>'Submission is only allowed during an open cycle set by the administrator. The cycle name and status are shown on your Dashboard. If no cycle is open, use Self-Assessment to prepare.'],
        ],
    ],
    [
        'icon'  => 'bi-calculator',
        'color' => '#1a3a6b',
        'bg'    => '#f0f4fb',
        'title' => 'Self-Assessment',
        'steps' => [
            ['num'=>'1','title'=>'Open Self-Assessment','desc'=>'Click "Self-Assessment" from the sidebar or portal. This lets you estimate your KRA scores and prepare evidence before a cycle opens.'],
            ['num'=>'2','title'=>'Add Entries Per KRA','desc'=>'Use the four KRA tabs (Instruction, Research, Extension, Professional Development) to add entries. Each entry calculates an estimated score automatically.'],
            ['num'=>'3','title'=>'Check Auto Sub Rank','desc'=>'Switch to the Auto Sub Rank tab to declare a Doctorate Degree or Prestigious Award trigger, which adds +1 sub-rank on top of your score.'],
            ['num'=>'4','title'=>'Check Position Requirements','desc'=>'The Position Requirements tab shows what additional documents are needed for your rank (e.g. CAV Transcript for Professor ranks). Prepare these before the cycle opens.'],
            ['num'=>'5','title'=>'Use the Evidence Repository','desc'=>'Upload and organise your evidence files in the Evidence Repository at any time, even outside an active cycle.'],
        ],
    ],
    [
        'icon'  => 'bi-file-earmark-person',
        'color' => '#059669',
        'bg'    => '#f0fdf4',
        'title' => 'Reclassification Application',
        'steps' => [
            ['num'=>'1','title'=>'Application Information','desc'=>'Go to Apply / KRA Entry from the sidebar. Confirm your personal and employment details. Your rank is auto-filled from your account, so verify it is correct.'],
            ['num'=>'2','title'=>'KRA Entries & Evidence','desc'=>'Click each KRA tab and open the entry table. Add entries, fill in the required fields, and upload evidence files (PDF, JPG, PNG, max 50 MB each). You need at least 41.00 weighted score to submit.'],
            ['num'=>'3','title'=>'Submit Your Application','desc'=>'When your weighted score meets the minimum and all evidence is uploaded, click "Submit Application" in the header. Your status changes to Submitted and your checker is notified.'],
        ],
    ],
    [
        'icon'  => 'bi-arrow-counterclockwise',
        'color' => '#475569',
        'bg'    => '#f8fafc',
        'title' => 'Handling Revision Requests',
        'steps' => [
            ['num'=>'1','title'=>'Check Notifications','desc'=>'If a checker flags an entry for revision, you will receive a notification and an amber banner appears on your Application Status page.'],
            ['num'=>'2','title'=>'Edit Flagged Entries Only','desc'=>'During revision mode, only the flagged entries can be edited. Non-flagged entries are locked. The checker\'s note appears below each flagged row.'],
            ['num'=>'3','title'=>'Resubmit','desc'=>'After correcting the flagged entries, click "Submit Revision" on your Dashboard. Your application returns to the checker for re-review.'],
        ],
    ],
    [
        'icon'  => 'bi-person-gear',
        'color' => '#64748b',
        'bg'    => '#f8fafc',
        'title' => 'Managing Your Account',
        'steps' => [
            ['num'=>'1','title'=>'Update Your Profile','desc'=>'Click "Profile" in the sidebar to edit your name, email, and personal information. Keep your profile accurate so your application details are correct.'],
            ['num'=>'2','title'=>'Change Your Password','desc'=>'In Profile, use the Security section to change your password. Always change a temporary password immediately.'],
            ['num'=>'3','title'=>'View Activity Log','desc'=>'Click "My Activity Log" to see a history of your actions — submissions, edits, file uploads, and login events.'],
        ],
    ],
];

// CHECKER guides
$checker_guides = [
    [
        'icon'  => 'bi-house-door',
        'color' => '#1e4d8c',
        'bg'    => '#eff6ff',
        'title' => 'Getting Started as a Checker',
        'steps' => [
            ['num'=>'1','title'=>'Log In','desc'=>'Log in with your checker credentials. If using a temporary password, change it immediately from the Profile page.'],
            ['num'=>'2','title'=>'Your Dashboard','desc'=>'The Checker Dashboard shows your KPI cards: Awaiting Decision (new submissions), Active Reviews, Needs Revision, and Total Completed. These update every 15 seconds automatically.'],
            ['num'=>'3','title'=>'Review Queue','desc'=>'The Review Queue lists all applications awaiting your evaluation. Use the filter tabs (Pending, Active, Revisions) to navigate. Click an application to start reviewing.'],
        ],
    ],
    [
        'icon'  => 'bi-play-circle',
        'color' => '#059669',
        'bg'    => '#f0fdf4',
        'title' => 'Starting a Review',
        'steps' => [
            ['num'=>'1','title'=>'Open the Application','desc'=>'Click an application from the Review Queue. The application page shows the faculty\'s KRA submissions, evidence files, and score breakdown.'],
            ['num'=>'2','title'=>'Click Start Review','desc'=>'Click the "Start Review" button to begin. This moves the application to Under Review status and notifies the faculty. You cannot verify or flag entries until you start the review.'],
            ['num'=>'3','title'=>'Review Evidence','desc'=>'For each KRA entry, open the evidence files to verify they are valid. Check that the document matches the claimed criterion.'],
        ],
    ],
    [
        'icon'  => 'bi-pencil-square',
        'color' => '#1a3a6b',
        'bg'    => '#f0f4fb',
        'title' => 'Adjusting Scores & Flagging Entries',
        'steps' => [
            ['num'=>'1','title'=>'Adjust a Score','desc'=>'If an entry\'s score needs correction, click the score cell to edit it. Enter the correct value and optionally add a "Reason for Change" note. The weighted score recalculates automatically.'],
            ['num'=>'2','title'=>'Verify Evidence','desc'=>'Click the "Verify" button on an entry row to mark it as verified by you. All entries must be verified before you can approve the application.'],
            ['num'=>'3','title'=>'Flag for Revision','desc'=>'If an entry needs correction by the faculty, click "Flag" and enter a revision note explaining what to fix. The faculty will receive a notification.'],
            ['num'=>'4','title'=>'Clear a Flag','desc'=>'After the faculty resubmits, you can clear a flag by clicking "Clear Flag" once the application is back under review.'],
        ],
    ],
    [
        'icon'  => 'bi-check-circle',
        'color' => '#16a34a',
        'bg'    => '#f0fdf4',
        'title' => 'Approving or Rejecting',
        'steps' => [
            ['num'=>'1','title'=>'Approve','desc'=>'Once all evidence is verified and the score is correct, click "Approve" at the bottom of the review page. This records your approval. When all required checkers approve, the application moves to the next stage.'],
            ['num'=>'2','title'=>'Reject','desc'=>'If the application does not meet requirements, click "Reject" and provide a reason. The faculty is notified and the application is returned for revision or closed.'],
            ['num'=>'3','title'=>'Remarks','desc'=>'Any remarks you add appear on the faculty\'s application status page and in the comparison table they can view in real time.'],
        ],
    ],
];

// TALISAY CHECKER guides
$talisay_guides = [
    [
        'icon'  => 'bi-building-up',
        'color' => '#1a3a6b',
        'bg'    => '#f0f4fb',
        'title' => 'Talisay Checker Role',
        'steps' => [
            ['num'=>'1','title'=>'Your Scope','desc'=>'As a Talisay Checker, you review applications that have already been approved by campus checkers and forwarded for Talisay-level evaluation. You see applications in "Talisay Review" status only.'],
            ['num'=>'2','title'=>'Dashboard','desc'=>'Your dashboard shows applications awaiting your review, total in Talisay Review, your approvals, and total reviewed. These counts update every 15 seconds.'],
            ['num'=>'3','title'=>'Review Queue','desc'=>'The Review Queue lists applications forwarded to Talisay. Click an application to open it and begin your review.'],
        ],
    ],
    [
        'icon'  => 'bi-check2-circle',
        'color' => '#059669',
        'bg'    => '#f0fdf4',
        'title' => 'Reviewing at Talisay Level',
        'steps' => [
            ['num'=>'1','title'=>'Open and Review','desc'=>'Open an application from your queue. You can see all KRA submissions, the campus checker\'s score adjustments, and the current weighted score.'],
            ['num'=>'2','title'=>'Adjust Scores if Needed','desc'=>'If any score requires adjustment at the Talisay level, you can edit it. Any changes appear in the faculty\'s comparison table labeled "Stage 2."'],
            ['num'=>'3','title'=>'Approve or Reject','desc'=>'Click "Approve" to finalize your decision or "Reject" to return the application. Once all Talisay checkers approve, the application status moves to Approved.'],
        ],
    ],
    [
        'icon'  => 'bi-person-gear',
        'color' => '#64748b',
        'bg'    => '#f8fafc',
        'title' => 'Account Management',
        'steps' => [
            ['num'=>'1','title'=>'Update Profile','desc'=>'Click "Profile" in the sidebar to update your name, email, and information.'],
            ['num'=>'2','title'=>'Change Password','desc'=>'Use the Security section in Profile to change your password at any time.'],
            ['num'=>'3','title'=>'Activity Log','desc'=>'Click "My Activity Log" (Checker Audit) to see a history of all review actions you have taken.'],
        ],
    ],
];

// ADMIN guides
$admin_guides = [
    [
        'icon'  => 'bi-speedometer2',
        'color' => '#1e4d8c',
        'bg'    => '#eff6ff',
        'title' => 'Admin Dashboard',
        'steps' => [
            ['num'=>'1','title'=>'Dashboard Overview','desc'=>'The Admin Dashboard shows real-time KPI cards: Total Faculty, Submitted, Awaiting Checker, Under Review, Needs Revision, and Approved. These update every 15 seconds without a page refresh.'],
            ['num'=>'2','title'=>'Submission Trend','desc'=>'The 7-day trend chart shows daily application activity. Use this to monitor when faculty are most active.'],
            ['num'=>'3','title'=>'Quick Actions','desc'=>'Use the sidebar links to navigate to Manage Users, Cycles, All Applications, Campuses, Configuration, and Reports.'],
        ],
    ],
    [
        'icon'  => 'bi-arrow-repeat',
        'color' => '#1a3a6b',
        'bg'    => '#f0f4fb',
        'title' => 'Managing Cycles',
        'steps' => [
            ['num'=>'1','title'=>'Create a Cycle','desc'=>'Go to Cycles from the sidebar. Click "Create Cycle" and fill in the cycle name, dates. A cycle must be set to Open for faculty to submit applications.'],
            ['num'=>'2','title'=>'Open / Close a Cycle','desc'=>'Change the cycle status to Open to allow submissions, or Closed to stop them. Only one cycle can be Open at a time.'],
            ['num'=>'3','title'=>'Edit Cycle Details','desc'=>'Click Manage on a cycle to edit the name, dates, and scoring criteria. Changes take effect immediately.'],
        ],
    ],
    [
        'icon'  => 'bi-people',
        'color' => '#059669',
        'bg'    => '#f0fdf4',
        'title' => 'Managing Users',
        'steps' => [
            ['num'=>'1','title'=>'Register Users','desc'=>'Go to Manage Users and click "Add User." Fill in the name, email, employee ID, campus, rank, and role. The system sends a temporary password to the user\'s email.'],
            ['num'=>'2','title'=>'Roles','desc'=>'Roles are: Faculty (apply only — registered by self), Checker (review applications — created by admin), Talisay Checker (Talisay-level review — created by admin), Admin (full access). Assign carefully.'],
            ['num'=>'3','title'=>'Activate / Deactivate','desc'=>'Use the Activate/Deactivate button on a user row to enable or disable access. Deactivated users cannot log in.'],
            ['num'=>'4','title'=>'Reset Password','desc'=>'If a user is locked out, use "Reset Password" on their row. A new temporary password is sent to their email.'],
        ],
    ],
    [
        'icon'  => 'bi-files',
        'color' => '#475569',
        'bg'    => '#f8fafc',
        'title' => 'Reviewing Applications',
        'steps' => [
            ['num'=>'1','title'=>'All Applications','desc'=>'Go to All Applications to see every application in the system. Filter by status, campus, or search by name. Click an application to open the review page.'],
            ['num'=>'2','title'=>'Admin Actions','desc'=>'As admin you can view scores and evidence for any application. You can also make final approval or rejection decisions after the checker stage.'],
            ['num'=>'3','title'=>'Reports','desc'=>'Use the Admin Report and OSS (Overall Score Summary) pages to generate PDF reports of all faculty scores, filterable by cycle, campus, and status.'],
        ],
    ],
    [
        'icon'  => 'bi-gear',
        'color' => '#334155',
        'bg'    => '#f8fafc',
        'title' => 'Configuration & Audit',
        'steps' => [
            ['num'=>'1','title'=>'Scoring Configuration','desc'=>'Go to Cycles → Manage → Scoring Criteria to view and edit point values for each KRA criterion. Changes are audit-logged.'],
            ['num'=>'2','title'=>'Campus Management','desc'=>'Go to Campuses to add, rename, activate, or deactivate campuses. Deactivated campuses cannot receive new registrations.'],
            ['num'=>'3','title'=>'Audit Log','desc'=>'Go to Audit Log to view a full timestamped history of all user actions across the system — logins, edits, submissions, score changes, and admin actions.'],
        ],
    ],
];

// ── Select guides based on role ───────────────────────────────
if ($role === 'admin') {
    $built_in_guides = $admin_guides;
} elseif ($role === 'talisay_checker') {
    $built_in_guides = $talisay_guides;
} elseif ($role === 'checker') {
    $built_in_guides = $checker_guides;
} else {
    // faculty + any fallback
    $built_in_guides = $faculty_guides;
}
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
            <p style="margin:0;color:#64748b;font-size:0.82rem;">
                Step-by-step guides for <strong><?= htmlspecialchars($role_label) ?></strong>
            </p>
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

    <!-- Guide cards -->
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
            <div style="margin-left:13px;width:1px;height:12px;background:<?= $g['color'] ?>33;"></div>
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
