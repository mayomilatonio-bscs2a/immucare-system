
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Allowed announcement statuses.
$statuses = ["Draft", "Active", "Archived"];

// Validate announcement ID.
$id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = filter_input(
        INPUT_POST,
        "id",
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );
}

if (!$id) {
    http_response_code(400);
    exit("A valid announcement is required.");
}

// Retrieve existing announcement.
try {

    $stmt = $mysqli->prepare("
        SELECT
            id,
            title,
            message,
            status,
            date_posted
        FROM announcements
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $announcement = $stmt->get_result()
        ->fetch_assoc();

    $stmt->close();

} catch (mysqli_sql_exception $e) {

    error_log(
        "Load announcement failed: " .
        $e->getMessage()
    );

    http_response_code(500);
    exit("Unable to load announcement.");
}

if (!$announcement) {
    http_response_code(404);
    exit("Announcement not found.");
}

// Default form values.
$error = "";

$title = (string) $announcement["title"];
$message = (string) $announcement["message"];

$status = in_array(
    $announcement["status"],
    $statuses,
    true
)
    ? (string) $announcement["status"]
    : "Archived";

// Create CSRF token.
$csrfToken = ensureCsrfToken();

// Process form submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $titleInput = $_POST["title"] ?? "";
    $messageInput = $_POST["message"] ?? "";
    $statusInput = $_POST["status"] ?? "";

    if (!validateCsrfToken()) {

        http_response_code(400);

        $error = "This form expired. Reload the page and try again.";

    } elseif (
        !is_string($titleInput) ||
        !is_string($messageInput) ||
        !is_string($statusInput)
    ) {

        $error = "Invalid form values.";

    } else {

        $title = trim($titleInput);
        $message = trim($messageInput);
        $status = trim($statusInput);

        if ($title === "") {

            $error = "Please enter an announcement title.";

        } elseif (mb_strlen($title, "UTF-8") > 255) {

            $error = "The title must not exceed 255 characters.";

        } elseif ($message === "") {

            $error = "Please enter an announcement message.";

        } elseif (mb_strlen($message, "UTF-8") > 10000) {

            $error = "The message must not exceed 10,000 characters.";

        } elseif (!in_array($status, $statuses, true)) {

            $error = "Please select a valid announcement status.";

        } else {

            try {

                $stmt = $mysqli->prepare("
                    UPDATE announcements
                    SET
                        title = ?,
                        message = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "sssi",
                    $title,
                    $message,
                    $status,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                setUserFlash(
                    "Announcement updated successfully."
                );

                header("Location: announcements.php");
                exit;

            } catch (mysqli_sql_exception $e) {

                error_log(
                    "Announcement update failed: " .
                    $e->getMessage()
                );

                $error = "Unable to update the announcement. Please try again.";
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

    <title>Edit Announcement | ImmuCare</title>

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
        }

        .form-container {
            width: calc(100% - 40px);
            max-width: 750px;
            margin: 30px auto;
            padding: 30px;
            border-radius: 18px;
            background: white;
            box-sizing: border-box;
            box-shadow: 0 4px 15px rgba(0,0,0,.05);
        }

        .form-container h1 {
            margin: 0 0 10px;
            color: #315f68;
        }

        .form-description {
            color: #82999c;
            font-size: 14px;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .announcement-form {
            display: grid;
            gap: 22px;
        }

        .form-group label {
            display: block;
            color: #416f72;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dcebea;
            border-radius: 9px;
            box-sizing: border-box;
            font: inherit;
            color: #315f68;
            background: white;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 150px;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #61bca4;
        }

        .form-hint {
            display: block;
            margin-top: 7px;
            color: #82999c;
            font-size: 12px;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 5px;
        }

        .save-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 9px;
            background: #58af98;
            color: white;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #459d86;
        }

        .cancel-btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 9px;
            background: #edf2f1;
            color: #315f68;
            text-decoration: none;
            font-weight: 600;
        }

        .form-error {
            padding: 13px;
            border-radius: 9px;
            background: #fff0f4;
            color: #9b5268;
            margin-bottom: 20px;
        }

        .announcement-info {
            background: #e8f8f3;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            color: #416f72;
            font-size: 13px;
            line-height: 1.8;
        }

        .announcement-info strong {
            color: #315f68;
        }

        .status-guide {
            background: #f5faf8;
            border: 1px solid #e0eeea;
            border-radius: 10px;
            padding: 15px;
            color: #416f72;
            font-size: 13px;
            line-height: 1.8;
        }

        .status-guide strong {
            color: #315f68;
        }

        @media (max-width: 768px) {

            .form-container {
                width: calc(100% - 30px);
                padding: 20px;
                margin: 20px auto;
            }
        }

    </style>

</head>

<body>

<div class="dashboard">

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

            <a href="management.php">Management</a>

            <a href="monitoring.php">Monitoring</a>

            <a href="announcements.php" class="active">
                Announcements
            </a>

            <a href="reports.php">Reports</a>

            <a href="settings.php">Settings</a>

        </nav>

    </aside>

    <main class="main">

        <header class="topbar">

            <div class="welcome-admin">
                Edit Announcement
            </div>

        </header>

        <section class="form-container">

            <h1>Edit Announcement</h1>

            <p class="form-description">
                Update the announcement details or change
                its publication status.
            </p>

            <div class="announcement-info">

                <div>
                    <strong>Announcement ID:</strong>
                    #<?= (int) $announcement["id"] ?>
                </div>

                <div>
                    <strong>Date Posted:</strong>

                    <?php if (!empty($announcement["date_posted"])): ?>

                        <?= escapeHtml(
                            date(
                                "F j, Y",
                                strtotime($announcement["date_posted"])
                            )
                        ) ?>

                    <?php else: ?>

                        Not available

                    <?php endif; ?>

                </div>

            </div>

            <?php if ($error !== ""): ?>

                <div
                    class="form-error"
                    role="alert"
                >
                    <?= escapeHtml($error) ?>
                </div>

            <?php endif; ?>

            <form
                method="POST"
                action="edit-announcement.php"
                class="announcement-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeHtml($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int) $id ?>"
                >

                <div class="form-group">

                    <label for="title">
                        Announcement Title
                    </label>

                    <input
                        type="text"
                        id="title"
                        name="title"
                        maxlength="255"
                        value="<?= escapeHtml($title) ?>"
                        placeholder="Enter announcement title"
                        required
                    >

                    <span class="form-hint">
                        Maximum of 255 characters.
                    </span>

                </div>

                <div class="form-group">

                    <label for="message">
                        Announcement Message
                    </label>

                    <textarea
                        id="message"
                        name="message"
                        rows="7"
                        maxlength="10000"
                        placeholder="Write your announcement here..."
                        required
                    ><?= escapeHtml($message) ?></textarea>

                    <span class="form-hint">
                        Maximum of 10,000 characters.
                    </span>

                </div>

                <div class="form-group">

                    <label for="status">
                        Announcement Status
                    </label>

                    <select
                        id="status"
                        name="status"
                        required
                    >

                        <?php foreach ($statuses as $option): ?>

                            <option
                                value="<?= escapeHtml($option) ?>"
                                <?= $status === $option
                                    ? "selected" : "" ?>
                            >

                                <?= escapeHtml(
                                    $option === "Active"
                                        ? "Published"
                                        : $option
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <span class="form-hint">
                        Select whether the announcement should
                        be published, saved as draft, or archived.
                    </span>

                </div>

                <div class="status-guide">

                    <strong>Draft:</strong>
                    The announcement is saved but not published.

                    <br>

                    <strong>Published:</strong>
                    The announcement is marked Active in the database.

                    <br>

                    <strong>Archived:</strong>
                    The announcement remains stored but is no
                    longer active.

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        class="save-btn"
                    >
                        Save Changes
                    </button>

                    <a
                        href="announcements.php"
                        class="cancel-btn"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        </section>

    </main>

</div>

</body>
</html>
