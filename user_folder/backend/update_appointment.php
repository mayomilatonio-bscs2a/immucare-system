<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Administrator access is required.");
}

require_once __DIR__ . "/common.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../admin_folder/pages/appointments.php");
    exit;
}

if (!validateCsrfToken()) {
    http_response_code(400);
    exit("The form expired. Reload the page and try again.");
}

$requestId = filter_var($_POST["request_id"] ?? null, FILTER_VALIDATE_INT);
$action = $_POST["action"] ?? "";
$adminNote = $_POST["admin_note"] ?? "";
$adminNoteIsString = is_string($adminNote);
$adminNote = $adminNoteIsString ? trim($adminNote) : "";
$date = $_POST["appointment_date"] ?? "";
$time = $_POST["appointment_time"] ?? "";

if (!$requestId || !is_string($action) || !$adminNoteIsString || strlen($adminNote) > 500) {
    http_response_code(400);
    exit("Invalid appointment request.");
}

if ($action === "reschedule") {
    $dateObject = is_string($date) ? DateTimeImmutable::createFromFormat("!Y-m-d", $date) : false;
    $dateErrors = DateTimeImmutable::getLastErrors();
    $timeObject = is_string($time) ? DateTimeImmutable::createFromFormat("!H:i", $time) : false;
    $timeErrors = DateTimeImmutable::getLastErrors();

    if (
        !is_string($date)
        || !$dateObject
        || ($dateErrors !== false && ($dateErrors["warning_count"] > 0 || $dateErrors["error_count"] > 0))
        || $dateObject->format("Y-m-d") !== $date
        || $date < date("Y-m-d")
        || !is_string($time)
        || !$timeObject
        || ($timeErrors !== false && ($timeErrors["warning_count"] > 0 || $timeErrors["error_count"] > 0))
        || $timeObject->format("H:i") !== $time
    ) {
        setUserFlash("Enter a valid future date and time.", "error");
        header("Location: ../../admin_folder/pages/appointments.php");
        exit;
    }

    $stmt = $mysqli->prepare("
        UPDATE appointment_requests
        SET appointment_date = ?, appointment_time = ?, admin_note = ?
        WHERE id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("sssi", $date, $time, $adminNote, $requestId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();
    setUserFlash($updated ? "Appointment request rescheduled." : "No pending request was changed.", $updated ? "success" : "error");
} elseif ($action === "approve" || $action === "reject") {
    $status = $action === "approve" ? "Approved" : "Rejected";
    $stmt = $mysqli->prepare("
        UPDATE appointment_requests
        SET status = ?, admin_note = ?
        WHERE id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("ssi", $status, $adminNote, $requestId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();
    setUserFlash($updated ? "Appointment request " . strtolower($status) . "." : "No pending request was changed.", $updated ? "success" : "error");
} else {
    http_response_code(400);
    exit("Unsupported appointment action.");
}

header("Location: ../../admin_folder/pages/appointments.php");
exit;
