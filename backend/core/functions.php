<?php
/**
 * Core helper functions for SUCFRMS.
 * Loads every helper module, so callers only need this one require.
 */
require_once __DIR__ . '/kra_numbering.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/../auth/guards.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/applications.php';
require_once __DIR__ . '/kra_display.php';
require_once __DIR__ . '/ranks.php';
require_once __DIR__ . '/../../frontend/components/ui.php';
require_once __DIR__ . '/../../frontend/components/confirm_modal.php';
