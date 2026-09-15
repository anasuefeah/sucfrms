<?php
/**
 * SUCFRMS — Data Dictionary Documentation
 * Standalone reference document for all database tables.
 * Access: http://localhost/SUCFRMS/pages/data_dictionary.php
 */
require_once __DIR__ . '/../config/db.php';
$generated = date('F d, Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SUCFRMS — Data Dictionary</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4fb;color:#1e293b;line-height:1.6;}
.cover{background:linear-gradient(135deg,#0f2952,#1a3a6b,#1e4d8c);color:#fff;padding:4rem 3rem;text-align:center;}
.cover h1{font-size:2rem;font-weight:800;letter-spacing:.02em;margin-bottom:.5rem;}
.cover p{font-size:1rem;color:rgba(255,255,255,.7);margin-bottom:.25rem;}
.cover .subtitle{font-size:.85rem;color:rgba(255,255,255,.5);margin-top:1rem;}
.toc{background:#fff;border-bottom:1px solid #e2e8f0;padding:1.5rem 3rem;}
.toc h2{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8;margin-bottom:.85rem;}
.toc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.35rem;}
.toc-item{display:flex;align-items:center;gap:.5rem;padding:.3rem .5rem;border-radius:6px;text-decoration:none;color:#475569;font-size:.82rem;transition:background .15s;}
.toc-item:hover{background:#f0f4fb;color:#1a3a6b;}
.toc-num{width:22px;height:22px;border-radius:50%;background:#1a3a6b;color:#fff;font-size:.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.container{max-width:1100px;margin:2rem auto;padding:0 2rem 4rem;}
.table-section{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);margin-bottom:2.5rem;overflow:hidden;}
.table-header{background:linear-gradient(135deg,#1a3a6b,#1e4d8c);padding:1.1rem 1.75rem;display:flex;align-items:center;gap:1rem;}
.table-num{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);color:#fff;font-size:.85rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.table-title{color:#fff;}
.table-title h2{font-size:1rem;font-weight:700;margin-bottom:2px;}
.table-title p{font-size:.75rem;color:rgba(255,255,255,.65);}
.table-body{padding:1.25rem 1.75rem;}
.field-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.field-table thead tr{background:#f8fafc;border-bottom:2px solid #e2e8f0;}
.field-table thead th{padding:.6rem .9rem;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#64748b;white-space:nowrap;}
.field-table tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
.field-table tbody tr:last-child{border-bottom:none;}
.field-table tbody tr:hover{background:#fafbff;}
.field-table td{padding:.6rem .9rem;vertical-align:top;}
.field-name{font-family:'Courier New',monospace;font-size:.8rem;font-weight:700;color:#1a3a6b;}
.field-type{font-family:'Courier New',monospace;font-size:.75rem;color:#475569;white-space:nowrap;}
.badge{display:inline-block;padding:1px 7px;border-radius:20px;font-size:.63rem;font-weight:700;margin-right:3px;white-space:nowrap;}
.pk{background:#dbeafe;color:#1e4d8c;}
.fk{background:#fef3c7;color:#92400e;}
.uq{background:#f3e8ff;color:#7e22ce;}
.nn{background:#f0fdf4;color:#16a34a;}
.au{background:#f1f5f9;color:#475569;}
.gen{background:#fff7ed;color:#c2410c;}
.desc{color:#475569;font-size:.8rem;line-height:1.5;}
.enum-vals{font-family:'Courier New',monospace;font-size:.72rem;color:#64748b;margin-top:3px;}
@media print{
  body{background:#fff;}
  .cover{background:#1a3a6b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .table-header{background:#1a3a6b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .toc{display:none;}
  .table-section{box-shadow:none;border:1px solid #e2e8f0;page-break-inside:avoid;}
}
</style>
</head>
<body>

<!-- Cover -->
<div class="cover">
  <img src="../assets/images/logo.jpg" alt="Logo" style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);margin-bottom:1.25rem;">
  <h1>SUCFRMS Data Dictionary</h1>
  <p>SUC Faculty Reclassification Management System</p>
  <p>State Universities and Colleges</p>
  <div class="subtitle">
    Database Reference Documentation &nbsp;·&nbsp; 18 Tables &nbsp;·&nbsp; Generated <?= $generated ?>
  </div>
  <div style="margin-top:1.5rem;">
    <button onclick="window.print()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.35);color:#fff;padding:.45rem 1.1rem;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;margin-right:.5rem;">🖨 Print / Save PDF</button>
  </div>
</div>

<!-- Table of Contents -->
<div class="toc">
  <h2>Table of Contents</h2>
  <div class="toc-grid">
    <?php
    $tables = [
      1=>'campuses',2=>'users',3=>'cycles',4=>'applications',
      5=>'kra_submissions',6=>'kra_evidence_files',7=>'kra_checker_verifications',
      8=>'scoring_criteria',9=>'application_checker_reviews',10=>'notifications',
      11=>'password_resets',12=>'audit_logs',13=>'pre_eval_entries',
      14=>'pre_eval_files',15=>'auto_sub_rank',16=>'position_requirements',
      17=>'help_articles',18=>'feedback_submissions',
    ];
    foreach ($tables as $n => $t): ?>
    <a class="toc-item" href="#t<?= $n ?>">
      <span class="toc-num"><?= $n ?></span>
      <span><?= $t ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="container">

<?php
// ── Helper ──────────────────────────────────────────────────────
function section(int $n, string $name, string $desc, array $fields): void { ?>
<div class="table-section" id="t<?= $n ?>">
  <div class="table-header">
    <div class="table-num"><?= $n ?></div>
    <div class="table-title">
      <h2><?= $name ?></h2>
      <p><?= $desc ?></p>
    </div>
  </div>
  <div class="table-body">
    <table class="field-table">
      <thead>
        <tr>
          <th style="width:165px;">Field</th>
          <th style="width:120px;">Data Type</th>
          <th style="width:160px;">Constraints</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as [$fname,$ftype,$fconst,$fdesc]): ?>
        <tr>
          <td><span class="field-name"><?= $fname ?></span></td>
          <td><span class="field-type"><?= $ftype ?></span></td>
          <td><?= $fconst ?></td>
          <td class="desc"><?= $fdesc ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }

function b(string $cls, string $txt): string {
    return '<span class="badge '.$cls.'">'.$txt.'</span>';
}
$PK = b('pk','PK'); $FK = b('fk','FK'); $UQ = b('uq','UQ');
$NN = b('nn','NOT NULL'); $AU = b('au','AUTO INC'); $GEN = b('gen','GENERATED');
$NUL = b('au','NULLABLE'); $DEF = b('nn','DEFAULT');

// ── 1. campuses ──────────────────────────────────────────────
section(1,'campuses','Stores all SUC campus locations in the system.',[
  ['campus_id',  'INT',         "$PK $AU",     'Unique campus identifier. Auto-incremented by the database.'],
  ['campus_name','VARCHAR(100)',"$NN $UQ",     'Official full name of the campus. Must be unique across the system.'],
  ['is_active',  'TINYINT(1)',  "$DEF 1",      'Availability flag. 1 = active, 0 = deactivated. Deactivated campuses still appear in faculty registration dropdowns but cannot be selected.'],
  ['created_at', 'TIMESTAMP',   "$DEF NOW()",  'Date and time the campus record was created.'],
]);

// ── 2. users ─────────────────────────────────────────────────
section(2,'users','Stores all system accounts regardless of role — faculty, checkers, and admin.',[
  ['user_id',    'INT',          "$PK $AU",         'Unique user identifier.'],
  ['first_name', 'VARCHAR(100)', "$NN",             'User\'s given name.'],
  ['middle_name','VARCHAR(100)', "$NUL",            'User\'s middle name. Null if not provided.'],
  ['last_name',  'VARCHAR(100)', "$NN",             'User\'s family name.'],
  ['full_name',  'VARCHAR(310)', "$GEN STORED",     'Auto-computed column. Formatted as "Last, First M." Used in display, PDFs, and notifications. Not editable directly.'],
  ['email',      'VARCHAR(150)', "$NN $UQ",         'Institutional email. Used as login username. Must be unique.'],
  ['password',   'VARCHAR(255)', "$NN",             'bcrypt-hashed password. Never stored in plaintext.'],
  ['role',       'ENUM',         "$NN $DEF faculty",'User access role. Values: <span class="enum-vals">faculty · checker · admin · checker_faculty · talisay_checker</span>'],
  ['status',     'ENUM',         "$DEF active",     'Account state. Values: <span class="enum-vals">active · inactive · rejected</span>'],
  ['campus_id',  'INT',          "$FK $NUL",        'FK → campuses.campus_id. The campus the user belongs to. SET NULL on campus delete.'],
  ['rank',       'VARCHAR(100)', "$NUL",            'Current faculty rank, e.g. "Associate Professor II". Used as the scoring baseline for weighted score calculation.'],
  ['employee_id','VARCHAR(50)',  "$UQ $NUL",        'Government-issued employee ID number. Must be unique if provided.'],
  ['profile_pic','VARCHAR(255)', "$NUL",            'Relative server path to the user\'s uploaded avatar image.'],
  ['created_at', 'TIMESTAMP',   "$DEF NOW()",      'Date and time the account was created.'],
]);

// ── 3. cycles ────────────────────────────────────────────────
section(3,'cycles','Stores reclassification evaluation cycles created by admin.',[
  ['cycle_id',           'INT',          "$PK $AU",        'Unique cycle identifier.'],
  ['cycle_name',         'VARCHAR(100)', "$NN",            'Descriptive name of the cycle, e.g. "AY 2025-2028 Reclassification Cycle".'],
  ['start_date',         'DATE',         "$NN",            'Official start date of the evaluation period.'],
  ['end_date',           'DATE',         "$NN",            'Official end date of the evaluation period.'],
  ['submission_deadline','DATE',         "$NUL",           'Last date faculty may submit applications. Faculty cannot add or edit entries after this date.'],
  ['status',             'ENUM',         "$DEF open",      'Current cycle state. Values: <span class="enum-vals">open · closed · archived</span>. Only one cycle can be open at a time.'],
  ['created_by',         'INT',          "$FK $NUL",       'FK → users.user_id. Admin who created this cycle. SET NULL if admin is deleted.'],
  ['created_at',         'TIMESTAMP',   "$DEF NOW()",     'Date and time the cycle was created.'],
]);

// ── 4. applications ──────────────────────────────────────────
section(4,'applications','Stores each faculty member\'s reclassification application per cycle.',[
  ['application_id',       'INT',          "$PK $AU",          'Unique application identifier.'],
  ['tracking_number',      'VARCHAR(30)',  "$UQ $NUL",         'System-generated reference code for official use.'],
  ['user_id',              'INT',          "$FK $NN",           'FK → users.user_id. Faculty who owns this application. CASCADE delete.'],
  ['cycle_id',             'INT',          "$FK $NUL",          'FK → cycles.cycle_id. The cycle this application belongs to. SET NULL if cycle deleted.'],
  ['status',               'ENUM',         "$DEF draft",        'Current workflow stage. Values: <span class="enum-vals">draft · submitted · under_review · talisay_review · approved · rejected · reclassified · admin_rejected · needs_revision</span>'],
  ['total_score',          'DECIMAL(7,2)', "$DEF 0",            'Sum of all raw KRA scores across all four categories.'],
  ['weighted_score',       'DECIMAL(7,2)', "$DEF 0",            'Final score after applying rank-based percentage weights. Must reach 41.00 to qualify for reclassification.'],
  ['sub_rank_increment',   'TINYINT',      "$DEF 0",            'Number of sub-rank steps earned. Determined by the weighted score bracket (0 to 6).'],
  ['potential_rank',       'VARCHAR(100)', "$NUL",              'Projected faculty rank after applying the sub-rank increment.'],
  ['checker_id',           'INT',          "$FK $NUL",          'FK → users.user_id. Primary campus checker assigned to review this application.'],
  ['checker_remarks',      'TEXT',         "$NUL",              'Overall comments from the checker on the final review decision.'],
  ['reviewed_at',          'TIMESTAMP',   "$NUL",              'Timestamp when the checker completed their review.'],
  ['submitted_at',         'TIMESTAMP',   "$NUL",              'Timestamp when the faculty officially submitted the application.'],
  ['position_title',       'VARCHAR(100)', "$NUL",              'Target faculty position being applied for in this cycle.'],
  ['salary_grade',         'VARCHAR(20)',  "$NUL",              'Salary grade corresponding to the target position.'],
  ['study_leave',          'ENUM',         "$DEF no",           'Whether the faculty member is currently on study leave. Values: <span class="enum-vals">yes · no</span>'],
  ['committee_route',      'VARCHAR(10)',  "$NUL",              'Review committee type determined by JC01 rules (IEC, REC, EAC, or CC).'],
  ['evaluation_period_ok', 'TINYINT(1)',  "$DEF 1",            'Flag indicating whether the application falls within a valid evaluation period per JC01.'],
  ['double_counting_flags','TEXT',         "$NUL",              'JSON array of entries detected as potentially double-counted across KRA categories.'],
  ['pending_documentation','TEXT',         "$NUL",              'JSON array of required documents that are missing from the application.'],
  ['config_incomplete',    'TEXT',         "$NUL",              'JSON array of scoring criteria that have not been fully configured by admin.'],
  ['orchestrator_flags',   'TEXT',         "$NUL",              'JSON array of system-level warnings generated during automated scoring validation.'],
  ['created_at',           'TIMESTAMP',   "$DEF NOW()",        'Date and time the application was first created.'],
  ['updated_at',           'TIMESTAMP',   "AUTO UPDATE",       'Date and time of the last modification to the application record.'],
]);

// ── 5. kra_submissions ───────────────────────────────────────
section(5,'kra_submissions','Stores individual KRA criterion entries within a reclassification application.',[
  ['submission_id',         'INT',          "$PK $AU",     'Unique KRA entry identifier.'],
  ['application_id',        'INT',          "$FK $NN",     'FK → applications.application_id. The application this entry belongs to. CASCADE delete.'],
  ['user_id',               'INT',          "$FK $NN",     'FK → users.user_id. Faculty who submitted this entry.'],
  ['kra_category',          'ENUM',         "$NN",         'KRA area this entry belongs to. Values: <span class="enum-vals">Instruction · Research · Extension · Professional Development</span>'],
  ['computed_points',       'DECIMAL(6,2)', "$DEF 0",      'Points automatically calculated by the JC01 scoring engine based on the remarks data.'],
  ['faculty_original_score','DECIMAL(6,2)', "$NUL",        'Snapshot of the score before any checker adjustment. Used in the Score Evaluation Comparison view.'],
  ['document_path',         'VARCHAR(255)', "$NUL",        'Legacy single-file path. Superseded by the kra_evidence_files table for multi-file support.'],
  ['remarks',               'TEXT',         "$NUL",        'Structured entry data in pipe-delimited format: type|||value1|||value2. Encodes the criterion type and all input values needed for scoring.'],
  ['verified',              'TINYINT(1)',   "$DEF 0",      '1 = all active campus checkers have individually verified this entry. Set automatically when all checkers verify.'],
  ['verified_by',           'INT',          "$FK $NUL",    'FK → users.user_id. The last checker who triggered the global verified flag.'],
  ['verified_at',           'TIMESTAMP',   "$NUL",        'Timestamp when the global verification was completed.'],
  ['revision_status',       'ENUM',         "$DEF ok",     'Revision state of this entry. Values: <span class="enum-vals">ok · needs_revision</span>'],
  ['revision_note',         'TEXT',         "$NUL",        'Checker\'s explanation of what must be corrected for this specific entry.'],
  ['revision_by',           'INT',          "$FK $NUL",    'FK → users.user_id. Checker who flagged this entry for revision.'],
  ['revision_at',           'TIMESTAMP',   "$NUL",        'Timestamp when the revision flag was set.'],
  ['submitted_at',          'TIMESTAMP',   "$DEF NOW()",  'Timestamp when this entry was saved.'],
]);

// ── 6. kra_evidence_files ────────────────────────────────────
section(6,'kra_evidence_files','Stores multiple evidence files attached to a single KRA submission.',[
  ['evidence_id',      'INT',          "$PK $AU",    'Unique evidence file record identifier.'],
  ['submission_id',    'INT',          "$FK $NN",    'FK → kra_submissions.submission_id. The entry this file is attached to. CASCADE delete.'],
  ['file_path',        'VARCHAR(255)', "$NN",        'Relative server path to the uploaded file, e.g. uploads/kra1/kra_abc123.pdf.'],
  ['original_filename','VARCHAR(255)', "$NN",        'Original filename as uploaded by the faculty member.'],
  ['file_size_bytes',  'INT',          "$DEF 0",     'File size in bytes at time of upload.'],
  ['uploaded_at',      'TIMESTAMP',   "$DEF NOW()", 'Date and time the file was uploaded.'],
  ['uploaded_by',      'INT',          "$FK $NUL",   'FK → users.user_id. User who uploaded the file. SET NULL if user deleted.'],
]);

// ── 7. kra_checker_verifications ─────────────────────────────
section(7,'kra_checker_verifications','Tracks each checker\'s individual verification of a KRA submission. Enables per-checker verification tracking so that a submission is only marked globally verified once all active checkers have verified it.',[
  ['ckv_id',       'INT',       "$PK $AU",    'Unique verification record identifier.'],
  ['submission_id','INT',       "$FK $NN",    'FK → kra_submissions.submission_id. The KRA entry being verified. CASCADE delete.'],
  ['checker_id',   'INT',       "$FK $NN",    'FK → users.user_id. The checker performing the verification. CASCADE delete.'],
  ['verified_at',  'TIMESTAMP',"$DEF NOW()", 'Timestamp when this checker verified the entry.'],
  ['—',            '—',         "UNIQUE (submission_id, checker_id)", 'Composite unique constraint — each checker can verify each submission only once.'],
]);

// ── 8. scoring_criteria ──────────────────────────────────────
section(8,'scoring_criteria','Stores point values, weights, and evidence requirements for every KRA criterion. Criteria can be global defaults or scoped to a specific cycle and faculty position rank.',[
  ['criteria_id',    'INT',           "$PK $AU",      'Unique criterion record identifier.'],
  ['cycle_id',       'INT',           "$FK $NUL",     'FK → cycles.cycle_id. NULL = global default applicable to all cycles. Set = specific to one cycle only.'],
  ['position_rank',  'VARCHAR(100)',  "$NUL",          'Target faculty rank this criterion applies to. NULL = applies to all ranks.'],
  ['kra_category',   'ENUM',          "$NN",           'KRA area. Values: <span class="enum-vals">Instruction · Research · Extension · Professional Development</span>'],
  ['criterion_key',  'VARCHAR(100)',  "$NN",           'Internal code used by the scoring engine, e.g. kra1_a_set. Unique within cycle + position scope.'],
  ['criterion_label','VARCHAR(255)',  "$NN",           'Human-readable label shown to faculty and checkers, e.g. "Criterion A – Student Evaluation of Teaching (SET)".'],
  ['max_points',     'DECIMAL(6,2)', "$NN",           'Maximum points this criterion can award per entry.'],
  ['weight_pct',     'DECIMAL(5,2)', "$DEF 100",      'Percentage weight of this criterion within its category (100 = full weight).'],
  ['description',    'TEXT',          "$NUL",          'Required evidence documents for this criterion as specified in JC01.'],
  ['is_active',      'TINYINT(1)',    "$DEF 1",        '1 = used in scoring, 0 = excluded from scoring calculations.'],
  ['updated_by',     'INT',           "$FK $NUL",      'FK → users.user_id. Admin who last modified this criterion.'],
  ['updated_at',     'TIMESTAMP',    "AUTO UPDATE",   'Timestamp of last modification.'],
  ['—',              '—',             "UNIQUE (cycle_id, position_rank, criterion_key)", 'Composite unique constraint — no duplicate criteria per scope.'],
]);

// ── 9. application_checker_reviews ───────────────────────────
section(9,'application_checker_reviews','Records each campus checker\'s individual decision on an application. Supports multi-checker approval — all active checkers must approve before an application advances.',[
  ['review_id',      'INT',       "$PK $AU",    'Unique review decision record identifier.'],
  ['application_id', 'INT',       "$FK $NN",    'FK → applications.application_id. The application under review. CASCADE delete.'],
  ['checker_id',     'INT',       "$FK $NN",    'FK → users.user_id. The checker making the decision. CASCADE delete.'],
  ['decision',       'ENUM',      "$DEF pending",'Current decision state. Values: <span class="enum-vals">pending · approved · rejected</span>'],
  ['remarks',        'TEXT',      "$NUL",       'Checker\'s comments accompanying the decision.'],
  ['decided_at',     'TIMESTAMP',"$NUL",       'Timestamp when the checker recorded their final decision.'],
  ['created_at',     'TIMESTAMP',"$DEF NOW()", 'Timestamp when the checker first joined the review panel.'],
  ['—',              '—',         "UNIQUE (application_id, checker_id)", 'Composite unique constraint — one decision per checker per application.'],
]);

// ── 10. notifications ────────────────────────────────────────
section(10,'notifications','Stores in-app bell notifications delivered to users based on workflow events. Each role sees only the notification types relevant to them.',[
  ['notif_id',      'INT',         "$PK $AU",    'Unique notification record identifier.'],
  ['user_id',       'INT',         "$FK $NN",    'FK → users.user_id. The user who receives this notification. CASCADE delete.'],
  ['type',          'VARCHAR(60)', "$NN",        'Event type that determines routing and display icon. Values: <span class="enum-vals">new_submission · under_review · score_adjusted · needs_revision · revision_resubmitted · approved · rejected · talisay_review · new_talisay_submission</span>'],
  ['message',       'TEXT',        "$NN",        'Human-readable notification message shown in the bell dropdown.'],
  ['application_id','INT',         "$FK $NUL",   'FK → applications.application_id. The related application. SET NULL if application deleted.'],
  ['is_read',       'TINYINT(1)', "$DEF 0",     '0 = unread (shown in badge count), 1 = read.'],
  ['created_at',    'TIMESTAMP',  "$DEF NOW()", 'Timestamp when the notification was generated.'],
]);

// ── 11. password_resets ──────────────────────────────────────
section(11,'password_resets','Manages temporary passwords for new accounts and OTP codes for the forgot password workflow. One record per user.',[
  ['reset_id',      'INT',        "$PK $AU",     'Unique reset record identifier.'],
  ['user_id',       'INT',        "$FK $NN $UQ", 'FK → users.user_id. One-to-one with users. CASCADE delete.'],
  ['temp_password', 'VARCHAR(20)',"$NUL",        'Plaintext temporary password sent via email at registration. Cleared after the user changes it.'],
  ['otp_code',      'VARCHAR(6)', "$NUL",        'Six-digit OTP code for the forgot password flow.'],
  ['otp_expires_at','DATETIME',   "$NUL",        'Expiry datetime of the OTP. OTPs are valid for 15 minutes.'],
  ['status',        'ENUM',       "$DEF pending",'Reset lifecycle state. Values: <span class="enum-vals">pending · released · verified</span>'],
  ['requested_at',  'TIMESTAMP', "$DEF NOW()",  'Timestamp when the reset was initiated.'],
  ['released_at',   'TIMESTAMP', "$NUL",        'Timestamp when the temporary password was emailed to the user.'],
]);

// ── 12. audit_logs ───────────────────────────────────────────
section(12,'audit_logs','Records every significant user action for accountability and traceability. Cannot be edited or deleted through the application interface.',[
  ['log_id',           'INT',          "$PK $AU",    'Unique audit entry identifier.'],
  ['user_id',          'INT',          "$FK $NUL",   'FK → users.user_id. The user who performed the action. SET NULL if user deleted.'],
  ['role_at_time',     'VARCHAR(20)',  "$NUL",       'The role of the user at the exact moment the action was performed. Preserved even if the role changes later.'],
  ['action_performed', 'VARCHAR(255)', "$NN",        'Short label identifying the type of action, e.g. "Application Submitted", "Score Adjusted".'],
  ['details',          'TEXT',         "$NUL",       'Additional context such as old and new values, affected IDs, or relevant remarks.'],
  ['timestamp',        'TIMESTAMP',   "$DEF NOW()", 'Date and time the action was recorded.'],
]);

// ── 13. pre_eval_entries ─────────────────────────────────────
section(13,'pre_eval_entries','Stores faculty self-assessment KRA entries for pre-evaluation — a practice scoring tool available before a cycle opens. Uses the same scoring logic as formal applications but is not linked to any cycle.',[
  ['entry_id',        'INT',          "$PK $AU",    'Unique pre-evaluation entry identifier.'],
  ['user_id',         'INT',          "$FK $NN",    'FK → users.user_id. Faculty who created this entry. CASCADE delete.'],
  ['kra_category',    'ENUM',         "$NN",        'KRA area. Values: <span class="enum-vals">Instruction · Research · Extension · Professional Development</span>'],
  ['remarks',         'TEXT',         "$NN",        'Structured data in the same pipe-delimited format as kra_submissions.remarks. Processed by the same scoring engine.'],
  ['computed_points', 'DECIMAL(6,2)', "$DEF 0",     'Score computed using the official JC01 scoring formula. For reference only.'],
  ['notes',           'TEXT',         "$NUL",       'Optional personal notes the faculty can attach to an entry.'],
  ['created_at',      'TIMESTAMP',   "$DEF NOW()", 'Timestamp when the entry was first created.'],
  ['updated_at',      'TIMESTAMP',   "AUTO UPDATE",'Timestamp of last modification.'],
]);

// ── 14. pre_eval_files ───────────────────────────────────────
section(14,'pre_eval_files','Stores evidence files attached to pre-evaluation entries. Separate from formal application evidence.',[
  ['file_id',         'INT',          "$PK $AU",    'Unique file record identifier.'],
  ['user_id',         'INT',          "$FK $NN",    'FK → users.user_id. Faculty who uploaded the file. CASCADE delete.'],
  ['entry_id',        'INT',          "$FK $NUL",   'FK → pre_eval_entries.entry_id. The entry this file is attached to. SET NULL if entry deleted.'],
  ['kra_category',    'ENUM',         "$NN",        'KRA area this file relates to.'],
  ['file_path',       'VARCHAR(255)', "$NN",        'Relative server path to the file, e.g. uploads/pre_eval/kra1/pe_abc123.pdf.'],
  ['original_filename','VARCHAR(255)',"$NN",        'Original filename as uploaded.'],
  ['file_size_bytes', 'INT',          "$DEF 0",     'File size in bytes.'],
  ['description',     'VARCHAR(255)', "$NUL",       'Optional label or note describing what this file represents.'],
  ['uploaded_at',     'TIMESTAMP',   "$DEF NOW()", 'Timestamp when the file was uploaded.'],
]);

// ── 15. auto_sub_rank ────────────────────────────────────────
section(15,'auto_sub_rank','Tracks automatic sub-rank increase triggers for each application. A doctorate degree or a prestigious national/international award can each add one additional sub-rank increment beyond the weighted score bracket.',[
  ['auto_rank_id',     'INT',          "$PK $AU",     'Unique record identifier.'],
  ['application_id',   'INT',          "$FK $NN",     'FK → applications.application_id. One record per application. CASCADE delete.'],
  ['doctorate_status', 'ENUM',         "$DEF Needs Review", 'Doctorate trigger evaluation. Values: <span class="enum-vals">Triggered · Not Triggered · Needs Review</span>'],
  ['award_status',     'ENUM',         "$DEF Needs Review", 'Prestigious award trigger evaluation. Values: <span class="enum-vals">Triggered · Not Triggered · Needs Review</span>'],
  ['doctorate_details','TEXT',         "$NUL",        'Source description of the doctorate record found in Professional Development KRA entries.'],
  ['award_details',    'TEXT',         "$NUL",        'Description of the prestigious award being claimed as a trigger.'],
  ['award_evidence',   'VARCHAR(255)', "$NUL",        'Path to uploaded evidence file supporting the award claim.'],
  ['created_at',       'TIMESTAMP',   "$DEF NOW()",  'Timestamp when the record was created.'],
  ['updated_at',       'TIMESTAMP',   "AUTO UPDATE", 'Timestamp of last modification.'],
]);

// ── 16. position_requirements ────────────────────────────────
section(16,'position_requirements','Tracks whether position-specific required documents have been uploaded. Required for Professor rank (CAV transcript + indexed article) and University Professor rank (certification form).',[
  ['pos_req_id',          'INT',          "$PK $AU",     'Unique record identifier.'],
  ['application_id',      'INT',          "$FK $NN",     'FK → applications.application_id. One record per application. CASCADE delete.'],
  ['cav_transcript',      'ENUM',         "$DEF Missing",'Status of CAV Transcript of Records. Values: <span class="enum-vals">Uploaded · Missing</span>'],
  ['cav_file',            'VARCHAR(255)', "$NUL",        'Server path to the uploaded CAV transcript file.'],
  ['international_article','ENUM',        "$DEF Missing",'Status of the internationally indexed journal article. Values: <span class="enum-vals">Uploaded · Missing</span>'],
  ['article_file',        'VARCHAR(255)', "$NUL",        'Server path to the uploaded journal article file.'],
  ['certification_form',  'ENUM',         "$DEF Missing",'Status of the University Professor certification form. Values: <span class="enum-vals">Uploaded · Missing</span>'],
  ['cert_file',           'VARCHAR(255)', "$NUL",        'Server path to the uploaded certification form file.'],
  ['created_at',          'TIMESTAMP',   "$DEF NOW()",  'Timestamp when the record was created.'],
  ['updated_at',          'TIMESTAMP',   "AUTO UPDATE", 'Timestamp of last modification.'],
]);

// ── 17. help_articles ────────────────────────────────────────
section(17,'help_articles','Stores help documentation and system release notes displayed to all users through the Help and What\'s New menu items.',[
  ['article_id',  'INT',          "$PK $AU",     'Unique article identifier.'],
  ['title',       'VARCHAR(255)', "$NN",         'Title of the help article or release note entry.'],
  ['content',     'TEXT',         "$NN",         'Full article content in plain text format.'],
  ['category',    'ENUM',         "$DEF help",   'Content type. Values: <span class="enum-vals">help · whats_new</span>'],
  ['is_published','TINYINT(1)',   "$DEF 1",      '1 = visible to all users, 0 = draft/hidden from users.'],
  ['created_by',  'INT',          "$FK $NUL",    'FK → users.user_id. Admin who authored the article. SET NULL if admin deleted.'],
  ['created_at',  'TIMESTAMP',   "$DEF NOW()",  'Timestamp when the article was published.'],
  ['updated_at',  'TIMESTAMP',   "AUTO UPDATE", 'Timestamp of last edit.'],
]);

// ── 18. feedback_submissions ─────────────────────────────────
section(18,'feedback_submissions','Stores feedback messages submitted by users. Supports both authenticated submissions (linked user) and anonymous submissions (contact email only).',[
  ['feedback_id',  'INT',           "$PK $AU",     'Unique feedback record identifier.'],
  ['user_id',      'INT',           "$FK $NUL",    'FK → users.user_id. The authenticated user who submitted feedback. NULL for anonymous submissions. SET NULL if user deleted.'],
  ['contact_email','VARCHAR(150)',  "$NUL",        'Email address provided by an anonymous submitter for follow-up.'],
  ['subject',      'VARCHAR(255)',  "$NUL",        'Brief subject line summarizing the feedback topic.'],
  ['message',      'TEXT',          "$NN",         'Full feedback message body.'],
  ['rating',       'TINYINT UNSIGNED',"$NUL",      'Optional star rating from 1 (lowest) to 5 (highest).'],
  ['status',       'ENUM',          "$DEF new",    'Admin processing state. Values: <span class="enum-vals">new · read · resolved</span>'],
  ['submitted_at', 'TIMESTAMP',    "$DEF NOW()",  'Timestamp when the feedback was submitted.'],
  ['resolved_by',  'INT',           "$FK $NUL",    'FK → users.user_id. Admin who marked the feedback as resolved.'],
  ['resolved_at',  'TIMESTAMP',    "$NUL",        'Timestamp when the feedback was resolved.'],
]);
?>

<!-- Legend -->
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);padding:1.25rem 1.75rem;margin-top:1rem;">
  <h3 style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:.85rem;">Constraint Legend</h3>
  <div style="display:flex;flex-wrap:wrap;gap:.6rem;font-size:.8rem;">
    <span><?= b('pk','PK') ?> Primary Key</span>
    <span><?= b('fk','FK') ?> Foreign Key</span>
    <span><?= b('uq','UQ') ?> Unique</span>
    <span><?= b('nn','NOT NULL') ?> Not Nullable</span>
    <span><?= b('au','AUTO INC') ?> Auto Increment</span>
    <span><?= b('gen','GENERATED') ?> Computed Column</span>
    <span><?= b('au','NULLABLE') ?> Allows NULL</span>
    <span><?= b('nn','DEFAULT') ?> Has Default Value</span>
    <span>AUTO UPDATE — Updated automatically on row change</span>
  </div>
</div>

<div style="text-align:center;padding:2rem 0 1rem;font-size:.75rem;color:#94a3b8;">
  SUCFRMS — SUC Faculty Reclassification Management System &nbsp;·&nbsp; Data Dictionary &nbsp;·&nbsp; <?= $generated ?>
</div>

</div>
</body>
</html>
