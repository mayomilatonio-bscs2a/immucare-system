
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function redirectSettings($message, $type = "error") {
    $_SESSION["settings_message"] = $message;
    $_SESSION["settings_message_type"] = $type;

    header("Location: ../pages/settings.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/settings.php");
    exit;
}

// Validate CSRF token.
$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["settings_csrf"]) ||
    !hash_equals($_SESSION["settings_csrf"], $token)
) {
    redirectSettings("Invalid request. Please try again.");
}

// Read password fields.
$currentPassword = $_POST["current_password"] ?? "";
$newPassword = $_POST["new_password"] ?? "";
$confirmPassword = $_POST["confirm_password"] ?? "";

if (
    !is_string($currentPassword) ||
    !is_string($newPassword) ||
    !is_string($confirmPassword) ||
    $currentPassword === "" ||
    $newPassword === "" ||
    $confirmPassword === ""
) {
    redirectSettings("Please complete all password fields.");
}

if ($newPassword !== $confirmPassword) {
    redirectSettings("New passwords do not match.");
}

if (strlen($newPassword) < 12) {
    redirectSettings(
        "New password must contain at least 12 characters."
    );
}

if (strlen($newPassword) > 72) {
    redirectSettings(
        "New password must not exceed 72 bytes."
    );
}

$userId = (int) $_SESSION["user_id"];

try {

    // Retrieve the administrator's stored password.
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
        redirectSettings("Administrator account not found.");
    }

    // Verify current password.
    if (!password_verify($currentPassword, $user["password"])) {
        redirectSettings("Current password is incorrect.");
    }

    // Prevent reusing the same password.
    if (password_verify($newPassword, $user["password"])) {
        redirectSettings(
            "Your new password must be different from your current password."
        );
    }

    // Hash new password.
    $hashedPassword = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    // Update password only if the stored hash
    // has not changed since verification.
    $stmt = $mysqli->prepare("
        UPDATE users
        SET password = ?
        WHERE id = ?
          AND role = 'Administrator'
          AND status = 'Active'
          AND password = ?
    ");

    $stmt->bind_param(
        "sis",
        $hashedPassword,
        $userId,
        $user["password"]
    );

    $stmt->execute();

    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated !== 1) {
        redirectSettings(
            "Password was not updated. Please try again."
        );
    }

    // Refresh session identifier.
    session_regenerate_id(true);

    // Refresh CSRF token.
    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));

    redirectSettings(
        "Your administrator password has been changed successfully.",
        "success"
    );

} catch (Throwable $e) {

    error_log("Password change error: " . $e->getMessage());

    redirectSettings(
        "Unable to change password. Please try again."
    );
}
