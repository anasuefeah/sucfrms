<?php
// Small HTML components shared by the layouts and views. Styles live in frontend/tailwind/components.css.

/**
 * Renders a small ? help button with a hover popover.
 * Usage: <?= helpBtn('Title', 'Explanation text here') ?>
 */
function helpBtn(string $title, string $body): string {
    $t = htmlspecialchars($title, ENT_QUOTES);
    $b = htmlspecialchars($body, ENT_QUOTES);
    return '<span class="ml-1.5 inline-block align-middle">'
         . '<button type="button" class="help-btn" data-title="' . $t . '" data-body="' . $b . '" tabindex="0" aria-label="Help: ' . $t . '">?</button>'
         . '</span>';
}

/** [pill variant, label] for an application status. User-facing labels stay neutral. */
function statusMeta(string $status): array {
    $map = [
        'draft'          => ['neutral',  'Draft'],
        'submitted'      => ['info',     'Submitted'],
        'under_review'   => ['progress', 'Under Evaluation'],
        'talisay_review' => ['progress', 'Under Evaluation'],
        'approved'       => ['success',  'Evaluation Complete'],
        'reclassified'   => ['success',  'Evaluation Complete'],
        'rejected'       => ['warning',  'Returned for Revision'],
        'needs_revision' => ['warning',  'Returned for Revision'],
        'admin_rejected' => ['neutral',  'Evaluation Complete'],
        'edit_requested' => ['brand',    'Edit Requested'],
    ];
    return $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', $status))];
}

function statusBadge(string $status): string {
    [$variant, $label] = statusMeta($status);
    return '<span class="pill pill-' . $variant . '">' . $label . '</span>';
}

/** Initials avatar or profile picture for the signed-in user. */
function userAvatar(string $sizeClass = 'size-10', string $textClass = 'text-sm'): string {
    $role = $_SESSION['role'] ?? '';
    $pic  = $_SESSION['profile_pic'] ?? '';
    if ($role === 'admin') {
        return '<img src="frontend/assets/images/logo.jpg" alt="" class="' . $sizeClass . ' shrink-0 rounded-full object-cover ring-2 ring-white">';
    }
    if ($pic !== '') {
        return '<img src="' . sanitize($pic) . '" alt="" class="' . $sizeClass . ' shrink-0 rounded-full object-cover ring-2 ring-white">';
    }
    $initials = strtoupper(substr($_SESSION['first_name'] ?? '', 0, 1) . substr($_SESSION['last_name'] ?? '', 0, 1)) ?: 'U';
    return '<span class="' . $sizeClass . ' ' . $textClass . ' inline-flex shrink-0 items-center justify-center rounded-full '
         . 'bg-gradient-to-br from-brand-500 to-brand-700 font-semibold text-white ring-2 ring-white">' . $initials . '</span>';
}

/** Page title block. $actions is raw HTML placed on the right (buttons, filters). */
function pageHeader(string $title, string $subtitle = '', string $eyebrow = '', string $actions = ''): string {
    $html = '<div class="page-header"><div class="min-w-0">';
    if ($eyebrow !== '')  $html .= '<div class="page-eyebrow">' . htmlspecialchars($eyebrow) . '</div>';
    $html .= '<h1 class="page-title">' . htmlspecialchars($title) . '</h1>';
    if ($subtitle !== '') $html .= '<p class="page-subtitle">' . $subtitle . '</p>';
    $html .= '</div>';
    if ($actions !== '')  $html .= '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>';
    return $html . '</div>';
}

/**
 * KPI card with an icon chip. $tone: brand, emerald, amber, rose, violet, sky, slate.
 * $pollKey keeps the live dashboard poll (data-poll-key) updating the number.
 */
function statCard(string $label, $value, string $icon, string $tone = 'brand', string $hint = '', string $pollKey = '', string $href = ''): string {
    $chip = $tone === 'brand' ? 'icon-chip' : 'icon-chip icon-chip-' . $tone;
    $poll = $pollKey !== '' ? ' data-poll-key="' . htmlspecialchars($pollKey) . '"' : '';
    $tag  = $href !== '' ? 'a href="' . htmlspecialchars($href) . '"' : 'div';
    $end  = $href !== '' ? 'a' : 'div';
    return '<' . $tag . ' class="stat-card no-underline">'
         . '<span class="' . $chip . '"><i class="bi ' . $icon . '"></i></span>'
         . '<div class="min-w-0"><div class="stat-label">' . htmlspecialchars($label) . '</div>'
         . '<div class="stat-value"' . $poll . '>' . $value . '</div>'
         . ($hint !== '' ? '<div class="stat-hint">' . $hint . '</div>' : '')
         . '</div></' . $end . '>';
}

function emptyState(string $icon, string $title, string $text = '', string $action = ''): string {
    return '<div class="empty-state">'
         . '<span class="empty-state-icon"><i class="bi ' . $icon . '"></i></span>'
         . '<p class="empty-state-title">' . htmlspecialchars($title) . '</p>'
         . ($text !== '' ? '<p class="empty-state-text">' . $text . '</p>' : '')
         . ($action !== '' ? '<div class="mt-3">' . $action . '</div>' : '')
         . '</div>';
}
