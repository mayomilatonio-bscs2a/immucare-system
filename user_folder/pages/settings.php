<?php

require_once __DIR__ . "/../backend/common.php";

$pageTitle = "Settings";
$activePage = "settings";
require __DIR__ . "/../backend/page_header.php";

?>
<section class="content-card">
    <h2>Appearance</h2>
    <p class="page-intro">Choose a theme for this browser. Your preference is saved on this device.</p>
    <div class="settings-actions">
        <button class="choice-button" type="button" data-theme-choice="light">
            <i class="fa-solid fa-sun"></i> Light theme
        </button>
        <button class="choice-button" type="button" data-theme-choice="dark">
            <i class="fa-solid fa-moon"></i> Dark theme
        </button>
    </div>
</section>
<section class="content-card">
    <h2>Account information</h2>
    <p class="page-intro">Your profile and child details are managed by the clinic.</p>
    <a class="text-link" href="profile.php">View profile <i class="fa-solid fa-arrow-right"></i></a>
</section>
<section class="content-card">
    <h2>Help and support</h2>
    <p class="page-intro">Find answers to common questions or send a message to the clinic team.</p>
    <div class="settings-actions">
        <a class="choice-button" href="help-center.php">
            <i class="fa-solid fa-circle-question"></i> Help center
        </a>
        <a class="choice-button" href="send-feedback.php">
            <i class="fa-solid fa-comment-dots"></i> Send feedback
        </a>
    </div>
</section>
<?php require __DIR__ . "/../backend/page_footer.php"; ?>
