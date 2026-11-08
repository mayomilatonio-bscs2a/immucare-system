
<?php

require_once __DIR__ . "/common.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/appointments.php");
    exit;
}

$guardian = currentGuardian();

if ($guardian === "") {
    http_response_code(401);
    exit("Sign in before requesting an appointment.");
}

if (!validateCsrfToken()) {
    http_response_code(400);
    exit("The form expired. Reload the page and try again.");
}

function appointmentRedirect($message, $type = "error") {
    setUserFlash($message, $type);
    header("Location: ../pages/appointments.php");
    exit;
}

$patientId = filter_var(
    $_POST["patient_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$date = $_POST["appointment_date"] ?? "";
$time = $_POST["appointment_time"] ?? "";
$notesInput = $_POST["notes"] ?? "";

$notesIsString = is_string($notesInput);
$notes = $notesIsString ? trim($notesInput) : "";

$dateObject = is_string($date)
    ? DateTimeImmutable::createFromFormat("!Y-m-d", $date)
    : false;

$dateErrors = DateTimeImmutable::getLastErrors();

$timeObject = is_string($time)
    ? DateTimeImmutable::createFromFormat("!H:i", $time)
    : false;

$timeErrors = DateTimeImmutable::getLastErrors();

if (
    !$patientId
    || !is_string($date)
    || !$dateObject
    || ($dateErrors !== false && (
        $dateErrors["warning_count"] > 0 ||
        $dateErrors["error_count"] > 0
    ))
    || $dateObject->format("Y-m-d") !== $date
    || $date < date("Y-m-d")
    || !is_string($time)
    || !$timeObject
    || ($timeErrors !== false && (
        $timeErrors["warning_count"] > 0 ||
        $timeErrors["error_count"] > 0
    ))
    || $timeObject->format("H:i") !== $time
    || !$notesIsString
    || strlen($notes) > 500
) {
    appointmentRedirect(
        "Enter a valid patient, date, time, and note (up to 500 characters)."
    );
}

// Confirm the patient belongs to the signed-in guardian.
$patientStmt = $mysqli->prepare("
    SELECT id
    FROM patients
    WHERE id = ? AND guardian = ?
    LIMIT 1
");

$patientStmt->bind_param("is", $patientId, $guardian);
$patientStmt->execute();

$patient = $patientStmt->get_result()->fetch_assoc();
$patientStmt->close();

if (!$patient) {
    http_response_code(403);
    exit("That patient is not linked to your account.");
}

try {
    $mysqli->begin_transaction();

    // Prevent another simultaneous submission for the same patient.
    $lockStmt = $mysqli->prepare("
        SELECT id
        FROM patients
        WHERE id = ? AND guardian = ?
        FOR UPDATE
    ");

    $lockStmt->bind_param("is", $patientId, $guardian);
    $lockStmt->execute();

    $lockedPatient = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if (!$lockedPatient) {
        throw new RuntimeException("Patient access could not be verified.");
    }

    // Prevent duplicate active requests for the same schedule.
    $duplicateStmt = $mysqli->prepare("
        SELECT id
        FROM appointment_requests
        WHERE patient_id = ?
          AND appointment_date = ?
          AND appointment_time = ?
          AND status IN ('Pending', 'Approved')
        LIMIT 1
    ");

    $duplicateStmt->bind_param("iss", $patientId, $date, $time);
    $duplicateStmt->execute();

    $duplicate = $duplicateStmt->get_result()->fetch_assoc();
    $duplicateStmt->close();

    if ($duplicate) {
        $mysqli->rollback();

        appointmentRedirect(
            "An active appointment request already exists for this patient, date, and time."
        );
    }

    // Create the appointment record.
    $vaccine = "To be determined";
    $status = "Pending";

    $appointmentStmt = $mysqli->prepare("
        INSERT INTO appointments (
            patient_id,
            vaccine,
            appointment_date,
            appointment_time,
            status
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $appointmentStmt->bind_param(
        "issss",
        $patientId,
        $vaccine,
        $date,
        $time,
        $status
    );

    $appointmentStmt->execute();

    $appointmentId = $mysqli->insert_id;
    $appointmentStmt->close();

    // Create the guardian request linked to the appointment.
    $requestStmt = $mysqli->prepare("
        INSERT INTO appointment_requests (
            appointment_id,
            patient_id,
            guardian,
            appointment_date,
            appointment_time,
            notes,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, 'Pending')
    ");

    $requestStmt->bind_param(
        "iissss",
        $appointmentId,
        $patientId,
        $guardian,
        $date,
        $time,
        $notes
    );

    $requestStmt->execute();
    $requestStmt->close();

    // Both records must be saved together.
    $mysqli->commit();

    appointmentRedirect(
        "Your appointment request was sent to the clinic.",
        "success"
    );

} catch (Throwable $e) {
    try {
        $mysqli->rollback();
    } catch (Throwable $rollbackError) {
        error_log($rollbackError->getMessage());
    }

    error_log(
        "Guardian appointment submission failed: " .
        $e->getMessage()
    );

    appointmentRedirect(
        "Unable to submit the appointment request. Please try again."
    );
}
