
<?php

require_once __DIR__ . "/../backend/common.php";

// Default clinic information.
$clinic = [
    "clinic_name" => "Health Rural Unit",
    "address" => "",
    "contact_number" => "",
    "email" => "",
    "operating_hours" => ""
];

// Load clinic information saved by the administrator.
try {

    $stmt = $mysqli->prepare("
        SELECT
            clinic_name,
            address,
            contact_number,
            email,
            operating_hours
        FROM clinic_settings
        WHERE id = 1
        LIMIT 1
    ");

    $stmt->execute();

    $savedClinic = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($savedClinic) {

        foreach ($clinic as $key => $default) {
            $clinic[$key] = (string) (
                $savedClinic[$key] ?? $default
            );
        }

    }

} catch (Throwable $e) {

    error_log(
        "Help Center clinic information error: "
        . $e->getMessage()
    );

}

// Safe HTML output.
function helpEscape($value) {

    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );

}

$pageTitle = "Help Center";
$activePage = "settings";

require __DIR__ . "/../backend/page_header.php";

?>

<section class="content-card">

    <p class="page-intro">
        Quick answers about using your ImmuCare guardian portal.
    </p>

    <!-- FREQUENTLY ASKED QUESTIONS -->

    <div class="help-list">

        <article class="help-item">

            <h2>
                <i class="fa-solid fa-right-to-bracket"></i>
                How do I sign in?
            </h2>

            <p>
                Use the username and password provided by your clinic.
                If you do not have login credentials or they do not work,
                contact the clinic.
            </p>

        </article>

        <article class="help-item">

            <h2>
                <i class="fa-solid fa-syringe"></i>
                Where can I see vaccination records?
            </h2>

            <p>
                Open Vaccination Records from the bottom navigation
                to see records for children linked to your guardian profile.
            </p>

        </article>

        <article class="help-item">

            <h2>
                <i class="fa-solid fa-calendar-days"></i>
                How do I request or check an appointment?
            </h2>

            <p>
                Open Appointment to request a visit.
                The clinic reviews requests, and its approval
                or rejection appears in Notifications.
            </p>

        </article>

        <article class="help-item">

            <h2>
                <i class="fa-solid fa-bell"></i>
                Where are clinic updates?
            </h2>

            <p>
                Notifications includes vaccination reminders,
                appointment updates, and announcements
                published by the clinic.
            </p>

        </article>

    </div>

    <!-- CLINIC CONTACT INFORMATION -->

    <div class="help-list" style="margin-top: 25px;">

        <article class="help-item">

            <h2>
                <i class="fa-solid fa-hospital"></i>
                Clinic Information
            </h2>

            <p>
                Contact your healthcare facility for assistance
                with vaccination schedules, appointments,
                and guardian account concerns.
            </p>

            <div style="margin-top: 18px;">

                <p>
                    <strong>Clinic Name:</strong>
                    <?= helpEscape($clinic["clinic_name"]) ?>
                </p>

                <?php if ($clinic["address"] !== ""): ?>

                    <p>
                        <strong>Address:</strong>
                        <?= nl2br(helpEscape($clinic["address"])) ?>
                    </p>

                <?php endif; ?>

                <?php if ($clinic["contact_number"] !== ""): ?>

                    <p>
                        <strong>Contact Number:</strong>

                        <a href="tel:<?= helpEscape(
                            preg_replace(
                                '/[^0-9+]/',
                                '',
                                $clinic["contact_number"]
                            )
                        ) ?>">
                            <?= helpEscape($clinic["contact_number"]) ?>
                        </a>

                    </p>

                <?php endif; ?>

                <?php if ($clinic["email"] !== ""): ?>

                    <p>
                        <strong>Email Address:</strong>

                        <a href="mailto:<?= helpEscape($clinic["email"]) ?>">
                            <?= helpEscape($clinic["email"]) ?>
                        </a>

                    </p>

                <?php endif; ?>

                <?php if ($clinic["operating_hours"] !== ""): ?>

                    <p>
                        <strong>Operating Hours:</strong>
                        <?= helpEscape($clinic["operating_hours"]) ?>
                    </p>

                <?php endif; ?>

            </div>

        </article>

    </div>

    <!-- FEEDBACK LINK -->

    <a
        class="text-link"
        href="send-feedback.php"
    >
        Still need help? Send feedback
        <i class="fa-solid fa-arrow-right"></i>
    </a>

</section>

<?php

require __DIR__ . "/../backend/page_footer.php";

?>
