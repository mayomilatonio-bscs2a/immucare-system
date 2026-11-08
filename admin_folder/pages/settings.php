
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Settings CSRF protection.
if (empty($_SESSION["settings_csrf"])) {
    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));
}

// Administrator information.
$adminFullName = (string) ($_SESSION["full_name"] ?? "");
$adminUsername = (string) ($_SESSION["username"] ?? "");

// Read latest account information from database.
try {
    $userId = (int) ($_SESSION["user_id"] ?? 0);

    $stmt = $mysqli->prepare("
        SELECT full_name, username
        FROM users
        WHERE id = ?
          AND role = 'Administrator'
          AND status = 'Active'
        LIMIT 1
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($admin) {
        $adminFullName = (string) ($admin["full_name"] ?? "");
        $adminUsername = (string) ($admin["username"] ?? "");

        $_SESSION["full_name"] = $adminFullName;
        $_SESSION["username"] = $adminUsername;
    } else {
        http_response_code(403);
        exit("Administrator account is no longer active.");
    }

} catch (Throwable $e) {
    error_log("Settings profile error: " . $e->getMessage());
}

// Password message.
$settingsMessage = (string) (
    $_SESSION["settings_message"] ?? ""
);

$settingsMessageType = (string) (
    $_SESSION["settings_message_type"] ?? "error"
);

unset(
    $_SESSION["settings_message"],
    $_SESSION["settings_message_type"]
);

// Profile message.
$profileMessage = (string) (
    $_SESSION["profile_message"] ?? ""
);

$profileMessageType = (string) (
    $_SESSION["profile_message_type"] ?? "error"
);

unset(
    $_SESSION["profile_message"],
    $_SESSION["profile_message_type"]
);

// Safe HTML output helper.
function settingsEscape($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$clinicMessage = (string) ($_SESSION["clinic_message"] ?? "");
$clinicMessageType = (string) ($_SESSION["clinic_message_type"] ?? "error");

unset(
    $_SESSION["clinic_message"],
    $_SESSION["clinic_message_type"]
);

$clinic = [
    "clinic_name" => "Health Rural Unit",
    "address" => "",
    "contact_number" => "",
    "email" => "",
    "operating_hours" => ""
];

try {
    $result = $mysqli->query("
        SELECT clinic_name, address, contact_number,
               email, operating_hours
        FROM clinic_settings
        WHERE id = 1
        LIMIT 1
    ");

    $savedClinic = $result->fetch_assoc();

    if ($savedClinic) {
        foreach ($clinic as $key => $default) {
            $clinic[$key] = (string) ($savedClinic[$key] ?? $default);
        }
    }

    $result->free();

} catch (Throwable $e) {
    error_log("Unable to load clinic settings: " . $e->getMessage());
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

    <title>Administrator Settings | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        * {
            box-sizing: border-box;
        }

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
            min-width: 0;
            min-height: 100vh;
            height: auto;
        }

        .settings-container {
            padding: 30px;
            max-width: 1250px;
            margin: 0 auto;
        }

        .settings-header {
            margin-bottom: 30px;
        }

        .settings-header h1 {
            font-size: 27px;
            color: #315f68;
            margin: 0 0 8px;
        }

        .settings-header p {
            color: #789397;
            font-size: 14px;
            margin: 0;
            line-height: 1.6;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            align-items: start;
        }

        .settings-card {
            background: #ffffff;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            min-width: 0;
        }

        .settings-card h2 {
            color: #315f68;
            font-size: 19px;
            margin: 0 0 8px;
        }

        .settings-card > p {
            color: #82999c;
            font-size: 13px;
            line-height: 1.6;
            margin: 0 0 22px;
        }

        .settings-field {
            margin-bottom: 18px;
        }

        .settings-field label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #416f72;
        }

        .settings-field input,
        .settings-field textarea {
            display: block;
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #dcebea;
            border-radius: 9px;
            font-family: inherit;
            font-size: 14px;
            color: #315f68;
            background: white;
            transition: border-color 0.2s;
        }

        .settings-field input:focus,
        .settings-field textarea:focus {
            outline: none;
            border-color: #58af98;
            box-shadow: 0 0 0 3px rgba(88,175,152,0.1);
        }

        .settings-field input[readonly] {
            background: #f4f8f7;
            color: #6d8588;
            cursor: default;
        }

        .settings-btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            background: #58af98;
            color: #ffffff;
            border: none;
            padding: 12px 19px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.2s;
        }

        .settings-btn:hover {
            background: #459d86;
        }

        .settings-note {
            padding: 13px 15px;
            border-radius: 9px;
            background: #f1f8f6;
            color: #416f72;
            font-size: 13px;
            line-height: 1.6;
            margin-top: 10px;
        }

        .settings-alert {
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .settings-alert.success {
            background: #e4f8ef;
            color: #287b61;
        }

        .settings-alert.error {
            background: #fff0f0;
            color: #a33;
        }

        .settings-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .password-hint {
            color: #82999c;
            font-size: 12px;
            margin-top: 7px;
            line-height: 1.5;
        }

        .settings-divider {
            border: none;
            border-top: 1px solid #edf2f1;
            margin: 20px 0;
        }

        .account-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            background: #e4f8ef;
            color: #287b61;
            font-size: 12px;
            font-weight: 600;
        }

        @media (max-width: 900px) {

            .settings-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 600px) {

            .settings-container {
                padding: 18px;
            }

            .settings-card {
                padding: 20px;
            }

            .settings-header h1 {
                font-size: 23px;
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

            <a href="../index.php">
                Dashboard
            </a>

            <a href="records.php">
                Records
            </a>

            <a href="guardian_accounts.php">
                Guardian Accounts
            </a>

            <a href="management.php">
                Management
            </a>

            <a href="monitoring.php">
                Monitoring
            </a>

            <a href="announcements.php">
                Announcements
            </a>

            <a href="reports.php">
                Reports
            </a>

            <a href="settings.php" class="active">
                Settings
            </a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main">

        <header class="topbar">

            <div class="welcome-admin">
                Settings
            </div>

        </header>

        <section class="settings-container">

            <!-- HEADER -->

            <div class="settings-header">

                <h1>Administrator Settings</h1>

                <p>
                    Manage your administrator account,
                    clinic information, and security settings.
                </p>

            </div>

            <div class="settings-grid">

                <!-- ADMINISTRATOR PROFILE -->

                <div class="settings-card">

                    <h2>Administrator Profile</h2>

                    <p>
                        Update your administrator name
                        and username.
                    </p>

                    <?php if ($profileMessage !== ""): ?>

                        <div class="settings-alert <?= $profileMessageType === "success" ? "success" : "error" ?>">

                            <?= settingsEscape($profileMessage) ?>

                        </div>

                    <?php endif; ?>

                    <form
                        action="../backend/update_profile.php"
                        method="POST"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= settingsEscape($_SESSION["settings_csrf"]) ?>"
                        >

                        <div class="settings-field">

                            <label for="full_name">
                                Full Name
                            </label>

                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                value="<?= settingsEscape($adminFullName) ?>"
                                maxlength="100"
                                autocomplete="name"
                                required
                            >

                        </div>

                        <div class="settings-field">

                            <label for="username">
                                Username
                            </label>

                            <input
                                type="text"
                                id="username"
                                name="username"
                                value="<?= settingsEscape($adminUsername) ?>"
                                minlength="3"
                                maxlength="50"
                                pattern="[A-Za-z0-9_.-]{3,50}"
                                autocomplete="username"
                                required
                            >

                        </div>

                        <div class="settings-field">

                            <label for="profile_current_password">
                                Current Password
                            </label>

                            <input
                                type="password"
                                id="profile_current_password"
                                name="current_password"
                                placeholder="Confirm your password"
                                autocomplete="current-password"
                                required
                            >

                        </div>

                        <div class="settings-field">

                            <label>Account Role</label>

                            <input
                                type="text"
                                value="Administrator"
                                readonly
                            >

                        </div>

                        <div class="settings-field">

                            <label>Account Status</label>

                            <span class="account-status">
                                Active
                            </span>

                        </div>

                        <button
                            type="submit"
                            class="settings-btn"
                        >
                            Save Profile Changes
                        </button>

                    </form>

                </div>

                <!-- CHANGE PASSWORD -->

                <div class="settings-card">

                    <h2>Change Password</h2>

                    <p>
                        Update your administrator password
                        to protect your account.
                    </p>

                    <?php if ($settingsMessage !== ""): ?>

                        <div class="settings-alert <?= $settingsMessageType === "success" ? "success" : "error" ?>">

                            <?= settingsEscape($settingsMessage) ?>

                        </div>

                    <?php endif; ?>

                    <form
                        action="../backend/change_password.php"
                        method="POST"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= settingsEscape($_SESSION["settings_csrf"]) ?>"
                        >

                        <div class="settings-field">

                            <label for="current_password">
                                Current Password
                            </label>

                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                autocomplete="current-password"
                                required
                            >

                        </div>

                        <div class="settings-field">

                            <label for="new_password">
                                New Password
                            </label>

                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                minlength="12"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                            <div class="password-hint">
                                Use at least 12 characters.
                                Choose a unique password.
                            </div>

                        </div>

                        <div class="settings-field">

                            <label for="confirm_password">
                                Confirm New Password
                            </label>

                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                minlength="12"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <button
                            type="submit"
                            class="settings-btn"
                        >
                            Update Password
                        </button>

                    </form>

                </div>

                
<!-- CLINIC INFORMATION -->

<div class="settings-card">

    <h2>Clinic Information</h2>

    <p>
        Update the healthcare facility's contact details
        and operating hours.
    </p>

    <?php if ($clinicMessage !== ""): ?>

        <div class="settings-alert <?= $clinicMessageType === "success" ? "success" : "error" ?>">
            <?= settingsEscape($clinicMessage) ?>
        </div>

    <?php endif; ?>

    <form
        action="../backend/update_clinic.php"
        method="POST"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= settingsEscape($_SESSION["settings_csrf"]) ?>"
        >

        <div class="settings-field">

            <label for="clinic_name">Clinic Name</label>

            <input
                type="text"
                id="clinic_name"
                name="clinic_name"
                value="<?= settingsEscape($clinic["clinic_name"]) ?>"
                maxlength="150"
                required
            >

        </div>

        <div class="settings-field">

            <label for="clinic_address">Clinic Address</label>

            <textarea
                id="clinic_address"
                name="address"
                rows="3"
                maxlength="255"
            ><?= settingsEscape($clinic["address"]) ?></textarea>

        </div>

        <div class="settings-field">

            <label for="contact_number">Contact Number</label>

            <input
                type="tel"
                id="contact_number"
                name="contact_number"
                value="<?= settingsEscape($clinic["contact_number"]) ?>"
                maxlength="30"
            >

        </div>

        <div class="settings-field">

            <label for="clinic_email">Email Address</label>

            <input
                type="email"
                id="clinic_email"
                name="email"
                value="<?= settingsEscape($clinic["email"]) ?>"
                maxlength="150"
            >

        </div>

        <div class="settings-field">

            <label for="operating_hours">Operating Hours</label>

            <input
                type="text"
                id="operating_hours"
                name="operating_hours"
                value="<?= settingsEscape($clinic["operating_hours"]) ?>"
                maxlength="150"
                placeholder="e.g. Monday-Friday, 8:00 AM-5:00 PM"
            >

        </div>

        <button type="submit" class="settings-btn">
            Save Clinic Information
        </button>

    </form>

</div>


                <!-- SYSTEM INFORMATION -->

                <div class="settings-card">

                    <h2>System Information</h2>

                    <p>
                        Information about your ImmuCare
                        administrator system.
                    </p>

                    <div class="settings-field">

                        <label>Application Name</label>

                        <input
                            type="text"
                            value="ImmuCare - Infant Immunization Ledger"
                            readonly
                        >

                    </div>

                    <div class="settings-field">

                        <label>Platform</label>

                        <input
                            type="text"
                            value="Web-based Administrator Dashboard"
                            readonly
                        >

                    </div>

                    <div class="settings-field">

                        <label>Logged-in Administrator</label>

                        <input
                            type="text"
                            value="<?= settingsEscape($adminFullName) ?>"
                            readonly
                        >

                    </div>

                    <hr class="settings-divider">

                    <div class="settings-actions">

                        <a
                            href="../index.php"
                            class="settings-btn"
                        >
                            Return to Dashboard
                        </a>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
