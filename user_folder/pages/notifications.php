
<?php

require_once __DIR__ . "/../backend/common.php";

$guardian = currentGuardian();
$notices = [];

function notificationDate($value) {
    if (
        empty($value) ||
        $value === "0000-00-00"
    ) {
        return "Date not available";
    }

    $timestamp = strtotime((string) $value);

    return $timestamp
        ? date("F j, Y", $timestamp)
        : "Date not available";
}

function notificationTime($value) {
    if (empty($value)) {
        return "Time not set";
    }

    $timestamp = strtotime((string) $value);

    return $timestamp
        ? date("g:i A", $timestamp)
        : "Time not set";
}

$today = date("Y-m-d");

if ($guardian !== "") {

    /*
     * 1. CLINIC ANNOUNCEMENTS
     */

    $announcementResult = $mysqli->query("
        SELECT
            title,
            message,
            date_posted
        FROM announcements
        WHERE status = 'Active'
        ORDER BY date_posted DESC, id DESC
        LIMIT 20
    ");

    foreach (
        $announcementResult->fetch_all(MYSQLI_ASSOC)
        as $announcement
    ) {

        $date = !empty($announcement["date_posted"])
            ? notificationDate($announcement["date_posted"])
            : "";

        $notices[] = [
            "title" => "Clinic announcement: " .
                (string) $announcement["title"],

            "text" => (string) $announcement["message"] .
                ($date !== "" ? " · " . $date : ""),

            "class" => "notice-info"
        ];
    }

    $announcementResult->free();

    /*
     * 2. VACCINATION SCHEDULE REMINDERS
     *
     * Schedules come from the patients table.
     * Dates are clinic-confirmed and are not
     * automatically calculated.
     */


$stmt = $mysqli->prepare("
    SELECT
        p.id,
        p.baby_name,
        p.next_vaccine,
        p.next_visit,
        p.status,
        (
            SELECT MAX(vr.date_given)
            FROM vaccination_records vr
            WHERE vr.patient_id = p.id
              AND vr.deleted_at IS NULL
        ) AS latest_date_given
    FROM patients p
    WHERE p.guardian = ?
      AND (
          p.next_visit IS NOT NULL
          OR p.status = 'Overdue'
      )
    ORDER BY p.next_visit ASC
");

$stmt->bind_param("s", $guardian);
$stmt->execute();

$patientNotices = $stmt->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

    foreach ($patientNotices as $notice) {

        $visitDate = (string) (
            $notice["next_visit"] ?? ""
        );

        if ($visitDate === "0000-00-00") {
            $visitDate = "";
        }

        $patientStatus = strtolower(trim(
            (string) ($notice["status"] ?? "")
        ));

        $babyName = (string) $notice["baby_name"];

        $vaccineName = trim(
            (string) ($notice["next_vaccine"] ?? "")
        );

        $latestGiven = (string) (
            $notice["latest_date_given"] ?? ""
        );

        $needsReview = (
            $visitDate !== "" &&
            $latestGiven !== "" &&
            $latestGiven >= $visitDate
        );

        $vaccineText = $vaccineName !== ""
            ? " · " . $vaccineName
            : " · Vaccine to be confirmed";

        $dateText = $visitDate !== ""
            ? " · " . notificationDate($visitDate)
            : "";

        if ($needsReview) {

            $notices[] = [
                "title" => "Vaccination schedule needs confirmation",

                "text" => $babyName .
                    $vaccineText .
                    $dateText .
                    " · A vaccination was recorded on or after this scheduled date. Please confirm the next visit with the clinic.",

                "class" => "notice-info"
            ];

        } elseif (
            $visitDate !== "" &&
            $visitDate < $today
        ) {

            $notices[] = [
                "title" => "Vaccination schedule overdue",

                "text" => $babyName .
                    $vaccineText .
                    $dateText .
                    " · Please contact the clinic to confirm the vaccination schedule.",

                "class" => "notice-error"
            ];

        } elseif (
            $visitDate !== "" &&
            $visitDate === $today
        ) {

            $notices[] = [
                "title" => "Vaccination due today",

                "text" => $babyName .
                    $vaccineText .
                    $dateText .
                    " · Your child has a scheduled clinic visit today.",

                "class" => "notice-error"
            ];

        } elseif (
            $visitDate !== "" &&
            $visitDate > $today
        ) {

            $notices[] = [
                "title" => "Upcoming vaccination schedule",

                "text" => $babyName .
                    $vaccineText .
                    $dateText .
                    " · Please follow the clinic-confirmed schedule.",

                "class" => "notice-info"
            ];

        } elseif ($patientStatus === "overdue") {

            $notices[] = [
                "title" => "Vaccination status requires review",

                "text" => $babyName .
                    " · The clinic has marked this child's vaccination status as overdue. Please confirm the schedule.",

                "class" => "notice-error"
            ];
        }
    }

    /*
     * 3. RECENT ADMINISTERED VACCINATIONS
     *
     * These are retrieved from vaccination_records,
     * not inferred from appointment status.
     */

    $stmt = $mysqli->prepare("
        SELECT
            vr.id,
            vr.vaccine,
            vr.date_given,
            vr.remarks,
            p.baby_name
        FROM vaccination_records vr
INNER JOIN patients p
    ON p.id = vr.patient_id
WHERE p.guardian = ?
  AND vr.deleted_at IS NULL
ORDER BY vr.date_given DESC, vr.id DESC
        LIMIT 20
    ");

    $stmt->bind_param("s", $guardian);
    $stmt->execute();

    $vaccinationNotices = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    foreach ($vaccinationNotices as $notice) {

        $babyName = (string) $notice["baby_name"];
        $vaccineName = (string) $notice["vaccine"];

        $dateGiven = notificationDate(
            $notice["date_given"]
        );

        $remarks = trim(
            (string) ($notice["remarks"] ?? "")
        );

        $notices[] = [
            "title" => "Vaccination recorded",

            "text" => $babyName .
                " · " . $vaccineName .
                " · Administered on " . $dateGiven .
                ($remarks !== ""
                    ? " · Remarks: " . $remarks
                    : ""),

            "class" => "notice-info"
        ];
    }

    /*
     * 4. APPOINTMENT UPDATES
     *
     * Use linked appointment information when
     * available, including current date and time.
     */

    $stmt = $mysqli->prepare("
        SELECT
            ar.id,
            ar.status AS request_status,
            ar.appointment_date AS request_date,
            ar.appointment_time AS request_time,
            ar.admin_note,

            a.status AS appointment_status,
            a.appointment_date AS actual_date,
            a.appointment_time AS actual_time,

            p.baby_name

        FROM appointment_requests ar

        INNER JOIN patients p
            ON p.id = ar.patient_id

        LEFT JOIN appointments a
            ON a.id = ar.appointment_id
           AND a.patient_id = ar.patient_id

        WHERE ar.guardian = ?
          AND p.guardian = ?

        ORDER BY ar.updated_at DESC, ar.id DESC
        LIMIT 20
    ");

    $stmt->bind_param("ss", $guardian, $guardian);
    $stmt->execute();

    $requestNotices = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    foreach ($requestNotices as $notice) {

        $requestStatus = strtolower(trim(
            (string) ($notice["request_status"] ?? "")
        ));

        $appointmentStatus = strtolower(trim(
            (string) ($notice["appointment_status"] ?? "")
        ));

        $dateValue = $notice["actual_date"]
            ?? $notice["request_date"];

        $timeValue = $notice["actual_time"]
            ?? $notice["request_time"];

        $appointmentDate = notificationDate($dateValue);
        $appointmentTime = notificationTime($timeValue);

        $adminNote = trim(
            (string) ($notice["admin_note"] ?? "")
        );

        $title = "Appointment request pending";
        $class = "notice";

        if ($appointmentStatus === "completed") {

            $title = "Appointment completed";
            $class = "notice-info";

        } elseif (
            in_array(
                $appointmentStatus,
                ["rejected", "cancelled", "canceled"],
                true
            ) ||
            $requestStatus === "rejected"
        ) {

            $title = "Appointment request rejected";
            $class = "notice-error";

        } elseif (
            in_array(
                $appointmentStatus,
                ["scheduled", "approved", "accepted"],
                true
            ) ||
            $requestStatus === "approved"
        ) {

            $title = "Appointment approved";
            $class = "notice-info";
        }

        $text = (string) $notice["baby_name"] .
            " · " . $appointmentDate .
            " · " . $appointmentTime;

        if ($adminNote !== "") {
            $text .= " · Clinic note: " . $adminNote;
        }

        $notices[] = [
            "title" => $title,
            "text" => $text,
            "class" => $class
        ];
    }

    /*
     * 5. GUARDIAN FEEDBACK UPDATES
     */

    $stmt = $mysqli->prepare("
        SELECT
            category,
            status,
            created_at
        FROM user_feedback
        WHERE guardian = ?
        ORDER BY updated_at DESC
        LIMIT 10
    ");

    $stmt->bind_param("s", $guardian);
    $stmt->execute();

    $feedbackNotices = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    foreach ($feedbackNotices as $notice) {

        $resolved = (
            $notice["status"] === "Resolved"
        );

        $createdDate = notificationDate(
            $notice["created_at"]
        );

        $notices[] = [
            "title" => "Your " .
                strtolower((string) $notice["category"]) .
                " is " .
                strtolower((string) $notice["status"]),

            "text" => "Sent " . $createdDate .
                ($resolved
                    ? " · The clinic has marked it resolved."
                    : " · The clinic team will review it."),

            "class" => $resolved
                ? "notice-info"
                : "notice"
        ];
    }
}

$pageTitle = "Notifications";
$activePage = "notifications";

require __DIR__ . "/../backend/page_header.php";

?>

<section class="content-card">

    <p class="page-intro">
        Vaccination reminders, clinic announcements,
        administered vaccines, feedback updates,
        and appointment information.
    </p>

    <?php if ($guardian === ""): ?>

        <div class="empty-state">
            <i class="fa-solid fa-lock"></i>

            <p>
                Please sign in to view your notifications.
            </p>
        </div>

    <?php elseif ($notices === []): ?>

        <div class="empty-state">
            <i class="fa-solid fa-heart"></i>

            <p>
                You're all caught up.
                There are no notifications to display.
            </p>
        </div>

    <?php endif; ?>

    <div class="notice-list">

        <?php foreach ($notices as $notice): ?>

            <article class="notice <?= escapeHtml(
                $notice["class"]
            ) ?>">

                <h2>
                    <?= escapeHtml(
                        $notice["title"]
                    ) ?>
                </h2>

                <p>
                    <?= nl2br(
                        escapeHtml($notice["text"])
                    ) ?>
                </p>

            </article>

        <?php endforeach; ?>

    </div>

    <a class="text-link" href="records.php">
        View Vaccination Records
        <i class="fa-solid fa-arrow-right"></i>
    </a>

    <a class="text-link" href="help-center.php">
        Visit the Help Center
        <i class="fa-solid fa-arrow-right"></i>
    </a>

    <a class="text-link" href="appointments.php">
        View appointment requests
        <i class="fa-solid fa-arrow-right"></i>
    </a>

</section>

<?php
require __DIR__ . "/../backend/page_footer.php";
?>
