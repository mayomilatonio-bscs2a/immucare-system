
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Date filters.
$startDate = $_GET["start_date"] ?? "";
$endDate = $_GET["end_date"] ?? "";

if (!is_string($startDate)) {
    $startDate = "";
}

if (!is_string($endDate)) {
    $endDate = "";
}

function validReportDate($date) {
    if ($date === "") {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $date
    );

    return $parsed !== false
        && $parsed->format("Y-m-d") === $date;
}

$error = "";

if (
    !validReportDate($startDate)
    || !validReportDate($endDate)
) {
    $error = "Please enter valid report dates.";
    $startDate = "";
    $endDate = "";
} elseif (
    $startDate !== ""
    && $endDate !== ""
    && $startDate > $endDate
) {
    $error = "Start date cannot be later than end date.";
    $startDate = "";
    $endDate = "";
}

// Summary statistics.
$totalInfants = 0;
$totalVaccinations = 0;
$totalCompleted = 0;
$totalUpcoming = 0;
$totalOverdue = 0;

$vaccinationRecords = [];
$appointmentRecords = [];

try {

    // Total registered infants.
    $result = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM patients
    ");

    $totalInfants = (int) $result->fetch_assoc()["total"];

// Total active vaccination records.
$result = $mysqli->query("
    SELECT COUNT(*) AS total
    FROM vaccination_records
    WHERE deleted_at IS NULL
");

    $totalVaccinations = (int) $result->fetch_assoc()["total"];

    // Completed appointments.
    $result = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE status = 'Completed'
    ");

    $totalCompleted = (int) $result->fetch_assoc()["total"];

    // Upcoming appointments.
    $result = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE status IN ('Pending', 'Approved', 'Scheduled')
          AND appointment_date >= CURDATE()
    ");

    $totalUpcoming = (int) $result->fetch_assoc()["total"];

    // Overdue vaccinations.
    $result = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM patients
        WHERE status = 'Overdue'
           OR (
               next_visit IS NOT NULL
               AND next_visit < CURDATE()
           )
    ");

    $totalOverdue = (int) $result->fetch_assoc()["total"];

// Vaccination records report — active records only.
$sql = "
    SELECT
        vr.id,
        p.baby_name,
        p.guardian,
        vr.vaccine,
        vr.date_given,
        vr.remarks
    FROM vaccination_records vr
    INNER JOIN patients p
        ON p.id = vr.patient_id
    WHERE vr.deleted_at IS NULL
";

    $types = "";
    $params = [];

    if ($startDate !== "") {
        $sql .= " AND vr.date_given >= ?";
        $types .= "s";
        $params[] = $startDate;
    }

    if ($endDate !== "") {
        $sql .= " AND vr.date_given <= ?";
        $types .= "s";
        $params[] = $endDate;
    }

    $sql .= "
        ORDER BY vr.date_given DESC, vr.id DESC
    ";

    $stmt = $mysqli->prepare($sql);

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $vaccinationRecords = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    // Appointment report.
    $sql = "
        SELECT
            a.id,
            p.baby_name,
            p.guardian,
            a.vaccine,
            a.appointment_date,
            a.appointment_time,
            a.status
        FROM appointments a
        INNER JOIN patients p
            ON p.id = a.patient_id
        WHERE 1 = 1
    ";

    $types = "";
    $params = [];

    if ($startDate !== "") {
        $sql .= " AND a.appointment_date >= ?";
        $types .= "s";
        $params[] = $startDate;
    }

    if ($endDate !== "") {
        $sql .= " AND a.appointment_date <= ?";
        $types .= "s";
        $params[] = $endDate;
    }

    $sql .= "
        ORDER BY a.appointment_date DESC, a.id DESC
    ";

    $stmt = $mysqli->prepare($sql);

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $appointmentRecords = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (mysqli_sql_exception $e) {

    error_log(
        "Reports error: " . $e->getMessage()
    );

    http_response_code(500);
    exit("Unable to load reports. Please check the database.");
}

// Formatting helper.
function reportDate($value) {

    if (empty($value)) {
        return "Not set";
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false
        ? "Not set"
        : date("M j, Y", $timestamp);
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

    <title>Reports | ImmuCare</title>

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
            min-width: 0;
            min-height: 100vh;
            height: auto;
        }

        .reports-container {
            padding: 25px;
        }

        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .reports-header h1 {
            color: #315f68;
            font-size: 26px;
            margin: 0 0 7px;
        }

        .reports-header p {
            color: #82999c;
            font-size: 14px;
            margin: 0;
        }

        .print-btn,
        .filter-btn {
            background: #58af98;
            color: white;
            border: none;
            padding: 12px 18px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .print-btn:hover,
        .filter-btn:hover {
            background: #459d86;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .summary-card p {
            margin: 0 0 10px;
            color: #708c90;
            font-size: 12px;
        }

        .summary-card h2 {
            margin: 0;
            color: #315f68;
            font-size: 28px;
        }

        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .filter-card h2 {
            margin: 0 0 15px;
            color: #315f68;
            font-size: 17px;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 15px;
        }

        .filter-group {
            display: grid;
            gap: 7px;
        }

        .filter-group label {
            color: #416f72;
            font-size: 13px;
            font-weight: 600;
        }

        .filter-group input {
            padding: 11px;
            border: 1px solid #dcebea;
            border-radius: 9px;
            color: #315f68;
            font: inherit;
        }

        .reset-link {
            color: #416f72;
            text-decoration: none;
            padding: 12px 5px;
            font-size: 13px;
        }

        .report-section {
            background: white;
            padding: 22px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .report-section h2 {
            margin: 0 0 7px;
            color: #315f68;
            font-size: 19px;
        }

        .report-section p {
            margin: 0 0 18px;
            color: #82999c;
            font-size: 13px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        .report-table th,
        .report-table td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #edf2f1;
            font-size: 13px;
            vertical-align: top;
        }

        .report-table th {
            background: #f1f8f6;
            color: #315f68;
            font-weight: 600;
        }

        .report-table td {
            color: #587578;
            overflow-wrap: anywhere;
        }

        .empty-table {
            padding: 20px;
            text-align: center;
            color: #82999c;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e7f2fa;
            color: #377a99;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.completed {
            background: #e4f8ef;
            color: #3f8b74;
        }

        .status-badge.cancelled,
        .status-badge.rejected {
            background: #ffe6eb;
            color: #b85367;
        }

        .report-error {
            padding: 12px 15px;
            margin-bottom: 20px;
            background: #fff0f4;
            color: #9b5268;
            border-radius: 9px;
        }

        .report-footer {
            color: #82999c;
            font-size: 12px;
            text-align: right;
            margin-top: 20px;
        }

        @media (max-width: 1100px) {

            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 750px) {

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .reports-container {
                padding: 15px;
            }

            .report-section {
                padding: 15px;
            }
        }

        @media print {

            @page {
                size: A4 landscape;
                margin: 12mm;
            }

            body {
                background: white !important;
            }

            .sidebar,
            .topbar,
            .print-btn,
            .filter-card {
                display: none !important;
            }

            .dashboard,
            .main {
                display: block !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: 0 !important;
            }

            .reports-container {
                padding: 0;
            }

            .summary-card,
            .report-section {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .report-section {
                break-inside: auto;
            }

            .report-table {
                min-width: 0;
            }

            .report-table thead {
                display: table-header-group;
            }

            .report-table tr {
                break-inside: avoid;
            }

            .table-wrapper {
                overflow: visible;
            }

        }
.report-export-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.export-btn {
    display: inline-block;
    padding: 12px 16px;
    border-radius: 9px;
    background: #e4f5ff;
    color: #377a99;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}

.export-btn:hover {
    background: #d2ebf9;
}

@media print {
    .report-export-actions {
        display: none !important;
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

            <a href="guardian_accounts.php">
                Guardian Accounts
            </a>

            <a href="management.php">Management</a>

            <a href="monitoring.php">Monitoring</a>

            <a href="announcements.php">Announcements</a>

            <a href="reports.php" class="active">
                Reports
            </a>

            <a href="settings.php">Settings</a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main">

        <header class="topbar">

            <div class="welcome-admin">
                Reports
            </div>

        </header>

        <section class="reports-container">

            <!-- PAGE HEADER -->

            <div class="reports-header">

                <div>

                    <h1>ImmuCare Reports</h1>

                    <p>
                        View vaccination records, appointments,
                        and clinic statistics.
                    </p>

                </div>

                
<div class="report-export-actions">

    <a
        class="export-btn"
        href="export-reports.php?<?= escapeHtml(http_build_query([
            'type' => 'vaccinations',
            'start_date' => $startDate,
            'end_date' => $endDate
        ])) ?>"
    >
        Export Vaccinations CSV
    </a>

    <a
        class="export-btn"
        href="export-reports.php?<?= escapeHtml(http_build_query([
            'type' => 'appointments',
            'start_date' => $startDate,
            'end_date' => $endDate
        ])) ?>"
    >
        Export Appointments CSV
    </a>

    <button
        type="button"
        class="print-btn"
        onclick="window.print()"
    >
        Print Report
    </button>

</div>


            </div>

            <?php if ($error !== ""): ?>

                <div class="report-error">
                    <?= escapeHtml($error) ?>
                </div>

            <?php endif; ?>

            <!-- SUMMARY CARDS -->

            <div class="summary-grid">

                <div class="summary-card">

                    <p>Registered Infants</p>

                    <h2><?= $totalInfants ?></h2>

                </div>

                <div class="summary-card">

                    <p>Vaccination Records</p>

                    <h2><?= $totalVaccinations ?></h2>

                </div>

                <div class="summary-card">

                    <p>Completed Appointments</p>

                    <h2><?= $totalCompleted ?></h2>

                </div>

                <div class="summary-card">

                    <p>Upcoming Appointments</p>

                    <h2><?= $totalUpcoming ?></h2>

                </div>

                <div class="summary-card">

                    <p>Overdue Vaccinations</p>

                    <h2><?= $totalOverdue ?></h2>

                </div>

            </div>

            <!-- DATE FILTER -->

            <div class="filter-card">

                <h2>Filter Reports by Date</h2>

                <form
                    method="GET"
                    class="filter-form"
                >

                    <div class="filter-group">

                        <label for="start_date">
                            Start Date
                        </label>

                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            value="<?= escapeHtml($startDate) ?>"
                        >

                    </div>

                    <div class="filter-group">

                        <label for="end_date">
                            End Date
                        </label>

                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            value="<?= escapeHtml($endDate) ?>"
                        >

                    </div>

                    <button
                        type="submit"
                        class="filter-btn"
                    >
                        Apply Filter
                    </button>

                    <a
                        href="reports.php"
                        class="reset-link"
                    >
                        Clear Filter
                    </a>

                </form>

            </div>

            <!-- VACCINATION REPORT -->

            <div class="report-section">

                <h2>Vaccination Records Report</h2>

                <p>
                    Total records displayed:
                    <?= count($vaccinationRecords) ?>
                </p>

                <div class="table-wrapper">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>#</th>
                                <th>Infant Name</th>
                                <th>Guardian</th>
                                <th>Vaccine</th>
                                <th>Date Given</th>
                                <th>Remarks</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($vaccinationRecords === []): ?>

                                <tr>
                                    <td colspan="6" class="empty-table">
                                        No vaccination records found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($vaccinationRecords as $index => $record): ?>

                                    <tr>

                                        <td><?= $index + 1 ?></td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) $record["baby_name"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) $record["guardian"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) $record["vaccine"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                reportDate($record["date_given"])
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) ($record["remarks"] ?? "")
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <!-- APPOINTMENT REPORT -->

            <div class="report-section">

                <h2>Appointment Report</h2>

                <p>
                    Total appointments displayed:
                    <?= count($appointmentRecords) ?>
                </p>

                <div class="table-wrapper">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>#</th>
                                <th>Infant Name</th>
                                <th>Guardian</th>
                                <th>Vaccine</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($appointmentRecords === []): ?>

                                <tr>
                                    <td colspan="7" class="empty-table">
                                        No appointment records found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($appointmentRecords as $index => $appointment): ?>

                                    <?php
                                    $appointmentStatus = (string) $appointment["status"];
                                    $statusClass = strtolower($appointmentStatus);
                                    ?>

                                    <tr>

                                        <td><?= $index + 1 ?></td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) $appointment["baby_name"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) $appointment["guardian"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                (string) $appointment["vaccine"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapeHtml(
                                                reportDate($appointment["appointment_date"])
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php if (!empty($appointment["appointment_time"])): ?>

                                                <?= escapeHtml(
                                                    date(
                                                        "g:i A",
                                                        strtotime(
                                                            (string) $appointment["appointment_time"]
                                                        )
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                Not set

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <span class="status-badge <?= escapeHtml($statusClass) ?>">
                                                <?= escapeHtml($appointmentStatus) ?>
                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <div class="report-footer">

                Generated on
                <?= escapeHtml(date("F j, Y g:i A")) ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>
