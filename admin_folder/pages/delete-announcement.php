
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Only accept POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method not allowed.");
}

// Validate CSRF token.
if (!validateCsrfToken()) {
    http_response_code(403);
    exit("Invalid or expired request. Please return and try again.");
}

// Validate announcement ID.
$id = filter_input(
    INPUT_POST,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$id) {
    http_response_code(400);
    exit("A valid announcement ID is required.");
}

try {

    // Check whether the announcement exists.
    $stmt = $mysqli->prepare("
        SELECT id
        FROM announcements
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $announcement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$announcement) {

        setUserFlash("Announcement not found.");

        header("Location: announcements.php");
        exit;
    }

    // Permanently delete the selected announcement.
    $stmt = $mysqli->prepare("
        DELETE FROM announcements
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $deleted = $stmt->affected_rows > 0;

    $stmt->close();

    if ($deleted) {
        setUserFlash("Announcement deleted successfully.");
    } else {
        setUserFlash("Announcement could not be found.");
    }

    header("Location: announcements.php");
    exit;

} catch (mysqli_sql_exception $e) {

    error_log(
        "Announcement deletion failed: " . $e->getMessage()
    );

    http_response_code(500);

    exit("Unable to delete the announcement. Please try again.");
}
