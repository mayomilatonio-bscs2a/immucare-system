
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function h($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function redirectAppointments($message, $success = false) {
    $_SESSION[
        $success ? "appointment_flash" : "appointment_error"
    ] = $message;

    header("Location: appointments.php");
    exit;
}

// CSRF protection.
if (empty($_SESSION["reschedule_csrf"])) {
    $_SESSION["reschedule_csrf"] = bin2hex(random_bytes(32));
}

// Get appointment ID.
$appointmentId = filter_var(
    $_SERVER["REQUEST_METHOD"] === "POST"
        ? ($_POST["appointment_id"] ?? null)
        : ($_GET["id"] ?? null),
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$appointmentId) {
    redirectAppointments("Invalid appointment ID.");
}

$allowedStatuses = [
    "pending",
    "scheduled",
    "approved",
    "accepted"
];

$error = "";

// Retrieve appointment information.
$stmt = $mysqli->prepare("
    SELECT
        a.id,
        a.patient_id,
        a.vaccine,
        a.appointment_date,
        a.appointment_time,
        a.status,
        p.baby_name,
        p.guardian
    FROM appointments a
    LEFT JOIN patients p ON p.id = a.patient_id
    WHERE a.id = ?
");

$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    redirectAppointments("Appointment not found.");
}

$currentStatus = strtolower(
    trim((string) $appointment["status"])
);

if (!in_array($currentStatus, $allowedStatuses, true)) {
    redirectAppointments(
        "This appointment can no longer be rescheduled."
    );
}

$newDate = (string) ($appointment["appointment_date"] ?? "");
$newTime = substr(
    (string) ($appointment["appointment_time"] ?? ""),
    0,
    5
);

$reason = "";

// Handle rescheduling.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["reschedule_csrf"], $token)
    ) {
        $error = "Invalid form request. Refresh and try again.";

    } else {

        $dateInput = $_POST["appointment_date"] ?? null;
        $timeInput = $_POST["appointment_time"] ?? null;
        $reasonInput = $_POST["reason"] ?? null;

        $newDate = is_string($dateInput) ? trim($dateInput) : "";
        $newTime = is_string($timeInput) ? trim($timeInput) : "";
        $reason = is_string($reasonInput) ? trim($reasonInput) : "";

        $dateObject = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            $newDate
        );

        $dateErrors = DateTimeImmutable::getLastErrors();

        $validDate =
            $dateObject !== false &&
            ($dateErrors === false ||
                ($dateErrors["warning_count"] === 0 &&
                 $dateErrors["error_count"] === 0)) &&
            $dateObject->format("Y-m-d") === $newDate;

        $validTime = preg_match(
            "/^(?:[01]\d|2[0-3]):[0-5]\d$/",
            $newTime
        ) === 1;

        if (!$validDate || !$validTime) {

            $error = "Please enter a valid appointment date and time.";

        } elseif (
            !is_string($reasonInput) ||
            strlen($reason) < 5 ||
            strlen($reason) > 255
        ) {

            $error = "Please provide a reason between 5 and 255 characters.";

        } else {

            $newSchedule = DateTimeImmutable::createFromFormat(
                "!Y-m-d H:i",
                $newDate . " " . $newTime
            );

            if (
                $newSchedule === false ||
                $newSchedule <= new DateTimeImmutable()
            ) {

                $error = "The new schedule must be in the future.";

            } else {

                try {

                    $mysqli->begin_transaction();

                    // Lock appointment during update.
                    $stmt = $mysqli->prepare("
                        SELECT
                            patient_id,
                            status,
                            appointment_date,
                            appointment_time
                        FROM appointments
                        WHERE id = ?
                        FOR UPDATE
                    ");

                    $stmt->bind_param("i", $appointmentId);
                    $stmt->execute();

                    $locked = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$locked) {
                        throw new RuntimeException(
                            "Appointment no longer exists."
                        );
                    }

                    $oldStatus = (string) $locked["status"];
                    $oldDate = (string) $locked["appointment_date"];
                    $oldTime = (string) $locked["appointment_time"];
                    $patientId = (int) $locked["patient_id"];

                    if (!in_array(
                        strtolower(trim($oldStatus)),
                        $allowedStatuses,
                        true
                    )) {
                        throw new RuntimeException(
                            "This appointment can no longer be rescheduled."
                        );
                    }

                    $oldTimeShort = substr($oldTime, 0, 5);

                    if (
                        $oldDate === $newDate &&
                        $oldTimeShort === $newTime
                    ) {
                        throw new RuntimeException(
                            "Please choose a different date or time."
                        );
                    }

                    // Check conflicting active appointments.
                    $stmt = $mysqli->prepare("
                        SELECT id
                        FROM appointments
                        WHERE patient_id = ?
                          AND id <> ?
                          AND appointment_date = ?
                          AND appointment_time = ?
                          AND LOWER(TRIM(status)) IN (
                              'pending',
                              'scheduled',
                              'approved',
                              'accepted'
                          )
                        LIMIT 1
                    ");

                    $stmt->bind_param(
                        "iiss",
                        $patientId,
                        $appointmentId,
                        $newDate,
                        $newTime
                    );

                    $stmt->execute();
                    $conflict = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($conflict) {
                        throw new RuntimeException(
                            "This patient already has an active appointment at that date and time."
                        );
                    }

                    // Retrieve linked guardian request.
                    $stmt = $mysqli->prepare("
                        SELECT id, status
                        FROM appointment_requests
                        WHERE appointment_id = ?
                          AND patient_id = ?
                        FOR UPDATE
                    ");

                    $stmt->bind_param(
                        "ii",
                        $appointmentId,
                        $patientId
                    );

                    $stmt->execute();

                    $linkedRequests = $stmt->get_result()
                        ->fetch_all(MYSQLI_ASSOC);

                    $stmt->close();

                    if (count($linkedRequests) > 1) {
                        throw new RuntimeException(
                            "Multiple guardian requests are linked to this appointment."
                        );
                    }

                    if (count($linkedRequests) === 1) {

                        $requestStatus = strtolower(
                            trim((string) $linkedRequests[0]["status"])
                        );

                        if (!in_array(
                            $requestStatus,
                            ["pending", "approved"],
                            true
                        )) {
                            throw new RuntimeException(
                                "The linked guardian request cannot be rescheduled."
                            );
                        }
                    }

                    // Update appointment.
                    $stmt = $mysqli->prepare("
                        UPDATE appointments
                        SET
                            appointment_date = ?,
                            appointment_time = ?
                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "ssi",
                        $newDate,
                        $newTime,
                        $appointmentId
                    );

                    $stmt->execute();
                    $stmt->close();

                    // Synchronize guardian request.
                    if (count($linkedRequests) === 1) {

                        $guardianNote =
                            "Appointment rescheduled to " .
                            $newDate . " at " . $newTime .
                            ". Reason: " . $reason;

                        $requestId = (int) $linkedRequests[0]["id"];

                        $stmt = $mysqli->prepare("
                            UPDATE appointment_requests
                            SET
                                appointment_date = ?,
                                appointment_time = ?,
                                admin_note = ?
                            WHERE id = ?
                              AND appointment_id = ?
                        ");

                        $stmt->bind_param(
                            "sssii",
                            $newDate,
                            $newTime,
                            $guardianNote,
                            $requestId,
                            $appointmentId
                        );

                        $stmt->execute();
                        $stmt->close();
                    }

                    // Record audit history.
                    $staffId = (int) ($_SESSION["user_id"] ?? 0);

                    if ($staffId <= 0) {
                        throw new RuntimeException(
                            "Administrator session has expired."
                        );
                    }

                    $action = "Rescheduled";
                    $newStatus = $oldStatus;

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
                        $newDate,
                        $oldTime,
                        $newTime,
                        $reason
                    );

                    $stmt->execute();
                    $stmt->close();

                    // Commit all updates.
                    $mysqli->commit();

                    redirectAppointments(
                        "Appointment rescheduled successfully.",
                        true
                    );

                } catch (Throwable $e) {

                    try {
                        $mysqli->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log($rollbackError->getMessage());
                    }

                    error_log(
                        "Appointment reschedule failed: " .
                        $e->getMessage()
                    );

                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : "Unable to reschedule the appointment.";
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reschedule Appointment | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>
        html,
        body {
            min-height: 100%;
            height: auto;
            overflow-y: auto;
        }

        .dashboard {
            min-height: 100vh;
            height: auto;
        }

        .main {
            min-height: 100vh;
            height: auto;
            min-width: 0;
            overflow: visible;
        }

        .page-container {
            padding: 30px;
        }

        /* Standard upper-left Back button */
        .page-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            margin-bottom: 20px;
            background: #e8f8f3;
            color: #315f68;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s ease;
        }

        .page-back:hover {
            background: #d0eee4;
        }

        .page-back:focus-visible {
            outline: 3px solid #315f68;
            outline-offset: 3px;
        }

        .page-back svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: #315f68;
            font-size: 26px;
        }

        .form-card {
            max-width: 760px;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(70,110,110,.08);
        }

        .patient-info {
            background: #f3faf7;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .patient-info h2 {
            color: #315f68;
            font-size: 18px;
            margin-bottom: 15px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .info-item p {
            font-size: 12px;
            color: #82999c;
            margin-bottom: 5px;
        }

        .info-item strong {
            color: #315f68;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #315f68;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d6e6e0;
            border-radius: 9px;
            font: inherit;
            box-sizing: border-box;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .save-btn {
            border: none;
            background: #61bca4;
            color: white;
            padding: 12px 20px;
            border-radius: 9px;
            font-weight: 600;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #459d86;
        }

        .cancel-btn {
            background: #edf5f2;
            color: #315f68;
            padding: 12px 20px;
            border-radius: 9px;
            text-decoration: none;
            font-weight: 600;
        }

        .cancel-btn:hover {
            background: #dceee7;
        }

        .error-message {
            padding: 14px 16px;
            background: #ffe8ed;
            color: #b44961;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .page-container {
                padding: 15px;
            }

            .form-card {
                padding: 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .page-back {
                margin-bottom: 18px;
            }
        }
    </style>
</head>

<body>

<div class="dashboard">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <img
                src="../images/logo.png"
                alt="ImmuCare Logo"
                width="150"
            >
        </div>

        <nav>
            <a href="../index.php">Dashboard</a>
            <a href="records.php">Records</a>
            <a href="guardian_accounts.php">Guardian Accounts</a>
            <a href="management.php" class="active">Management</a>
            <a href="monitoring.php">Monitoring</a>
            <a href="announcements.php">Announcements</a>
            <a href="reports.php">Reports</a>
            <a href="settings.php">Settings</a>
        </nav>

    </aside>

    <!-- MAIN -->
    <main class="main">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="welcome-admin">
                Reschedule Appointment
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <section class="page-container">

            <!-- Upper-left Back button -->
            <a
                href="appointments.php"
                class="page-back"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Back to Appointments
            </a>

            <div class="page-header">
                <h1>Reschedule Appointment</h1>
            </div>

            <div class="form-card">

                <?php if ($error !== ""): ?>
                    <div class="error-message" role="alert">
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <!-- APPOINTMENT INFORMATION -->
                <div class="patient-info">

                    <h2>Appointment Information</h2>

                    <div class="info-grid">

                        <div class="info-item">
                            <p>Patient</p>
                            <strong>
                                <?= h($appointment["baby_name"] ?? "Unknown") ?>
                            </strong>
                        </div>

                        <div class="info-item">
                            <p>Guardian</p>
                            <strong>
                                <?= h($appointment["guardian"] ?? "—") ?>
                            </strong>
                        </div>

                        <div class="info-item">
                            <p>Vaccine</p>
                            <strong>
                                <?= h($appointment["vaccine"]) ?>
                            </strong>
                        </div>

                        <div class="info-item">
                            <p>Current Status</p>
                            <strong>
                                <?= h($appointment["status"]) ?>
                            </strong>
                        </div>

                        <div class="info-item">
                            <p>Current Date</p>
                            <strong>
                                <?= h($appointment["appointment_date"]) ?>
                            </strong>
                        </div>

                        <div class="info-item">
                            <p>Current Time</p>
                            <strong>
                                <?= h($appointment["appointment_time"]) ?>
                            </strong>
                        </div>

                    </div>
                </div>

                <!-- RESCHEDULE FORM -->
                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION["reschedule_csrf"]) ?>"
                    >

                    <input
                        type="hidden"
                        name="appointment_id"
                        value="<?= (int) $appointmentId ?>"
                    >

                    <div class="form-group">
                        <label for="appointment_date">
                            New Appointment Date
                        </label>

                        <input
                            type="date"
                            id="appointment_date"
                            name="appointment_date"
                            value="<?= h($newDate) ?>"
                            min="<?= date("Y-m-d") ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="appointment_time">
                            New Appointment Time
                        </label>

                        <input
                            type="time"
                            id="appointment_time"
                            name="appointment_time"
                            value="<?= h($newTime) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="reason">
                            Reason for Rescheduling
                        </label>

                        <textarea
                            id="reason"
                            name="reason"
                            maxlength="255"
                            minlength="5"
                            placeholder="Enter the reason for changing this appointment..."
                            required
                        ><?= h($reason) ?></textarea>
                    </div>

                    <div class="form-actions">

                        <button
                            type="submit"
                            class="save-btn"
                        >
                            Save New Schedule
                        </button>

                        <a
                            href="appointments.php"
                            class="cancel-btn"
                        >
                            Cancel
                        </a>

                    </div>

                </form>

            </div>

        </section>

    </main>

</div>

</body>
</html>
