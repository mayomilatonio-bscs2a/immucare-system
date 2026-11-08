
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function profileRedirect($message, $type = "error") {
    $_SESSION["profile_message"] = $message;
    $_SESSION["profile_message_type"] = $type;

    header("Location: ../pages/settings.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/settings.php");
    exit;
}

$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["settings_csrf"]) ||
    !hash_equals($_SESSION["settings_csrf"], $token)
) {
    profileRedirect("Invalid request. Please refresh and try again.");
}

$fullName = $_POST["full_name"] ?? "";
$username = $_POST["username"] ?? "";
$currentPassword = $_POST["current_password"] ?? "";

if (
    !is_string($fullName) ||
    !is_string($username) ||
    !is_string($currentPassword)
) {
    profileRedirect("Invalid form information.");
}

$fullName = trim($fullName);
$username = trim($username);

if (
    $fullName === "" ||
    $username === "" ||
    $currentPassword === ""
) {
    profileRedirect("Please complete all fields.");
}

if (mb_strlen($fullName) > 100) {
    profileRedirect("Full name must not exceed 100 characters.");
}

if (
    !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
) {
    profileRedirect(
        "Username must contain 3–50 characters using letters, numbers, dots, underscores, or hyphens."
    );
}

$userId = (int) $_SESSION["user_id"];

try {

    // Verify the currently logged-in administrator.
    $stmt = $mysqli->prepare("
        SELECT password
        FROM users
        WHERE id = ?
          AND role = 'Administrator'
          AND status = 'Active'
        LIMIT 1
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        profileRedirect("Administrator account not found.");
    }

    if (!password_verify($currentPassword, $user["password"])) {
        profileRedirect("Current password is incorrect.");
    }

    // Check whether another user already owns this username.
    $stmt = $mysqli->prepare("
        SELECT id
        FROM users
        WHERE username = ?
          AND id <> ?
        LIMIT 1
    ");

    $stmt->bind_param("si", $username, $userId);
    $stmt->execute();

    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existingUser) {
        profileRedirect("This username is already in use.");
    }

    // Update only the current administrator's profile.
    $stmt = $mysqli->prepare("
        UPDATE users
        SET full_name = ?, username = ?
        WHERE id = ?
          AND role = 'Administrator'
          AND status = 'Active'
    ");

    $stmt->bind_param(
        "ssi",
        $fullName,
        $username,
        $userId
    );

    $stmt->execute();
    $stmt->close();

    // Refresh session information.
    $_SESSION["full_name"] = $fullName;
    $_SESSION["username"] = $username;

    session_regenerate_id(true);

    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));

    profileRedirect(
        "Administrator profile updated successfully.",
        "success"
    );

} catch (mysqli_sql_exception $e) {

    error_log("Profile update failed: " . $e->getMessage());

    if ($e->getCode() === 1062) {
        profileRedirect("This username is already in use.");
    }

    profileRedirect("Unable to update profile. Please try again.");

} catch (Throwable $e) {

    error_log("Profile update failed: " . $e->getMessage());

    profileRedirect("Unable to update profile. Please try again.");
}
