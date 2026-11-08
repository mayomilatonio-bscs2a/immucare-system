
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Only accept POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit("Method not allowed.");
}

// Verify CSRF token.
if (!validateCsrfToken()) {
    http_response_code(403);
    exit("Invalid or expired request. Please reload the page.");
}

// Validate announcement ID.
$id = filter_input(
    INPUT_POST,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$action = $_POST["action"] ?? "";

if (!$id || !is_string($action)) {
    http_response_code(400);
    exit("Invalid announcement request.");
}

// Map actions to database statuses.
$actions = [
    "publish" => "Active",
    "unpublish" => "Draft",
    "archive" => "Archived"
];

if (!array_key_exists($action, $actions)) {
    http_response_code(400);
    exit("Invalid announcement action.");
}

$newStatus = $actions[$action];

try {

    // Check existing announcement.
    $stmt = $mysqli->prepare("
        SELECT status
        FROM announcements
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $announcement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$announcement) {
        http_response_code(404);
        exit("Announcement not found.");
    }

    // Update status only if it has changed.
    if ($announcement["status"] !== $newStatus) {

        $stmt = $mysqli->prepare("
            UPDATE announcements
            SET status = ?
            WHERE id = ?
        ");

        $stmt->bind_param("si", $newStatus, $id);
        $stmt->execute();
        $stmt->close();
    }

    $messages = [
        "publish" => "Announcement published successfully.",
        "unpublish" => "Announcement unpublished successfully.",
        "archive" => "Announcement archived successfully."
    ];

    setUserFlash($messages[$action]);

    header("Location: announcements.php");
    exit;

} catch (mysqli_sql_exception $e) {

    error_log(
        "Announcement status update failed: " .
        $e->getMessage()
    );

    http_response_code(500);
    exit("Unable to update announcement status.");
}
