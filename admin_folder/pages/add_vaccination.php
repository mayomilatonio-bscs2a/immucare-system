
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

// Get patient ID.
$patientId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$patientId) {
    http_response_code(400);
    exit("Invalid patient ID.");
}

// Load patient.
$stmt = $mysqli->prepare("
    SELECT id, baby_name, birthday, guardian
    FROM patients
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    http_response_code(404);
    exit("Patient not found.");
}

// Load active vaccines and available appointments.
try {

    $stmt = $mysqli->prepare("
        SELECT
            id,
            vaccine_name,
            dose,
            recommended_age
        FROM vaccines
        WHERE status = 'Active'
        ORDER BY vaccine_name ASC, dose ASC, id ASC
    ");

    $stmt->execute();
    $activeVaccines = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $mysqli->prepare("
        SELECT
            a.id,
            a.vaccine,
            a.appointment_date,
            a.appointment_time,
            a.status
        FROM appointments AS a
        WHERE a.patient_id = ?
          AND LOWER(TRIM(a.status)) IN (
              'scheduled',
              'approved',
              'accepted'
          )
          AND NOT EXISTS (
              SELECT 1
              FROM vaccination_records AS vr
              WHERE vr.appointment_id = a.id
                AND vr.deleted_at IS NULL
          )
        ORDER BY a.appointment_date DESC, a.id DESC
    ");

    $stmt->bind_param("i", $patientId);
    $stmt->execute();

    $availableAppointments = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (Throwable $e) {

    error_log("Load vaccination form: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load vaccination information.");
}

// CSRF protection.
if (empty($_SESSION["add_vaccination_csrf"])) {
    $_SESSION["add_vaccination_csrf"] =
        bin2hex(random_bytes(32));
}

$error = "";
$vaccineId = 0;
$appointmentId = 0;
$dateGiven = date("Y-m-d");
$remarks = "";

// Preselect appointment from URL.
if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $requestedAppointmentId = filter_input(
        INPUT_GET,
        "appointment_id",
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    if ($requestedAppointmentId) {

        foreach ($availableAppointments as $item) {

            if (
                (int) $item["id"] ===
                (int) $requestedAppointmentId
            ) {
                $appointmentId = (int) $requestedAppointmentId;
                break;
            }
        }
    }
}

// Handle vaccination submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    $vaccineId = filter_var(
        $_POST["vaccine_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    ) ?: 0;

    $appointmentInput = $_POST["appointment_id"] ?? "";

    $appointmentId = $appointmentInput === ""
        ? 0
        : (
            filter_var(
                $appointmentInput,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            ) ?: -1
        );

    $dateInput = $_POST["date_given"] ?? "";
    $remarksInput = $_POST["remarks"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION["add_vaccination_csrf"],
            $token
        )
    ) {
        $error = "Invalid request. Refresh the page.";

    } elseif (
        !is_string($dateInput) ||
        !is_string($remarksInput) ||
        !is_string($appointmentInput)
    ) {
        $error = "Invalid form values.";

    } else {

        $dateGiven = trim($dateInput);
        $remarks = trim($remarksInput);

        if ($vaccineId <= 0) {
            $error = "Please select a vaccine.";

        } elseif ($appointmentId < 0) {
            $error = "Please select a valid appointment.";

        } elseif (mb_strlen($remarks) > 255) {
            $error = "Remarks must not exceed 255 characters.";

        } else {

            $date = DateTimeImmutable::createFromFormat(
                "!Y-m-d",
                $dateGiven
            );

            $dateErrors = DateTimeImmutable::getLastErrors();

            $validDate =
                $date !== false &&
                (
                    $dateErrors === false ||
                    (
                        $dateErrors["warning_count"] === 0 &&
                        $dateErrors["error_count"] === 0
                    )
                ) &&
                $date->format("Y-m-d") === $dateGiven &&
                $dateGiven <= date("Y-m-d") &&
                (
                    empty($patient["birthday"]) ||
                    $dateGiven >= $patient["birthday"]
                );

            if (!$validDate) {

                $error = "Enter a valid vaccination date.";

            } else {

                try {

                    $mysqli->begin_transaction();

                    // 1. Validate selected vaccine.
                    $stmt = $mysqli->prepare("
                        SELECT id, vaccine_name, dose
                        FROM vaccines
                        WHERE id = ?
                          AND status = 'Active'
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $stmt->bind_param("i", $vaccineId);
                    $stmt->execute();

                    $selectedVaccine = $stmt->get_result()
                        ->fetch_assoc();

                    $stmt->close();

                    if (!$selectedVaccine) {
                        throw new DomainException(
                            "Please select an active registered vaccine."
                        );
                    }

                    $vaccineName = trim(
                        (string) $selectedVaccine["vaccine_name"]
                    );

                    if ($vaccineName === "") {
                        throw new DomainException(
                            "The selected vaccine has no name."
                        );
                    }

                    // 2. Handle appointment-linked vaccination.
                    if ($appointmentId > 0) {

                        $stmt = $mysqli->prepare("
                            SELECT
                                id,
                                patient_id,
                                vaccine,
                                status
                            FROM appointments
                            WHERE id = ?
                            FOR UPDATE
                        ");

                        $stmt->bind_param("i", $appointmentId);
                        $stmt->execute();

                        $appointment = $stmt->get_result()
                            ->fetch_assoc();

                        $stmt->close();

                        if (!$appointment) {
                            throw new DomainException(
                                "Selected appointment does not exist."
                            );
                        }

                        if (
                            (int) $appointment["patient_id"]
                            !== (int) $patientId
                        ) {
                            throw new DomainException(
                                "The appointment belongs to another patient."
                            );
                        }

                        $appointmentStatus = strtolower(
                            trim((string) $appointment["status"])
                        );

                        if (!in_array(
                            $appointmentStatus,
                            ["scheduled", "approved", "accepted"],
                            true
                        )) {
                            throw new DomainException(
                                "This appointment is not eligible for vaccination recording."
                            );
                        }

                        // 3. Prevent duplicate records.
                        $stmt = $mysqli->prepare("
                            SELECT id
                            FROM vaccination_records
                            WHERE appointment_id = ?
                              AND deleted_at IS NULL
                            LIMIT 1
                        ");

                        $stmt->bind_param("i", $appointmentId);
                        $stmt->execute();

                        $existingRecord = $stmt->get_result()
                            ->fetch_assoc();

                        $stmt->close();

                        if ($existingRecord) {
                            throw new DomainException(
                                "This appointment already has a vaccination record."
                            );
                        }

                        // 4. Check assigned vaccine.
                        $assignedVaccine = trim(
                            (string) ($appointment["vaccine"] ?? "")
                        );

                        $isPlaceholder =
                            $assignedVaccine === "" ||
                            strcasecmp(
                                $assignedVaccine,
                                "To be determined"
                            ) === 0;

                        if (
                            !$isPlaceholder &&
                            strcasecmp(
                                $assignedVaccine,
                                $vaccineName
                            ) !== 0
                        ) {
                            throw new DomainException(
                                "The selected vaccine does not match the appointment."
                            );
                        }

                        // 5. Replace placeholder with actual vaccine.
                        if ($isPlaceholder) {

                            $stmt = $mysqli->prepare("
                                UPDATE appointments
                                SET vaccine = ?
                                WHERE id = ?
                                  AND patient_id = ?
                            ");

                            $stmt->bind_param(
                                "sii",
                                $vaccineName,
                                $appointmentId,
                                $patientId
                            );

                            $stmt->execute();
                            $stmt->close();
                        }

                        // 6. Insert linked vaccination record.
                        $stmt = $mysqli->prepare("
                            INSERT INTO vaccination_records (
                                patient_id,
                                appointment_id,
                                vaccine_id,
                                vaccine,
                                date_given,
                                remarks
                            )
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->bind_param(
                            "iiisss",
                            $patientId,
                            $appointmentId,
                            $vaccineId,
                            $vaccineName,
                            $dateGiven,
                            $remarks
                        );

                    } else {

                        // 7. Walk-in vaccination without appointment.
                        $stmt = $mysqli->prepare("
                            INSERT INTO vaccination_records (
                                patient_id,
                                appointment_id,
                                vaccine_id,
                                vaccine,
                                date_given,
                                remarks
                            )
                            VALUES (?, NULL, ?, ?, ?, ?)
                        ");

                        $stmt->bind_param(
                            "iisss",
                            $patientId,
                            $vaccineId,
                            $vaccineName,
                            $dateGiven,
                            $remarks
                        );
                    }

                    $stmt->execute();
                    $stmt->close();

                    // Save changes.
                    $mysqli->commit();

                    $_SESSION["add_vaccination_csrf"] =
                        bin2hex(random_bytes(32));

                    $_SESSION["vaccination_flash"] =
                        "Vaccination record added successfully.";

                    header(
                        "Location: vaccination_history.php?id=" .
                        $patientId
                    );
                    exit;

                } catch (Throwable $e) {

                    try {
                        $mysqli->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log($rollbackError->getMessage());
                    }

                    error_log(
                        "Add vaccination error: " .
                        $e->getMessage()
                    );

                    $error = $e instanceof DomainException
                        ? $e->getMessage()
                        : "Unable to save the vaccination record.";
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

    <title>Add Vaccination | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>
        html, body {
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

        /* Main content area */
        .form-page {
            padding: 25px;
        }

        /* Consistent upper-left Back button */
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

        .form-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 18px;
            box-sizing: border-box;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .form-container h1 {
            color: #315f68;
            margin-bottom: 10px;
        }

        .patient-info {
            background: #e8f8f3;
            padding: 15px;
            border-radius: 10px;
            color: #315f68;
            margin: 20px 0;
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
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d6e4e1;
            border-radius: 9px;
            font: inherit;
            box-sizing: border-box;
            background: white;
            color: #315f68;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #61bca4;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .save-btn,
        .cancel-btn {
            padding: 12px 20px;
            border-radius: 9px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .save-btn {
            border: none;
            background: #61bca4;
            color: white;
        }

        .save-btn:hover {
            background: #459d86;
        }

        .save-btn:disabled {
            background: #a8c9c0;
            cursor: not-allowed;
        }

        .cancel-btn {
            background: #edf2f1;
            color: #315f68;
        }

        .error-message {
            background: #ffe7ec;
            color: #a63f56;
            padding: 12px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .warning-message {
            color: #a63f56;
            font-size: 13px;
            margin-top: 8px;
        }

        .form-hint {
            color: #82999c;
            font-size: 12px;
            margin-top: 7px;
            display: block;
        }

        @media (max-width: 768px) {
            .form-page {
                padding: 15px;
            }

            .form-container {
                padding: 20px;
            }

            .page-back {
                margin-bottom: 18px;
            }
        }
    </style>
</head>

<body>

<div class="dashboard">

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
            <a href="records.php" class="active">Records</a>
            <a href="guardian_accounts.php">Guardian Accounts</a>
            <a href="management.php">Management</a>
            <a href="monitoring.php">Monitoring</a>
            <a href="announcements.php">Announcements</a>
            <a href="reports.php">Reports</a>
            <a href="settings.php">Settings</a>
        </nav>

    </aside>

    <main class="main">

        <header class="topbar">
            <div class="welcome-admin">
                Add Vaccination Record
            </div>
        </header>

        <section class="form-page">

            <!-- Upper-left Back button -->
            <a
                href="vaccination_history.php?id=<?= (int) $patientId ?>"
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
                Back to Vaccination History
            </a>

            <div class="form-container">

                <h1>Add Vaccination Record</h1>

                <div class="patient-info">
                    <strong>Patient:</strong>
                    <?= h($patient["baby_name"]) ?>

                    <p>
                        <strong>Guardian:</strong>
                        <?= h($patient["guardian"]) ?>
                    </p>
                </div>

                <?php if ($error !== ""): ?>
                    <div class="error-message" role="alert">
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION["add_vaccination_csrf"]) ?>"
                    >

                    <div class="form-group">

                        <label for="appointment_id">
                            Related Appointment (Optional)
                        </label>

                        <select
                            id="appointment_id"
                            name="appointment_id"
                        >
                            <option value="">
                                No Appointment / Walk-in
                            </option>

                            <?php foreach ($availableAppointments as $item): ?>

                                <option
                                    value="<?= (int) $item["id"] ?>"
                                    <?= $appointmentId === (int) $item["id"]
                                        ? "selected" : "" ?>
                                >
                                    <?= h(
                                        "#" . $item["id"] .
                                        " — " .
                                        $item["vaccine"] .
                                        " — " .
                                        $item["appointment_date"]
                                    ) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>

                        <span class="form-hint">
                            Select the appointment receiving the
                            vaccination, or leave blank for a walk-in.
                        </span>
                    </div>

                    <div class="form-group">

                        <label for="vaccine_id">
                            Vaccine Administered
                        </label>

                        <select
                            id="vaccine_id"
                            name="vaccine_id"
                            required
                            <?= empty($activeVaccines) ? "disabled" : "" ?>
                        >
                            <option value="">
                                -- Select Vaccine and Dose --
                            </option>

                            <?php foreach ($activeVaccines as $item): ?>

                                <option
                                    value="<?= (int) $item["id"] ?>"
                                    <?= $vaccineId === (int) $item["id"]
                                        ? "selected" : "" ?>
                                >
                                    <?= h($item["vaccine_name"]) ?>

                                    <?php if (!empty($item["dose"])): ?>
                                        — <?= h($item["dose"]) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($item["recommended_age"])): ?>
                                        (<?= h($item["recommended_age"]) ?>)
                                    <?php endif; ?>
                                </option>

                            <?php endforeach; ?>
                        </select>

                        <?php if (empty($activeVaccines)): ?>
                            <p class="warning-message">
                                No active vaccines available.
                                Please register or activate a vaccine first.
                            </p>
                        <?php endif; ?>

                    </div>

                    <div class="form-group">

                        <label for="date_given">
                            Date Given
                        </label>

                        <input
                            type="date"
                            id="date_given"
                            name="date_given"
                            value="<?= h($dateGiven) ?>"
                            max="<?= date("Y-m-d") ?>"
                            required
                        >
                    </div>

                    <div class="form-group">

                        <label for="remarks">
                            Remarks (Optional)
                        </label>

                        <textarea
                            id="remarks"
                            name="remarks"
                            maxlength="255"
                        ><?= h($remarks) ?></textarea>
                    </div>

                    <div class="form-actions">

                        <button
                            type="submit"
                            class="save-btn"
                            <?= empty($activeVaccines) ? "disabled" : "" ?>
                        >
                            Save Vaccination
                        </button>

                        <a
                            href="vaccination_history.php?id=<?= (int) $patientId ?>"
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
