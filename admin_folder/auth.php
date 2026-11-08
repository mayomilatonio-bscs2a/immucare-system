
<?php

// Start a secure session.
if (session_status() === PHP_SESSION_NONE) {

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");

    $isHttps = (
        (!empty($_SERVER["HTTPS"]) &&
         $_SERVER["HTTPS"] !== "off")
        ||
        (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https")
    );

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $isHttps,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}

// Determine whether the application runs locally.
function immucareAdminLoginUrl() {

    $host = $_SERVER["HTTP_HOST"] ?? "";

    if (
        $host === "localhost" ||
        str_starts_with($host, "localhost:") ||
        $host === "127.0.0.1" ||
        str_starts_with($host, "127.0.0.1:")
    ) {
        return "/immucare/admin_folder/login.php";
    }

    return "/admin_folder/login.php";
}

// Require staff or administrator login.
function requireStaffLogin() {

    if (empty($_SESSION["user_id"])) {

        header("Location: " . immucareAdminLoginUrl());
        exit;
    }
}

// Require administrator privileges.
function requireAdministrator() {

    requireStaffLogin();

    if (($_SESSION["role"] ?? "") !== "Administrator") {

        http_response_code(403);
        exit("Administrator access is required.");
    }
}
