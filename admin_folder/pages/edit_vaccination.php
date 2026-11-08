
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

// Validate vaccination record ID.
$recordId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$recordId) {
    http_response_code(400);
    exit("Invalid vaccination record ID.");
}

// Load active vaccination record and patient.
$stmt = $mysqli->prepare("
    SELECT
        vr.id,
        vr.patient_id,
        vr.vaccine_id,
        vr.vaccine,
        vr.date_given,
        vr.remarks,
        p.baby_name,
        p.birthday,
        p.guardian
    FROM vaccination_records AS vr
    INNER JOIN patients AS p
        ON p.id = vr.patient_id
    WHERE vr.id = ?
      AND vr.deleted_at IS NULL
    LIMIT 1
");

$stmt->bind_param("i", $recordId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    http_response_code(404);
    exit("Vaccination record not found.");
}

$patientId = (int) $record["patient_id"];
$currentVaccineId = (int) ($record["vaccine_id"] ?? 0);

// Load active vaccines and currently linked vaccine.
// The current vaccine remains selectable even if inactive.
$stmt = $mysqli->prepare("
    SELECT
        id,
        vaccine_name,
        dose,
        recommended_age,
        status
    FROM vaccines
    WHERE status = 'Active'
       OR id = ?
    ORDER BY vaccine_name ASC, dose ASC, id ASC
");

$stmt->bind_param("i", $currentVaccineId);
$stmt->execute();

$availableVaccines = $stmt->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// Create CSRF token.
if (empty($_SESSION["edit_vaccination_csrf"])) {
    $_SESSION["edit_vaccination_csrf"] =
        bin2hex(random_bytes(32));
}

$error = "";

$vaccineId = $currentVaccineId;
$dateGiven = $record["date_given"];
$remarks = $record["remarks"] ?? "";
$reason = "";

// Handle form submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    $submittedVaccineId = filter_var(
        $_POST["vaccine_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    $vaccineId = $submittedVaccineId ?: 0;

    $dateInput = $_POST["date_given"] ?? "";
    $remarksInput = $_POST["remarks"] ?? "";
    $reasonInput = $_POST["reason"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION["edit_vaccination_csrf"],
            $token
        )
    ) {
        $error = "Invalid request. Refresh the page.";

    } elseif (
        !is_string($dateInput) ||
        !is_string($remarksInput) ||
        !is_string($reasonInput)
    ) {
        $error = "Invalid form values.";

    } else {

        $dateGiven = trim($dateInput);
        $remarks = trim($remarksInput);
        $reason = trim($reasonInput);

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
                empty($record["birthday"]) ||
                $dateGiven >= $record["birthday"]
            );

        if (
            $vaccineId <= 0 ||
            mb_strlen($remarks) > 255 ||
            $reason === "" ||
            mb_strlen($reason) > 255
        ) {
            $error = "Complete all required fields within their limits.";

        } elseif (!$validDate) {
            $error = "Enter a valid vaccination date.";

        } else {

            try {

                $mysqli->begin_transaction();

                // Lock and reload active vaccination record.
                $stmt = $mysqli->prepare("
                    SELECT
                        patient_id,
                        vaccine_id,
                        vaccine,
                        date_given,
                        remarks
                    FROM vaccination_records
                    WHERE id = ?
                      AND deleted_at IS NULL
                    FOR UPDATE
                ");

                $stmt->bind_param("i", $recordId);
                $stmt->execute();

                $current = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$current) {
                    throw new DomainException(
                        "This vaccination record has been deleted or no longer exists. Editing is not allowed."
                    );
                }

                if (
                    (int) $current["patient_id"] !== $patientId
                ) {
                    throw new DomainException(
                        "Patient association has changed."
                    );
                }

                // Prevent saving an outdated form.
                if (
                    (int) ($current["vaccine_id"] ?? 0)
                        !== $currentVaccineId ||
                    $current["vaccine"] !== $record["vaccine"] ||
                    $current["date_given"] !== $record["date_given"] ||
                    ($current["remarks"] ?? "") !==
                        ($record["remarks"] ?? "")
                ) {
                    throw new DomainException(
                        "This record was changed by another user. Reload the page."
                    );
                }

                // Load selected vaccine details.
                $stmt = $mysqli->prepare("
                    SELECT
                        id,
                        vaccine_name,
                        dose,
                        recommended_age,
                        status
                    FROM vaccines
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->bind_param("i", $vaccineId);
                $stmt->execute();

                $selectedVaccine = $stmt->get_result()
                    ->fetch_assoc();

                $stmt->close();

                if (!$selectedVaccine) {
                    throw new DomainException(
                        "Selected vaccine was not found."
                    );
                }

                // Newly selected vaccines must be active.
                if (
                    strcasecmp(
                        (string) $selectedVaccine["status"],
                        "Active"
                    ) !== 0 &&
                    $vaccineId !== $currentVaccineId
                ) {
                    throw new DomainException(
                        "Please select an active vaccine."
                    );
                }

                // Retrieve previous vaccine catalog details.
                $oldVaccineId = $current["vaccine_id"] === null
                    ? null
                    : (int) $current["vaccine_id"];

                $oldDose = null;
                $oldRecommendedAge = null;

                if ($oldVaccineId !== null) {

                    $stmt = $mysqli->prepare("
                        SELECT
                            dose,
                            recommended_age
                        FROM vaccines
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->bind_param("i", $oldVaccineId);
                    $stmt->execute();

                    $oldCatalog = $stmt->get_result()
                        ->fetch_assoc();

                    $stmt->close();

                    if ($oldCatalog) {
                        $oldDose = $oldCatalog["dose"];
                        $oldRecommendedAge =
                            $oldCatalog["recommended_age"];
                    }
                }

                // Save new vaccine details as snapshots.
                $newVaccineId = $vaccineId;
                $newDose = $selectedVaccine["dose"];
                $newRecommendedAge =
                    $selectedVaccine["recommended_age"];

                $vaccineChanged =
                    $vaccineId !== $currentVaccineId;

                $newVaccine = $vaccineChanged
                    ? $selectedVaccine["vaccine_name"]
                    : $current["vaccine"];

                // Check whether anything changed.
                if (
                    !$vaccineChanged &&
                    $newVaccine === $current["vaccine"] &&
                    $dateGiven === $current["date_given"] &&
                    $remarks === ($current["remarks"] ?? "")
                ) {
                    throw new DomainException(
                        "No changes were made to this vaccination record."
                    );
                }

                $oldVaccine = $current["vaccine"];
                $oldDate = $current["date_given"];
                $oldRemarks = $current["remarks"] ?? "";

                $editedBy = (int) $_SESSION["user_id"];

                // Update only an active vaccination record.
                $stmt = $mysqli->prepare("
                    UPDATE vaccination_records
                    SET
                        vaccine_id = ?,
                        vaccine = ?,
                        date_given = ?,
                        remarks = ?
                    WHERE id = ?
                      AND deleted_at IS NULL
                ");

                $stmt->bind_param(
                    "isssi",
                    $newVaccineId,
                    $newVaccine,
                    $dateGiven,
                    $remarks,
                    $recordId
                );

                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new DomainException(
                        "Unable to update the vaccination record."
                    );
                }

                $stmt->close();

                // Save permanent correction snapshots.
                $stmt = $mysqli->prepare("
                    INSERT INTO vaccination_audit_logs (
                        vaccination_record_id,
                        patient_id,
                        edited_by,
                        old_vaccine,
                        new_vaccine,
                        old_vaccine_id,
                        new_vaccine_id,
                        old_dose,
                        new_dose,
                        old_recommended_age,
                        new_recommended_age,
                        old_date_given,
                        new_date_given,
                        old_remarks,
                        new_remarks,
                        reason
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");

                $stmt->bind_param(
                    "iissiissssssssss",
                    $recordId,
                    $patientId,
                    $editedBy,
                    $oldVaccine,
                    $newVaccine,
                    $oldVaccineId,
                    $newVaccineId,
                    $oldDose,
                    $newDose,
                    $oldRecommendedAge,
                    $newRecommendedAge,
                    $oldDate,
                    $dateGiven,
                    $oldRemarks,
                    $remarks,
                    $reason
                );

                $stmt->execute();
                $stmt->close();

                $mysqli->commit();

                $_SESSION["edit_vaccination_csrf"] =
                    bin2hex(random_bytes(32));

                $_SESSION["vaccination_flash"] =
                    "Vaccination record updated successfully. Correction saved in audit history.";

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
                    "Vaccination edit failed: " .
                    $e->getMessage()
                );

                if ($e instanceof DomainException) {
                    $error = $e->getMessage();
                } else {
                    $error = "Unable to save the correction. Please try again.";
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

    <title>Edit Vaccination | ImmuCare</title>

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
            overflow: visible;
            min-width: 0;
        }

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
            padding: 30px;
            box-sizing: border-box;
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,.05);
        }

        .form-container h1 {
            color: #315f68;
            margin-bottom: 20px;
        }

        .patient-info {
            background: #e8f8f3;
            color: #315f68;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            line-height: 1.8;
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
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #d6e4e1;
            border-radius: 9px;
            font: inherit;
            background: white;
            color: #315f68;
        }

        .form-group textarea {
            min-height: 95px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .save-btn,
        .cancel-btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 9px;
            font-weight: 600;
            text-decoration: none;
        }

        .save-btn {
            border: none;
            background: #61bca4;
            color: white;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #459d86;
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

        .helper-text {
            color: #789297;
            font-size: 12px;
            margin-top: 6px;
        }

        .warning-text {
            color: #a66a37;
            font-size: 13px;
            margin-top: 8px;
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
                alt="ImmuCare"
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
                Edit Vaccination Record
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

                <h1>Edit Vaccination Record</h1>

                <div class="patient-info">

                    <strong>Patient:</strong>
                    <?= h($record["baby_name"]) ?>

                    <p>
                        <strong>Guardian:</strong>
                        <?= h($record["guardian"]) ?>
                    </p>

                    <p>
                        <strong>Current Vaccine:</strong>
                        <?= h($record["vaccine"]) ?>
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
                        value="<?= h(
                            $_SESSION["edit_vaccination_csrf"]
                        ) ?>"
                    >

                    <div class="form-group">

                        <label for="vaccine_id">
                            Vaccine Administered
                        </label>

                        <select
                            id="vaccine_id"
                            name="vaccine_id"
                            required
                        >
                            <option value="">
                                -- Select Vaccine and Dose --
                            </option>

                            <?php foreach ($availableVaccines as $item): ?>

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

                                    <?php if ($item["status"] !== "Active"): ?>
                                        [Inactive - Current Record]
                                    <?php endif; ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <?php if ($currentVaccineId === 0): ?>
                            <p class="warning-text">
                                This is an older vaccination record
                                without a linked vaccine ID.
                                Please select the correct vaccine
                                and dose before saving.
                            </p>
                        <?php endif; ?>

                        <p class="helper-text">
                            Select the correct vaccine and dose
                            from the registered vaccine list.
                        </p>

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

                    <div class="form-group">

                        <label for="reason">
                            Reason for Correction
                        </label>

                        <textarea
                            id="reason"
                            name="reason"
                            maxlength="255"
                            placeholder="Explain why this vaccination record needs correction."
                            required
                        ><?= h($reason) ?></textarea>

                        <p class="helper-text">
                            Required. This reason will be saved
                            in the vaccination audit history.
                        </p>

                    </div>

                    <div class="form-actions">

                        <button type="submit" class="save-btn">
                            Save Correction
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
