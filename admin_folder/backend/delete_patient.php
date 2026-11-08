
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

// Only accept POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed.");
}

// Verify CSRF token.
$submittedToken = $_POST["csrf_token"] ?? "";

if (
    !is_string($submittedToken) ||
    empty($_SESSION["delete_patient_csrf"]) ||
    !hash_equals(
        $_SESSION["delete_patient_csrf"],
        $submittedToken
    )
) {
    http_response_code(403);
    exit("Invalid request. Please refresh the Records page.");
}

// Validate patient ID.
$id = filter_var(
    $_POST["id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$id) {
    http_response_code(400);
    exit("Invalid patient ID.");
}

try {

    $mysqli->begin_transaction();

    // Lock the patient row during deletion.
    $stmt = $mysqli->prepare("
        SELECT id
        FROM patients
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$patient) {
        throw new Exception("Patient not found.");
    }

    // Check vaccination records.
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM vaccination_records
        WHERE patient_id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $vaccinationCount = (int)
        $stmt->get_result()->fetch_assoc()["total"];

    $stmt->close();

    // Check appointments.
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE patient_id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $appointmentCount = (int)
        $stmt->get_result()->fetch_assoc()["total"];

    $stmt->close();

    if ($vaccinationCount > 0 || $appointmentCount > 0) {
        throw new Exception(
            "Cannot delete this patient because vaccination records or appointments exist."
        );
    }

    // Delete only if no related records were found.
    $stmt = $mysqli->prepare("
        DELETE FROM patients
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();

    $_SESSION["patient_flash"] = [
        "type" => "success",
        "message" => "Patient deleted successfully."
    ];

} catch (Throwable $e) {

    $mysqli->rollback();

    $_SESSION["patient_flash"] = [
        "type" => "error",
        "message" =>
            $e instanceof mysqli_sql_exception
            ? "Unable to delete patient. Related records may exist."
            : $e->getMessage()
    ];
}

header("Location: ../pages/records.php");
exit;
