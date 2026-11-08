
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function redirectAppointment($message, $success = false) {
    $key = $success
        ? "appointment_flash"
        : "appointment_error";

    $_SESSION[$key] = $message;

    header("Location: appointments.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: appointments.php");
    exit;
}

// Verify CSRF token.
$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["appointment_status_csrf"]) ||
    !hash_equals(
        $_SESSION["appointment_status_csrf"],
        $token
    )
) {
    redirectAppointment(
        "Invalid request. Refresh the page and try again."
    );
}

// Validate appointment ID.
$appointmentId = filter_var(
    $_POST["appointment_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

// Validate action.
$action = $_POST["action"] ?? "";

if (
    !$appointmentId ||
    !is_string($action) ||
    !in_array($action, ["accept", "reject"], true)
) {
    redirectAppointment(
        "Invalid appointment or action."
    );
}

$staffId = (int) ($_SESSION["user_id"] ?? 0);

if ($staffId <= 0) {
    redirectAppointment(
        "Your administrator session has expired."
    );
}

$isAccept = $action === "accept";

$newStatus = $isAccept
    ? "Approved"
    : "Rejected";

$requestStatus = $isAccept
    ? "Approved"
    : "Rejected";

$auditAction = $isAccept
    ? "Accepted"
    : "Rejected";

$reason = $isAccept
    ? "Appointment accepted by administrator."
    : "Appointment rejected by administrator.";

$transactionStarted = false;

try {

    /*
     * Check required database structure.
     */

    $columnResult = $mysqli->query("
        SHOW COLUMNS
        FROM appointment_requests
        LIKE 'appointment_id'
    ");

    $hasAppointmentLink = (
        $columnResult->num_rows > 0
    );

    $columnResult->free();

    if (!$hasAppointmentLink) {
        throw new RuntimeException(
            "Database setup incomplete: appointment_requests " .
            "is missing the appointment_id column."
        );
    }

    /*
     * Confirm the appointments.status column
     * supports the intended status.
     */

    $statusResult = $mysqli->query("
        SHOW COLUMNS
        FROM appointments
        LIKE 'status'
    ");

    $statusColumn = $statusResult->fetch_assoc();
    $statusResult->free();

    if (!$statusColumn) {
        throw new RuntimeException(
            "The appointments status column was not found."
        );
    }

    $statusType = (string) $statusColumn["Type"];

    if (
        stripos($statusType, "enum(") === 0 &&
        strpos($statusType, "'" . $newStatus . "'") === false
    ) {
        throw new RuntimeException(
            "Database status configuration does not support " .
            $newStatus . ". Check appointments.status."
        );
    }

    /*
     * Begin transaction.
     */

    $mysqli->begin_transaction();
    $transactionStarted = true;

    /*
     * 1. Lock appointment.
     */

    $stmt = $mysqli->prepare("
        SELECT
            id,
            patient_id,
            status,
            appointment_date,
            appointment_time
        FROM appointments
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->bind_param(
        "i",
        $appointmentId
    );

    $stmt->execute();

    $appointment = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$appointment) {
        throw new RuntimeException(
            "Appointment not found."
        );
    }

    $oldStatus = trim(
        (string) $appointment["status"]
    );

    $oldDate = $appointment["appointment_date"];
    $oldTime = $appointment["appointment_time"];

    if (strcasecmp($oldStatus, "Pending") !== 0) {
        throw new RuntimeException(
            "Only pending appointments can be accepted or rejected. " .
            "Current status: " . $oldStatus
        );
    }

    /*
     * 2. Update main appointment.
     */

    $stmt = $mysqli->prepare("
        UPDATE appointments
        SET status = ?
        WHERE id = ?
          AND status = 'Pending'
    ");

    $stmt->bind_param(
        "si",
        $newStatus,
        $appointmentId
    );

    $stmt->execute();

    $updatedAppointments = $stmt->affected_rows;
    $stmt->close();

    if ($updatedAppointments !== 1) {
        throw new RuntimeException(
            "The appointment status could not be updated."
        );
    }

    /*
     * 3. Update linked guardian request.
     *
     * Only the request linked to this appointment
     * is changed. Other requests remain untouched.
     */

    $stmt = $mysqli->prepare("
        UPDATE appointment_requests
        SET
            status = ?,
            admin_note = ?
        WHERE appointment_id = ?
          AND patient_id = ?
          AND status = 'Pending'
    ");

    $patientId = (int) $appointment["patient_id"];

    $stmt->bind_param(
        "ssii",
        $requestStatus,
        $reason,
        $appointmentId,
        $patientId
    );

    $stmt->execute();

    $updatedRequests = $stmt->affected_rows;
    $stmt->close();

    /*
     * An appointment may have been created
     * directly by clinic staff, without a
     * guardian appointment request.
     *
     * In that case, zero linked requests
     * is acceptable.
     */

    /*
     * 4. Save appointment audit history.
     */

    $stmt = $mysqli->prepare("
        INSERT INTO appointment_audit_logs (
            appointment_id,
            changed_by,
            action,
            old_status,
            new_status,
            old_date,
            new_date,
            old_time,
            new_time,
            reason
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iissssssss",
        $appointmentId,
        $staffId,
        $auditAction,
        $oldStatus,
        $newStatus,
        $oldDate,
        $oldDate,
        $oldTime,
        $oldTime,
        $reason
    );

    $stmt->execute();
    $stmt->close();

    /*
     * 5. Commit all changes.
     */

    $mysqli->commit();
    $transactionStarted = false;

    $_SESSION["appointment_status_csrf"] =
        bin2hex(random_bytes(32));

    if ($isAccept) {

        redirectAppointment(
            $updatedRequests > 0
                ? "Appointment accepted successfully. " .
                  "The guardian request was also approved."
                : "Appointment accepted successfully. " .
                  "No linked guardian request was found.",
            true
        );

    } else {

        redirectAppointment(
            $updatedRequests > 0
                ? "Appointment rejected successfully. " .
                  "The guardian request was also rejected."
                : "Appointment rejected successfully. " .
                  "No linked guardian request was found.",
            true
        );
    }

} catch (Throwable $e) {

    if ($transactionStarted) {
        try {
            $mysqli->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                "Appointment rollback failed: " .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        "Appointment status update failed: " .
        $e->getMessage()
    );

    if ($e instanceof RuntimeException) {

        redirectAppointment(
            $e->getMessage()
        );

    }

    redirectAppointment(
        "Unable to update appointment. " .
        "Please check the PHP error log for details."
    );
}

?>
