
<?php

require_once __DIR__ . "/../auth.php";
requireStaffLogin();

require_once __DIR__ . "/../connect.php";

$isAdministrator = strcasecmp(
    trim((string) ($_SESSION["role"] ?? "")),
    "Administrator"
) === 0;

function h($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function formatVisitDate($value) {
    if (empty($value) || $value === "0000-00-00") {
        return "—";
    }

    $timestamp = strtotime((string) $value);
    return $timestamp ? date("M d, Y", $timestamp) : "—";
}

// Retrieve patient vaccination schedules.
try {

    $stmt = $mysqli->prepare("
        SELECT
            p.id,
            p.baby_name,
            p.guardian,
            p.phone_number,
            COALESCE(
                (
                    SELECT vr.vaccine
                    FROM vaccination_records vr
                    WHERE vr.patient_id = p.id
                    ORDER BY vr.date_given DESC, vr.id DESC
                    LIMIT 1
                ),
                p.last_vaccine
            ) AS last_vaccine,
            p.next_vaccine,
            p.next_visit,
            p.status,
            (
                SELECT MAX(vr.date_given)
                FROM vaccination_records vr
                WHERE vr.patient_id = p.id
                  AND vr.deleted_at IS NULL
            ) AS latest_date_given,
            DATEDIFF(p.next_visit, CURDATE()) AS days_remaining
        FROM patients p
        WHERE p.next_visit IS NOT NULL
          AND p.next_visit <> '0000-00-00'
        ORDER BY p.next_visit ASC, p.baby_name ASC
    ");

    $stmt->execute();

    $patients = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (mysqli_sql_exception $e) {

    error_log("Monitoring error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load vaccination schedules.");
}

// Group patients by vaccination schedule.
$overduePatients = [];
$dueTodayPatients = [];
$upcomingPatients = [];

foreach ($patients as $patient) {

    $days = (int) $patient["days_remaining"];

    // Review flag does not confirm vaccine administration.
    $patient["needs_review"] = (
        !empty($patient["latest_date_given"]) &&
        $patient["latest_date_given"] >= $patient["next_visit"]
    );

    if ($days < 0) {

        $overduePatients[] = $patient;

    } elseif ($days === 0) {

        $dueTodayPatients[] = $patient;

    } else {

        $upcomingPatients[] = $patient;
    }
}

// Summary counts.
$totalScheduled = count($patients);
$totalOverdue = count($overduePatients);
$totalDueToday = count($dueTodayPatients);
$totalUpcoming = count($upcomingPatients);

// Monitoring sections.
$sections = [
    [
        "key" => "overdue",
        "title" => "Overdue Vaccinations",
        "patients" => $overduePatients,
        "badge" => "Overdue",
        "date_heading" => "Scheduled Visit"
    ],
    [
        "key" => "today",
        "title" => "Vaccinations Due Today",
        "patients" => $dueTodayPatients,
        "badge" => "Due Today",
        "date_heading" => "Visit Date"
    ],
    [
        "key" => "upcoming",
        "title" => "Next Vaccine Schedule",
        "patients" => $upcomingPatients,
        "badge" => "Upcoming",
        "date_heading" => "Next Visit"
    ]
];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Monitoring | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        html,
        body {
            min-height: 100%;
            height: auto;
            overflow-y: auto;
        }

        .dashboard,
        .main {
            min-height: 100vh;
            height: auto;
        }

        .main {
            min-width: 0;
            overflow: visible;
        }

        .monitoring-container {
            padding: 25px;
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

        /* Page heading */
        .monitoring-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .monitoring-title h1 {
            font-size: 26px;
            color: #315f68;
            margin-bottom: 7px;
        }

        .monitoring-title p {
            color: #82999c;
            font-size: 14px;
        }

        .schedule-history-btn {
            display: inline-block;
            background: #315f68;
            color: white;
            padding: 11px 18px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .schedule-history-btn:hover {
            background: #244b53;
        }

        /* Summary cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(70,110,110,0.08);
        }

        .summary-card p {
            color: #708c90;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .summary-card h2 {
            color: #315f68;
            font-size: 29px;
        }

        /* Monitoring sections */
        .monitoring-section {
            background: white;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 4px 12px rgba(70,110,110,0.08);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .section-header h2 {
            color: #315f68;
            font-size: 19px;
        }

        .section-count {
            color: #82999c;
            font-size: 13px;
        }

        /* Search and filter */
        .filter-container {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .filter-container input,
        .filter-container select {
            padding: 12px;
            border: 1px solid #d6e6e0;
            border-radius: 9px;
            background: white;
            color: #315f68;
            font: inherit;
        }

        .filter-container input {
            flex: 1;
            min-width: 200px;
        }

        /* Monitoring tables */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1000px;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            background: #e8f8f3;
            color: #315f68;
            padding: 13px;
            font-size: 13px;
        }

        td {
            padding: 13px;
            border-bottom: 1px solid #edf2f1;
            color: #55777d;
            font-size: 13px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f8fcfb;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .overdue-badge {
            background: #ffe6eb;
            color: #b85367;
        }

        .today-badge {
            background: #fff1d8;
            color: #a97822;
        }

        .upcoming-badge {
            background: #e5f8f1;
            color: #3f8b74;
        }

        .review-badge {
            display: inline-block;
            background: #fff1d8;
            color: #946718;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .review-note {
            color: #946718;
            font-size: 12px;
            margin-top: 5px;
            line-height: 1.5;
        }

        /* Action buttons */
        .action-group {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-block;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .view-btn {
            background: #e8f8f3;
            color: #315f68;
        }

        .update-btn {
            background: #5ab49c;
            color: white;
        }

        .patient-history-btn {
            background: #e8eff5;
            color: #315f68;
        }

        .empty-message,
        .filter-empty {
            padding: 25px;
            text-align: center;
            color: #80969a;
        }

        .filter-empty {
            display: none;
        }

        .monitoring-note {
            color: #82999c;
            font-size: 12px;
            margin-top: 8px;
            line-height: 1.5;
        }

        /* Responsive layout */
        @media (max-width: 1000px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {

            .monitoring-container {
                padding: 15px;
            }

            .summary-grid {
                gap: 10px;
            }

            .summary-card {
                padding: 15px;
            }

            .summary-card h2 {
                font-size: 24px;
            }

            .filter-container input,
            .filter-container select {
                width: 100%;
                box-sizing: border-box;
            }

            .schedule-history-btn {
                width: 100%;
                box-sizing: border-box;
                text-align: center;
            }

            .monitoring-section {
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
            <a href="management.php">Management</a>
            <a href="monitoring.php" class="active">Monitoring</a>
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
                Patient Monitoring
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <section class="monitoring-container">

            <!-- Upper-left Back button -->
            <a
                href="../index.php"
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
                Back to Dashboard
            </a>

            <!-- MONITORING HEADING -->
            <div class="monitoring-title">

                <div>
                    <h1>Vaccination Monitoring</h1>

                    <p>
                        Monitor upcoming, due today, and overdue
                        vaccination schedules.
                    </p>
                </div>

                <?php if ($isAdministrator): ?>

                    <a
                        href="vaccination_schedule_audit_history.php"
                        class="schedule-history-btn"
                    >
                        View Schedule History
                    </a>

                <?php endif; ?>

            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-grid">

                <div class="summary-card">
                    <p>Total Scheduled</p>
                    <h2><?= $totalScheduled ?></h2>
                </div>

                <div class="summary-card">
                    <p>Overdue</p>
                    <h2><?= $totalOverdue ?></h2>
                </div>

                <div class="summary-card">
                    <p>Due Today</p>
                    <h2><?= $totalDueToday ?></h2>
                </div>

                <div class="summary-card">
                    <p>Upcoming</p>
                    <h2><?= $totalUpcoming ?></h2>
                </div>

            </div>

            <!-- SEARCH AND FILTER -->
            <div class="filter-container">

                <input
                    type="search"
                    id="monitorSearch"
                    placeholder="Search patient, guardian, or vaccine..."
                    aria-label="Search vaccination schedules"
                >

                <select
                    id="monitorFilter"
                    aria-label="Filter vaccination schedules"
                >
                    <option value="">All Schedules</option>
                    <option value="overdue">Overdue</option>
                    <option value="today">Due Today</option>
                    <option value="upcoming">Upcoming</option>
                </select>

            </div>

            <!-- MONITORING SECTIONS -->
            <?php foreach ($sections as $section): ?>

                <?php
                    $sectionPatients = $section["patients"];
                    $sectionCount = count($sectionPatients);
                    $sectionKey = $section["key"];

                    $columnCount = $sectionKey === "overdue"
                        ? 10
                        : ($sectionKey === "today" ? 8 : 9);
                ?>

                <div
                    class="monitoring-section"
                    data-section="<?= h($sectionKey) ?>"
                >

                    <div class="section-header">

                        <h2>
                            <?= h($section["title"]) ?>
                        </h2>

                        <span class="section-count">
                            <?= $sectionCount ?> patient(s)
                        </span>

                    </div>

                    <div class="table-wrapper">

                        <table>

                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Guardian</th>
                                    <th>Phone</th>

                                    <?php if ($sectionKey === "overdue"): ?>
                                        <th>Last Vaccine</th>
                                    <?php endif; ?>

                                    <th>Next Vaccine</th>

                                    <th>
                                        <?= h($section["date_heading"]) ?>
                                    </th>

                                    <?php if ($sectionKey !== "today"): ?>
                                        <th>
                                            <?= $sectionKey === "overdue"
                                                ? "Days Overdue"
                                                : "Days Remaining" ?>
                                        </th>
                                    <?php endif; ?>

                                    <th>Status</th>
                                    <th>Schedule Review</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if ($sectionCount > 0): ?>

                                    <?php foreach ($sectionPatients as $patient): ?>

                                        <?php
                                            $currentPatientId =
                                                (int) $patient["id"];
                                        ?>

                                        <tr class="monitor-row">

                                            <td>
                                                <?= h($patient["baby_name"]) ?>
                                            </td>

                                            <td>
                                                <?= h($patient["guardian"]) ?>
                                            </td>

                                            <td>
                                                <?= h($patient["phone_number"]) ?>
                                            </td>

                                            <?php if ($sectionKey === "overdue"): ?>
                                                <td>
                                                    <?= h(
                                                        $patient["last_vaccine"]
                                                        ?: "—"
                                                    ) ?>
                                                </td>
                                            <?php endif; ?>

                                            <td>
                                                <?= h(
                                                    $patient["next_vaccine"]
                                                    ?: "—"
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= h(formatVisitDate(
                                                    $patient["next_visit"]
                                                )) ?>
                                            </td>

                                            <?php if ($sectionKey !== "today"): ?>

                                                <td>
                                                    <?php if ($sectionKey === "overdue"): ?>

                                                        <?= abs(
                                                            (int) $patient["days_remaining"]
                                                        ) ?>

                                                    <?php else: ?>

                                                        <?= (int) $patient["days_remaining"] ?>

                                                    <?php endif; ?>

                                                    day(s)
                                                </td>

                                            <?php endif; ?>

                                            <td>
                                                <span class="status-badge <?= h(
                                                    $sectionKey . "-badge"
                                                ) ?>">
                                                    <?= h($section["badge"]) ?>
                                                </span>
                                            </td>

                                            <td>

                                                <?php if ($patient["needs_review"]): ?>

                                                    <span class="review-badge">
                                                        Review needed
                                                    </span>

                                                    <p class="review-note">
                                                        A vaccination was recorded
                                                        on or after this visit date.
                                                        Confirm whether this
                                                        schedule is still current.
                                                    </p>

                                                <?php else: ?>

                                                    —

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <div class="action-group">

                                                    <!-- View vaccination records -->
                                                    <a
                                                        href="vaccination_history.php?id=<?= $currentPatientId ?>"
                                                        class="action-btn view-btn"
                                                    >
                                                        View Records
                                                    </a>

                                                    <!-- Update schedule -->
                                                    <a
                                                        href="update-schedule.php?id=<?= $currentPatientId ?>"
                                                        class="action-btn update-btn"
                                                    >
                                                        Update
                                                    </a>

                                                    <!-- Administrator history -->
                                                    <?php if ($isAdministrator): ?>

                                                        <a
                                                            href="vaccination_schedule_audit_history.php?id=<?= $currentPatientId ?>"
                                                            class="action-btn patient-history-btn"
                                                        >
                                                            History
                                                        </a>

                                                    <?php endif; ?>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>
                                        <td
                                            colspan="<?= $columnCount ?>"
                                            class="empty-message"
                                        >
                                            No <?= h($sectionKey) ?>
                                            vaccination schedules.
                                        </td>
                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <p class="filter-empty">
                        No matching vaccination schedules.
                    </p>

                </div>

            <?php endforeach; ?>

            <!-- MONITORING NOTE -->
            <p class="monitoring-note">
                Monitoring categories are based on each patient's
                clinic-recorded next visit date. A review flag does not
                confirm that the scheduled vaccine was administered.
                Clinic staff must verify the vaccination record and
                update the schedule when appropriate.
            </p>

        </section>

    </main>

</div>

<script>

const monitorSearch = document.getElementById("monitorSearch");
const monitorFilter = document.getElementById("monitorFilter");

const sections = document.querySelectorAll(
    ".monitoring-section"
);

function filterMonitoring() {

    const search = monitorSearch.value.toLowerCase().trim();
    const selectedStatus = monitorFilter.value;

    sections.forEach(section => {

        const sectionStatus = section.dataset.section;

        const statusMatches =
            selectedStatus === "" ||
            selectedStatus === sectionStatus;

        section.style.display = statusMatches ? "" : "none";

        const rows = section.querySelectorAll(".monitor-row");
        let visibleRows = 0;

        rows.forEach(row => {

            const matchesSearch =
                row.textContent.toLowerCase().includes(search);

            row.style.display = matchesSearch ? "" : "none";

            if (matchesSearch) {
                visibleRows++;
            }

        });

        const emptyMessage = section.querySelector(
            ".filter-empty"
        );

        if (emptyMessage) {
            emptyMessage.style.display =
                rows.length > 0 && visibleRows === 0
                    ? "block"
                    : "none";
        }

    });
}

monitorSearch.addEventListener("input", filterMonitoring);
monitorFilter.addEventListener("change", filterMonitoring);

</script>

</body>
</html>
