
<?php
require_once __DIR__ . "/backend/common.php";

$guardian = currentGuardian();
$avatarFilename = guardianAvatarFilename($guardian);

function dashboardEscape($value) {
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}

function dashboardDate($value) {
    if (empty($value) || $value === "0000-00-00") {
        return "Not scheduled";
    }

    $timestamp = strtotime((string) $value);
    return $timestamp ? date("M j, Y", $timestamp) : "Not scheduled";
}

$patients = [];
$appointmentRequests = [];
$upcomingAppointments = [];
$appointmentDates = [];

$vaccinationStatus = "No records";
$remindersDue = 0;
$nextVaccination = null;
$latestVaccination = null;
$today = date("Y-m-d");

if ($guardian !== "") {

    // Retrieve only the logged-in guardian's children,
    // including their latest administered vaccination.
    $stmt = $mysqli->prepare("
        SELECT
            p.id,
            p.baby_name,
            p.status,
            p.next_vaccine,
            p.next_visit,
            (
    SELECT vr.vaccine
    FROM vaccination_records vr
    WHERE vr.patient_id = p.id
      AND vr.deleted_at IS NULL
    ORDER BY vr.date_given DESC, vr.id DESC
    LIMIT 1
) AS last_vaccine,
            (
                SELECT MAX(vr.date_given)
FROM vaccination_records vr
WHERE vr.patient_id = p.id
  AND vr.deleted_at IS NULL
            ) AS last_vaccine_date
        FROM patients p
        WHERE p.guardian = ?
        ORDER BY
            (p.next_visit IS NULL) ASC,
            p.next_visit ASC,
            p.baby_name ASC
    ");

    $stmt->bind_param("s", $guardian);
    $stmt->execute();
    $patients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Retrieve pending and approved guardian requests.
    $requestStmt = $mysqli->prepare("
        SELECT
            ar.id,
            ar.patient_id,
            p.baby_name,
            ar.appointment_date AS next_visit,
            ar.appointment_time,
            ar.status
        FROM appointment_requests ar
        INNER JOIN patients p
            ON p.id = ar.patient_id
        WHERE ar.guardian = ?
          AND p.guardian = ?
          AND ar.status IN ('Pending', 'Approved')
          AND ar.appointment_date >= CURDATE()
        ORDER BY
            ar.appointment_date ASC,
            ar.appointment_time ASC
    ");

    $requestStmt->bind_param("ss", $guardian, $guardian);
    $requestStmt->execute();
    $appointmentRequests = $requestStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $requestStmt->close();

    $hasOverdue = false;
    $hasDueToday = false;
    $hasScheduled = false;
    $hasPending = false;
    $hasUpToDate = false;

    // Track displayed visits to avoid counting the
    // same child and visit date twice.
    $scheduledKeys = [];

    foreach ($patients as $patient) {

        $status = strtolower(trim(
            (string) ($patient["status"] ?? "")
        ));

        $nextVisit = (string) ($patient["next_visit"] ?? "");
        $nextVaccine = trim(
            (string) ($patient["next_vaccine"] ?? "")
        );

        if ($nextVisit === "0000-00-00") {
            $nextVisit = "";
        }

        // Latest administered vaccine across all children.
        if (!empty($patient["last_vaccine_date"])) {
            if (
                $latestVaccination === null ||
                $patient["last_vaccine_date"] >
                    $latestVaccination["last_vaccine_date"]
            ) {
                $latestVaccination = $patient;
            }
        }

        if ($nextVisit !== "") {

            if ($nextVisit < $today) {
                $hasOverdue = true;
                $remindersDue++;

            } elseif ($nextVisit === $today) {
                $hasDueToday = true;
                $remindersDue++;

            } else {
                $hasScheduled = true;
            }

            // Add clinic-confirmed schedules to calendar.
            if ($nextVisit >= $today) {

                $key = $patient["id"] . "|" . $nextVisit;
                $scheduledKeys[$key] = true;

                $upcomingAppointments[] = [
                    "patient_id" => (int) $patient["id"],
                    "baby_name" => $patient["baby_name"],
                    "next_visit" => $nextVisit,
                    "next_vaccine" => $nextVaccine,
                    "type" => "Clinic Schedule",
                    "status" => "Scheduled"
                ];

                $appointmentDates[$nextVisit][] =
                    $patient["baby_name"];

                if (
                    $nextVaccination === null ||
                    $nextVisit < $nextVaccination["next_visit"]
                ) {
                    $nextVaccination = [
                        "baby_name" => $patient["baby_name"],
                        "next_vaccine" => $nextVaccine,
                        "next_visit" => $nextVisit
                    ];
                }
            }

        } elseif ($status === "overdue") {
            // Retain the existing clinic status when no
            // visit date is available, without inventing a date.
            $hasOverdue = true;

        } elseif ($status === "pending") {
            $hasPending = true;

        } elseif ($status === "up to date") {
            $hasUpToDate = true;
        }
    }

    // Add appointment requests not already represented
    // by a clinic-confirmed schedule.
    foreach ($appointmentRequests as $request) {

        $visitDate = (string) $request["next_visit"];
        $key = $request["patient_id"] . "|" . $visitDate;

        if ($request["status"] === "Pending") {
            $hasPending = true;
        }

        if (!isset($scheduledKeys[$key])) {

            $scheduledKeys[$key] = true;

            $upcomingAppointments[] = [
                "patient_id" => (int) $request["patient_id"],
                "baby_name" => $request["baby_name"],
                "next_visit" => $visitDate,
                "next_vaccine" => "",
                "type" => "Appointment Request",
                "status" => $request["status"]
            ];

            $appointmentDates[$visitDate][] =
                $request["baby_name"];
        }
    }

    // Sort all displayed visits by date.
    usort($upcomingAppointments, function ($a, $b) {
        return strcmp(
            (string) $a["next_visit"],
            (string) $b["next_visit"]
        );
    });

    if ($hasOverdue) {
        $vaccinationStatus = "Overdue";
    } elseif ($hasDueToday) {
        $vaccinationStatus = "Due Today";
    } elseif ($hasPending) {
        $vaccinationStatus = "Pending";
    } elseif ($hasScheduled) {
        $vaccinationStatus = "Scheduled";
    } elseif ($hasUpToDate) {
        $vaccinationStatus = "Up to Date";
    } elseif ($patients !== []) {
        $vaccinationStatus = "No upcoming schedule";
    }
}

$vaccinationStatusClass = "green-label";

if ($vaccinationStatus === "Overdue") {
    $vaccinationStatusClass = "pink-label";
} elseif (
    $vaccinationStatus === "Pending" ||
    $vaccinationStatus === "Scheduled"
) {
    $vaccinationStatusClass = "blue-label";
} elseif ($vaccinationStatus === "Due Today") {
    $vaccinationStatusClass = "pink-label";
}

// Calendar month validation.
$requestedMonth = $_GET["month"] ?? date("Y-m");

if (
    !is_string($requestedMonth) ||
    !preg_match('/^(\d{4})-(\d{2})$/', $requestedMonth, $monthParts) ||
    !checkdate((int) $monthParts[2], 1, (int) $monthParts[1])
) {
    $requestedMonth = date("Y-m");
}

$calendarMonth = new DateTimeImmutable($requestedMonth . "-01");
$previousMonth = $calendarMonth->modify("-1 month")->format("Y-m");
$nextMonth = $calendarMonth->modify("+1 month")->format("Y-m");

$daysInMonth = (int) $calendarMonth->format("t");
$firstWeekday = (int) $calendarMonth->format("w");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ImmuCare</title>

    <link rel="stylesheet" href="style.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    >

    <style>
        .dashboard-vaccine-summary {
            margin-top: 18px;
            padding: 18px 20px;
            background: #f1faf7;
            border: 1px solid #d9eee6;
            border-radius: 15px;
            color: #315f68;
        }

        .dashboard-vaccine-summary h3 {
            margin: 0 0 12px;
            font-size: 17px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .dashboard-vaccine-summary h3 i {
            color: #459d86;
        }

        .dashboard-vaccine-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .dashboard-vaccine-details p {
            margin: 0;
            color: #708c90;
            font-size: 12px;
        }

        .dashboard-vaccine-details strong {
            display: block;
            margin-top: 5px;
            color: #315f68;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .dashboard-vaccine-note {
            margin-top: 12px;
            color: #708c90;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 600px) {
            .dashboard-vaccine-details {
                grid-template-columns: 1fr;
            }

            .dashboard-vaccine-summary {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="app">

    <!-- HEADER -->
    <section class="hero">

        <div class="hero-left">

            <div class="brand">
                <img
                    src="images/logo.png"
                    alt="ImmuCare Logo"
                    class="header-logo"
                >

                <p class="ledger-text">
                    Infant Immunization Ledger
                </p>
            </div>

            <div class="welcome">
                <h2>
                    Welcome,<br>
                    <?= dashboardEscape(
                        $guardian !== "" ? $guardian : "there"
                    ) ?>!
                </h2>

                <p>Healthy babies, brighter tomorrows.</p>
            </div>

        </div>

        <div class="hero-right">

            <a
                class="profile profile-link"
                href="pages/profile.php"
            >

                <?php if ($avatarFilename !== ""): ?>

                    <img
                        class="avatar"
                        src="uploads/avatars/<?= dashboardEscape($avatarFilename) ?>"
                        alt="Profile avatar"
                    >

                <?php else: ?>

                    <div class="avatar" aria-hidden="true">
                        👩🏻
                    </div>

                <?php endif; ?>

                <div class="profile-text">
                    Profile
                    <i class="fa-solid fa-chevron-right"></i>
                </div>

            </a>

            <div class="theme-switch" aria-label="Theme">

                <button
                    class="sun"
                    type="button"
                    data-theme-choice="light"
                    aria-label="Light theme"
                >
                    <i class="fa-solid fa-sun"></i>
                </button>

                <button
                    class="moon"
                    type="button"
                    data-theme-choice="dark"
                    aria-label="Dark theme"
                >
                    <i class="fa-solid fa-moon"></i>
                </button>

            </div>

        </div>

    </section>

    <?php if ($guardian === ""): ?>

        <p class="dashboard-message" role="status">
            Sign in with the username and password
            provided by your clinic to view your records.
            <a href="user_login/">Sign in</a>
        </p>

    <?php elseif ($patients === []): ?>

        <p class="dashboard-message" role="status">
            No patient records are linked to
            <?= dashboardEscape($guardian) ?> yet.
        </p>

    <?php endif; ?>

    <!-- CALENDAR -->
    <section class="calendar-section">

        <div class="calendar-header">

            <div class="calendar-title">
                <i class="fa-solid fa-calendar-days"></i>

                <h2>
                    <?= dashboardEscape(
                        $calendarMonth->format("F Y")
                    ) ?>
                </h2>
            </div>

            <div class="calendar-buttons">

                <a
                    href="?month=<?= dashboardEscape($previousMonth) ?>"
                    aria-label="Previous month"
                >
                    <i class="fa-solid fa-chevron-left"></i>
                </a>

                <a
                    href="?month=<?= dashboardEscape($nextMonth) ?>"
                    aria-label="Next month"
                >
                    <i class="fa-solid fa-chevron-right"></i>
                </a>

            </div>

        </div>

        <!-- WEEKDAYS -->
        <div class="weekdays">
            <div class="pink">Sun</div>
            <div class="blue">Mon</div>
            <div class="green">Tue</div>
            <div class="pink">Wed</div>
            <div class="blue">Thu</div>
            <div class="green">Fri</div>
            <div class="pink">Sat</div>
        </div>

        <!-- CALENDAR DAYS -->
        <div class="calendar-grid">

            <?php for ($blank = 0; $blank < $firstWeekday; $blank++): ?>
                <div></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>

                <?php
                $calendarDate =
                    $calendarMonth->format("Y-m-") .
                    str_pad((string) $day, 2, "0", STR_PAD_LEFT);

                $dayClasses = [];

                if ($calendarDate === $today) {
                    $dayClasses[] = "active-day";
                }

                if (isset($appointmentDates[$calendarDate])) {
                    $dayClasses[] = "appointment-day";
                }

                $dayTitle = isset($appointmentDates[$calendarDate])
                    ? "Scheduled visit: " .
                        implode(", ", array_unique(
                            $appointmentDates[$calendarDate]
                        ))
                    : "";
                ?>

                <div
                    class="<?= dashboardEscape(
                        implode(" ", $dayClasses)
                    ) ?>"
                    <?php if ($dayTitle !== ""): ?>
                        title="<?= dashboardEscape($dayTitle) ?>"
                    <?php endif; ?>
                >
                    <?= $day ?>
                </div>

            <?php endfor; ?>

            <?php
            $calendarCellCount = $firstWeekday + $daysInMonth;
            $trailingBlanks =
                (7 - ($calendarCellCount % 7)) % 7;
            ?>

            <?php for ($blank = 0; $blank < $trailingBlanks; $blank++): ?>
                <div></div>
            <?php endfor; ?>

        </div>

        <!-- CLINIC-CONFIRMED NEXT VACCINATION -->
        <?php if ($guardian !== "" && $patients !== []): ?>

            <div class="dashboard-vaccine-summary">

                <h3>
                    <i class="fa-solid fa-syringe"></i>
                    Next Vaccination
                </h3>

                <?php if ($nextVaccination !== null): ?>

                    <div class="dashboard-vaccine-details">

                        <div>
                            <p>Child</p>
                            <strong>
                                <?= dashboardEscape(
                                    $nextVaccination["baby_name"]
                                ) ?>
                            </strong>
                        </div>

                        <div>
                            <p>Next Vaccine</p>
                            <strong>
                                <?= dashboardEscape(
                                    $nextVaccination["next_vaccine"]
                                    ?: "To be confirmed by clinic"
                                ) ?>
                            </strong>
                        </div>

                        <div>
                            <p>Scheduled Visit</p>
                            <strong>
                                <?= dashboardEscape(
                                    dashboardDate(
                                        $nextVaccination["next_visit"]
                                    )
                                ) ?>
                            </strong>
                        </div>

                        <div>
                            <p>Schedule Status</p>
                            <strong>
                                <?= $nextVaccination["next_visit"] === $today
                                    ? "Due Today"
                                    : "Upcoming" ?>
                            </strong>
                        </div>

                    </div>

                <?php else: ?>

                    <p>
                        No upcoming clinic-confirmed vaccination
                        schedule is available.
                    </p>

                <?php endif; ?>

                <?php if ($latestVaccination !== null): ?>

                    <p class="dashboard-vaccine-note">
                        Latest recorded vaccination:
                        <strong>
                            <?= dashboardEscape(
                                $latestVaccination["last_vaccine"]
                                ?: "Not specified"
                            ) ?>
                        </strong>
                        for
                        <?= dashboardEscape(
                            $latestVaccination["baby_name"]
                        ) ?>
                        on
                        <?= dashboardEscape(
                            dashboardDate(
                                $latestVaccination["last_vaccine_date"]
                            )
                        ) ?>.
                    </p>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <!-- STATUS CARDS -->
        <div class="status-grid">

            <!-- VACCINATION STATUS -->
            <a
                class="status-card green-card"
                href="pages/records.php"
            >

                <div class="card-icon green-icon">
                    <i class="fa-solid fa-syringe"></i>
                </div>

                <div class="card-text">
                    <h3>Vaccination<br>Status</h3>

                    <span class="status-label <?= dashboardEscape(
                        $vaccinationStatusClass
                    ) ?>">
                        <?= dashboardEscape($vaccinationStatus) ?>
                    </span>
                </div>

                <i class="fa-solid fa-chevron-right arrow"></i>

            </a>

            <!-- REMINDERS -->
            <a
                class="status-card pink-card"
                href="pages/notifications.php"
            >

                <div class="card-icon pink-icon">
                    <i class="fa-solid fa-exclamation"></i>
                </div>

                <div class="card-text">
                    <h3>Reminders</h3>

                    <span class="status-label pink-label">
                        <?= $remindersDue ?> Due
                    </span>
                </div>

                <i class="fa-solid fa-chevron-right arrow"></i>

            </a>

            <!-- APPOINTMENTS -->
            <a
                class="status-card blue-card appointment-wide"
                href="pages/appointments.php"
            >

                <div class="card-icon blue-icon">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>

                <div class="card-text">
                    <h3>Appointment</h3>

                    <span class="status-label blue-label">
                        <?=
                        count($upcomingAppointments) > 0
                            ? count($upcomingAppointments) . " Upcoming"
                            : "Request a visit"
                        ?>
                    </span>

                    <?php if ($upcomingAppointments !== []): ?>

                        <?php $appointment = $upcomingAppointments[0]; ?>

                        <small>
                            <?= dashboardEscape(
                                $appointment["baby_name"]
                            ) ?>

                            ·

                            <?= dashboardEscape(
                                dashboardDate(
                                    $appointment["next_visit"]
                                )
                            ) ?>
                        </small>

                    <?php endif; ?>
                </div>

                <i class="fa-solid fa-chevron-right arrow"></i>

            </a>

        </div>

    </section>

    <!-- BOTTOM NAVIGATION -->
    <nav class="bottom-nav">

        <a href="pages/notifications.php">
            <i class="fa-solid fa-heart pink-nav"></i>
            <span>Notifications</span>
        </a>

        <a href="pages/records.php">
            <i class="fa-solid fa-clipboard-list pink-nav"></i>
            <span>Vaccination<br>Records</span>
        </a>

        <a href="index.php" class="active-nav">
            <div class="active-nav-icon">
                <i class="fa-solid fa-house"></i>
            </div>
            <span>Home</span>
        </a>

        <a href="pages/appointments.php">
            <i class="fa-solid fa-circle-plus blue-nav"></i>
            <span>Appointment</span>
        </a>

        <a href="pages/settings.php">
            <i class="fa-solid fa-grip purple-nav"></i>
            <span>Settings</span>
        </a>

    </nav>

</div>

<script src="app.js"></script>

</body>
</html>
