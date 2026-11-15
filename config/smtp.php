<?php
/**
 * SMTP configuration for SUCFRMS.
 *
 * Override any value via environment variables so credentials are never
 * hard-coded in source.  Set them in Laragon's Apache vhost config, a
 * .env loader, or your hosting panel — e.g.:
 *
 *   SetEnv SMTP_USER  sucfrms.chmsu@gmail.com
 *   SetEnv SMTP_PASS  your_app_password_here
 *
 * The defaults below match the original hard-coded values and keep the
 * app working out-of-the-box, but you should replace SMTP_PASS with a
 * real Gmail App Password (or a different SMTP provider).
 */
if (!defined('SMTP_HOST'))      define('SMTP_HOST',      getenv('SMTP_HOST')      ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_USER'))      define('SMTP_USER',      getenv('SMTP_USER')      ?: 'sucfrms.chmsu@gmail.com');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      getenv('SMTP_PASS')      ?: 'wwbbxdrtpuammsma');
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      getenv('SMTP_FROM')      ?: 'sucfrms.chmsu@gmail.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'SUCFRMS');
