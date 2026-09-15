<?php
// Old standalone dashboard &mdash; replaced by role-specific dashboards in includes/dashboards/
// This file is kept only as a fallback and redirects to the correct dashboard.
include __DIR__ . '/../includes/dashboards/' . ($_SESSION['role'] ?? 'faculty') . '_dashboard.php';

