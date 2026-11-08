
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function redirectVaccination($patientId, $message, $success = false) {
    $_SESSION[
        $success ? "vaccination_flash" : "vaccination_error"
    ] = $message;

    header(
        "Location: vaccination_history.php?id=" .
        (int) $patientId
    );
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed.");
}

$patientId = filter_var(
    $_POST["patient_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$recordId = filter_var(
    $_POST["record_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$patientId || !$recordId) {
    http_response_code(400);
    exit("Invalid patient or vaccination record.");
}

$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["vaccination_delete_csrf"]) ||
    !hash_equals(
        $_SESSION["vaccination_delete_csrf"],
        $token
    )
) {
    redirectVaccination(
        $patientId,
        "Invalid request. Please refresh and try again."
    );
}

$reason = $_POST["reason"] ?? "";

if (!is_string($reason)) {
    redirectVaccination(
        $patientId,
        "Invalid deletion reason."
    );
}

$reason = trim($reason);

if (
    mb_strlen($reason, "UTF-8") < 5 ||
    mb_strlen($reason, "UTF-8") > 500
) {
    redirectVaccination(
        $patientId,
        "Please provide a deletion reason between 5 and 500 characters."
    );
}

$administratorId = (int) ($_SESSION["user_id"] ?? 0);

if ($administratorId <= 0) {
    redirectVaccination(
        $patientId,
        "Administrator session expired."
    );
}

$transactionStarted = false;

try {

    $mysqli->begin_transaction();
    $transactionStarted = true;

    // Lock the vaccination record before changing it.
    $stmt = $mysqli->prepare("
        SELECT
            id,
            patient_id,
            appointment_id,
            vaccine,
            date_given,
            deleted_at
        FROM vaccination_records
        WHERE id = ?
          AND patient_id = ?
        FOR UPDATE
    ");

    $stmt->bind_param(
        "ii",
        $recordId,
        $patientId
    );

    $stmt->execute();

    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        throw new RuntimeException(
            "Vaccination record not found."
        );
    }

    if ($record["deleted_at"] !== null) {
        throw new RuntimeException(
            "This vaccination record has already been deleted."
        );
    }

    // Do not remove the active vaccination evidence
    // from an appointment already marked Completed.
    if (!empty($record["appointment_id"])) {

        $appointmentId = (int) $record["appointment_id"];

        $stmt = $mysqli->prepare("
            SELECT status
            FROM appointments
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("i", $appointmentId);
        $stmt->execute();

        $appointment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $appointment &&
            strcasecmp(
                (string) $appointment["status"],
                "Completed"
            ) === 0
        ) {
            throw new RuntimeException(
                "This vaccination belongs to a completed appointment. " .
                "Please review and correct the appointment first."
            );
        }
    }

    // Soft-delete the record.
    $stmt = $mysqli->prepare("
        UPDATE vaccination_records
        SET
            deleted_at = NOW(),
            deleted_by = ?,
            deletion_reason = ?
        WHERE id = ?
          AND patient_id = ?
          AND deleted_at IS NULL
    ");

    $stmt->bind_param(
        "isii",
        $administratorId,
        $reason,
        $recordId,
        $patientId
    );

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        throw new RuntimeException(
            "Unable to delete the vaccination record."
        );
    }

    $mysqli->commit();
    $transactionStarted = false;

    $_SESSION["vaccination_delete_csrf"] =
        bin2hex(random_bytes(32));

    redirectVaccination(
        $patientId,
        "Vaccination record removed from active history. " .
        "The original record was retained for audit purposes.",
        true
    );

} catch (Throwable $e) {

    if ($transactionStarted) {
        try {
            $mysqli->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                "Vaccination deletion rollback error: " .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        "Vaccination deletion error: " .
        $e->getMessage()
    );

    redirectVaccination(
        $patientId,
        $e instanceof RuntimeException
            ? $e->getMessage()
            : "Unable to delete vaccination record. " .
              "Check the PHP error log."
    );
}
