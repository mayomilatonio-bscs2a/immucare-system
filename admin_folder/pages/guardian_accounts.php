
<?php

require_once __DIR__ . "/../auth.php";
requireStaffLogin();

require_once __DIR__ . "/../connect.php";

// CSRF protection.
if (empty($_SESSION["guardian_accounts_csrf"])) {
    $_SESSION["guardian_accounts_csrf"] =
        bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$error = "";
$success = "";
$selectedGuardian = "";
$username = "";
$guardians = [];

// Handle form submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";
    $guardianInput = $_POST["guardian"] ?? "";
    $usernameInput = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION["guardian_accounts_csrf"],
            $token
        )
    ) {

        $error = "Invalid form request. Refresh and try again.";

    } elseif (
        !is_string($guardianInput) ||
        !is_string($usernameInput) ||
        !is_string($password)
    ) {

        $error = "Invalid form values.";

    } else {

        $selectedGuardian = trim($guardianInput);
        $username = trim($usernameInput);

        if (
            $selectedGuardian === "" ||
            strlen($username) < 3 ||
            strlen($username) > 100 ||
            strlen($password) < 8 ||
            strlen($password) > 72
        ) {

            $error = "Please complete the form. Username must have 3–100 characters and password 8–72 characters.";

        } else {

            try {

                // Verify the guardian has a registered patient.
                $check = $mysqli->prepare("
                    SELECT 1
                    FROM patients
                    WHERE guardian = ?
                    LIMIT 1
                ");

                $check->bind_param(
                    "s",
                    $selectedGuardian
                );

                $check->execute();

                $exists = (
                    $check->get_result()->num_rows > 0
                );

                $check->close();

                if (!$exists) {

                    $error = "This guardian has no registered patient.";

                } else {

                    $hash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    // Create or reset the guardian account.
                    $stmt = $mysqli->prepare("
                        INSERT INTO guardian_accounts (
                            guardian,
                            username,
                            password_hash
                        )
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            username = VALUES(username),
                            password_hash = VALUES(password_hash)
                    ");

                    $stmt->bind_param(
                        "sss",
                        $selectedGuardian,
                        $username,
                        $hash
                    );

                    $stmt->execute();
                    $stmt->close();

                    $success =
                        "Guardian account saved successfully.";

                    $username = "";
                    $selectedGuardian = "";

                    // Rotate CSRF token after success.
                    $_SESSION["guardian_accounts_csrf"] =
                        bin2hex(random_bytes(32));
                }

            } catch (mysqli_sql_exception $exception) {

                if (
                    (int) $exception->getCode() === 1062
                ) {

                    $error =
                        "Username already exists. Choose another.";

                } else {

                    error_log(
                        "Guardian account save error: " .
                        $exception->getMessage()
                    );

                    $error =
                        "Unable to save the guardian account.";
                }
            }
        }
    }
}

/*
 * Load guardians and their existing usernames.
 *
 * Both guardian columns use utf8mb4_unicode_ci
 * to prevent an illegal mix of collations.
 */

try {

    $result = $mysqli->query("
        SELECT
            p.guardian,
            ga.username
        FROM (
            SELECT DISTINCT guardian
            FROM patients
            WHERE guardian IS NOT NULL
              AND guardian <> ''
        ) AS p
        LEFT JOIN guardian_accounts AS ga
            ON ga.guardian COLLATE utf8mb4_unicode_ci
             = p.guardian COLLATE utf8mb4_unicode_ci
        ORDER BY p.guardian ASC
    ");

    $guardians = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

} catch (mysqli_sql_exception $exception) {

    error_log(
        "Guardian accounts load error: " .
        $exception->getMessage()
    );

    $error = "Unable to load guardian accounts.";
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

    <title>Guardian Accounts | ImmuCare</title>

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

        .account-page {
            padding: 25px;
        }

        /* Consistent upper-left Back button */
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

        .account-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            padding: 30px;
            box-sizing: border-box;
            background: white;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .account-container h1 {
            color: #315f68;
            margin-bottom: 10px;
        }

        .account-container p {
            color: #677f82;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .account-container label {
            display: block;
            margin: 15px 0 7px;
            color: #416f72;
            font-weight: bold;
        }

        .account-container input,
        .account-container select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dcebea;
            border-radius: 9px;
            box-sizing: border-box;
            font: inherit;
        }

        .account-container input:focus,
        .account-container select:focus {
            outline: none;
            border-color: #58af98;
        }

        .account-container button {
            margin-top: 20px;
            padding: 13px 25px;
            background: #58af98;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: bold;
        }

        .account-container button:hover {
            background: #438e77;
        }

        .account-container button:disabled {
            background: #a8c9c0;
            cursor: not-allowed;
        }

        .notice {
            padding: 12px;
            border-radius: 9px;
            margin: 15px 0;
            line-height: 1.5;
        }

        .error {
            background: #fff0f0;
            color: #a33;
        }

        .success {
            background: #e8f8f3;
            color: #315f68;
        }

        .form-hint {
            display: block;
            color: #82999c;
            font-size: 12px;
            margin-top: 6px;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .account-page {
                padding: 15px;
            }

            .account-container {
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

    <!-- Admin Sidebar -->
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
            <a href="guardian_accounts.php" class="active">Guardian Accounts</a>
            <a href="management.php">Management</a>
            <a href="monitoring.php">Monitoring</a>
            <a href="announcements.php">Announcements</a>
            <a href="reports.php">Reports</a>
            <a href="settings.php">Settings</a>
        </nav>

    </aside>

    <main class="main">

        <!-- Top Navigation Bar -->
        <header class="topbar">
            <div class="welcome-admin">
                Guardian Accounts
            </div>
        </header>

        <section class="account-page">

            <!-- Upper-left Back Button -->
            <a href="records.php" class="page-back">
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
                Back to Patient Records
            </a>

            <div class="account-container">

                <h1>Guardian Accounts</h1>

                <p>
                    Create or reset login credentials for guardians
                    who have registered patients in ImmuCare.
                </p>

                <?php if ($error !== ""): ?>

                    <div class="notice error" role="alert">
                        <?= e($error) ?>
                    </div>

                <?php endif; ?>

                <?php if ($success !== ""): ?>

                    <div class="notice success" role="status">
                        <?= e($success) ?>
                    </div>

                <?php endif; ?>

                <?php if (empty($guardians)): ?>

                    <div class="notice error">
                        No guardians are available.
                        Please check the registered patient records.
                    </div>

                <?php else: ?>

                    <form method="POST">

                        <!-- CSRF TOKEN -->
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(
                                $_SESSION["guardian_accounts_csrf"]
                            ) ?>"
                        >

                        <!-- SELECT GUARDIAN -->
                        <label for="guardian">
                            Select Guardian
                        </label>

                        <select
                            id="guardian"
                            name="guardian"
                            required
                        >

                            <option value="">
                                Select a guardian
                            </option>

                            <?php foreach ($guardians as $record): ?>

                                <option
                                    value="<?= e(
                                        $record["guardian"]
                                    ) ?>"
                                    <?= $selectedGuardian ===
                                        $record["guardian"]
                                        ? "selected"
                                        : "" ?>
                                >
                                    <?= e(
                                        $record["guardian"]
                                    ) ?>

                                    <?= !empty($record["username"])
                                        ? " (Account exists)"
                                        : " (No account)" ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <span class="form-hint">
                            Only guardians with registered patients
                            can be assigned login credentials.
                        </span>

                        <!-- USERNAME -->
                        <label for="username">
                            Username
                        </label>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            minlength="3"
                            maxlength="100"
                            value="<?= e($username) ?>"
                            autocomplete="off"
                            required
                        >

                        <span class="form-hint">
                            Username must contain 3 to 100 characters.
                        </span>

                        <!-- PASSWORD -->
                        <label for="password">
                            Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="8"
                            maxlength="72"
                            autocomplete="new-password"
                            required
                        >

                        <span class="form-hint">
                            Password must contain 8 to 72 characters.
                            Saving an existing guardian account
                            will replace its current password.
                        </span>

                        <!-- SUBMIT BUTTON -->
                        <button type="submit">
                            Save Guardian Account
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>
