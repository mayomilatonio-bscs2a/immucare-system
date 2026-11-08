
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

$isAdministrator = strcasecmp(
    trim((string) ($_SESSION["role"] ?? "")),
    "Administrator"
) === 0;

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

if (empty($_SESSION["vaccination_delete_csrf"])) {
    $_SESSION["vaccination_delete_csrf"] = bin2hex(random_bytes(32));
}

// Patient information.
$stmt = $mysqli->prepare("
    SELECT id, baby_name, birthday, guardian,
           relationship, phone_number
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

// Active vaccination records only.
$stmt = $mysqli->prepare("
    SELECT
        vr.id,
        vr.appointment_id,
        vr.vaccine_id,
        vr.vaccine,
        vr.date_given,
        vr.remarks,
        v.vaccine_name AS catalog_vaccine_name,
        v.dose,
        v.recommended_age
    FROM vaccination_records AS vr
    LEFT JOIN vaccines AS v
        ON vr.vaccine_id = v.id
    WHERE vr.patient_id = ?
      AND vr.deleted_at IS NULL
    ORDER BY vr.date_given DESC, vr.id DESC
");
$stmt->bind_param("i", $patientId);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalVaccinations = count($records);

$success = $_SESSION["vaccination_flash"] ?? "";
$error = $_SESSION["vaccination_error"] ?? "";

unset(
    $_SESSION["vaccination_flash"],
    $_SESSION["vaccination_error"]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vaccination History | ImmuCare</title>
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
            overflow: visible;
            min-width: 0;
        }

        .history-container {
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

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .history-header h1 {
            font-size: 25px;
            color: #315f68;
        }

        /* Existing action buttons */
        .back-btn {
            display: inline-block;
            background: #61bca4;
            color: white;
            padding: 11px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }

        .back-btn:hover {
            background: #459d86;
        }

        .patient-card,
        .history-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(70,110,110,0.08);
        }

        .patient-card h2,
        .history-card h2 {
            color: #315f68;
            font-size: 19px;
            margin-bottom: 18px;
        }

        .patient-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .detail-label {
            font-size: 12px;
            color: #81999a;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 15px;
            color: #315f68;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .history-summary {
            color: #66858b;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th {
            background: #e8f8f3;
            color: #315f68;
            text-align: left;
            padding: 14px;
            white-space: nowrap;
        }

        td {
            padding: 14px;
            border-bottom: 1px solid #edf2f1;
            color: #55777d;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f8fcfb;
        }

        .empty-message {
            padding: 25px;
            text-align: center;
            color: #82999c;
        }

        .record-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .edit-vaccine-btn,
        .delete-vaccine-btn {
            display: inline-block;
            padding: 8px 14px;
            border: 0;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }

        .edit-vaccine-btn {
            background: #e4f5ff;
            color: #377a99;
        }

        .edit-vaccine-btn:hover {
            background: #cceaff;
        }

        .delete-vaccine-btn {
            background: #ffe5e8;
            color: #b63f55;
        }

        .delete-vaccine-btn:hover {
            background: #ffd0d7;
        }

        .delete-form {
            display: inline;
            margin: 0;
        }

        .success-message,
        .error-message {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .success-message {
            background: #e8f8f3;
            color: #315f68;
            border: 1px solid #c6e9df;
        }

        .error-message {
            background: #fff0f0;
            color: #a33;
            border: 1px solid #ffd5d5;
        }

        .catalog-note {
            color: #81999a;
            font-size: 12px;
            margin-top: 14px;
            line-height: 1.6;
        }

        @media (max-width: 700px) {
            .patient-grid {
                grid-template-columns: 1fr;
            }

            .history-container {
                padding: 15px;
            }

            .patient-card,
            .history-card {
                padding: 18px;
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
                Patient Vaccination History
            </div>
        </header>

        <section class="history-container">

            <!-- Upper-left Back button -->
            <a href="records.php" class="page-back">
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
                Back to Records
            </a>

            <div class="history-header">
                <h1>Vaccination History</h1>
            </div>

            <?php if ($success !== ""): ?>
                <div class="success-message" role="status">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="error-message" role="alert">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <div class="patient-card">
                <h2>Patient Information</h2>

                <div class="patient-grid">
                    <div>
                        <div class="detail-label">Patient ID</div>
                        <div class="detail-value">
                            <?= h($patient["id"]) ?>
                        </div>
                    </div>

                    <div>
                        <div class="detail-label">Baby Name</div>
                        <div class="detail-value">
                            <?= h($patient["baby_name"]) ?>
                        </div>
                    </div>

                    <div>
                        <div class="detail-label">Birthday</div>
                        <div class="detail-value">
                            <?= h($patient["birthday"] ?: "—") ?>
                        </div>
                    </div>

                    <div>
                        <div class="detail-label">Guardian</div>
                        <div class="detail-value">
                            <?= h($patient["guardian"] ?: "—") ?>
                        </div>
                    </div>

                    <div>
                        <div class="detail-label">Relationship</div>
                        <div class="detail-value">
                            <?= h($patient["relationship"] ?: "—") ?>
                        </div>
                    </div>

                    <div>
                        <div class="detail-label">Phone Number</div>
                        <div class="detail-value">
                            <?= h($patient["phone_number"] ?: "—") ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="history-card">
                <h2>Vaccination Records</h2>

                <div class="action-buttons">

                    <a
                        href="add_vaccination.php?id=<?= (int) $patientId ?>"
                        class="back-btn"
                    >
                        + Add Vaccination
                    </a>

                    <?php if ($isAdministrator): ?>
                        <a
                            href="vaccination_audit_history.php?id=<?= (int) $patientId ?>"
                            class="back-btn"
                        >
                            View Audit History
                        </a>
                    <?php endif; ?>

                </div>

                <p class="history-summary">
                    Total Recorded Vaccinations:
                    <strong><?= $totalVaccinations ?></strong>
                </p>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Record ID</th>
                                <th>Vaccine</th>
                                <th>Dose</th>
                                <th>Recommended Age</th>
                                <th>Date Given</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="7" class="empty-message">
                                    No active vaccination history recorded
                                    for this patient.
                                </td>
                            </tr>
                        <?php else: ?>

                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><?= h($record["id"]) ?></td>

                                    <td>
                                        <?= h(
                                            $record["vaccine"]
                                            ?: $record["catalog_vaccine_name"]
                                            ?: "—"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h($record["dose"] ?: "—") ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            $record["recommended_age"] ?: "—"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            !empty($record["date_given"])
                                                ? date(
                                                    "F j, Y",
                                                    strtotime($record["date_given"])
                                                )
                                                : "—"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h($record["remarks"] ?: "—") ?>
                                    </td>

                                    <td>
                                        <div class="record-actions">

                                            <a
                                                href="edit_vaccination.php?id=<?= (int) $record["id"] ?>"
                                                class="edit-vaccine-btn"
                                            >
                                                Edit
                                            </a>

                                            <?php if ($isAdministrator): ?>

                                                <form
                                                    method="POST"
                                                    action="delete_vaccination.php"
                                                    class="delete-form"
                                                    onsubmit="return confirmVaccinationDelete(this);"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= h($_SESSION["vaccination_delete_csrf"]) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="patient_id"
                                                        value="<?= (int) $patientId ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="record_id"
                                                        value="<?= (int) $record["id"] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="reason"
                                                        value=""
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="delete-vaccine-btn"
                                                    >
                                                        Delete
                                                    </button>
                                                </form>

                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="catalog-note">
                    Dose and recommended age are displayed from
                    the current vaccine catalog. Older records
                    without a linked vaccine may show a dash.
                    Deleted records are retained for audit purposes
                    but are not included in the active total.
                </p>
            </div>

        </section>
    </main>
</div>

<script>
function confirmVaccinationDelete(form) {
    const reason = prompt(
        "Why should this vaccination record be deleted?\n\n" +
        "A reason is required. The record will be retained " +
        "in the database for audit purposes."
    );

    if (reason === null) {
        return false;
    }

    const trimmedReason = reason.trim();

    if (trimmedReason.length < 5) {
        alert("Please enter a reason of at least 5 characters.");
        return false;
    }

    if (trimmedReason.length > 500) {
        alert("The reason must not exceed 500 characters.");
        return false;
    }

    const confirmed = confirm(
        "Are you sure you want to remove this vaccination " +
        "from the active records?\n\n" +
        "This action will be recorded for audit purposes."
    );

    if (!confirmed) {
        return false;
    }

    form.querySelector('[name="reason"]').value = trimmedReason;
    return true;
}
</script>

</body>
</html>
