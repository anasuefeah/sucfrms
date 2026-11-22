<?php
/** Delete a cycle's records inside the caller's verified, atomic transaction. */
function deleteCycleRecords(PDO $pdo, int $cycleId): void {
    if (!$pdo->inTransaction()) throw new LogicException('Cycle deletion requires a transaction.');
    $cycle = $pdo->prepare('SELECT cycle_id FROM cycles WHERE cycle_id=? FOR UPDATE');
    $cycle->execute([$cycleId]);
    if (!$cycle->fetchColumn()) throw new RuntimeException('Cycle not found.');

    $pdo->prepare('DELETE FROM checker_kra_assignments WHERE cycle_id=?')->execute([$cycleId]);
    $pdo->prepare('DELETE FROM scoring_criteria WHERE cycle_id=? AND parent_id IS NOT NULL')->execute([$cycleId]);
    $pdo->prepare('DELETE FROM scoring_criteria WHERE cycle_id=?')->execute([$cycleId]);
    // Current foreign keys cascade from applications to submissions, evidence,
    // evaluator reviews, verifications, appeals, messages, and notifications.
    // Delete applications explicitly: their cycle FK uses ON DELETE SET NULL.
    $pdo->prepare('DELETE FROM applications WHERE cycle_id=?')->execute([$cycleId]);
    $pdo->prepare('DELETE FROM cycles WHERE cycle_id=?')->execute([$cycleId]);
    $pdo->prepare('DELETE FROM criteria_scope_state WHERE scope_id=?')->execute([$cycleId]);
}
