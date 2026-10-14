<?php
// Flash messages and output escaping.

// -- Flash messages --------------------------------------------
function flashMessage(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function showFlash(): void {
    if (empty($_SESSION['flash'])) return;
    $f    = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $icon = match($f['type']) {
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'danger'  => 'bi-x-circle-fill',
        default   => 'bi-info-circle-fill',
    };
    $color = match($f['type']) {
        'success' => '#16a34a',
        'warning' => '#d97706',
        'danger'  => '#dc2626',
        default   => '#1e4d8c',
    };
    $msg_html = $f['msg'];
    // Toast: fixed bottom-right, slides in, auto-dismisses after 3.5 s, never pushes content down
    echo "<div id='sucfrms-toast' style='position:fixed;bottom:1.5rem;right:1.5rem;z-index:999999;"
       . "min-width:280px;max-width:400px;background:#fff;border-radius:12px;"
       . "box-shadow:0 8px 32px rgba(0,0,0,0.18);display:flex;align-items:flex-start;gap:0.75rem;"
       . "padding:1rem 1.1rem;border-left:4px solid {$color};animation:sucfrmsToastIn 0.3s ease;'>"
       . "<i class='bi {$icon}' style='color:{$color};font-size:1.15rem;flex-shrink:0;margin-top:2px;'></i>"
       . "<div style='flex:1;font-size:0.85rem;color:#1e293b;line-height:1.5;'>{$msg_html}</div>"
       . "<button onclick=\"document.getElementById('sucfrms-toast').remove()\" "
       .         "style='background:none;border:none;color:#94a3b8;cursor:pointer;"
       .                "font-size:1.2rem;line-height:1;padding:0 0 2px;flex-shrink:0;'>&times;</button>"
       . "</div>"
       . "<style>"
       . "@keyframes sucfrmsToastIn{from{opacity:0;transform:translateY(1rem)}to{opacity:1;transform:translateY(0)}}"
       . "@keyframes sucfrmsToastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(1rem)}}"
       . "</style>"
       . "<script>(function(){"
       .   "var t=document.getElementById('sucfrms-toast');"
       .   "if(!t)return;"
       .   "setTimeout(function(){"
       .     "t.style.animation='sucfrmsToastOut 0.4s ease forwards';"
       .     "setTimeout(function(){if(t&&t.parentNode)t.parentNode.removeChild(t);},400);"
       .   "},3500);"
       . "})();</script>";
}

// -- Output sanitization ---------------------------------------
function sanitize(string $str): string {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}
