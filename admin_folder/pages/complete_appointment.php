
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function redirectAppointments($message, $success = false) {

    $_SESSION[
        $success
            ? "appointment_flash"
            : "appointment_error"
    ] = $message;

    header("Location: appointments.php");
    exit;
}

// Only accept POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirectAppointments("Invalid request method.");
}

// Validate CSRF token.
$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["appointment_status_csrf"]) ||
    !hash_equals(
        $_SESSION["appointment_status_csrf"],
        $token
    )
) {
    redirectAppointments(
        "Invalid request. Please refresh and try again."
    );
}

// Validate appointment ID.
$appointmentId = filter_var(
    $_POST["appointment_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$appointmentId) {
    redirectAppointments("Invalid appointment ID.");
}

// Validate administrator session.
$staffId = (int) ($_SESSION["user_id"] ?? 0);

if ($staffId <= 0) {
    redirectAppointments("Your session has expired.");
}

try {

    $mysqli->begin_transaction();

    // 1. Lock the appointment.
$stmt = $mysqli->prepare("
    SELECT
        id,
        patient_id,
        vaccine,
        date_given
    FROM vaccination_records
    WHERE appointment_id = ?
      AND deleted_at IS NULL
    FOR UPDATE
");

    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();

    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        throw new DomainException(
            "Appointment not found."
        );
    }

    $oldStatus = (string) $appointment["status"];
    $oldDate = (string) $appointment["appointment_date"];
    $oldTime = (string) $appointment["appointment_time"];
    $patientId = (int) $appointment["patient_id"];

    $appointmentVaccine = trim(
        (string) $appointment["vaccine"]
    );

    // 2. Only active scheduled appointments
    // can be marked completed.
    if (!in_array(
        strtolower(trim($oldStatus)),
        ["scheduled", "approved", "accepted"],
        true
    )) {
        throw new DomainException(
            "Only scheduled appointments can be completed."
        );
    }

    // The actual vaccine must be assigned.
    if (
        $appointmentVaccine === "" ||
        strtolower($appointmentVaccine) === "to be determined"
    ) {
        throw new DomainException(
            "Please assign the actual vaccine before completing this appointment."
        );
    }

    // 3. Check all vaccination records linked
    // to this appointment.
    $stmt = $mysqli->prepare("
        SELECT
            id,
            patient_id,
            vaccine,
            date_given
        FROM vaccination_records
        WHERE appointment_id = ?
        FOR UPDATE
    ");

    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();

    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($records) === 0) {
        throw new DomainException(
            "Cannot complete this appointment. " .
            "Please record the administered vaccine first."
        );
    }

    if (count($records) > 1) {
        throw new DomainException(
            "Multiple vaccination records are linked to this appointment. " .
            "Please review the records before completing it."
        );
    }

    $vaccinationRecord = $records[0];

    // 4. Verify the patient matches.
    if (
        (int) $vaccinationRecord["patient_id"] !== $patientId
    ) {
        throw new DomainException(
            "The vaccination record belongs to a different patient."
        );
    }

    // 5. Verify the administered vaccine matches.
    $recordedVaccine = trim(
        (string) $vaccinationRecord["vaccine"]
    );

    if (
        strcasecmp(
            $recordedVaccine,
            $appointmentVaccine
        ) !== 0
    ) {
        throw new DomainException(
            "The recorded vaccine does not match the appointment vaccine."
        );
    }

    // 6. Validate the vaccination date.
    $dateGiven = (string) (
        $vaccinationRecord["date_given"] ?? ""
    );

    $dateObject = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $dateGiven
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    if (
        !$dateObject ||
        ($dateErrors !== false && (
            $dateErrors["warning_count"] > 0 ||
            $dateErrors["error_count"] > 0
        )) ||
        $dateObject->format("Y-m-d") !== $dateGiven ||
        $dateGiven > date("Y-m-d")
    ) {
        throw new DomainException(
            "The linked vaccination record has an invalid date."
        );
    }

    // 7. Update the appointment status.
    $newStatus = "Completed";

    $stmt = $mysqli->prepare("
        UPDATE appointments
        SET status = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "si",
        $newStatus,
        $appointmentId
    );

    $stmt->execute();
    $stmt->close();

    // 8. Preserve the linked guardian request.
    //
    // appointment_requests only supports:
    // Pending, Approved, Rejected.
    //
    // Therefore, we keep Approved and update
    // the note to explain completion.
    $guardianNote =
        "Appointment completed. Vaccination record #" .
        (int) $vaccinationRecord["id"] .
        " has been verified.";

    $stmt = $mysqli->prepare("
        UPDATE appointment_requests
        SET admin_note = ?
        WHERE appointment_id = ?
          AND patient_id = ?
          AND status = 'Approved'
    ");

    $stmt->bind_param(
        "sii",
        $guardianNote,
        $appointmentId,
        $patientId
    );

    $stmt->execute();
    $stmt->close();

    // 9. Record the completion in audit history.
    $action = "Completed";

    $reason =
        "Appointment completed after verifying vaccination record #" .
        (int) $vaccinationRecord["id"] . ".";

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
        $action,
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

    // 10. Commit all updates together.
    $mysqli->commit();

    redirectAppointments(
        "Appointment completed successfully. " .
        "Vaccination record verified.",
        true
    );

} catch (Throwable $e) {

    try {
        $mysqli->rollback();
    } catch (Throwable $rollbackError) {
        error_log($rollbackError->getMessage());
    }

    error_log(
        "Complete appointment error: " .
        $e->getMessage()
    );

    redirectAppointments(
        $e instanceof DomainException
            ? $e->getMessage()
            : "Unable to complete the appointment."
    );
}
