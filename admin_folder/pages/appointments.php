
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

// CSRF protection.
if (empty($_SESSION["appointment_status_csrf"])) {
    $_SESSION["appointment_status_csrf"] =
        bin2hex(random_bytes(32));
}

// Retrieve appointments and patient information.
$stmt = $mysqli->prepare("
    SELECT
        a.id,
        a.patient_id,
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
    ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC,
        a.id DESC
");

$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Appointment summary.
$total = count($appointments);
$pending = 0;
$scheduled = 0;
$completed = 0;
$cancelled = 0;

foreach ($appointments as $appointment) {
    $status = strtolower(trim((string) $appointment["status"]));

    if ($status === "pending") {
        $pending++;
    } elseif (in_array(
        $status,
        ["scheduled", "approved", "accepted"],
        true
    )) {
        $scheduled++;
    } elseif ($status === "completed") {
        $completed++;
    } elseif (in_array(
        $status,
        ["cancelled", "canceled", "rejected"],
        true
    )) {
        $cancelled++;
    }
}

function formatDateValue($value) {
    if (!$value) {
        return "—";
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date("M d, Y", $timestamp)
        : $value;
}

function formatTimeValue($value) {
    if (!$value) {
        return "—";
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date("h:i A", $timestamp)
        : $value;
}

// Flash messages.
$success = $_SESSION["appointment_flash"] ?? "";
$error = $_SESSION["appointment_error"] ?? "";

unset(
    $_SESSION["appointment_flash"],
    $_SESSION["appointment_error"]
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Appointment Management | ImmuCare</title>

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

        .appointments-container {
            padding: 25px;
        }

        /* Upper-left Back button */
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
            font-size: 26px;
            color: #315f68;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .history-btn {
            display: inline-block;
            padding: 11px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            background: #61bca4;
            color: white;
        }

        .history-btn:hover {
            background: #459d86;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 14px;
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

        .table-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

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
            flex-wrap: wrap;
            gap: 10px;
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
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1200px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #edf2f0;
            font-size: 14px;
        }

        th {
            color: #315f68;
            background: #f4faf7;
            font-weight: 600;
        }

        td {
            color: #55777d;
        }

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background: #fff4d9;
            color: #946900;
        }

        .status-scheduled,
        .status-approved,
        .status-accepted {
            background: #e1f5ee;
            color: #237c60;
        }

        .status-completed {
            background: #e6efff;
            color: #365fa0;
        }

        .status-cancelled,
        .status-canceled,
        .status-rejected {
            background: #ffe8ed;
            color: #b44961;
        }

        .status-other {
            background: #edf1f2;
            color: #62777b;
        }

        .action-buttons {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .action-buttons form {
            margin: 0;
        }

        .accept-btn,
        .reject-btn,
        .reschedule-btn,
        .complete-btn {
            display: inline-block;
            border: none;
            padding: 9px 13px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            white-space: nowrap;
        }

        .accept-btn {
            background: #61bca4;
            color: white;
        }

        .accept-btn:hover {
            background: #459d86;
        }

        .reject-btn {
            background: #ffe4e9;
            color: #b44961;
        }

        .reject-btn:hover {
            background: #ffd0d9;
        }

        .reschedule-btn {
            background: #e9f1ff;
            color: #365fa0;
        }

        .reschedule-btn:hover {
            background: #d7e6ff;
        }

        .complete-btn {
            background: #e6efff;
            color: #365fa0;
        }

        .complete-btn:hover {
            background: #d3e3ff;
        }

        .record-vaccination-btn {
            display: inline-block;
            padding: 9px 14px;
            background: #e8f8f3;
            color: #315f68;
            border: 1px solid #b9e4d5;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .record-vaccination-btn:hover {
            background: #d2efe5;
        }

        .no-action {
            color: #82999c;
        }

        .empty-message {
            text-align: center;
            padding: 30px;
            color: #82999c;
        }

        .flash-success,
        .flash-error {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .flash-success {
            background: #e8f8f3;
            color: #315f68;
        }

        .flash-error {
            background: #ffe8ed;
            color: #b44961;
        }

        .info-note {
            color: #82999c;
            font-size: 13px;
            margin-top: 15px;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .appointments-container {
                padding: 15px;
            }

            .summary-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .table-card {
                padding: 15px;
            }

            .filters {
                width: 100%;
            }

            .filters input,
            .filters select {
                width: 100%;
                box-sizing: border-box;
            }

            .header-actions {
                width: 100%;
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

        <header class="topbar">
            <div class="welcome-admin">
                Appointment Management
            </div>
        </header>

        <section class="appointments-container">

            <!-- Upper-left Back button -->
            <a
                href="management.php"
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
                Back to Management
            </a>

            <div class="page-header">

                <h1>Appointment Management</h1>

                <div class="header-actions">

                    <a
                        href="appointment_history.php"
                        class="history-btn"
                    >
                        View Appointment History
                    </a>

                    <?php if (
                        ($_SESSION["role"] ?? "") === "Administrator"
                    ): ?>

                        <a
                            href="appointment_audit_history.php"
                            class="history-btn"
                        >
                            View Audit History
                        </a>

                    <?php endif; ?>

                </div>

            </div>

            <!-- Flash messages -->
            <?php if ($success !== ""): ?>
                <div class="flash-success" role="status">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="flash-error" role="alert">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <!-- Summary -->
            <div class="summary-grid">

                <div class="summary-card">
                    <p>Total Appointments</p>
                    <h2><?= $total ?></h2>
                </div>

                <div class="summary-card">
                    <p>Pending</p>
                    <h2><?= $pending ?></h2>
                </div>

                <div class="summary-card">
                    <p>Scheduled</p>
                    <h2><?= $scheduled ?></h2>
                </div>

                <div class="summary-card">
                    <p>Completed</p>
                    <h2><?= $completed ?></h2>
                </div>

                <div class="summary-card">
                    <p>Cancelled / Rejected</p>
                    <h2><?= $cancelled ?></h2>
                </div>

            </div>

            <!-- Appointment records -->
            <div class="table-card">

                <div class="table-header">

                    <h2>Appointment Records</h2>

                    <div class="filters">

                        <input
                            type="search"
                            id="searchAppointments"
                            placeholder="Search patient or vaccine..."
                            aria-label="Search appointments"
                        >

                        <select
                            id="statusFilter"
                            aria-label="Filter by status"
                        >
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="approved">Approved</option>
                            <option value="accepted">Accepted</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="rejected">Rejected</option>
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
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody id="appointmentsTable">

                            <?php if (empty($appointments)): ?>

                                <tr>
                                    <td
                                        colspan="8"
                                        class="empty-message"
                                    >
                                        No appointments found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($appointments as $item): ?>

                                    <?php
                                        $status = strtolower(
                                            trim((string) $item["status"])
                                        );

                                        $knownStatuses = [
                                            "pending",
                                            "scheduled",
                                            "approved",
                                            "accepted",
                                            "completed",
                                            "cancelled",
                                            "canceled",
                                            "rejected"
                                        ];

                                        $statusClass = in_array(
                                            $status,
                                            $knownStatuses,
                                            true
                                        )
                                            ? "status-" . $status
                                            : "status-other";

                                        $canReschedule = in_array(
                                            $status,
                                            [
                                                "pending",
                                                "scheduled",
                                                "approved",
                                                "accepted"
                                            ],
                                            true
                                        );

                                        $canComplete = in_array(
                                            $status,
                                            [
                                                "scheduled",
                                                "approved",
                                                "accepted"
                                            ],
                                            true
                                        );
                                    ?>

                                    <tr
                                        class="appointment-row"
                                        data-status="<?= h($status) ?>"
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
                                            <span class="status <?= h($statusClass) ?>">
                                                <?= h(
                                                    $item["status"]
                                                    ?: "Unknown"
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="action-buttons">

                                                <!-- Accept / Reject -->
                                                <?php if ($status === "pending"): ?>

                                                    <form
                                                        method="POST"
                                                        action="update_appointment_status.php"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= h(
                                                                $_SESSION["appointment_status_csrf"]
                                                            ) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="appointment_id"
                                                            value="<?= (int) $item["id"] ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="accept"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="accept-btn"
                                                        >
                                                            Accept
                                                        </button>
                                                    </form>

                                                    <form
                                                        method="POST"
                                                        action="update_appointment_status.php"
                                                        onsubmit="return confirm('Reject this appointment request?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= h(
                                                                $_SESSION["appointment_status_csrf"]
                                                            ) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="appointment_id"
                                                            value="<?= (int) $item["id"] ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reject"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="reject-btn"
                                                        >
                                                            Reject
                                                        </button>
                                                    </form>

                                                <?php endif; ?>

                                                <!-- Reschedule -->
                                                <?php if ($canReschedule): ?>

                                                    <a
                                                        href="reschedule_appointment.php?id=<?= (int) $item["id"] ?>"
                                                        class="reschedule-btn"
                                                    >
                                                        Reschedule
                                                    </a>

                                                <?php endif; ?>

                                                <!-- Mark Completed -->
                                                <?php if ($canComplete): ?>

                                                    <form
                                                        method="POST"
                                                        action="complete_appointment.php"
                                                        onsubmit="return confirm('Confirm the visit and vaccination record before marking this appointment as completed. Continue?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= h(
                                                                $_SESSION["appointment_status_csrf"]
                                                            ) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="appointment_id"
                                                            value="<?= (int) $item["id"] ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="complete-btn"
                                                        >
                                                            Mark Completed
                                                        </button>
                                                    </form>

                                                    <!-- Record Vaccination -->
                                                    <a
                                                        href="add_vaccination.php?id=<?= (int) $item["patient_id"] ?>&appointment_id=<?= (int) $item["id"] ?>"
                                                        class="record-vaccination-btn"
                                                        title="Record an administered vaccine"
                                                    >
                                                        Record Vaccination
                                                    </a>

                                                <?php endif; ?>

                                                <!-- No available actions -->
                                                <?php if (
                                                    $status !== "pending" &&
                                                    !$canReschedule &&
                                                    !$canComplete
                                                ): ?>

                                                    <span class="no-action">
                                                        —
                                                    </span>

                                                <?php endif; ?>

                                            </div>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p class="info-note">
                    Pending appointments can be accepted, rejected,
                    or rescheduled. Scheduled appointments can be
                    rescheduled or marked as completed after confirming
                    the vaccination record. Completed, rejected, and
                    cancelled appointments are available in
                    Appointment History.
                </p>

            </div>

        </section>

    </main>

</div>

<script>
const searchInput = document.getElementById(
    "searchAppointments"
);

const statusFilter = document.getElementById(
    "statusFilter"
);

const rows = document.querySelectorAll(
    ".appointment-row"
);

function filterAppointments() {
    const search = searchInput.value
        .toLowerCase()
        .trim();

    const selectedStatus = statusFilter.value;

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const status = row.dataset.status;

        const matchesSearch = text.includes(search);

        const matchesStatus =
            selectedStatus === "" ||
            status === selectedStatus ||
            (
                selectedStatus === "cancelled" &&
                status === "canceled"
            );

        row.style.display =
            matchesSearch && matchesStatus
                ? ""
                : "none";
    });
}

searchInput.addEventListener(
    "input",
    filterAppointments
);

statusFilter.addEventListener(
    "change",
    filterAppointments
);
</script>

</body>
</html>
