
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

function displayValue($value) {
    return h(
        $value !== null && $value !== ""
            ? $value
            : "—"
    );
}

function formatAuditDate($value, $withTime = false) {
    if (empty($value)) {
        return "—";
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return h($value);
    }

    return h(
        date(
            $withTime ? "F j, Y h:i A" : "F j, Y",
            $timestamp
        )
    );
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

// Get patient details.
$stmt = $mysqli->prepare("
    SELECT
        id,
        baby_name,
        guardian
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

// Get correction history using permanent snapshots.
$stmt = $mysqli->prepare("
    SELECT
        a.id,
        a.vaccination_record_id,

        a.old_vaccine,
        a.new_vaccine,

        a.old_vaccine_id,
        a.new_vaccine_id,

        a.old_dose,
        a.new_dose,

        a.old_recommended_age,
        a.new_recommended_age,

        a.old_date_given,
        a.new_date_given,

        a.old_remarks,
        a.new_remarks,

        a.reason,
        a.edited_at,

        u.full_name AS edited_by_name

    FROM vaccination_audit_logs AS a

    LEFT JOIN users AS u
        ON u.id = a.edited_by

    WHERE a.patient_id = ?

    ORDER BY a.edited_at DESC, a.id DESC
");

$stmt->bind_param("i", $patientId);
$stmt->execute();

$auditLogs = $stmt->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$totalCorrections = count($auditLogs);

// Retrieve deleted vaccination records for audit review.
$stmt = $mysqli->prepare("
    SELECT
        vr.id,
        vr.vaccine,
        vr.date_given,
        vr.remarks,
        vr.deleted_at,
        vr.deletion_reason,
        vr.deleted_by,
        u.full_name AS deleted_by_name
    FROM vaccination_records AS vr
    LEFT JOIN users AS u
        ON u.id = vr.deleted_by
    WHERE vr.patient_id = ?
      AND vr.deleted_at IS NOT NULL
    ORDER BY vr.deleted_at DESC, vr.id DESC
");

$stmt->bind_param("i", $patientId);
$stmt->execute();

$deletedRecords = $stmt->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$totalDeleted = count($deletedRecords);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Vaccination Audit History | ImmuCare</title>

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

        .audit-container {
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

        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .audit-header h1 {
            font-size: 25px;
            color: #315f68;
        }

        .patient-card,
        .audit-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .patient-card h2,
        .audit-card h2 {
            color: #315f68;
            font-size: 19px;
            margin-bottom: 15px;
        }

        .patient-info {
            color: #55777d;
            line-height: 1.8;
        }

        .audit-summary {
            color: #66858b;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .audit-entry {
            border: 1px solid #e0eeea;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
        }

        .audit-entry-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .audit-entry-header strong {
            color: #315f68;
        }

        .audit-date {
            color: #82999c;
            font-size: 13px;
        }

        .edited-by {
            color: #55777d;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .change-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .change-box {
            background: #f5faf8;
            padding: 15px;
            border-radius: 10px;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .change-box.updated {
            background: #eaf8f3;
        }

        .change-box h3 {
            color: #315f68;
            font-size: 15px;
            margin-bottom: 12px;
        }

        .change-box p {
            color: #55777d;
            font-size: 14px;
            margin-bottom: 9px;
            line-height: 1.6;
        }

        .change-box strong {
            color: #315f68;
        }

        .reason-box {
            background: #e8f8f3;
            padding: 12px 15px;
            border-radius: 9px;
            color: #315f68;
            margin-top: 15px;
            overflow-wrap: anywhere;
            line-height: 1.6;
        }

        .empty-message {
            text-align: center;
            color: #82999c;
            padding: 25px;
        }

        .missing-detail {
            color: #94a6a8;
        }

        .audit-note {
            font-size: 12px;
            color: #81999a;
            margin-top: 15px;
            line-height: 1.6;
        }

        @media (max-width: 700px) {
            .change-grid {
                grid-template-columns: 1fr;
            }

            .audit-container {
                padding: 15px;
            }

            .patient-card,
            .audit-card {
                padding: 18px;
            }

            .audit-entry {
                padding: 15px;
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
            <a href="records.php" class="active">
                Records
            </a>
            <a href="guardian_accounts.php">
                Guardian Accounts
            </a>
            <a href="management.php">
                Management
            </a>
            <a href="monitoring.php">
                Monitoring
            </a>
            <a href="announcements.php">
                Announcements
            </a>
            <a href="reports.php">
                Reports
            </a>
            <a href="settings.php">
                Settings
            </a>
        </nav>

    </aside>

    <main class="main">

        <header class="topbar">
            <div class="welcome-admin">
                Vaccination Audit History
            </div>
        </header>

        <section class="audit-container">

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

            <div class="audit-header">
                <h1>Vaccination Audit History</h1>
            </div>

            <!-- Patient Information -->
            <div class="patient-card">

                <h2>Patient Information</h2>

                <div class="patient-info">
                    <p>
                        <strong>Patient ID:</strong>
                        <?= h($patient["id"]) ?>
                    </p>

                    <p>
                        <strong>Patient:</strong>
                        <?= h($patient["baby_name"]) ?>
                    </p>

                    <p>
                        <strong>Guardian:</strong>
                        <?= h($patient["guardian"]) ?>
                    </p>
                </div>

            </div>

            <!-- Correction History -->
            <div class="audit-card">

                <h2>Correction History</h2>

                <p class="audit-summary">
                    Total Corrections:
                    <strong><?= $totalCorrections ?></strong>
                </p>

                <?php if (empty($auditLogs)): ?>

                    <p class="empty-message">
                        No vaccination corrections have been recorded.
                    </p>

                <?php else: ?>

                    <?php foreach ($auditLogs as $log): ?>

                        <div class="audit-entry">

                            <div class="audit-entry-header">
                                <strong>
                                    Vaccination Record
                                    #<?= h($log["vaccination_record_id"]) ?>
                                </strong>

                                <span class="audit-date">
                                    <?= formatAuditDate(
                                        $log["edited_at"],
                                        true
                                    ) ?>
                                </span>
                            </div>

                            <p class="edited-by">
                                <strong>Edited by:</strong>
                                <?= h(
                                    $log["edited_by_name"] ??
                                    "Unknown staff"
                                ) ?>
                            </p>

                            <div class="change-grid">

                                <!-- Previous Information -->
                                <div class="change-box">

                                    <h3>Previous Information</h3>

                                    <p>
                                        <strong>Vaccine:</strong>
                                        <?= displayValue($log["old_vaccine"]) ?>
                                    </p>

                                    <p>
                                        <strong>Dose:</strong>
                                        <?= displayValue($log["old_dose"]) ?>
                                    </p>

                                    <p>
                                        <strong>Recommended Age:</strong>
                                        <?= displayValue($log["old_recommended_age"]) ?>
                                    </p>

                                    <p>
                                        <strong>Date Given:</strong>
                                        <?= formatAuditDate($log["old_date_given"]) ?>
                                    </p>

                                    <p>
                                        <strong>Remarks:</strong>
                                        <?= displayValue($log["old_remarks"]) ?>
                                    </p>

                                </div>

                                <!-- Updated Information -->
                                <div class="change-box updated">

                                    <h3>Updated Information</h3>

                                    <p>
                                        <strong>Vaccine:</strong>
                                        <?= displayValue($log["new_vaccine"]) ?>
                                    </p>

                                    <p>
                                        <strong>Dose:</strong>
                                        <?= displayValue($log["new_dose"]) ?>
                                    </p>

                                    <p>
                                        <strong>Recommended Age:</strong>
                                        <?= displayValue($log["new_recommended_age"]) ?>
                                    </p>

                                    <p>
                                        <strong>Date Given:</strong>
                                        <?= formatAuditDate($log["new_date_given"]) ?>
                                    </p>

                                    <p>
                                        <strong>Remarks:</strong>
                                        <?= displayValue($log["new_remarks"]) ?>
                                    </p>

                                </div>

                            </div>

                            <!-- Correction Reason -->
                            <div class="reason-box">
                                <strong>Reason for Correction:</strong>
                                <?= displayValue($log["reason"]) ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

                <p class="audit-note">
                    Vaccine doses and recommended ages are saved
                    when a correction is made. Later changes to
                    the vaccine catalog will not change these
                    saved audit details. Older audit entries may
                    have missing dose information.
                </p>

            </div>

            <!-- Deleted Vaccination History -->
            <div class="audit-card">

                <h2>Deleted Vaccination History</h2>

                <p class="audit-summary">
                    Total Deleted Records:
                    <strong><?= $totalDeleted ?></strong>
                </p>

                <?php if (empty($deletedRecords)): ?>

                    <p class="empty-message">
                        No deleted vaccination records found.
                    </p>

                <?php else: ?>

                    <?php foreach ($deletedRecords as $deleted): ?>

                        <div class="audit-entry">

                            <div class="audit-entry-header">

                                <strong>
                                    Vaccination Record
                                    #<?= (int) $deleted["id"] ?>
                                </strong>

                                <span class="audit-date">
                                    <?= formatAuditDate(
                                        $deleted["deleted_at"],
                                        true
                                    ) ?>
                                </span>

                            </div>

                            <p class="edited-by">
                                <strong>Deleted by:</strong>
                                <?= h(
                                    $deleted["deleted_by_name"]
                                    ?: "Unknown administrator"
                                ) ?>
                            </p>

                            <div class="change-box">

                                <p>
                                    <strong>Vaccine:</strong>
                                    <?= displayValue($deleted["vaccine"]) ?>
                                </p>

                                <p>
                                    <strong>Date Given:</strong>
                                    <?= formatAuditDate($deleted["date_given"]) ?>
                                </p>

                                <p>
                                    <strong>Remarks:</strong>
                                    <?= displayValue($deleted["remarks"]) ?>
                                </p>

                            </div>

                            <div class="reason-box">
                                <strong>Reason for Deletion:</strong>
                                <?= displayValue(
                                    $deleted["deletion_reason"]
                                ) ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

                <p class="audit-note">
                    Deleted vaccination records are retained
                    for audit purposes and excluded from active
                    vaccination history. Only administrators
                    can view this information.
                </p>

            </div>

        </section>

    </main>

</div>

</body>
</html>
