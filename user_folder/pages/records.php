
<?php

require_once __DIR__ . "/../backend/common.php";

// Get logged-in guardian.
$guardian = currentGuardian();

$records = [];
$vaccinationHistory = [];

// Only retrieve information for the logged-in guardian.
if ($guardian !== "") {

    // 1. Retrieve registered children.
    $stmt = $mysqli->prepare("
        SELECT
            id,
            baby_name,
            age,
            birthday,
            guardian,
            relationship,
            phone_number,
            last_vaccine,
            status,
            next_vaccine,
            next_visit
        FROM patients
        WHERE guardian = ?
        ORDER BY baby_name ASC
    ");

    $stmt->bind_param("s", $guardian);
    $stmt->execute();

    $records = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    // 2. Retrieve vaccination history.
    //
    // Join patients to ensure records belong
    // only to the currently logged-in guardian.
    $stmt = $mysqli->prepare("
        SELECT
            vr.id,
            vr.patient_id,
            vr.vaccine,
            vr.date_given,
            vr.remarks
        FROM vaccination_records AS vr
INNER JOIN patients AS p
    ON p.id = vr.patient_id
WHERE p.guardian = ?
  AND vr.deleted_at IS NULL
ORDER BY
    vr.date_given DESC,
    vr.id DESC
    ");

    $stmt->bind_param("s", $guardian);
    $stmt->execute();

    $historyRows = $stmt->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    // 3. Group vaccination records by patient ID.
    foreach ($historyRows as $history) {

        $patientId = (int) $history["patient_id"];

        if (!isset($vaccinationHistory[$patientId])) {
            $vaccinationHistory[$patientId] = [];
        }

        $vaccinationHistory[$patientId][] = $history;
    }
}

// Format dates for display.
function formatRecordDate($date) {

    if (empty($date) || $date === "0000-00-00") {
        return "Not recorded";
    }

    try {
        return (new DateTimeImmutable(
            (string) $date
        ))->format("F j, Y");

    } catch (Exception $e) {
        return (string) $date;
    }
}

$pageTitle = "Vaccination Records";
$activePage = "records";

require __DIR__ . "/../backend/page_header.php";

?>

<section class="content-card">

    <p class="page-intro">
        View your child's immunization information
        and vaccination history as recorded by the clinic.
    </p>

    <?php if ($guardian === ""): ?>

        <div class="empty-state">
            <i class="fa-solid fa-lock"></i>
            <p>
                Please log in to view vaccination records.
            </p>
        </div>

    <?php elseif ($records === []): ?>

        <div class="empty-state">
            <i class="fa-solid fa-clipboard-list"></i>
            <p>
                No children are linked to your account yet.
            </p>
        </div>

    <?php else: ?>

        <?php foreach ($records as $record): ?>

            <?php

            $patientId = (int) $record["id"];

            $childVaccinations =
                $vaccinationHistory[$patientId] ?? [];

            $latestVaccine = !empty($childVaccinations)
    ? ($childVaccinations[0]["vaccine"] ?: "Not recorded")
    : "Not recorded";

            ?>

            <article class="record-card">

                <!-- Patient heading -->
                <div class="record-heading">

                    <div>

                        <h2>
                            <?= escapeHtml(
                                (string) $record["baby_name"]
                            ) ?>
                        </h2>

                        <p>
                            <?= escapeHtml(
                                (string) $record["relationship"]
                            ) ?>

                            ·

                            <?= escapeHtml(
                                (string) $record["guardian"]
                            ) ?>
                        </p>

                    </div>

                    <span class="pill">
                        <?= escapeHtml(
                            (string) (
                                $record["status"] ?: "Not set"
                            )
                        ) ?>
                    </span>

                </div>

                <!-- Patient information -->
                <dl class="detail-grid">

                    <div>
                        <dt>Age</dt>
                        <dd>
                            <?= escapeHtml(
                                (string) $record["age"]
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Birthday</dt>
                        <dd>
                            <?= escapeHtml(
                                formatRecordDate(
                                    $record["birthday"]
                                )
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Phone</dt>
                        <dd>
                            <?= escapeHtml(
                                (string) (
                                    $record["phone_number"] ?: "Not provided"
                                )
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Last Vaccine</dt>
                        <dd>
                            <?= escapeHtml(
                                (string) $latestVaccine
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Next Vaccine</dt>
                        <dd>
                            <?= escapeHtml(
                                (string) (
                                    $record["next_vaccine"]
                                    ?: "Not scheduled"
                                )
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Next Visit</dt>
                        <dd>
                            <?= escapeHtml(
                                !empty($record["next_visit"])
                                    ? formatRecordDate(
                                        $record["next_visit"]
                                    )
                                    : "Not scheduled"
                            ) ?>
                        </dd>
                    </div>

                </dl>

                <!-- Vaccination history -->
                <div class="vaccination-history-section">

                    <h3>
                        <i class="fa-solid fa-syringe"></i>
                        Vaccination History
                    </h3>

                    <?php if (empty($childVaccinations)): ?>

                        <div class="vaccination-empty">
                            <i class="fa-solid fa-clipboard-list"></i>
                            <p>
                                No administered vaccines
                                have been recorded yet.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="vaccination-history-list">

                            <?php foreach (
                                $childVaccinations as $vaccination
                            ): ?>

                                <div class="vaccination-history-item">

                                    <div class="vaccination-icon">
                                        <i class="fa-solid fa-syringe"></i>
                                    </div>

                                    <div class="vaccination-details">

                                        <strong>
                                            <?= escapeHtml(
                                                (string) $vaccination["vaccine"]
                                            ) ?>
                                        </strong>

                                        <p>
                                            <i class="fa-regular fa-calendar"></i>

                                            Administered:
                                            <?= escapeHtml(
                                                formatRecordDate(
                                                    $vaccination["date_given"]
                                                )
                                            ) ?>
                                        </p>

                                        <?php if (
                                            !empty($vaccination["remarks"])
                                        ): ?>

                                            <p class="vaccination-remarks">
                                                <i class="fa-regular fa-note-sticky"></i>

                                                <?= escapeHtml(
                                                    (string) $vaccination["remarks"]
                                                ) ?>
                                            </p>

                                        <?php endif; ?>

                                    </div>

                                    <span class="vaccination-recorded">
                                        Recorded
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </article>

        <?php endforeach; ?>

    <?php endif; ?>

</section>

<style>

.vaccination-history-section {
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #e1eeeb;
}

.vaccination-history-section h3 {
    margin: 0 0 18px;
    color: #315f68;
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.vaccination-history-section h3 i {
    color: #61bca4;
}

.vaccination-history-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.vaccination-history-item {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    background: #f7fbfa;
    border: 1px solid #e1eeeb;
    padding: 16px;
    border-radius: 12px;
}

.vaccination-icon {
    width: 42px;
    height: 42px;
    min-width: 42px;
    background: #e3f5ef;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #459d86;
    font-size: 18px;
}

.vaccination-details {
    flex: 1;
    min-width: 0;
}

.vaccination-details strong {
    display: block;
    color: #315f68;
    font-size: 15px;
    margin-bottom: 6px;
    overflow-wrap: anywhere;
}

.vaccination-details p {
    margin: 5px 0;
    color: #647f80;
    font-size: 13px;
    line-height: 1.5;
}

.vaccination-details p i {
    margin-right: 5px;
}

.vaccination-remarks {
    overflow-wrap: anywhere;
}

.vaccination-recorded {
    background: #e3f5ef;
    color: #32846d;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.vaccination-empty {
    text-align: center;
    padding: 25px 15px;
    background: #f7fbfa;
    border-radius: 12px;
    color: #82999c;
}

.vaccination-empty i {
    font-size: 24px;
    margin-bottom: 10px;
    color: #a8c9c0;
}

.vaccination-empty p {
    margin: 0;
    font-size: 14px;
}

@media (max-width: 600px) {

    .vaccination-history-item {
        flex-wrap: wrap;
        gap: 12px;
    }

    .vaccination-recorded {
        margin-left: 54px;
    }

    .vaccination-history-section h3 {
        font-size: 16px;
    }
}

</style>

<?php require __DIR__ . "/../backend/page_footer.php"; ?>
