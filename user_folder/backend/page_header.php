<?php

$pageTitle = $pageTitle ?? "ImmuCare";
$activePage = $activePage ?? "";
$guardian = currentGuardian();
$avatarFilename = guardianAvatarFilename($guardian);
$flash = takeUserFlash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escapeHtml($pageTitle) ?> | ImmuCare</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="<?= $activePage === "profile" ? "profile-edit-body" : "" ?>">
<div class="app inner-app<?= $activePage === "profile" ? " profile-edit-app" : "" ?>">
    <header class="inner-header">
        <a class="inner-brand" href="../index.php">
            <img src="../images/logo.png" alt="ImmuCare">
            <span>Infant Immunization Ledger</span>
        </a>
        <div class="inner-header-actions">
            <a class="inner-profile" href="profile.php">
                <?php if ($avatarFilename !== ""): ?>
                    <img class="inner-avatar" src="../uploads/avatars/<?= escapeHtml($avatarFilename) ?>" alt="">
                <?php else: ?>
                    <span class="inner-avatar">👩🏻</span>
                <?php endif; ?>
                <span><?= escapeHtml($guardian !== "" ? $guardian : "Profile") ?></span>
            </a>
            <?php if ($guardian !== ""): ?>
                <a class="sign-out-link" href="../user_login/logout.php">Sign out</a>
            <?php else: ?>
                <a class="sign-out-link" href="../user_login/">Sign in</a>
            <?php endif; ?>
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark theme">
                <i class="fa-solid fa-circle-half-stroke"></i>
            </button>
        </div>
    </header>

    <main class="inner-page<?= $activePage === "profile" ? " profile-edit-page" : "" ?>">
        <?php if ($activePage !== "profile"): ?>
        <div class="inner-page-heading">
            <a class="back-home" href="../index.php" aria-label="Back to home">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <h1><?= escapeHtml($pageTitle) ?></h1>
        </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="notice notice-<?= escapeHtml((string) ($flash["type"] ?? "success")) ?>" role="status">
                <?= escapeHtml((string) ($flash["message"] ?? "")) ?>
            </div>
        <?php endif; ?>

        <?php if ($guardian === ""): ?>
            <div class="notice notice-error" role="alert">
                Sign in with the username and password provided by your clinic to view your information.
                <a href="../user_login/">Sign in</a>
            </div>
        <?php endif; ?>
