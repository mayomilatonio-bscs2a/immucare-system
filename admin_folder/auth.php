<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => !empty($_SERVER["HTTPS"])
            && $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}

function requireStaffLogin() {
    if (empty($_SESSION["user_id"])) {
        header("Location: /immucare/admin_folder/login.php");
        exit;
    }
}

function requireAdministrator() {
    requireStaffLogin();

    if (($_SESSION["role"] ?? "") !== "Administrator") {
        http_response_code(403);
        exit("Administrator access is required.");
    }
}
