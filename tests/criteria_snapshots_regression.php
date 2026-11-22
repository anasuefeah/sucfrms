<?php
// Connection-local temporary tables keep all accounts, notifications, and saves isolated.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scoring/entry_scores.php';
require_once __DIR__ . '/../includes/scoring/orchestrator.php';
require_once __DIR__ . '/../includes/apply/kra_entry_matching.php';
require_once __DIR__ . '/../includes/kra_pdf_render.php';
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',getenv('DB_USER') ?: 'root',getenv('DB_PASS') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
foreach (['users','cycles','scoring_criteria','criteria_scope_state','criteria_snapshots','notifications','audit_logs',
    'applications','kra_submissions','kra_evidence_files','auto_sub_rank','checker_kra_assignments','cycle_summary'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE snapshot_test_{$table} LIKE {$table}");
    $pdo->exec("ALTER TABLE snapshot_test_{$table} RENAME TO {$table}");
}
$pdo->exec('INSERT INTO criteria_scope_state (scope_id) VALUES (0)');
$checks = 0;
function snapshotCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function snapshotDenied(callable $f, string $message): void {
    try { $f(); } catch (RuntimeException $e) { snapshotCheck(true,$message); return; }
    throw new LogicException($message);
}
function snapshotCount(PDO $p,string $table): int { return (int)$p->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); }
function snapshotCriterion(PDO $p,int $scope,string $key,?string $rank=null): array {
    $q=$p->prepare('SELECT * FROM scoring_criteria WHERE cycle_id <=> ? AND position_rank <=> ? AND criterion_key=?'); $q->execute([$scope ?: null,$rank,$key]); return $q->fetch();
}
function snapshotCycle(PDO $p,string $name): int { $p->prepare("INSERT INTO cycles (cycle_name,start_date,end_date,status) VALUES (?,'2020-01-01','2020-12-31','closed')")->execute([$name]); return (int)$p->lastInsertId(); }
function snapshotPdf(array $criteria, array $entries, array $scores): string {
    $pdf = renderKraPdf(['full_name'=>'Sample Faculty', 'rank'=>'Instructor I'], $entries, ['I', 'III'],
        score_iss: $scores, criteria: $criteria);
    $pdf->SetCompression(false);
    return preg_replace('#/CreationDate \([^)]*\)#', '', $pdf->Output('S'));
}
$password='TestOverride!2026';
$account=$pdo->prepare('INSERT INTO users (first_name,last_name,email,password,role,status) VALUES (?,?,?,?,?,?)');
$ids=[];
foreach ([['admin','active'],['faculty','active'],['faculty','inactive'],['evaluator','active']] as $i=>[$role,$status]) { $account->execute(['Test','Account',"snapshot-{$i}@example.invalid",password_hash($password,PASSWORD_DEFAULT),$role,$status]); $ids[]=(int)$pdo->lastInsertId(); }
$admin=$ids[0];
$configs=[['Instruction','kra1_a_set','Criterion A - Student Evaluation of Teaching',36],['Research','kra2_a_journal_test','Criterion A - Journal Article',50],['Extension','kra3_b_tv_radio_host','Criterion B - TV Radio Host',10],['Professional Development','kra4_a_org_membership','Criterion A - Organization Membership',5]];
$insert=$pdo->prepare('INSERT INTO scoring_criteria (cycle_id,position_rank,kra_category,criterion_key,criterion_label,max_points,weight_pct,is_active) VALUES (?,?,?,?,?,?,100,1)');
foreach ($configs as [$cat,$key,$label,$points]) $insert->execute([null,null,$cat,$key,$label,$points]);
$pdo->exec("UPDATE scoring_criteria SET weight_pct=75 WHERE criterion_key='kra1_a_set' AND cycle_id IS NULL");
$old=snapshotCycle($pdo,'Closed historical cycle');
$insert->execute([$old,'Instructor I','Instruction','kra1_a_set','Criterion A - Student Evaluation of Teaching',12]);
CriteriaSnapshots::baseline($pdo);
snapshotCheck(snapshotCount($pdo,'notifications')===0,'Baseline migration notified faculty');
snapshotCheck(snapshotCriterion($pdo,$old,'kra1_a_set','Instructor I')['max_points']==12,'Baseline overwrote a past cycle customization');
snapshotCheck(count(CriteriaSnapshots::rows($pdo,$old,'Instructor II'))===4,'Legacy cycle was not materialized independently');
$oldRows=CriteriaSnapshots::rows($pdo,$old,'Instructor I');
$oldSnapshot=$pdo->query("SELECT criteria_json FROM criteria_snapshots WHERE scope_id={$old}")->fetchColumn();
CriteriaSnapshots::baseline($pdo);
snapshotCheck(snapshotCount($pdo,'criteria_snapshots')===2,'Repeated initialization created duplicate snapshots');
$set=snapshotCriterion($pdo,0,'kra1_a_set');
$update=['criteria_id'=>$set['criteria_id'],'criterion_label'=>$set['criterion_label'],'max_points'=>'18','description'=>'Updated defaults','is_active'=>'on'];
$result=CriteriaSnapshots::save($pdo,$admin,'update_criterion',$update);
snapshotCheck($result['changed'] && $result['scope']===0,'Defaults could not be edited without an active cycle');
snapshotCheck(snapshotCount($pdo,'notifications')===2,'Successful change did not notify every faculty account exactly once');
snapshotCheck($pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id IN ({$ids[1]},{$ids[2]}) AND type='criteria_updated'")->fetchColumn()==2,'Faculty notification recipients are wrong');
snapshotCheck(CriteriaSnapshots::rows($pdo,$old,'Instructor I')===$oldRows,'Default edits changed a historical cycle');
$before=snapshotCount($pdo,'notifications');
$noOp = CriteriaSnapshots::save($pdo,$admin,'update_criterion',$update+['weight_pct'=>'15']);
snapshotCheck(!$noOp['changed'] && (float)snapshotCriterion($pdo,0,'kra1_a_set')['weight_pct']===75.0,'Changing a legacy weight altered the criterion or created a version');
snapshotCheck(snapshotCount($pdo,'notifications')===$before,'No-op save sent duplicate faculty alerts');
$future=snapshotCycle($pdo,'New independent cycle');
$pdo->beginTransaction(); CriteriaSnapshots::lockScopes($pdo); CriteriaSnapshots::initializeCycle($pdo,$future,$admin); $pdo->commit();
snapshotCheck(snapshotCriterion($pdo,$future,'kra1_a_set','Instructor I')['max_points']==18,'New cycle did not capture the updated defaults');
snapshotCheck(snapshotCount($pdo,'notifications')===$before+2,'New cycle setup did not notify all faculty');
$active=snapshotCriterion($pdo,$future,'kra1_a_set','Instructor I');
// Preserve coverage for versions created before the active-cycle edit option was removed.
$pdo->beginTransaction(); CriteriaSnapshots::lockScopes($pdo);
$pdo->prepare('UPDATE scoring_criteria SET max_points=14 WHERE criteria_id=?')->execute([$active['criteria_id']]);
CriteriaSnapshots::record($pdo,$future,$admin,'Criterion updated',false); $pdo->commit();
CriteriaSnapshots::changeCycleStatus($pdo,$future,'open');
$edit=array_replace($update,['criteria_id'=>$active['criteria_id'],'max_points'=>'30','description'=>'Attempted change']);
$add=['kra_category'=>'Instruction','criterion_key'=>'future_only','criterion_label'=>'Future cycle only','max_points'=>4,'weight_pct'=>25,'description'=>'Future defaults'];
$addParent=['kra_category'=>'Instruction','criterion_key'=>'E','criterion_label'=>'Additional Teaching',
    'max_points'=>10,'parent_criterion'=>'new_parent'];
$before=snapshotCount($pdo,'notifications');
$revisions=snapshotCount($pdo,'criteria_snapshots');
$auditCount=snapshotCount($pdo,'audit_logs');
$insertApplication = $pdo->prepare("INSERT INTO applications (cycle_id,user_id,status,position_title,weighted_score) VALUES (?,?,?,'Instructor I',7)");
$insertApplication->execute([$future,$ids[1],'submitted']);
$draft = (int)$pdo->lastInsertId();
$insertApplication->execute([$future,$ids[2],'approved']);
$completed = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO kra_submissions (application_id,user_id,kra_category,remarks,computed_points) VALUES (?,?,'Instruction',?,18)")
    ->execute([$draft,$ids[1],'A-set-sem|||AY 2025-2026|||1|||100']);
$retiredOverride=['override_confirmed'=>'1','override_password'=>$password];
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'update_criterion',$edit),'An open cycle permitted a criteria edit');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'update_criterion',$edit+$retiredOverride),'The former password override still permitted an edit');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'delete_criterion',$edit),'An open cycle permitted criteria deactivation');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'add_criterion',$add+['cycle_id'=>$future,'position_rank'=>'Instructor I']),'An open cycle accepted a new criterion');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'update_criterion',$update),'Defaults were editable during an open cycle');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'delete_criterion',$update),'Defaults could be deactivated during an open cycle');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'add_criterion',$add),'Defaults accepted new criteria during an open cycle');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'add_criterion',$addParent),'Defaults accepted a new parent during an open cycle');
snapshotCheck(snapshotCount($pdo,'notifications')===$before && snapshotCount($pdo,'criteria_snapshots')===$revisions
    && snapshotCount($pdo,'audit_logs')===$auditCount,'Rejected saves produced changes or alerts');
$savedScore = $pdo->prepare('SELECT weighted_score FROM applications WHERE application_id=?');
$savedScore->execute([$draft]);
snapshotCheck((float)$savedScore->fetchColumn()===7.0 && (float)$pdo->query("SELECT computed_points FROM kra_submissions WHERE application_id={$draft}")->fetchColumn()===18.0,
    'A rejected criteria save changed scores under review');
$savedScore->execute([$completed]);
snapshotCheck((float)$savedScore->fetchColumn()===7.0,'A rejected criteria save changed a completed application');
snapshotCheck(snapshotCriterion($pdo,$future,'kra1_a_set','Instructor I')['max_points']==14
    && snapshotCriterion($pdo,0,'kra1_a_set')['max_points']==18,'A rejected save modified active or default criteria');
CriteriaSnapshots::changeCycleStatus($pdo,$future,'closed');
$historical=snapshotCriterion($pdo,$old,'kra1_a_set','Instructor I');
foreach (['update_criterion','delete_criterion'] as $action) snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,$action,array_replace($edit,['criteria_id'=>$historical['criteria_id']])),'Historical criteria were editable');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'add_criterion',$add+['cycle_id'=>$old]),'A historical cycle accepted a new criterion');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$ids[1],'update_criterion',$update),'A faculty account edited defaults');
foreach (['kra2_a_journal_test'=>34,'kra3_b_tv_radio_host'=>7,'kra4_a_org_membership'=>3] as $key=>$points) {
    $row=snapshotCriterion($pdo,0,$key);
    CriteriaSnapshots::save($pdo,$admin,'update_criterion',array_replace($update,['criteria_id'=>$row['criteria_id'],'criterion_label'=>$row['criterion_label'],'max_points'=>$points]));
}
CriteriaSnapshots::save($pdo,$admin,'add_criterion',$add);
snapshotCheck((float)snapshotCriterion($pdo,0,'future_only')['weight_pct']===100.0,'A new criterion did not use the stored default weight');
snapshotCheck(getCriteria($pdo,'future_only',$old,'Instructor I')===false,'A historical cycle inherited a newly added default');
snapshotCheck(getCriteria($pdo,'future_only',$future,'Instructor I')===false,'An existing active cycle inherited a newly added default');
$historyEntries = ['Instruction'=>ClaimReview::loadEntries($pdo, $draft)];
$historyCriteria = CriteriaSnapshots::rows($pdo, $future, 'Instructor I');
$historyScores = Scoring\Orchestrator::run($pdo, $draft);
$historyPdf = snapshotPdf($historyCriteria, $historyEntries, $historyScores);

$extensionParent = array_replace($addParent, ['kra_category'=>'Extension','criterion_key'=>'C','criterion_label'=>'Client Satisfaction','max_points'=>20]);
CriteriaSnapshots::save($pdo, $admin, 'add_criterion', $extensionParent);
$extensionParent = snapshotCriterion($pdo, 0, criteriaParentKey('Extension', 'C'));
$subX = ['kra_category'=>'Extension','criterion_key'=>'extension_sub_x','criterion_label'=>'Sub X',
    'max_points'=>4.5,'parent_criterion'=>$extensionParent['criteria_id'],'description'=>"Signed summary\nRating sheet"];
$added = CriteriaSnapshots::save($pdo, $admin, 'add_criterion', $subX);
snapshotCheck(CriteriaSnapshots::versionDetails($pdo, 0, $added['revision'])['changes'][0]['type']==='Added', 'New child was missing from version details');
$extGroups = criteriaGroups(CriteriaSnapshots::rows($pdo, 0, 'Instructor I', 'Extension'));
$extChild = $extGroups['C']['children'][0];
snapshotCheck($extGroups['C']['name']==='Client Satisfaction' && $extChild['criterion_label']==='Sub X'
    && $extChild['description']===$subX['description'] && (float)$extChild['max_points']===4.5,
    'Defaults did not expose the child with its parent, points and Evidence Guide bullets');
$updated = CriteriaSnapshots::save($pdo, $admin, 'update_criterion', $subX + [
    'criteria_id'=>$extChild['criteria_id'], 'is_active'=>'on',
]);
snapshotCheck(!$updated['changed'], 'Saving the same child again created a revision');
$updated = CriteriaSnapshots::save($pdo, $admin, 'update_criterion', array_replace($subX, [
    'criteria_id'=>$extChild['criteria_id'], 'description'=>"Signed summary\nUpdated rating sheet", 'is_active'=>'on',
]));
snapshotCheck(CriteriaSnapshots::versionDetails($pdo, 0, $updated['revision'])['changes'][0]['type']==='Updated'
    && getCriteria($pdo, $subX['criterion_key'])['description']==="Signed summary\nUpdated rating sheet",
    'Updated evidence did not appear in defaults and version history');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo, $admin, 'add_criterion', array_replace($addParent, ['criterion_key'=>'EE'])),
    'A multi-letter parent key was accepted');

$scorers = [
    'Instruction'=>Scoring\KRA1Scorer::class, 'Research'=>Scoring\KRA2Scorer::class,
    'Extension'=>Scoring\KRA3Scorer::class, 'Professional Development'=>Scoring\KRA4Scorer::class,
];
foreach ($scorers as $category => $scorer) {
    $parentInput = array_replace($addParent, ['kra_category'=>$category]);
    CriteriaSnapshots::save($pdo, $admin, 'add_criterion', $parentInput);
    snapshotDenied(fn()=>CriteriaSnapshots::save($pdo, $admin, 'add_criterion', $parentInput), "Duplicate parent letter accepted for $category");
    $parent = snapshotCriterion($pdo, 0, criteriaParentKey($category, 'E'));
    $childKey = 'additional_e_' . str_replace(' ', '_', strtolower($category));
    CriteriaSnapshots::save($pdo, $admin, 'add_criterion', array_replace($subX, [
        'kra_category'=>$category, 'criterion_key'=>$childKey, 'criterion_label'=>'Five-point child',
        'parent_criterion'=>$parent['criteria_id'], 'max_points'=>5,
    ]));
    $remarks = configuredCustomCriterionRemarks($pdo, 0, 'Instructor I', $category, $childKey, 'Evidence title');
    snapshotCheck(str_starts_with($remarks, 'CUSTOM|||E|||' . $childKey . '|||')
        && str_ends_with($remarks, '|||PARENT_MAX=10'), "$category did not save the parent cap in custom remarks");
    $scorer::setPdo($pdo);
    $scorer::setCriteriaContext(0, 'Instructor I');
    $row = ['remarks'=>$remarks, 'computed_points'=>5];
    $under = $scorer::score([$row]);
    $over = $scorer::score([$row, $row, $row]);
    snapshotCheck((float)$under['parent_scores']['E']===5.0 && (float)$over['parent_scores']['E']===10.0
        && (float)$over['subtotal']===10.0, "$category did not add the capped parent score to its subtotal");
    $allParents = [$row, $row, $row];
    foreach (range('F', 'P') as $letter) $allParents[] = ['remarks'=>"CUSTOM|||{$letter}|||cap_test|||Cap test|||10|||Evidence|||PARENT_MAX=10", 'computed_points'=>10];
    snapshotCheck((float)$scorer::score($allParents)['subtotal']===100.0, "$category exceeded the KRA cap with additional parents");
}
$next=snapshotCycle($pdo,'Another independent cycle');
$pdo->beginTransaction(); CriteriaSnapshots::lockScopes($pdo); CriteriaSnapshots::initializeCycle($pdo,$next,$admin,false); $pdo->commit();
snapshotCheck(getCriteria($pdo,'future_only',$next,'Instructor I')!==false,'Future cycle did not capture its new criterion');
foreach (CriteriaSnapshots::ranks() as $rank) {
    $copied = criteriaGroups(CriteriaSnapshots::rows($pdo, $next, $rank, 'Extension'))['C']['children'][0];
    snapshotCheck($copied['criterion_key']===$subX['criterion_key'] && (float)$copied['max_points']===4.5
        && $copied['parent_id']!==$extensionParent['criteria_id'], "Extension C child was not copied under its cycle parent for $rank");
}
$copiedCriteria = CriteriaSnapshots::rows($pdo, $next, 'Instructor I');
$extRemarks = configuredCustomCriterionRemarks($pdo, $next, 'Instructor I', 'Extension', $subX['criterion_key'], 'C evidence');
snapshotCheck(kraEntryMatchesCriterion('Extension', explode('|||', $extRemarks), $extChild, [], [], []),
    'Application entry matching did not resolve the added Extension C key');
$nextPdf = snapshotPdf($copiedCriteria, [], []);
$parentOffset = strpos($nextPdf, 'CRITERION C - Client Satisfaction');
$childOffset = strpos($nextPdf, 'Sub X');
snapshotCheck($parentOffset !== false && $childOffset !== false && $parentOffset < $childOffset
    && str_contains($nextPdf, 'CRITERION E - Additional Teaching'), 'KRA PDF did not group the added rows under their parent name and letter');
$insertEntry = $pdo->prepare('INSERT INTO kra_submissions (application_id,user_id,kra_category,remarks,computed_points) VALUES (?,?,?,?,?)');
$customE = configuredCustomCriterionRemarks($pdo, $next, 'Instructor I', 'Instruction', 'additional_e_instruction');
foreach (['submitted','approved'] as $status) {
    $account->execute(['Test','Reference',"reference-{$status}@example.invalid",password_hash($password,PASSWORD_DEFAULT),'faculty','active']);
    $referenceUser = (int)$pdo->lastInsertId();
    $insertApplication->execute([$next,$referenceUser,$status]);
    $insertEntry->execute([(int)$pdo->lastInsertId(),$referenceUser,'Instruction',$customE,5]);
    if ($status === 'submitted') $customEntryId = (int)$pdo->lastInsertId();
}
$parentE = snapshotCriterion($pdo, 0, criteriaParentKey('Instruction', 'E'));
$childE = snapshotCriterion($pdo, 0, 'additional_e_instruction');
$counts = CriteriaSnapshots::defaultReferenceCounts($pdo);
snapshotCheck($counts[$parentE['criteria_id']]===1 && $counts[$childE['criteria_id']]===1,
    'Delete preview did not count a child reference once and omit the final application');
$entryCount = snapshotCount($pdo, 'kra_submissions');
$removed = CriteriaSnapshots::save($pdo, $admin, 'delete_criterion', ['criteria_id'=>$childE['criteria_id']]);
snapshotCheck($pdo->query("SELECT remarks FROM kra_submissions WHERE submission_id={$customEntryId}")->fetchColumn()===$customE
    && snapshotCount($pdo, 'kra_submissions')===$entryCount
    && (int)snapshotCriterion($pdo, 0, 'additional_e_instruction')['is_active']===0, 'Deactivation deleted a criterion or touched a submission');
snapshotCheck(configuredCustomCriterionRemarks($pdo, 0, 'Instructor I', 'Instruction', 'additional_e_instruction')===null
    && !isset(criteriaGroups(CriteriaSnapshots::rows($pdo, 0, 'Instructor I', 'Instruction'))['E'])
    && CriteriaSnapshots::versionDetails($pdo, 0, $removed['revision'])['changes'][0]['type']==='Deactivated',
    'Deactivated child remained in the active catalog or was missing from history');
$postDeleteCycle = snapshotCycle($pdo, 'Cycle after child deactivation');
$pdo->beginTransaction(); CriteriaSnapshots::lockScopes($pdo); CriteriaSnapshots::initializeCycle($pdo,$postDeleteCycle,$admin,false); $pdo->commit();
snapshotCheck(getCriteria($pdo, 'additional_e_instruction', $postDeleteCycle, 'Instructor I')===false
    && CriteriaSnapshots::rows($pdo, $next, 'Instructor I')===$copiedCriteria,
    'Deactivation did not propagate to a future cycle or changed an existing cycle');
$currentHistoryScores = Scoring\Orchestrator::run($pdo, $draft);
snapshotCheck($currentHistoryScores['weighted_score']===$historyScores['weighted_score']
    && $currentHistoryScores['sub_rank_increment']===$historyScores['sub_rank_increment']
    && snapshotPdf(CriteriaSnapshots::rows($pdo, $future, 'Instructor I'), ['Instruction'=>ClaimReview::loadEntries($pdo, $draft)], $currentHistoryScores)===$historyPdf,
    'Default hierarchy changes altered historical weighted scores, sub-rank or PDF content');
$parentDeactivation = CriteriaSnapshots::save($pdo, $admin, 'delete_criterion', ['criteria_id'=>$extensionParent['criteria_id']]);
snapshotCheck($parentDeactivation['changed'] && getCriteria($pdo, $subX['criterion_key'])===false
    && (int)snapshotCriterion($pdo, 0, $subX['criterion_key'])['is_active']===1
    && CriteriaSnapshots::rows($pdo, $next, 'Instructor I')===$copiedCriteria,
    'Parent deactivation did not hide its child without mutating it or affected an existing copy');
foreach ([
    ['Instruction','A-set-sem|||AY 2025-2026|||1|||100',12,18],
    ['Research','Criterion A - Journal Article (50pts)|||Author|||University|||Area|||Details|||100',50,34],
    ['Extension','media-tv-radio-host|||Activity|||1|||lead',10,7],
    ['Professional Development','A-org|||Organization|||1',5,3],
] as [$category,$remarks,$oldPoints,$newPoints]) {
    snapshotCheck(abs(Scoring\EntryScores::compute($pdo,$category,$remarks,$old,'Instructor I')-$oldPoints)<.005,"{$category}: default edit changed closed-cycle scoring");
    snapshotCheck(abs(Scoring\EntryScores::compute($pdo,$category,$remarks,$next,'Instructor I')-$newPoints)<.005,"{$category}: new cycle did not use its own scoring criteria");
    snapshotCheck(abs(Scoring\EntryScores::compute($pdo,$category,$remarks,0,'Instructor I')-$newPoints)<.005,"{$category}: no-cycle preview ignored editable defaults");
}
snapshotCheck($pdo->query("SELECT criteria_json FROM criteria_snapshots WHERE scope_id={$old}")->fetchColumn()===$oldSnapshot,'Historical snapshot content was overwritten');
$activeHistory=$pdo->query("SELECT criteria_json FROM criteria_snapshots WHERE scope_id={$future} AND revision=1")->fetchColumn();
$decoded=CriteriaSnapshots::decodeRows($activeHistory);
snapshotCheck(count(array_filter($decoded,fn($r)=>$r['criterion_key']==='kra1_a_set' && $r['position_rank']==='Instructor I' && $r['max_points']==18))===1,'Previous active-cycle revision was not preserved');
$versionDetails = CriteriaSnapshots::versionDetails($pdo, $future, 2);
snapshotCheck($versionDetails['previous_revision']===1 && count($versionDetails['changes'])===1
    && $versionDetails['changes'][0]['criterion']['position_rank']==='Instructor I'
    && in_array(['label'=>'Maximum points','before'=>'18.00','after'=>'14.00'],$versionDetails['changes'][0]['fields'],true),'Version details did not show the historical rank and score change');
$baselineDetails = CriteriaSnapshots::versionDetails($pdo, $old, 1);
snapshotCheck($baselineDetails['previous_revision']===null && $baselineDetails['changes']===[] && !isset($baselineDetails['rows']),'The initial version exposed the full criteria catalog');
snapshotCheck(CriteriaSnapshots::versionDetails($pdo, $old, 2)===null,'A revision from another cycle appeared in historical details');
$newDefault=snapshotCriterion($pdo,0,'future_only');
$deactivated=CriteriaSnapshots::save($pdo,$admin,'delete_criterion',['criteria_id'=>$newDefault['criteria_id']]);
$versionDetails = CriteriaSnapshots::versionDetails($pdo, 0, $deactivated['revision']);
snapshotCheck($versionDetails['changes'][0]['type']==='Deactivated'
    && in_array(['label'=>'Status','before'=>'Active','after'=>'Inactive'],$versionDetails['changes'][0]['fields'],true),'Deactivation did not appear in version details');
snapshotCheck(getCriteria($pdo,'future_only',0,'Instructor I')===false,'Deactivation left the default criterion active');
snapshotCheck(getCriteria($pdo,'future_only',$next,'Instructor I')!==false && CriteriaSnapshots::rows($pdo,$old,'Instructor I')===$oldRows,'Deactivating the default criterion changed a saved cycle');
$zero=snapshotCriterion($pdo,0,'kra1_a_set');
CriteriaSnapshots::save($pdo,$admin,'update_criterion',array_replace($update,['criteria_id'=>$zero['criteria_id'],'max_points'=>0]));
snapshotCheck(Scoring\EntryScores::compute($pdo,'Instruction','A-set-sem|||AY 2025-2026|||1|||100',0,'Instructor I')===0.0,'An explicit zero-point criterion reverted to a hardcoded score');

// Force notification insertion to fail after the criteria write, then verify rollback.
$beforeRows=$pdo->query('SELECT * FROM scoring_criteria ORDER BY criteria_id')->fetchAll();
$beforeSnapshots=snapshotCount($pdo,'criteria_snapshots'); $beforeAlerts=snapshotCount($pdo,'notifications');
$pdo->exec('ALTER TABLE notifications ADD COLUMN must_supply INT NOT NULL');
snapshotDenied(fn()=>CriteriaSnapshots::save($pdo,$admin,'add_criterion',array_replace($add,['criterion_key'=>'should_rollback','criterion_label'=>'Should rollback'])),'Notification failure did not reject the save');
snapshotCheck($pdo->query('SELECT * FROM scoring_criteria ORDER BY criteria_id')->fetchAll()===$beforeRows,'Failed notification left saved criteria behind');
snapshotCheck(snapshotCount($pdo,'criteria_snapshots')===$beforeSnapshots && snapshotCount($pdo,'notifications')===$beforeAlerts,'Failed save left a snapshot or faculty alert behind');
$pdo->exec('ALTER TABLE notifications DROP COLUMN must_supply');

// Large evidence descriptions must survive shared-host packet limits losslessly.
$pdo->beginTransaction(); CriteriaSnapshots::lockScopes($pdo);
$longDescription=str_repeat('Evidence requirements. ',2200);
$long=$pdo->prepare("INSERT INTO scoring_criteria (kra_category,criterion_key,criterion_label,max_points,description) VALUES ('Instruction',?,?,1,?)");
for ($i=0;$i<6;$i++) $long->execute(["large_{$i}","Large description {$i}",$longDescription]);
CriteriaSnapshots::record($pdo,0,$admin,'Large snapshot regression',false); $pdo->commit();
$large=$pdo->query('SELECT criteria_json FROM criteria_snapshots WHERE scope_id=0 ORDER BY revision DESC LIMIT 1')->fetchColumn();
snapshotCheck(strlen($large)<262144,'Large snapshot was not compacted for shared hosting');
$decoded=CriteriaSnapshots::decodeRows($large);
snapshotCheck(count(array_filter($decoded,fn($r)=>str_starts_with($r['criterion_key'],'large_') && $r['description']===$longDescription))===6,'Large snapshot lost evidence descriptions');
snapshotCheck(!$pdo->inTransaction(),'A failed request left its transaction open');

// Render the actual module: edit controls exist only for defaults between cycles.
CriteriaSnapshots::changeCycleStatus($pdo,$future,'open');
$_SESSION=['role'=>'admin','user_id'=>$admin]; $_SERVER['REQUEST_METHOD']='GET';
$_GET=['cycle_id'=>$future,'position'=>'Instructor I'];
ob_start(); include __DIR__ . '/../admin/scoring_criteria.php'; $html=ob_get_clean();
snapshotCheck(str_contains($html,'Criteria cannot be edited while a cycle is open') && !str_contains($html,'name="max_points"')
    && !str_contains($html,'value="delete_criterion"') && !str_contains($html,'id="addCritForm"')
    && !str_contains($html,'id="critReviewModal"'),'The active-cycle view rendered editing controls');
snapshotCheck(str_contains($html,'aria-label="View version 2 details"'),'The criteria history did not include a version details control');
snapshotCheck(str_contains($html,'data-version-revision="2" aria-haspopup="dialog"')
    && str_contains($html,'<dialog class="criteria-version-popup"'),'Version details are not connected to the popup');
$_GET=['cycle_id'=>$future,'position'=>'Instructor I','revision'=>'2'];
ob_start(); include __DIR__ . '/../admin/scoring_criteria.php'; $html=ob_get_clean();
snapshotCheck(str_contains($html,'Version 2 details') && str_contains($html,'criteria-history" id="criteria-history" open')
    && str_contains($html,'<td>18.00</td><td>14.00</td>') && !str_contains($html,'name="max_points"'),'The version details view did not render its comparison as read-only');
$_GET=['cycle_id'=>$future,'position'=>'Instructor I','override'=>'1'];
ob_start(); include __DIR__ . '/../admin/scoring_criteria.php'; $html=ob_get_clean();
snapshotCheck(!str_contains($html,'name="max_points"') && !str_contains($html,'override=1'),'A former edit link bypassed the lock or was propagated');
$_GET=['cycle_id'=>0];
ob_start(); include __DIR__ . '/../admin/scoring_criteria.php'; $html=ob_get_clean();
snapshotCheck(str_contains($html,'Criteria cannot be edited while a cycle is open') && !str_contains($html,'name="max_points"'),'The defaults page rendered edits during an open cycle');
CriteriaSnapshots::changeCycleStatus($pdo,$future,'closed');
$_GET=['cycle_id'=>$old,'position'=>'Instructor I'];
ob_start(); include __DIR__ . '/../admin/scoring_criteria.php'; $html=ob_get_clean();
snapshotCheck(str_contains($html,'historical cycle') && !str_contains($html,'name="max_points"'),'Closing the last open cycle enabled historical edits');
$_GET=['cycle_id'=>0];
ob_start(); include __DIR__ . '/../admin/scoring_criteria.php'; $html=ob_get_clean();
snapshotCheck(str_contains($html,'no active cycle') && str_contains($html,'name="max_points"')
    && str_contains($html,'name="csrf_token"') && str_contains($html,'id="addCritForm"'),'Default edits were unavailable between cycles');

// Assignment initialization must honor an intentionally empty assignment table.
$assign = $pdo->prepare("INSERT INTO checker_kra_assignments (cycle_id,checker_id,kra_category) VALUES (?,?,'Research')");
$assign->execute([$next,$ids[3]]);
$pdo->prepare('DELETE FROM checker_kra_assignments WHERE cycle_id=?')->execute([$next]);
ensureKraAssignmentTable($pdo);
snapshotCheck(snapshotCount($pdo,'checker_kra_assignments')===0,'Assignment initialization restored removed permissions');

// Opening a different cycle must run the same closeout as an explicit close.
CriteriaSnapshots::changeCycleStatus($pdo,$next,'open');
$insertApplication->execute([$next,$ids[1],'draft']);
$insertApplication->execute([$next,$ids[2],'needs_revision']);
$assign->execute([$next,$ids[3]]);
$assign->execute([$next,$ids[2]]);
$closeoutAlerts = $pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('cycle_expired','cycle_closed_pending')");
$beforeCloseout = (int)$closeoutAlerts->fetchColumn();
CriteriaSnapshots::changeCycleStatus($pdo,$future,'open');
snapshotCheck($pdo->query("SELECT status FROM cycles WHERE cycle_id={$next}")->fetchColumn()==='closed' && $pdo->query("SELECT status FROM applications WHERE cycle_id={$next} AND user_id={$ids[1]}")->fetchColumn()==='expired','Switching cycles left the previous cycle or its draft unfinished');
snapshotCheck((int)$pdo->query("SELECT expired_drafts FROM cycle_summary WHERE cycle_id={$next}")->fetchColumn()===1,'Switching cycles did not save a closeout summary');
snapshotCheck(snapshotCount($pdo,'checker_kra_assignments')===1,'Closeout did not remove an ineligible evaluator assignment');
CriteriaSnapshots::changeCycleStatus($pdo,$next,'closed');
snapshotCheck((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('cycle_expired','cycle_closed_pending')")->fetchColumn()===$beforeCloseout+2,'Closeout alerts were missing or duplicated');

$legacy = ['criteria_id'=>101,'criterion_key'=>'legacy','criterion_label'=>'Revised criterion','kra_category'=>'Instruction',
    'max_points'=>10,'weight_pct'=>100,'is_active'=>1,'description'=>"Unchanged requirement\nOld requirement"];
$linked = array_replace($legacy,['parent_id'=>102,'is_parent'=>0,'description'=>"Unchanged requirement\nNew requirement"]);
$generated = array_replace($legacy,['criteria_id'=>102,'criterion_key'=>'@parent:Instruction:A','criterion_label'=>'Generated parent','is_parent'=>1,'parent_id'=>null]);
$added = array_replace($linked,['criteria_id'=>103,'criterion_key'=>'new','criterion_label'=>'Added criterion','updated_by'=>$admin]);
$insertHistory = $pdo->prepare("INSERT INTO criteria_snapshots (scope_id, revision, criteria_json, change_summary) VALUES (9999, ?, ?, 'Fixture version')");
$insertHistory->execute([1,json_encode([$legacy],JSON_THROW_ON_ERROR)]);
$insertHistory->execute([2,json_encode([$linked,$generated,$added],JSON_THROW_ON_ERROR)]);
$version = CriteriaSnapshots::versionDetails($pdo,9999,2);
snapshotCheck(array_column($version['changes'],'type')===['Updated','Added'] && $version['changes'][1]['fields']===[],'Version details displayed automatic hierarchy changes or every field of an addition');
snapshotCheck($version['changes'][0]['fields']===[['label'=>'Evidence requirements','before'=>'Old requirement','after'=>'New requirement']],'Version details included unchanged evidence requirements');
$requestedRevision = 2;
$versionError = '';
$versionUrl = static fn(int $revision): string => '?revision='.$revision;
ob_start(); include __DIR__ . '/../includes/criteria_version_details.php'; $detailMarkup=ob_get_clean();
snapshotCheck(str_contains($detailMarkup,'Added criterion') && substr_count($detailMarkup,'<table')===1 && !str_contains($detailMarkup,'Generated parent'),'Version summary did not show additions compactly alongside actual edits');
echo "Criteria snapshots: {$checks} checks passed; temporary tables only.\n";
