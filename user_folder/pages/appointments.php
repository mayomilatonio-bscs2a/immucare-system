<?php

require_once __DIR__ . "/../backend/common.php";

$guardian = currentGuardian();
$patients = [];
$requests = [];
if ($guardian !== "") {
    $stmt = $mysqli->prepare("
        SELECT id, baby_name
        FROM patients
        WHERE guardian = ?
        ORDER BY baby_name ASC
    ");
    $stmt->bind_param("s", $guardian);
    $stmt->execute();
    $patients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $mysqli->prepare("
        SELECT ar.id, ar.appointment_date, ar.appointment_time, ar.notes,
               ar.status, ar.admin_note, ar.created_at, p.baby_name
        FROM appointment_requests ar
        INNER JOIN patients p ON p.id = ar.patient_id
        WHERE ar.guardian = ? AND p.guardian = ?
        ORDER BY ar.appointment_date DESC, ar.appointment_time DESC, ar.id DESC
    ");
    $stmt->bind_param("ss", $guardian, $guardian);
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = "Appointments";
$activePage = "appointments";
require __DIR__ . "/../backend/page_header.php";

?>
<section class="content-card">
    <h2>Request an appointment</h2>
    <p class="page-intro">Requests are reviewed by clinic staff. Your appointment is not confirmed until approved.</p>
    <?php if ($patients !== []): ?>
        <form class="form-card" action="../backend/create_appointment.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(ensureCsrfToken()) ?>">
            <label>
                Child
                <select name="patient_id" required>
                    <option value="">Choose a child</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?= (int) $patient["id"] ?>"><?= escapeHtml((string) $patient["baby_name"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form-row">
                <label>
                    Requested date
                    <input type="date" name="appointment_date" min="<?= date("Y-m-d") ?>" required>
                </label>
                <label>
                    Preferred time
                    <input type="time" name="appointment_time" required>
                </label>
            </div>
            <label>
                Note for the clinic <span class="muted">(optional)</span>
                <textarea name="notes" maxlength="500" rows="3" placeholder="Add a short note about your visit"></textarea>
            </label>
            <button class="primary-button" type="submit">Send request</button>
        </form>
    <?php elseif ($guardian !== ""): ?>
        <div class="empty-state"><p>There are no children linked to your account to request an appointment for.</p></div>
    <?php endif; ?>
</section>

<section class="content-card">
    <h2>Your appointment requests</h2>
    <?php if ($requests === [] && $guardian !== ""): ?>
        <div class="empty-state"><p>You have not submitted any requests yet.</p></div>
    <?php endif; ?>
    <div class="request-list">
        <?php foreach ($requests as $request): ?>
            <article class="request-card">
                <div class="record-heading">
                    <div>
                        <h3><?= escapeHtml((string) $request["baby_name"]) ?></h3>
                        <p><?= date("F j, Y", strtotime((string) $request["appointment_date"])) ?> · <?= date("g:i A", strtotime((string) $request["appointment_time"])) ?></p>
                    </div>
                    <span class="pill pill-<?= strtolower((string) $request["status"]) ?>"><?= escapeHtml((string) $request["status"]) ?></span>
                </div>
                <?php if ($request["notes"] !== ""): ?>
                    <p class="request-note"><?= escapeHtml((string) $request["notes"]) ?></p>
                <?php endif; ?>
                <?php if ($request["admin_note"] !== ""): ?>
                    <p class="clinic-note"><strong>Clinic note:</strong> <?= escapeHtml((string) $request["admin_note"]) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . "/../backend/page_footer.php"; ?>
