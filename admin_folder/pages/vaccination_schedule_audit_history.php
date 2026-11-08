
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

function formatAuditDate($value) {
    if (!$value || $value === "0000-00-00") {
        return "—";
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date("M d, Y", $timestamp)
        : "—";
}

function formatAuditDateTime($value) {
    if (!$value) {
        return "—";
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date("M d, Y h:i A", $timestamp)
        : "—";
}

// Optional patient filter.
$patientId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

// Load schedule change history.
try {

    $sql = "
        SELECT
            l.id,
            l.patient_id,
            l.changed_by,
            l.old_vaccine,
            l.new_vaccine,
            l.old_visit,
            l.new_visit,
            l.reason,
            l.changed_at,
            p.baby_name,
            p.guardian,
            u.full_name AS staff_name
        FROM vaccination_schedule_audit_logs AS l
        LEFT JOIN patients AS p
            ON p.id = l.patient_id
        LEFT JOIN users AS u
            ON u.id = l.changed_by
    ";

    if ($patientId) {

        $sql .= "
            WHERE l.patient_id = ?
            ORDER BY l.changed_at DESC, l.id DESC
        ";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $patientId);

    } else {

        $sql .= "
            ORDER BY l.changed_at DESC, l.id DESC
        ";

        $stmt = $mysqli->prepare($sql);
    }

    $stmt->execute();

    $logs = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (mysqli_sql_exception $e) {

    error_log(
        "Schedule audit history error: " .
        $e->getMessage()
    );

    http_response_code(500);

    exit("Unable to load schedule audit history.");
}

// Calculate summary information.
$totalChanges = count($logs);
$changedPatients = [];
$changedStaff = [];

foreach ($logs as $log) {

    $changedPatients[(int) $log["patient_id"]] = true;
    $changedStaff[(int) $log["changed_by"]] = true;
}

$totalPatients = count($changedPatients);
$totalStaff = count($changedStaff);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Schedule Audit History | ImmuCare</title>

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

        .audit-container {
            padding: 25px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 26px;
            color: #315f68;
        }

        .header-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .back-btn {
            display: inline-block;
            padding: 11px 18px;
            background: #edf5f2;
            color: #315f68;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card,
        .table-card {
            background: white;
            border-radius: 15px;
            padding: 22px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .summary-card p {
            color: #708c90;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .summary-card h2 {
            color: #315f68;
            font-size: 28px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .table-header h2 {
            color: #315f68;
            font-size: 19px;
        }

        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filters input,
        .filters select {
            padding: 10px 12px;
            border: 1px solid #d6e6e0;
            border-radius: 9px;
            font: inherit;
            color: #315f68;
            background: white;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #edf2f0;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: #f4faf7;
            color: #315f68;
        }

        td {
            color: #55777d;
        }

        .change-details {
            display: grid;
            gap: 8px;
        }

        .change-details strong {
            color: #315f68;
        }

        .previous-value {
            color: #b44961;
        }

        .updated-value {
            color: #237c60;
        }

        .change-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            background: #e1f5ee;
            color: #237c60;
            font-size: 12px;
            font-weight: 600;
        }

        .patient-link {
            color: #315f68;
            font-weight: 600;
            text-decoration: none;
        }

        .patient-link:hover {
            text-decoration: underline;
        }

        .empty-message {
            text-align: center;
            padding: 30px;
            color: #82999c;
        }

        .filter-empty {
            display: none;
            text-align: center;
            padding: 20px;
            color: #82999c;
        }

        .info-note {
            margin-top: 15px;
            color: #82999c;
            font-size: 13px;
        }

        @media (max-width: 900px) {

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {

            .audit-container {
                padding: 15px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .filters,
            .filters input,
            .filters select {
                width: 100%;
                box-sizing: border-box;
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
                Vaccination Schedule Audit History
            </div>
        </header>

        <section class="audit-container">

            <div class="page-header">

                <h1>Schedule Change History</h1>

                <div class="header-actions">

                    <a
                        href="monitoring.php"
                        class="back-btn"
                    >
                        Back to Monitoring
                    </a>

                    <?php if ($patientId): ?>

                        <a
                            href="vaccination_schedule_audit_history.php"
                            class="back-btn"
                        >
                            View All Changes
                        </a>

                    <?php endif; ?>

                </div>

            </div>

            <!-- SUMMARY CARDS -->

            <div class="summary-grid">

                <div class="summary-card">
                    <p>Total Schedule Changes</p>
                    <h2><?= $totalChanges ?></h2>
                </div>

                <div class="summary-card">
                    <p>Patients with Changes</p>
                    <h2><?= $totalPatients ?></h2>
                </div>

                <div class="summary-card">
                    <p>Staff Members Involved</p>
                    <h2><?= $totalStaff ?></h2>
                </div>

            </div>

            <!-- AUDIT TABLE -->

            <div class="table-card">

                <div class="table-header">

                    <h2>Recorded Schedule Changes</h2>

                    <div class="filters">

                        <input
                            type="search"
                            id="auditSearch"
                            placeholder="Search patient, guardian, staff..."
                            aria-label="Search schedule changes"
                        >

                        <select id="auditFilter">
                            <option value="">All Changes</option>
                            <option value="vaccine">Vaccine Changed</option>
                            <option value="date">Date Changed</option>
                            <option value="both">Both Changed</option>
                        </select>

                    </div>

                </div>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Guardian</th>
                                <th>Change Type</th>
                                <th>Previous Schedule</th>
                                <th>Updated Schedule</th>
                                <th>Reason</th>
                                <th>Changed By</th>
                                <th>Changed At</th>
                                <th>Records</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (empty($logs)): ?>

                            <tr>
                                <td
                                    colspan="9"
                                    class="empty-message"
                                >
                                    No schedule audit records found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($logs as $log): ?>

                                <?php

                                $vaccineChanged =
                                    (string) ($log["old_vaccine"] ?? "") !==
                                    (string) ($log["new_vaccine"] ?? "");

                                $dateChanged =
                                    (string) ($log["old_visit"] ?? "") !==
                                    (string) ($log["new_visit"] ?? "");

                                if ($vaccineChanged && $dateChanged) {
                                    $changeType = "both";
                                    $changeLabel = "Both Changed";

                                } elseif ($vaccineChanged) {
                                    $changeType = "vaccine";
                                    $changeLabel = "Vaccine Changed";

                                } elseif ($dateChanged) {
                                    $changeType = "date";
                                    $changeLabel = "Date Changed";

                                } else {
                                    $changeType = "none";
                                    $changeLabel = "No Change";
                                }

                                ?>

                                <tr
                                    class="audit-row"
                                    data-change="<?= h($changeType) ?>"
                                >

                                    <td>
                                        <a
                                            href="vaccination_schedule_audit_history.php?id=<?= (int) $log["patient_id"] ?>"
                                            class="patient-link"
                                        >
                                            <?= h(
                                                $log["baby_name"]
                                                ?? "Unknown patient"
                                            ) ?>
                                        </a>

                                        <div>
                                            #<?= (int) $log["patient_id"] ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= h($log["guardian"] ?? "—") ?>
                                    </td>

                                    <td>
                                        <span class="change-badge">
                                            <?= h($changeLabel) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="change-details">

                                            <div>
                                                <strong>Vaccine:</strong>
                                                <span class="previous-value">
                                                    <?= h(
                                                        $log["old_vaccine"]
                                                        ?: "—"
                                                    ) ?>
                                                </span>
                                            </div>

                                            <div>
                                                <strong>Date:</strong>
                                                <span class="previous-value">
                                                    <?= h(formatAuditDate(
                                                        $log["old_visit"]
                                                    )) ?>
                                                </span>
                                            </div>

                                        </div>
                                    </td>

                                    <td>
                                        <div class="change-details">

                                            <div>
                                                <strong>Vaccine:</strong>
                                                <span class="updated-value">
                                                    <?= h(
                                                        $log["new_vaccine"]
                                                        ?: "—"
                                                    ) ?>
                                                </span>
                                            </div>

                                            <div>
                                                <strong>Date:</strong>
                                                <span class="updated-value">
                                                    <?= h(formatAuditDate(
                                                        $log["new_visit"]
                                                    )) ?>
                                                </span>
                                            </div>

                                        </div>
                                    </td>

                                    <td>
                                        <?= h($log["reason"] ?: "—") ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            $log["staff_name"]
                                            ?? "Unknown staff"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(formatAuditDateTime(
                                            $log["changed_at"]
                                        )) ?>
                                    </td>

                                    <td>
                                        <a
                                            href="vaccination_history.php?id=<?= (int) $log["patient_id"] ?>"
                                            class="patient-link"
                                        >
                                            View Records
                                        </a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p
                    id="filterEmpty"
                    class="filter-empty"
                >
                    No matching schedule changes found.
                </p>

                <p class="info-note">
                    This page displays schedule changes recorded after
                    vaccination schedule audit logging was enabled.
                    Previous changes cannot be reconstructed automatically.
                </p>

            </div>

        </section>

    </main>

</div>

<script>

const auditSearch = document.getElementById("auditSearch");
const auditFilter = document.getElementById("auditFilter");
const auditRows = document.querySelectorAll(".audit-row");
const filterEmpty = document.getElementById("filterEmpty");

function filterAuditHistory() {

    const search = auditSearch.value.toLowerCase().trim();
    const selectedChange = auditFilter.value;

    let visibleRows = 0;

    auditRows.forEach(row => {

        const matchesSearch =
            row.textContent.toLowerCase().includes(search);

        const matchesChange =
            selectedChange === "" ||
            row.dataset.change === selectedChange;

        const visible = matchesSearch && matchesChange;

        row.style.display = visible ? "" : "none";

        if (visible) {
            visibleRows++;
        }

    });

    filterEmpty.style.display =
        auditRows.length > 0 && visibleRows === 0
            ? "block"
            : "none";
}

auditSearch.addEventListener("input", filterAuditHistory);
auditFilter.addEventListener("change", filterAuditHistory);

</script>

</body>
</html>
