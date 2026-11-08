<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../connect.php";

$mysqli->query("
    CREATE TABLE IF NOT EXISTS appointment_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        guardian VARCHAR(255) NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        notes VARCHAR(500) NOT NULL DEFAULT '',
        status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
        admin_note VARCHAR(500) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_appointment_guardian (guardian),
        INDEX idx_appointment_patient (patient_id),
        INDEX idx_appointment_status_date (status, appointment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
    CREATE TABLE IF NOT EXISTS user_feedback (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        guardian VARCHAR(100) NOT NULL,
        category VARCHAR(40) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('Open', 'Resolved') NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_feedback_guardian (guardian),
        INDEX idx_feedback_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
    CREATE TABLE IF NOT EXISTS guardian_profiles (
        guardian VARCHAR(255) NOT NULL PRIMARY KEY,
        avatar_filename VARCHAR(80) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

function currentGuardian(): string
{
    return isset($_SESSION["guardian"]) && is_string($_SESSION["guardian"])
        ? trim($_SESSION["guardian"])
        : "";
}

function guardianAvatarFilename(string $guardian): string
{
    global $mysqli;

    if ($guardian === "") {
        return "";
    }

    $stmt = $mysqli->prepare("
        SELECT avatar_filename
        FROM guardian_profiles
        WHERE guardian = ?
    ");
    $stmt->bind_param("s", $guardian);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $filename = is_array($record) ? (string) ($record["avatar_filename"] ?? "") : "";
    return preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $filename) === 1
        ? $filename
        : "";
}

function ensureCsrfToken(): string
{
    if (!isset($_SESSION["user_csrf_token"]) || !is_string($_SESSION["user_csrf_token"])) {
        $_SESSION["user_csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["user_csrf_token"];
}

function validateCsrfToken(): bool
{
    $submitted = $_POST["csrf_token"] ?? "";
    return is_string($submitted)
        && isset($_SESSION["user_csrf_token"])
        && hash_equals($_SESSION["user_csrf_token"], $submitted);
}

function setUserFlash(string $message, string $type = "success"): void
{
    $_SESSION["user_flash"] = [
        "message" => $message,
        "type" => $type,
    ];
}

function takeUserFlash(): ?array
{
    $flash = $_SESSION["user_flash"] ?? null;
    unset($_SESSION["user_flash"]);

    return is_array($flash) ? $flash : null;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}
