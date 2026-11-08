
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

// Retrieve completed, rejected, and cancelled appointments.
$stmt = $mysqli->prepare("
    SELECT
        a.id,
        a.vaccine,
        a.appointment_date,
        a.appointment_time,
        a.status,
        a.created_at,
        p.baby_name,
        p.guardian
    FROM appointments AS a
    LEFT JOIN patients AS p
        ON p.id = a.patient_id
    WHERE LOWER(TRIM(a.status)) IN (
        'completed',
        'rejected',
        'cancelled',
        'canceled'
    )
    ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC,
        a.id DESC
");

$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Appointment history summary.
$total = count($appointments);
$completed = 0;
$rejected = 0;
$cancelled = 0;

foreach ($appointments as $item) {
    $status = strtolower(trim((string) $item["status"]));

    if ($status === "completed") {
        $completed++;
    } elseif ($status === "rejected") {
        $rejected++;
    } elseif (in_array($status, ["cancelled", "canceled"], true)) {
        $cancelled++;
    }
}

// Format appointment date.
function formatDateValue($value) {
    if (!$value) {
        return "—";
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date("M d, Y", $timestamp)
        : $value;
}

// Format appointment time.
function formatTimeValue($value) {
    if (!$value) {
        return "—";
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date("h:i A", $timestamp)
        : $value;
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

    <title>Appointment History | ImmuCare</title>

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

        /* Main page content */
        .history-container {
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
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: #315f68;
            font-size: 26px;
        }

        /* Summary cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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

        /* History table */
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
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
            min-width: 850px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf2f0;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f4faf7;
            color: #315f68;
        }

        td {
            color: #55777d;
        }

        /* Appointment status badges */
        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 6px 11px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-completed {
            background: #e6efff;
            color: #365fa0;
        }

        .status-rejected {
            background: #ffe8ed;
            color: #b44961;
        }

        .status-cancelled,
        .status-canceled {
            background: #edf1f2;
            color: #62777b;
        }

        .empty-message {
            text-align: center;
            padding: 30px;
            color: #82999c;
        }

        .info-note {
            margin-top: 15px;
            color: #82999c;
            font-size: 13px;
        }

        /* Responsive layout */
        @media (max-width: 800px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .history-container {
                padding: 15px;
            }

            .filters,
            .filters input,
            .filters select {
                width: 100%;
                box-sizing: border-box;
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

        <!-- TOP NAVIGATION BAR -->
        <header class="topbar">
            <div class="welcome-admin">
                Appointment History
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <section class="history-container">

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
                <h1>Appointment History</h1>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-grid">

                <div class="summary-card">
                    <p>Total History Records</p>
                    <h2><?= $total ?></h2>
                </div>

                <div class="summary-card">
                    <p>Completed</p>
                    <h2><?= $completed ?></h2>
                </div>

                <div class="summary-card">
                    <p>Rejected</p>
                    <h2><?= $rejected ?></h2>
                </div>

                <div class="summary-card">
                    <p>Cancelled</p>
                    <h2><?= $cancelled ?></h2>
                </div>

            </div>

            <!-- HISTORY TABLE -->
            <div class="table-card">

                <div class="table-header">

                    <h2>Past Appointment Records</h2>

                    <div class="filters">

                        <input
                            type="search"
                            id="historySearch"
                            placeholder="Search patient or vaccine..."
                            aria-label="Search appointment history"
                        >

                        <select
                            id="historyStatus"
                            aria-label="Filter appointment history by status"
                        >
                            <option value="">All Statuses</option>
                            <option value="completed">Completed</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>

                    </div>

                </div>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient</th>
                                <th>Guardian</th>
                                <th>Vaccine</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($appointments)): ?>

                                <tr>
                                    <td
                                        colspan="7"
                                        class="empty-message"
                                    >
                                        No appointment history found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($appointments as $item): ?>

                                    <?php
                                        $status = strtolower(
                                            trim((string) $item["status"])
                                        );

                                        $filterStatus =
                                            $status === "canceled"
                                                ? "cancelled"
                                                : $status;
                                    ?>

                                    <tr
                                        class="history-row"
                                        data-status="<?= h($filterStatus) ?>"
                                    >

                                        <td>
                                            <?= h($item["id"]) ?>
                                        </td>

                                        <td>
                                            <?= h(
                                                $item["baby_name"]
                                                ?? "Unknown patient"
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= h(
                                                $item["guardian"]
                                                ?? "—"
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= h($item["vaccine"]) ?>
                                        </td>

                                        <td>
                                            <?= h(formatDateValue(
                                                $item["appointment_date"]
                                            )) ?>
                                        </td>

                                        <td>
                                            <?= h(formatTimeValue(
                                                $item["appointment_time"]
                                            )) ?>
                                        </td>

                                        <td>
                                            <span class="status status-<?= h($status) ?>">
                                                <?= h($item["status"]) ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p class="info-note">
                    This page shows appointments with completed,
                    rejected, or cancelled status.
                </p>

            </div>

        </section>

    </main>

</div>

<script>
    const searchInput = document.getElementById("historySearch");
    const statusFilter = document.getElementById("historyStatus");
    const rows = document.querySelectorAll(".history-row");

    function filterHistory() {
        const search = searchInput.value.toLowerCase().trim();
        const selectedStatus = statusFilter.value;

        rows.forEach(row => {
            const matchesSearch =
                row.textContent.toLowerCase().includes(search);

            const matchesStatus =
                selectedStatus === "" ||
                row.dataset.status === selectedStatus;

            row.style.display =
                matchesSearch && matchesStatus ? "" : "none";
        });
    }

    searchInput.addEventListener("input", filterHistory);
    statusFilter.addEventListener("change", filterHistory);
</script>

</body>
</html>
