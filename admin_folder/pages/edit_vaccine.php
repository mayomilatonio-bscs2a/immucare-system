
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

// Validate vaccine ID.
$vaccineId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$vaccineId) {
    http_response_code(400);
    exit("Invalid vaccine ID.");
}

// Load selected vaccine.
$stmt = $mysqli->prepare("
    SELECT
        id,
        vaccine_name,
        dose,
        recommended_age,
        description,
        status
    FROM vaccines
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $vaccineId);
$stmt->execute();

$vaccine = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vaccine) {
    http_response_code(404);
    exit("Vaccine not found.");
}

// CSRF protection.
if (empty($_SESSION["edit_vaccine_csrf"])) {
    $_SESSION["edit_vaccine_csrf"] =
        bin2hex(random_bytes(32));
}

$error = "";

$name = $vaccine["vaccine_name"];
$dose = $vaccine["dose"];
$age = $vaccine["recommended_age"];
$description = $vaccine["description"] ?? "";
$status = $vaccine["status"] ?? "Active";

// Handle form submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    $name = $_POST["vaccine_name"] ?? "";
    $dose = $_POST["dose"] ?? "";
    $age = $_POST["recommended_age"] ?? "";
    $description = $_POST["description"] ?? "";
    $status = $_POST["status"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["edit_vaccine_csrf"], $token)
    ) {

        $error = "Invalid request. Refresh the page.";

    } elseif (
        !is_string($name) ||
        !is_string($dose) ||
        !is_string($age) ||
        !is_string($description) ||
        !is_string($status)
    ) {

        $error = "Invalid form values.";

    } else {

        $name = trim($name);
        $dose = trim($dose);
        $age = trim($age);
        $description = trim($description);
        $status = trim($status);

        if (
            $name === "" ||
            $dose === "" ||
            $age === ""
        ) {

            $error = "Complete all required fields.";

        } elseif (
            mb_strlen($name) > 100 ||
            mb_strlen($dose) > 50 ||
            mb_strlen($age) > 100 ||
            mb_strlen($description) > 255
        ) {

            $error = "One or more fields are too long.";

        } elseif (
            !in_array(
                $status,
                ["Active", "Inactive"],
                true
            )
        ) {

            $error = "Invalid vaccine status.";

        } else {

            try {

                $stmt = $mysqli->prepare("
                    UPDATE vaccines
                    SET
                        vaccine_name = ?,
                        dose = ?,
                        recommended_age = ?,
                        description = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "sssssi",
                    $name,
                    $dose,
                    $age,
                    $description,
                    $status,
                    $vaccineId
                );

                $stmt->execute();
                $stmt->close();

                $_SESSION["vaccine_flash"] =
                    "Vaccine updated successfully.";

                // Rotate token after successful update.
                $_SESSION["edit_vaccine_csrf"] =
                    bin2hex(random_bytes(32));

                header("Location: vaccines.php");
                exit;

            } catch (mysqli_sql_exception $e) {

                error_log(
                    "Edit vaccine error: " .
                    $e->getMessage()
                );

                $error = "Unable to update vaccine.";
            }
        }
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

    <title>Edit Vaccine | ImmuCare</title>

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

        /* Page layout */
        .form-page {
            padding: 25px;
        }

        /* Standardized upper-left Back button */
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

        /* Form container */
        .form-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            padding: 30px;
            box-sizing: border-box;
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,.05);
        }

        .form-container h1 {
            color: #315f68;
            font-size: 25px;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #789297;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #315f68;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d6e4e1;
            border-radius: 9px;
            box-sizing: border-box;
            font: inherit;
            color: #315f68;
            background: white;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #61bca4;
        }

        /* Form actions */
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .save-btn {
            background: #61bca4;
            color: white;
            padding: 12px 22px;
            border: none;
            border-radius: 9px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #459d86;
        }

        .cancel-btn {
            display: inline-block;
            background: #edf2f1;
            color: #315f68;
            padding: 12px 22px;
            border-radius: 9px;
            text-decoration: none;
            font-weight: 600;
        }

        .cancel-btn:hover {
            background: #dce8e5;
        }

        .error {
            background: #ffe7ec;
            color: #a63f56;
            padding: 13px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .notice {
            background: #e8f8f3;
            color: #315f68;
            padding: 13px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .form-page {
                padding: 15px;
            }

            .form-container {
                padding: 20px;
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
                alt="ImmuCare"
                width="150"
            >
        </div>

        <nav>
            <a href="../index.php">Dashboard</a>
            <a href="records.php">Records</a>
            <a href="guardian_accounts.php">Guardian Accounts</a>

            <a href="management.php" class="active">
                Management
            </a>

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
                Vaccination Management
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <section class="form-page">

            <!-- Upper-left Back button -->
            <a
                href="vaccines.php"
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
                Back to Vaccines
            </a>

            <div class="form-container">

                <h1>Edit Vaccine</h1>

                <p class="subtitle">
                    Update vaccine information and availability.
                </p>

                <div class="notice">
                    Setting a vaccine to Inactive keeps it
                    in the database. Existing patient
                    vaccination records are not deleted.
                </div>

                <?php if ($error !== ""): ?>
                    <div class="error" role="alert">
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">

                    <!-- CSRF TOKEN -->
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION["edit_vaccine_csrf"]) ?>"
                    >

                    <!-- VACCINE NAME -->
                    <div class="form-group">
                        <label for="vaccine_name">
                            Vaccine Name *
                        </label>

                        <input
                            type="text"
                            id="vaccine_name"
                            name="vaccine_name"
                            maxlength="100"
                            value="<?= h($name) ?>"
                            required
                        >
                    </div>

                    <!-- DOSE -->
                    <div class="form-group">
                        <label for="dose">
                            Dose *
                        </label>

                        <input
                            type="text"
                            id="dose"
                            name="dose"
                            maxlength="50"
                            value="<?= h($dose) ?>"
                            required
                        >
                    </div>

                    <!-- RECOMMENDED AGE -->
                    <div class="form-group">
                        <label for="recommended_age">
                            Recommended Age *
                        </label>

                        <input
                            type="text"
                            id="recommended_age"
                            name="recommended_age"
                            maxlength="100"
                            value="<?= h($age) ?>"
                            required
                        >
                    </div>

                    <!-- DESCRIPTION -->
                    <div class="form-group">
                        <label for="description">
                            Description (Optional)
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            maxlength="255"
                        ><?= h($description) ?></textarea>
                    </div>

                    <!-- STATUS -->
                    <div class="form-group">
                        <label for="status">
                            Status *
                        </label>

                        <select
                            id="status"
                            name="status"
                            required
                        >
                            <option
                                value="Active"
                                <?= $status === "Active"
                                    ? "selected"
                                    : "" ?>
                            >
                                Active
                            </option>

                            <option
                                value="Inactive"
                                <?= $status === "Inactive"
                                    ? "selected"
                                    : "" ?>
                            >
                                Inactive
                            </option>
                        </select>
                    </div>

                    <!-- FORM ACTIONS -->
                    <div class="actions">

                        <button
                            type="submit"
                            class="save-btn"
                        >
                            Save Changes
                        </button>

                        <a
                            href="vaccines.php"
                            class="cancel-btn"
                        >
                            Cancel
                        </a>

                    </div>

                </form>

            </div>

        </section>

    </main>

</div>

</body>
</html>
