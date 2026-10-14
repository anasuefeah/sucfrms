<?php
// SMTP settings. Set these as environment variables in production; the values
// below are the fallbacks used when a variable isn't set.

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST',      getenv('SMTP_HOST') ?: 'smtp.gmail.com');
    define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
    define('SMTP_USER',      getenv('SMTP_USER') ?: 'sucfrms.chmsu@gmail.com');
    define('SMTP_PASS',      getenv('SMTP_PASS') ?: 'wwbbxdrtpuammsma');
    define('SMTP_FROM',      getenv('SMTP_FROM') ?: 'sucfrms.chmsu@gmail.com');
    define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'SUCFRMS');
}
