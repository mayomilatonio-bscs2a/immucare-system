
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
    if (!$value) return "—";

    $timestamp = strtotime($value);
    return $timestamp ? date("M d, Y", $timestamp) : "—";
}

function formatAuditTime($value) {
    if (!$value) return "—";

    $timestamp = strtotime($value);
    return $timestamp ? date("h:i A", $timestamp) : "—";
}

function formatAuditDateTime($value) {
    if (!$value) return "—";

    $timestamp = strtotime($value);
    return $timestamp
        ? date("M d, Y h:i A", $timestamp)
        : "—";
}

// Optional appointment ID filter.
$appointmentId = filter_var(
    $_GET["id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

// Retrieve appointment audit history.
if ($appointmentId) {

    $stmt = $mysqli->prepare("
        SELECT
            l.*,
            u.full_name AS staff_name,
            p.baby_name,
            p.guardian,
            a.vaccine
        FROM appointment_audit_logs AS l
        LEFT JOIN users AS u
            ON u.id = l.changed_by
        LEFT JOIN appointments AS a
            ON a.id = l.appointment_id
        LEFT JOIN patients AS p
            ON p.id = a.patient_id
        WHERE l.appointment_id = ?
        ORDER BY l.changed_at DESC, l.id DESC
    ");

    $stmt->bind_param("i", $appointmentId);

} else {

    $stmt = $mysqli->prepare("
        SELECT
            l.*,
            u.full_name AS staff_name,
            p.baby_name,
            p.guardian,
            a.vaccine
        FROM appointment_audit_logs AS l
        LEFT JOIN users AS u
            ON u.id = l.changed_by
        LEFT JOIN appointments AS a
            ON a.id = l.appointment_id
        LEFT JOIN patients AS p
            ON p.id = a.patient_id
        ORDER BY l.changed_at DESC, l.id DESC
    ");
}

$stmt->execute();

$logs = $stmt->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// Calculate summary counts.
$total = count($logs);

$accepted = 0;
$rejected = 0;
$rescheduled = 0;
$completed = 0;

foreach ($logs as $log) {

    $action = strtolower(
        trim((string) $log["action"])
    );

    if ($action === "accepted") {
        $accepted++;

    } elseif ($action === "rejected") {
        $rejected++;

    } elseif ($action === "rescheduled") {
        $rescheduled++;

    } elseif ($action === "completed") {
        $completed++;
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

    <title>Appointment Audit History | ImmuCare</title>

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

        .audit-container {
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

        .summary-grid {
            display: grid;
            grid-template-columns:
                repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card,
        .table-card {
            background: white;
            border-radius: 15px;
            padding: 22px;
            box-shadow:
                0 4px 12px rgba(70,110,110,.08);
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
            min-width: 1150px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 12px;
            text-align: left;
            border-bottom:
                1px solid #edf2f0;
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

        .action-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .action-accepted {
            background: #e1f5ee;
            color: #237c60;
        }

        .action-rejected {
            background: #ffe8ed;
            color: #b44961;
        }

        .action-rescheduled {
            background: #e9f1ff;
            color: #365fa0;
        }

        .action-completed {
            background: #dff6e9;
            color: #237a4c;
        }

        .action-other {
            background: #edf1f2;
            color: #62777b;
        }

        .change-details {
            display: grid;
            gap: 6px;
        }

        .change-details strong {
            color: #315f68;
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

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {

            .audit-container {
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
            <a href="guardian_accounts.php">
                Guardian Accounts
            </a>
            <a href="management.php" class="active">
                Management
            </a>
            <a href="monitoring.php">Monitoring</a>
            <a href="announcements.php">
                Announcements
            </a>
            <a href="reports.php">Reports</a>
            <a href="settings.php">Settings</a>
        </nav>

    </aside>

    <!-- MAIN -->
    <main class="main">

        <!-- TOP NAVIGATION BAR -->
        <header class="topbar">
            <div class="welcome-admin">
                Appointment Audit History
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <section class="audit-container">

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

                <h1>Appointment Audit History</h1>

            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-grid">

                <div class="summary-card">
                    <p>Total Changes</p>
                    <h2><?= $total ?></h2>
                </div>

                <div class="summary-card">
                    <p>Accepted</p>
                    <h2><?= $accepted ?></h2>
                </div>

                <div class="summary-card">
                    <p>Rejected</p>
                    <h2><?= $rejected ?></h2>
                </div>

                <div class="summary-card">
                    <p>Rescheduled</p>
                    <h2><?= $rescheduled ?></h2>
                </div>

                <div class="summary-card">
                    <p>Completed</p>
                    <h2><?= $completed ?></h2>
                </div>

            </div>

            <!-- AUDIT HISTORY TABLE -->
            <div class="table-card">

                <div class="table-header">

                    <h2>Staff Appointment Changes</h2>

                    <div class="filters">

                        <input
                            type="search"
                            id="auditSearch"
                            placeholder="Search patient, staff, or appointment..."
                            aria-label="Search audit history"
                        >

                        <select
                            id="auditAction"
                            aria-label="Filter audit history by action"
                        >

                            <option value="">
                                All Actions
                            </option>

                            <option value="accepted">
                                Accepted
                            </option>

                            <option value="rejected">
                                Rejected
                            </option>

                            <option value="rescheduled">
                                Rescheduled
                            </option>

                            <option value="completed">
                                Completed
                            </option>

                        </select>

                    </div>

                </div>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>Appointment</th>
                                <th>Patient / Guardian</th>
                                <th>Vaccine</th>
                                <th>Action</th>
                                <th>Previous Information</th>
                                <th>Updated Information</th>
                                <th>Reason</th>
                                <th>Changed By</th>
                                <th>Changed At</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($logs)): ?>

                                <tr>
                                    <td
                                        colspan="9"
                                        class="empty-message"
                                    >
                                        No appointment audit records found.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($logs as $log): ?>

                                    <?php

                                        $action = strtolower(
                                            trim((string) $log["action"])
                                        );

                                        $allowedActions = [
                                            "accepted",
                                            "rejected",
                                            "rescheduled",
                                            "completed"
                                        ];

                                        $actionClass = in_array(
                                            $action,
                                            $allowedActions,
                                            true
                                        )
                                            ? "action-" . $action
                                            : "action-other";

                                    ?>

                                    <tr
                                        class="audit-row"
                                        data-action="<?= h($action) ?>"
                                    >

                                        <td>
                                            #<?= (int) $log["appointment_id"] ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(
                                                    $log["baby_name"]
                                                    ?? "Unknown patient"
                                                ) ?>
                                            </strong>

                                            <div>
                                                <?= h(
                                                    $log["guardian"]
                                                    ?? "—"
                                                ) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?= h($log["vaccine"] ?? "—") ?>
                                        </td>

                                        <td>
                                            <span
                                                class="action-badge <?= h($actionClass) ?>"
                                            >
                                                <?= h($log["action"]) ?>
                                            </span>
                                        </td>

                                        <!-- PREVIOUS INFORMATION -->
                                        <td>
                                            <div class="change-details">

                                                <div>
                                                    <strong>Status:</strong>
                                                    <?= h(
                                                        $log["old_status"]
                                                        ?: "—"
                                                    ) ?>
                                                </div>

                                                <div>
                                                    <strong>Date:</strong>
                                                    <?= h(formatAuditDate(
                                                        $log["old_date"]
                                                    )) ?>
                                                </div>

                                                <div>
                                                    <strong>Time:</strong>
                                                    <?= h(formatAuditTime(
                                                        $log["old_time"]
                                                    )) ?>
                                                </div>

                                            </div>
                                        </td>

                                        <!-- UPDATED INFORMATION -->
                                        <td>
                                            <div class="change-details">

                                                <div>
                                                    <strong>Status:</strong>
                                                    <?= h(
                                                        $log["new_status"]
                                                        ?: "—"
                                                    ) ?>
                                                </div>

                                                <div>
                                                    <strong>Date:</strong>
                                                    <?= h(formatAuditDate(
                                                        $log["new_date"]
                                                    )) ?>
                                                </div>

                                                <div>
                                                    <strong>Time:</strong>
                                                    <?= h(formatAuditTime(
                                                        $log["new_time"]
                                                    )) ?>
                                                </div>

                                            </div>
                                        </td>

                                        <!-- REASON -->
                                        <td>
                                            <?= h(
                                                $log["reason"]
                                                ?: "—"
                                            ) ?>
                                        </td>

                                        <!-- STAFF -->
                                        <td>
                                            <?= h(
                                                $log["staff_name"]
                                                ?? "Unknown staff"
                                            ) ?>
                                        </td>

                                        <!-- DATE AND TIME -->
                                        <td>
                                            <?= h(formatAuditDateTime(
                                                $log["changed_at"]
                                            )) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p class="info-note">
                    This page records staff actions saved after
                    appointment audit logging was enabled.
                    Earlier changes cannot be reconstructed automatically.
                </p>

            </div>

        </section>

    </main>

</div>

<script>

const searchInput = document.getElementById("auditSearch");
const actionFilter = document.getElementById("auditAction");
const rows = document.querySelectorAll(".audit-row");

function filterAudit() {

    const search = searchInput.value.toLowerCase().trim();
    const selectedAction = actionFilter.value;

    rows.forEach(row => {

        const matchesSearch =
            row.textContent.toLowerCase().includes(search);

        const matchesAction =
            selectedAction === "" ||
            row.dataset.action === selectedAction;

        row.style.display =
            matchesSearch && matchesAction ? "" : "none";

    });
}

searchInput.addEventListener("input", filterAudit);
actionFilter.addEventListener("change", filterAudit);

</script>

</body>
</html>
