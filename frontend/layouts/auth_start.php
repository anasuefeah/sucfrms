<?php
// Split-screen layout for the sign-in / password-recovery pages (frontend/pages/*).
// Set $authTitle before including; close with auth_end.php.
$_css = __DIR__ . '/../assets/css/app.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a3a6b">
    <title>SUCFRMS | <?= htmlspecialchars($authTitle ?? 'Sign in') ?></title>
    <link rel="preload" href="../assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= @filemtime($_css) ?: 1 ?>">
</head>
<body class="m-0 min-h-screen bg-canvas font-sans text-ink antialiased">
<div class="grid min-h-screen lg:grid-cols-[1.05fr_1fr]">

    <aside class="relative hidden overflow-hidden bg-brand-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <img src="../assets/images/login_bg.jpg" alt="" class="absolute inset-0 size-full object-cover opacity-25 mix-blend-luminosity">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-950/95 via-brand-800/85 to-brand-600/70"></div>
        <div class="pointer-events-none absolute -right-32 -top-32 size-[28rem] rounded-full bg-brand-400/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-40 -left-20 size-[26rem] rounded-full bg-sky-400/10 blur-3xl"></div>

        <div class="relative flex items-center gap-3">
            <img src="../assets/images/logo.jpg" alt="" class="size-11 rounded-full object-cover ring-2 ring-white/30">
            <div>
                <p class="m-0 text-base font-semibold tracking-tight text-white">SUCFRMS</p>
                <p class="m-0 text-xs text-brand-100/80">Faculty Reclassification</p>
            </div>
        </div>

        <div class="relative max-w-md">
            <p class="m-0 mb-4 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-brand-50 ring-1 ring-white/20">
                <span class="size-1.5 rounded-full bg-emerald-400"></span> State Universities &amp; Colleges
            </p>
            <h1 class="m-0 text-4xl font-semibold leading-tight tracking-tight text-white">
                Faculty reclassification,<br>without the paperwork.
            </h1>
            <p class="m-0 mt-4 text-base leading-relaxed text-brand-100/80">
                Submit, evaluate and track KRA-based reclassification applications in one place.
            </p>
            <ul class="m-0 mt-8 grid list-none gap-3 p-0 text-sm text-brand-50/90">
                <li class="flex items-center gap-3"><i class="bi bi-check2-circle text-lg text-emerald-300"></i>Guided KRA entry with live point tallies</li>
                <li class="flex items-center gap-3"><i class="bi bi-check2-circle text-lg text-emerald-300"></i>Transparent evaluator review and appeals</li>
                <li class="flex items-center gap-3"><i class="bi bi-check2-circle text-lg text-emerald-300"></i>Real-time status notifications</li>
            </ul>
        </div>

        <p class="relative m-0 text-xs text-brand-100/60">&copy; <?= date('Y') ?> SUC Faculty Reclassification Management System</p>
    </aside>

    <main class="flex items-center justify-center px-5 py-10 sm:px-8">
        <div class="w-full max-w-[420px] animate-fade-up">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <img src="../assets/images/logo.jpg" alt="" class="size-11 rounded-full object-cover ring-1 ring-line">
                <div>
                    <p class="m-0 text-base font-semibold tracking-tight text-brand-800">SUCFRMS</p>
                    <p class="m-0 text-xs text-slate-500">Faculty Reclassification</p>
                </div>
            </div>
