
<?php

require_once __DIR__ . "/../auth.php";
requireStaffLogin();

require_once __DIR__ . "/../connect.php";

function h($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function validScheduleDate($value) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $value
    );

    return $date !== false
        && $date->format("Y-m-d") === $value;
}

function normalizeScheduleDate($value) {
    $value = (string) ($value ?? "");

    return $value === "0000-00-00" ? "" : $value;
}

// Validate patient ID.
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

// Retrieve patient.
$stmt = $mysqli->prepare("
    SELECT
        id,
        baby_name,
        birthday,
        guardian,
        phone_number,
        COALESCE(
    (
        SELECT vr.vaccine
        FROM vaccination_records AS vr
        WHERE vr.patient_id = patients.id
        ORDER BY vr.date_given DESC, vr.id DESC
        LIMIT 1
    ),
    last_vaccine
) AS last_vaccine,
        next_vaccine,
        next_visit,
        status
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

// Retrieve active vaccines.
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

    $vaccines = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (mysqli_sql_exception $e) {

    error_log(
        "Load update schedule: " . $e->getMessage()
    );

    http_response_code(500);
    exit("Unable to load vaccine information.");
}

// Create CSRF token.
if (empty($_SESSION["update_schedule_csrf"])) {
    $_SESSION["update_schedule_csrf"] =
        bin2hex(random_bytes(32));
}

// Default form values.
$error = "";
$success = $_SESSION["schedule_flash"] ?? "";

unset($_SESSION["schedule_flash"]);

$nextVaccine = (string) ($patient["next_vaccine"] ?? "");
$nextVisit = normalizeScheduleDate($patient["next_visit"]);
$changeReason = "";

// Process form submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";
    $vaccineInput = $_POST["next_vaccine"] ?? "";
    $visitInput = $_POST["next_visit"] ?? "";
    $reasonInput = $_POST["change_reason"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION["update_schedule_csrf"],
            $token
        )
    ) {

        $error = "Invalid request. Refresh the page.";

    } elseif (
        !is_string($vaccineInput) ||
        !is_string($visitInput) ||
        !is_string($reasonInput)
    ) {

        $error = "Invalid form values.";

    } else {

        $nextVaccine = trim($vaccineInput);
        $nextVisit = trim($visitInput);
        $changeReason = trim($reasonInput);

        if ($nextVaccine === "") {

            $error = "Please select the next vaccine.";

        } elseif ($nextVisit === "") {

            $error = "Please select the next visit date.";

        } elseif (!validScheduleDate($nextVisit)) {

            $error = "Please enter a valid visit date.";

        } elseif (
            !empty($patient["birthday"]) &&
            validScheduleDate($patient["birthday"]) &&
            $nextVisit < $patient["birthday"]
        ) {

            $error = "The visit date cannot be before the patient's birthday.";

        } elseif (
            mb_strlen($changeReason, "UTF-8") < 5 ||
            mb_strlen($changeReason, "UTF-8") > 255
        ) {

            $error = "The reason must contain 5 to 255 characters.";

        } else {

            // Validate selected vaccine against active vaccines.
            $validVaccine = false;

            foreach ($vaccines as $vaccine) {

                if (
                    $vaccine["vaccine_name"] === $nextVaccine
                ) {
                    $validVaccine = true;
                    break;
                }
            }

            if (!$validVaccine) {

                $error = "Please select an active registered vaccine.";

            } else {

                try {

                    $mysqli->begin_transaction();

                    // Lock patient row and retrieve current schedule.
                    $stmt = $mysqli->prepare("
                        SELECT
                            id,
                            birthday,
                            next_vaccine,
                            next_visit
                        FROM patients
                        WHERE id = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $stmt->bind_param("i", $patientId);
                    $stmt->execute();

                    $lockedPatient = $stmt->get_result()
                        ->fetch_assoc();

                    $stmt->close();

                    if (!$lockedPatient) {
                        throw new DomainException(
                            "Patient not found."
                        );
                    }

                    // Check the birthday again while the row is locked.
                    $birthday = $lockedPatient["birthday"];

                    if (
                        !empty($birthday) &&
                        validScheduleDate($birthday) &&
                        $nextVisit < $birthday
                    ) {
                        throw new DomainException(
                            "The visit date cannot be before the patient's birthday."
                        );
                    }

                    $oldVaccine = $lockedPatient["next_vaccine"];
                    $oldVisit = normalizeScheduleDate(
                        $lockedPatient["next_visit"]
                    );

                    // Avoid creating audit records for unchanged data.
                    if (
                        (string) ($oldVaccine ?? "") === $nextVaccine &&
                        $oldVisit === $nextVisit
                    ) {
                        throw new DomainException(
                            "No schedule changes detected. Please update the vaccine or visit date."
                        );
                    }

                    // Confirm vaccine is still active.
                    $stmt = $mysqli->prepare("
                        SELECT id
                        FROM vaccines
                        WHERE vaccine_name = ?
                          AND status = 'Active'
                        LIMIT 1
                    ");

                    $stmt->bind_param("s", $nextVaccine);
                    $stmt->execute();

                    $activeVaccine = $stmt->get_result()
                        ->fetch_assoc();

                    $stmt->close();

                    if (!$activeVaccine) {
                        throw new DomainException(
                            "The selected vaccine is no longer active."
                        );
                    }

                    // Update the patient's next schedule.
                    $stmt = $mysqli->prepare("
                        UPDATE patients
                        SET
                            next_vaccine = ?,
                            next_visit = ?
                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "ssi",
                        $nextVaccine,
                        $nextVisit,
                        $patientId
                    );

                    $stmt->execute();
                    $stmt->close();

                    // Save previous and updated values to audit history.
                    $changedBy = (int) ($_SESSION["user_id"] ?? 0);

                    if ($changedBy <= 0) {
                        throw new DomainException(
                            "Your staff session has expired. Please log in again."
                        );
                    }

                    $auditOldVisit = $oldVisit !== ""
                        ? $oldVisit
                        : null;

                    $stmt = $mysqli->prepare("
                        INSERT INTO vaccination_schedule_audit_logs (
                            patient_id,
                            changed_by,
                            old_vaccine,
                            new_vaccine,
                            old_visit,
                            new_visit,
                            reason
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->bind_param(
                        "iisssss",
                        $patientId,
                        $changedBy,
                        $oldVaccine,
                        $nextVaccine,
                        $auditOldVisit,
                        $nextVisit,
                        $changeReason
                    );

                    $stmt->execute();
                    $stmt->close();

                    // Both changes must succeed together.
                    $mysqli->commit();

                    // Rotate CSRF token after successful save.
                    $_SESSION["update_schedule_csrf"] =
                        bin2hex(random_bytes(32));

                    $_SESSION["schedule_flash"] =
                        "Vaccination schedule updated and recorded in the audit history successfully.";

                    header(
                        "Location: update-schedule.php?id=" .
                        $patientId
                    );

                    exit;

                } catch (Throwable $e) {

                    try {
                        $mysqli->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log(
                            "Schedule rollback error: " .
                            $rollbackError->getMessage()
                        );
                    }

                    error_log(
                        "Update schedule error: " .
                        $e->getMessage()
                    );

                    $error = $e instanceof DomainException
                        ? $e->getMessage()
                        : "Unable to update vaccination schedule. Please try again.";
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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Update Schedule | ImmuCare</title>

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
        }

        .form-container {
            width: calc(100% - 40px);
            max-width: 750px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 18px;
            box-sizing: border-box;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .form-container h1 {
            margin-bottom: 10px;
            color: #315f68;
        }

        .form-description {
            color: #82999c;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .patient-info {
            background: #e8f8f3;
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            color: #315f68;
        }

        .patient-info p {
            margin-bottom: 8px;
        }

        .patient-info p:last-child {
            margin-bottom: 0;
        }

        .form-group {
            margin-bottom: 22px;
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
            box-sizing: border-box;
            font: inherit;
            color: #315f68;
            background: white;
        }

        .form-group textarea {
            min-height: 110px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #61bca4;
        }

        .form-hint {
            display: block;
            color: #82999c;
            font-size: 12px;
            margin-top: 7px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .save-btn,
        .cancel-btn {
            padding: 12px 20px;
            border-radius: 9px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }

        .save-btn {
            background: #61bca4;
            color: white;
            border: none;
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

        .success-message {
            background: #e1f5ee;
            color: #237c60;
            padding: 12px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .warning-message {
            color: #a63f56;
            font-size: 13px;
            margin-top: 8px;
        }

        @media (max-width: 768px) {

            .form-container {
                width: calc(100% - 30px);
                padding: 20px;
                margin: 20px auto;
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

            <a href="records.php">Records</a>

            <a href="guardian_accounts.php">
                Guardian Accounts
            </a>

            <a href="management.php">Management</a>

            <a href="monitoring.php" class="active">
                Monitoring
            </a>

            <a href="announcements.php">
                Announcements
            </a>

            <a href="reports.php">Reports</a>

            <a href="settings.php">Settings</a>

        </nav>

    </aside>

    <main class="main">

        <header class="topbar">

            <div class="welcome-admin">
                Update Vaccination Schedule
            </div>

        </header>

        <section class="form-container">

            <h1>Update Vaccination Schedule</h1>

            <p class="form-description">
                Update the patient's next vaccine and scheduled visit date.
                Every change requires a reason and is recorded for accountability.
            </p>

            <div class="patient-info">

                <p>
                    <strong>Patient:</strong>
                    <?= h($patient["baby_name"]) ?>
                </p>

                <p>
                    <strong>Guardian:</strong>
                    <?= h($patient["guardian"]) ?>
                </p>

                <p>
                    <strong>Phone:</strong>
                    <?= h($patient["phone_number"] ?: "—") ?>
                </p>

                <p>
                    <strong>Last Vaccine:</strong>
                    <?= h($patient["last_vaccine"] ?: "—") ?>
                </p>

                <p>
                    <strong>Current Next Vaccine:</strong>
                    <?= h($patient["next_vaccine"] ?: "—") ?>
                </p>

                <p>
                    <strong>Current Visit Date:</strong>
                    <?= h(normalizeScheduleDate($patient["next_visit"]) ?: "—") ?>
                </p>

            </div>

            <?php if ($error !== ""): ?>

                <div class="error-message">
                    <?= h($error) ?>
                </div>

            <?php endif; ?>

            <?php if ($success !== ""): ?>

                <div class="success-message">
                    <?= h($success) ?>
                </div>

            <?php endif; ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h(
                        $_SESSION["update_schedule_csrf"]
                    ) ?>"
                >

                <div class="form-group">

                    <label for="next_vaccine">
                        Next Vaccine
                    </label>

                    <select
                        id="next_vaccine"
                        name="next_vaccine"
                        required
                        <?= empty($vaccines) ? "disabled" : "" ?>
                    >

                        <option value="">
                            -- Select Next Vaccine --
                        </option>

                        <?php foreach ($vaccines as $vaccine): ?>

                            <option
                                value="<?= h($vaccine["vaccine_name"]) ?>"
                                <?= $nextVaccine === $vaccine["vaccine_name"]
                                    ? "selected" : "" ?>
                            >

                                <?= h($vaccine["vaccine_name"]) ?>

                                <?php if (!empty($vaccine["dose"])): ?>
                                    — <?= h($vaccine["dose"]) ?>
                                <?php endif; ?>

                                <?php if (!empty($vaccine["recommended_age"])): ?>
                                    (<?= h($vaccine["recommended_age"]) ?>)
                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <span class="form-hint">
                        Only active registered vaccines are displayed.
                    </span>

                    <?php if (empty($vaccines)): ?>

                        <p class="warning-message">
                            No active vaccines are available.
                            Please register or activate a vaccine first.
                        </p>

                    <?php endif; ?>

                </div>

                <div class="form-group">

                    <label for="next_visit">
                        Next Visit Date
                    </label>

                    <input
                        type="date"
                        id="next_visit"
                        name="next_visit"
                        value="<?= h($nextVisit) ?>"
                        required
                    >

                    <span class="form-hint">
                        Select the patient's next scheduled vaccination date.
                        Past dates are allowed when correcting existing schedules.
                    </span>

                </div>

                <div class="form-group">

                    <label for="change_reason">
                        Reason for Schedule Change
                    </label>

                    <textarea
                        id="change_reason"
                        name="change_reason"
                        rows="4"
                        minlength="5"
                        maxlength="255"
                        required
                        placeholder="Explain why the vaccination schedule needs to be changed."
                    ><?= h($changeReason) ?></textarea>

                    <span class="form-hint">
                        Required: 5 to 255 characters.
                        This explanation will be saved in the audit history.
                    </span>

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        class="save-btn"
                        <?= empty($vaccines) ? "disabled" : "" ?>
                    >
                        Save Changes
                    </button>

                    <a
                        href="monitoring.php"
                        class="cancel-btn"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        </section>

    </main>

</div>

</body>
</html>
