<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    exit;
}
session_write_close();

$scope = filter_var($_GET['cycle_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$requestedRevision = filter_var($_GET['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($scope === false || !$requestedRevision) {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/../config/db.php';
try {
    $version = CriteriaSnapshots::versionDetails($pdo, $scope, $requestedRevision);
} catch (Throwable $error) {
    error_log('SUCFRMS criteria version details: ' . $error->getMessage());
    http_response_code(500);
    exit;
}
if (!$version) {
    http_response_code(404);
    exit;
}

$versionError = '';
$versionUrl = static fn(int $revision): string => 'index.php?' . http_build_query([
    'page' => 'scoring_criteria', 'cycle_id' => $scope, 'revision' => $revision,
]) . '#criteria-version-details';
?>
<div data-criteria-version-content>
    <?php include __DIR__ . '/../includes/criteria_version_details.php'; ?>
</div>
