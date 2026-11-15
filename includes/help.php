<?php
// Help page  -  role-based guides
$role = $_SESSION['role'] ?? 'faculty';
$role_labels = ['faculty'=>'Faculty','evaluator'=>'Evaluator','admin'=>'Administrator'];
$role_label  = $role_labels[$role] ?? 'User';

$articles = [];
try {
    $stmt = $pdo->prepare("SELECT article_id, title, content FROM help_articles WHERE category='help' AND is_published=1 ORDER BY article_id ASC");
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

// ── Faculty guides ──────────────────────────────────────────────
$faculty_guides = [
    [
        'id'    => 'getting-started',
        'icon'  => 'bi-house-door',
        'title' => 'Getting Started',
        'intro' => 'Everything you need to know to access the system and understand your workspace.',
        'steps' => [
            ['title'=>'Log In',
             'desc' =>'Go to the login page and enter your registered email and password. If this is your first login, use the temporary password your administrator sent and change it immediately from your Profile page.'],
            ['title'=>'Complete Your Profile',
             'desc' =>'On first login, you will be guided through a profile wizard — fill in your employee ID, campus, faculty rank, educational attainment, and employment history. This information is locked after completion; contact your administrator for corrections.'],
            ['title'=>'Understand Your Dashboard',
             'desc' =>'After logging in, the Faculty Portal shows your application status, the active reclassification cycle countdown, and quick-access links. Check the notification banner at the top for any important alerts from your evaluator.'],
            ['title'=>'Know the Reclassification Cycle',
             'desc' =>'Submission is only possible during an open cycle set by your administrator. Outside a cycle, use the Self-Assessment tool to prepare. When a cycle opens, the countdown and deadline appear on your portal.'],
        ],
    ],
    [
        'id'    => 'self-assessment',
        'icon'  => 'bi-calculator',
        'title' => 'Self-Assessment',
        'intro' => 'Use Self-Assessment to estimate your KRA scores and prepare evidence before a cycle opens. Nothing here is official — it is a preparation tool only.',
        'steps' => [
            ['title'=>'How scoring works',
             'desc' =>'Your weighted score is calculated from four KRA areas. Each area has a weight percentage based on your faculty rank (e.g. Instruction carries 40% for Associate Professors). The formula is: Weighted Score = (KRA I × weight) + (KRA II × weight) + (KRA III × weight) + (KRA IV × weight). You need at least 41.00 weighted points to be eligible for reclassification.'],
            ['title'=>'Add KRA entries',
             'desc' =>'Click each KRA tab (Instruction, Research, Extension, Professional Development) and add entries using the form. Select the appropriate criterion, fill in the sub-fields, and save. Your estimated score updates automatically after each entry.'],
            ['title'=>'Auto Sub Rank tab',
             'desc' =>'If you hold a Doctorate Degree that has not yet been used for a previous reclassification, or if you have received a prestigious national/international award (Ramon Magsaysay-level), declare it in this tab. A qualifying doctorate grants +1 sub-rank on top of your score. Awards also grant +1 but require evaluator confirmation.'],
            ['title'=>'Position Requirements tab',
             'desc' =>'Some ranks require additional documents (e.g. a CAV Transcript of Records for Professor ranks). This tab lists what is needed for your rank. Prepare these before the cycle opens — missing requirements can delay evaluation.'],
            ['title'=>'Analytics tab',
             'desc' =>'The Analytics tab shows your score summary, KRA breakdown, rank projection, and what to focus on next. Use it to understand exactly where you stand and what additional entries would have the biggest impact on your score.'],
            ['title'=>'Evidence Repository',
             'desc' =>'Upload and organise your evidence files (PDF only) at any time using the Evidence Repository button at the top. Files are stored by KRA category. You can rename, move, or delete files. Attach them to entries during application submission.'],
        ],
    ],
    [
        'id'    => 'applying',
        'icon'  => 'bi-file-earmark-person',
        'title' => 'Submitting a Reclassification Application',
        'intro' => 'When a cycle is open and your score meets the minimum, you can submit your official application.',
        'steps' => [
            ['title'=>'Open KRA Entry',
             'desc' =>'Click "Reclassification" from your portal or "Apply / KRA Entry" from the sidebar. Your entries from Self-Assessment do NOT carry over — you must add entries here separately. This is the official record that evaluators will review.'],
            ['title'=>'Add entries and upload evidence',
             'desc' =>'For each KRA tab, add entries, fill all required fields, and upload evidence files (PDF, max 50 MB each). Evidence files are required — entries without attached files will be flagged by your evaluator for revision.'],
            ['title'=>'Check the score threshold',
             'desc' =>'Your weighted score is shown at the top of the page. You must reach at least 41.00 before you can submit. If your score is below 41.00, you cannot submit and need to add more entries.'],
            ['title'=>'Submit your application',
             'desc' =>'When your score meets the minimum and evidence is uploaded, click "Submit Application." Your status changes to Submitted and your evaluator is automatically notified. You cannot edit your application after submission unless an evaluator returns it for revision.'],
        ],
    ],
    [
        'id'    => 'revisions',
        'icon'  => 'bi-arrow-counterclockwise',
        'title' => 'Handling Revision Requests',
        'intro' => 'If your evaluator flags entries for revision, here is exactly what to do.',
        'steps' => [
            ['title'=>'Understand revision mode',
             'desc' =>'When an evaluator flags one or more KRA entries, your application status changes to "Needs Revision." A notification appears on your Dashboard. Only the flagged entries can be edited — all other entries are locked to prevent changes to already-reviewed work.'],
            ['title'=>'Read the evaluator\'s note',
             'desc' =>'Open your application. Flagged entries are highlighted with an amber indicator. The evaluator\'s revision note appears below the entry explaining exactly what needs to be corrected (e.g. "Evidence file is unclear — please upload a higher resolution scan").'],
            ['title'=>'Correct and re-upload',
             'desc' =>'Edit the flagged entry, correct the information, and upload a new or replacement evidence file. You can also file an Appeal from the Appeals section if you disagree with the evaluator\'s flag.'],
            ['title'=>'Resubmit',
             'desc' =>'After fixing all flagged entries, click "Submit Revision." Your application returns to the evaluator. They will review your corrections and either mark them acceptable or flag them again.'],
        ],
    ],
    [
        'id'    => 'appeals',
        'icon'  => 'bi-chat-square-text',
        'title' => 'Filing an Appeal',
        'intro' => 'If you believe an evaluator\'s score adjustment or revision flag is incorrect, you can file an appeal.',
        'steps' => [
            ['title'=>'When to appeal',
             'desc' =>'File an appeal if an evaluator changed your score in a way you believe is inaccurate, or flagged an entry for revision that you believe meets the criterion. Appeals are for factual disputes — not for requesting higher scores without justification.'],
            ['title'=>'Open an appeal',
             'desc' =>'Go to Appeals in the sidebar and click "New Appeal." Select the flagged entry or score change you are disputing, state your reason clearly, and optionally attach a supporting document (e.g. a clearer scan of your evidence file).'],
            ['title'=>'Appeal conversation',
             'desc' =>'Your evaluator can reply directly in the appeal thread. You can add follow-up messages and attachments. The appeal is resolved when the evaluator marks it Upheld (your score/entry is restored), Revised (a middle ground is agreed), or Dismissed (the original decision stands).'],
        ],
    ],
    [
        'id'    => 'account',
        'icon'  => 'bi-person-gear',
        'title' => 'Managing Your Account',
        'intro' => 'Keep your account information accurate — it directly affects what appears on your official application.',
        'steps' => [
            ['title'=>'Update profile information',
             'desc' =>'Go to Profile in the sidebar to update your name and email. Note that employee ID, campus, and faculty rank are locked after profile completion — contact your administrator if any of these need correction.'],
            ['title'=>'Change your password',
             'desc' =>'In Profile, go to the "Change Password" tab. Your password must be at least 8 characters, include uppercase and lowercase letters, a number, and a special character. Change any temporary password immediately.'],
            ['title'=>'View your activity log',
             'desc' =>'Go to "My Activity Log" to see a full timestamped history of your actions — logins, submissions, file uploads, edits, and any flagged entries. This is useful if you need to verify when something was submitted.'],
        ],
    ],
];

// ── Evaluator guides ────────────────────────────────────────────
$checker_guides = [
    [
        'id'    => 'evaluator-start',
        'icon'  => 'bi-house-door',
        'title' => 'Getting Started as an Evaluator',
        'intro' => 'Your role is to review faculty KRA submissions, verify evidence, and record evaluation decisions.',
        'steps' => [
            ['title'=>'Your Dashboard',
             'desc' =>'The Evaluator Dashboard shows four KPI cards: Awaiting Decision (new submissions you have not started), Active Reviews (in-progress), Needs Revision (flagged and waiting for faculty resubmission), and Total Completed. These update every 15 seconds.'],
            ['title'=>'Review Queue',
             'desc' =>'The Review Queue lists all applications assigned to your KRA categories. Use the filter tabs — "Awaiting My Decision" shows new ones, "Active Reviews" shows those you have started, and "My History" shows completed ones.'],
            ['title'=>'KRA assignment',
             'desc' =>'You are assigned to specific KRA categories per cycle by the administrator. You can only mark entries acceptable or flag entries for the KRAs assigned to you. Entries from other KRAs appear read-only.'],
        ],
    ],
    [
        'id'    => 'evaluator-review',
        'icon'  => 'bi-eye',
        'title' => 'Reviewing an Application',
        'intro' => 'Step-by-step process from opening an application to recording your decision.',
        'steps' => [
            ['title'=>'Open and start the review',
             'desc' =>'Click an application in your queue, then click "Start Review." This moves the application to Under Review status and notifies the faculty. You must start the review before you can take any action on individual entries.'],
            ['title'=>'Check evidence — eye button',
             'desc' =>'Each KRA entry row has an eye icon. Click it to open the Evidence & Action panel. You can preview evidence files inline (PDF renders in the panel; images display directly), or open them in a new tab using the ↗ icon. Review whether the file genuinely supports the criterion claimed.'],
            ['title'=>'Mark as Acceptable',
             'desc' =>'If the entry and its evidence are valid, click "Mark as Acceptable" in the Evidence & Action panel. You can add an optional note. Once all evaluators assigned to that KRA have marked it acceptable, the entry is fully verified.'],
            ['title'=>'Flag for Revision',
             'desc' =>'If the entry needs correction, click "Flag for Revision" in the panel. A reason field expands — you must enter a specific, clear explanation (e.g. "Evidence is a photocopy and unreadable — please upload the original scan"). The faculty receives a notification.'],
            ['title'=>'Adjust a score',
             'desc' =>'If the computed score for an entry needs correction, click the pencil icon next to the score in the Raw Score column. Enter the correct value. If the resulting weighted score drops below 41, the application is automatically returned to the faculty.'],
        ],
    ],
    [
        'id'    => 'evaluator-complete',
        'icon'  => 'bi-check-circle',
        'title' => 'Completing or Returning',
        'intro' => 'How to finalise your evaluation decision.',
        'steps' => [
            ['title'=>'Complete Evaluation',
             'desc' =>'Once all evidence files in your assigned KRAs are marked acceptable and you are satisfied with the scores, click "Complete Evaluation" in the Evaluation Action section at the bottom of the review page. This records that your evaluation is done. Final reclassification decisions are made by the committee outside the system.'],
            ['title'=>'Return for Revision',
             'desc' =>'If the submission needs correction, click "Return for Revision" and enter a reason. The faculty is notified and can correct the flagged entries and resubmit. You can return at any point after starting the review, not just after marking all entries.'],
            ['title'=>'After completion',
             'desc' =>'Once you complete the evaluation, the application moves to Evaluation Complete status. Your decision and any remarks appear on the faculty\'s Application Status page. You can still view the application in "My History" but cannot make further changes.'],
        ],
    ],
];

// ── Admin guides ────────────────────────────────────────────────
$admin_guides = [
    [
        'id'    => 'admin-dashboard',
        'icon'  => 'bi-speedometer2',
        'title' => 'Admin Dashboard',
        'intro' => 'The Admin Dashboard gives you a real-time view of the entire reclassification pipeline.',
        'steps' => [
            ['title'=>'KPI cards',
             'desc' =>'Six cards show: Total Faculty registered, Total Submitted, Awaiting Evaluator (submitted but not yet started), Under Evaluation, Needs Revision, and Evaluation Complete. Cards update every 15 seconds via a background poll — no need to refresh the page.'],
            ['title'=>'Charts',
             'desc' =>'The 7-day submission trend shows daily application activity. The status donut shows the distribution across statuses. The KRA averages bar shows where faculty are scoring highest and lowest.'],
            ['title'=>'Campus breakdown table',
             'desc' =>'The table below the charts shows faculty count, applied count, pending, and evaluation-complete per campus — useful for tracking which campuses are lagging.'],
        ],
    ],
    [
        'id'    => 'admin-cycles',
        'icon'  => 'bi-arrow-repeat',
        'title' => 'Managing Cycles',
        'intro' => 'Cycles control when faculty can submit. Only one cycle can be Open at a time.',
        'steps' => [
            ['title'=>'Create a cycle',
             'desc' =>'Go to Cycles in the sidebar and click "Create Cycle." Enter the cycle name (e.g. "AY 2026-2027 Reclassification Cycle"), submission start date, submission deadline, and evaluation deadline. Save as Draft first.'],
            ['title'=>'Open a cycle',
             'desc' =>'Change the cycle status to Open. From this moment, faculty can submit applications. The countdown appears on all faculty portals. Only one cycle can be Open at a time — opening a new one while another is open will prompt a confirmation.'],
            ['title'=>'Scoring criteria',
             'desc' =>'Under Manage → Scoring Criteria, you can view and edit the point values for each KRA criterion per position rank. Changes take effect immediately for new score calculations. Changes are audit-logged.'],
            ['title'=>'Extensions',
             'desc' =>'You can extend the submission deadline for all faculty or individual faculty members from the cycle management page. Extensions are logged and visible to the affected faculty.'],
        ],
    ],
    [
        'id'    => 'admin-users',
        'icon'  => 'bi-people',
        'title' => 'Managing Users',
        'intro' => 'Faculty accounts are created by the administrator — faculty cannot self-register.',
        'steps' => [
            ['title'=>'Create a faculty account',
             'desc' =>'Go to Manage Users → Create Account. Enter the faculty member\'s name, email, and role (Faculty). The system generates a temporary password that is emailed to them. On first login, faculty complete the profile wizard (employee ID, campus, rank, education, employment).'],
            ['title'=>'Create evaluator accounts',
             'desc' =>'Same process as faculty but set role to Evaluator. Evaluator accounts are assigned anonymous labels (Evaluator #1, Evaluator #2) so faculty cannot identify who reviewed their application.'],
            ['title'=>'Assign KRA categories to evaluators',
             'desc' =>'Go to Manage Users → KRA Assignments. For each cycle, assign which evaluator covers which KRA categories (Instruction, Research, Extension, Professional Development). An evaluator can only act on their assigned KRAs.'],
            ['title'=>'Activate / Deactivate / Reset password',
             'desc' =>'Use the action buttons on each user row: Deactivate blocks login immediately. Activate restores access. Reset Password sends a new temporary password to their email. The admin cannot see existing passwords.'],
        ],
    ],
    [
        'id'    => 'admin-reports',
        'icon'  => 'bi-files',
        'title' => 'Reports & Audit',
        'intro' => 'Generate official reports and monitor all activity across the system.',
        'steps' => [
            ['title'=>'Admin Report',
             'desc' =>'Go to Admin Report for a filterable list of all faculty applications — filter by cycle, campus, and status. Export as PDF for committee distribution.'],
            ['title'=>'OSS Report (Overall Score Summary)',
             'desc' =>'The OSS Report generates the standard government Overall Score Summary sheet. It lists all faculty with their KRA subtotals, weighted scores, and recommended ranks, organised by campus. Filter by cycle before generating.'],
            ['title'=>'Individual ISS (per faculty)',
             'desc' =>'From any application review page, click "Print ISS" to generate the Individual Summary Sheet for a single faculty member — showing the full two-pass rank-weighted scoring calculation and recommended rank.'],
            ['title'=>'Audit Log',
             'desc' =>'Go to Audit Log to see a timestamped record of every action in the system — logins, score changes, submissions, file uploads, admin actions. Filter by user, role, or date range.'],
        ],
    ],
];

if ($role === 'admin')     $built_in_guides = $admin_guides;
elseif ($role === 'evaluator') $built_in_guides = $checker_guides;
else $built_in_guides = $faculty_guides;
?>

<style>
.help-wrap{display:grid;grid-template-columns:220px 1fr;gap:1.5rem;max-width:100%;margin:0;padding:.75rem 0 3rem;align-items:start;}
.help-nav{position:sticky;top:70px;border:1px solid #e2e8f0;background:#fff;max-height:calc(100vh - 80px);overflow-y:auto;}
.help-nav-head{font-size:.57rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;padding:.55rem .8rem .3rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;position:sticky;top:0;}
.help-nav-link{display:flex;align-items:center;gap:.5rem;padding:.42rem .8rem;text-decoration:none;font-size:.77rem;font-weight:600;color:#475569;border-left:3px solid transparent;transition:all .1s;}
.help-nav-link i{font-size:.74rem;flex-shrink:0;color:#94a3b8;}
.help-nav-link:hover{background:#f8fafc;color:#1a3a6b;border-left-color:#cbd5e1;}
.help-nav-link:hover i{color:#1a3a6b;}
.help-nav-link.active{background:#eff6ff;color:#1a3a6b;border-left-color:#1a3a6b;font-weight:700;}
.help-nav-link.active i{color:#1a3a6b;}
.help-section{scroll-margin-top:72px;border:1px solid #e2e8f0;margin-bottom:.75rem;}
.help-section-head{display:flex;align-items:center;gap:.55rem;padding:.6rem .9rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
.help-section-icon i{font-size:.8rem;color:#1a3a6b;}
.help-section-title{font-size:.85rem;font-weight:700;color:#0f172a;}
.help-section-intro{font-size:.73rem;color:#64748b;line-height:1.6;padding:.55rem .9rem;border-bottom:1px solid #e2e8f0;background:#fff;}
.help-step{display:flex;border-bottom:1px solid #f1f5f9;}
.help-step:last-child{border-bottom:none;}
.help-step:hover{background:#fafbfd;}
.help-step-num{width:36px;flex-shrink:0;display:flex;align-items:flex-start;justify-content:center;padding-top:.65rem;border-right:1px solid #f1f5f9;background:#fafafa;}
.help-step-num span{width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:.57rem;font-weight:800;display:flex;align-items:center;justify-content:center;}
.help-step-body{flex:1;padding:.62rem .85rem;}
.help-step-title{font-size:.79rem;font-weight:700;color:#1e293b;margin-bottom:2px;}
.help-step-desc{font-size:.73rem;color:#475569;line-height:1.58;}
@media(max-width:700px){.help-wrap{grid-template-columns:1fr;}.help-nav{position:static;max-height:none;}}
</style>

<div class="help-wrap">

    <!-- Sidebar -->
    <div class="help-nav">
        <div class="help-nav-head">Contents</div>
        <?php foreach ($built_in_guides as $gi => $g): ?>
        <a href="#help-<?= htmlspecialchars($g['id']) ?>" class="help-nav-link <?= $gi===0?'active':'' ?>"
           onclick="document.querySelectorAll('.help-nav-link').forEach(l=>l.classList.remove('active'));this.classList.add('active');">
            <i class="bi <?= $g['icon'] ?>"></i>
            <?= htmlspecialchars($g['title']) ?>
        </a>
        <?php endforeach; ?>
        <div style="border-top:1px solid #e2e8f0;padding:.6rem .8rem;background:#f8fafc;">
            <div style="font-size:.7rem;font-weight:700;color:#1a3a6b;margin-bottom:.25rem;">Need more help?</div>
            <div style="font-size:.7rem;color:#64748b;line-height:1.5;margin-bottom:.5rem;">Contact your campus administrator or send feedback.</div>
            <a href="#" onclick="openFeedbackModal();return false;"
               style="display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:700;color:#1a3a6b;text-decoration:none;padding:.28rem .65rem;border:1px solid #1a3a6b;background:#fff;"
               onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'"
               onmouseout="this.style.background='#fff';this.style.color='#1a3a6b'">
                <i class="bi bi-chat-right-text"></i> Send Feedback
            </a>
        </div>
    </div>

    <!-- Content -->
    <div>
        <div style="margin-bottom:.85rem;">
            <div style="font-size:1rem;font-weight:700;color:#0f172a;">Help Center</div>
            <div style="font-size:.74rem;color:#64748b;margin-top:2px;">
                Step-by-step guides for <strong style="color:#1a3a6b;"><?= htmlspecialchars($role_label) ?></strong>
            </div>
        </div>

        <?php foreach ($built_in_guides as $gi => $g): ?>
        <div class="help-section" id="help-<?= htmlspecialchars($g['id']) ?>">
            <div class="help-section-head">
                <i class="bi <?= $g['icon'] ?> help-section-icon"></i>
                <span class="help-section-title"><?= htmlspecialchars($g['title']) ?></span>
            </div>
            <div class="help-section-intro"><?= htmlspecialchars($g['intro']) ?></div>
            <?php foreach ($g['steps'] as $si => $step): ?>
            <div class="help-step">
                <div class="help-step-num"><span><?= $si + 1 ?></span></div>
                <div class="help-step-body">
                    <div class="help-step-title"><?= htmlspecialchars($step['title']) ?></div>
                    <div class="help-step-desc"><?= htmlspecialchars($step['desc']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($articles)): ?>
        <div style="border:1px solid #e2e8f0;margin-top:.75rem;">
            <div style="font-size:.57rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;padding:.5rem .9rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">Additional Articles</div>
            <div class="accordion" id="helpAccordion">
                <?php foreach ($articles as $i => $art): ?>
                <div style="border-bottom:1px solid #e2e8f0;">
                    <button class="accordion-button <?= $i>0?'collapsed':'' ?> fw-semibold" type="button"
                            data-bs-toggle="collapse" data-bs-target="#helpArt<?= $art['article_id'] ?>"
                            style="font-size:.8rem;color:#1a3a6b;background:#fff;padding:.55rem .9rem;width:100%;text-align:left;border:none;">
                        <?= htmlspecialchars($art['title']) ?>
                    </button>
                    <div id="helpArt<?= $art['article_id'] ?>" class="accordion-collapse collapse <?= $i===0?'show':'' ?>" data-bs-parent="#helpAccordion">
                        <div style="padding:.65rem .9rem;font-size:.78rem;color:#334155;white-space:pre-line;line-height:1.65;border-top:1px solid #f1f5f9;">
                            <?= htmlspecialchars($art['content']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Highlight active nav link on scroll
(function(){
    const links = document.querySelectorAll('.help-nav-link');
    const sections = document.querySelectorAll('.help-section');
    if(!links.length||!sections.length) return;
    const obs = new IntersectionObserver(entries=>{
        entries.forEach(e=>{
            if(e.isIntersecting){
                links.forEach(l=>l.classList.remove('active'));
                const active = document.querySelector('.help-nav-link[href="#'+e.target.id+'"]');
                if(active) active.classList.add('active');
            }
        });
    },{threshold:0.3,rootMargin:'-60px 0px -60% 0px'});
    sections.forEach(s=>obs.observe(s));
})();
</script>


