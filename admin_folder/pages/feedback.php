<?php
require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = filter_input(INPUT_POST, "feedback_id", FILTER_VALIDATE_INT);
    $status = $_POST["status"] ?? "";

    if (!validateCsrfToken()) {
        http_response_code(400);
        exit("This form expired. Reload the page and try again.");
    }

    if (
        !$id ||
        !is_string($status) ||
        !in_array($status, ["Open", "Resolved"], true)
    ) {
        http_response_code(400);
        exit("A valid feedback item and status are required.");
    }

    $stmt = $mysqli->prepare(
        "UPDATE user_feedback SET status = ? WHERE id = ?"
    );
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated === 0) {
        $check = $mysqli->prepare(
            "SELECT id FROM user_feedback WHERE id = ?"
        );
        $check->bind_param("i", $id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc() !== null;
        $check->close();

        if (!$exists) {
            http_response_code(404);
            exit("Feedback item not found.");
        }
    }

    setUserFlash("Feedback status updated to " . $status . ".");
    header("Location: feedback.php");
    exit;
}

$result = $mysqli->query("
    SELECT id, guardian, category, message, status, created_at, updated_at
    FROM user_feedback
    ORDER BY
        CASE status WHEN 'Open' THEN 0 ELSE 1 END,
        created_at DESC
");

$feedbackItems = $result->fetch_all(MYSQLI_ASSOC);

$openCount = count(array_filter(
    $feedbackItems,
    static fn (array $item): bool => $item["status"] === "Open"
));

$csrfToken = ensureCsrfToken();
$flash = takeUserFlash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guardian Feedback | ImmuCare Admin</title>
    <link rel="stylesheet" href="../style.css">

    <style>
        .feedback-container {
            padding: 20px;
        }

        .feedback-container h1 {
            margin: 0 0 8px;
            color: #315f68;
            font-size: 25px;
        }

        .feedback-intro {
            margin: 0 0 18px;
            color: #71868a;
        }

        .feedback-card {
            margin: 12px 0;
            padding: 18px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .feedback-heading {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px;
        }

        .feedback-heading h2 {
            margin: 0 0 5px;
            color: #315f68;
            font-size: 17px;
        }

        .feedback-meta {
            margin: 0;
            color: #71868a;
            font-size: 12px;
        }

        .feedback-message {
            margin: 13px 0;
            color: #486b70;
            font-size: 14px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }

        .feedback-status {
            align-self: flex-start;
            padding: 6px 10px;
            border-radius: 14px;
            background: #fff1d8;
            color: #9a6e28;
            font-size: 12px;
            font-weight: bold;
        }

        .feedback-status.resolved {
            background: #e5f6ee;
            color: #438d72;
        }

        .feedback-actions {
            display: flex;
            gap: 8px;
        }

        .feedback-actions button {
            padding: 9px 13px;
            border: 0;
            border-radius: 8px;
            background: #55ad90;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .feedback-actions button.reopen {
            background: #5a9fca;
        }

        .feedback-empty {
            padding: 25px;
            border-radius: 14px;
            background: white;
            color: #80969a;
            text-align: center;
        }

        .admin-flash {
            margin-bottom: 14px;
            padding: 12px;
            border-radius: 9px;
            background: #e9f8ef;
            color: #39745d;
        }
    </style>
</head>

<body>
<div class="dashboard">

    <aside class="sidebar">
        <div class="logo">
            <img src="../images/logo.png" alt="ImmuCare" width="150">
        </div>

        <nav>
            <a href="../index.php">Dashboard</a>
            <a href="records.php">Records</a>
            <a href="management.php">Management</a>
            <a href="monitoring.php">Monitoring</a>
            <a href="announcements.php">Announcements</a>
            <a href="feedback.php" class="active">Feedback</a>
            <a href="reports.php">Reports</a>
            <a href="settings.php">Settings</a>
        </nav>
    </aside>

    <main class="main">

        <header class="topbar">
            <div class="welcome-admin">Guardian feedback</div>
            <div class="profile">🔔 👤 Profile</div>
        </header>

        <section class="feedback-container">

            <h1>Feedback inbox</h1>

            <p class="feedback-intro">
                <?= $openCount ?>
                open item<?= $openCount === 1 ? "" : "s" ?>.
                Review guardian messages and mark them resolved when handled.
            </p>

            <?php if ($flash): ?>
                <div class="admin-flash" role="status">
                    <?= escapeHtml((string) ($flash["message"] ?? "")) ?>
                </div>
            <?php endif; ?>

            <?php if ($feedbackItems === []): ?>
                <div class="feedback-empty">
                    There is no guardian feedback yet.
                </div>
            <?php endif; ?>

            <?php foreach ($feedbackItems as $item): ?>
                <?php
                    $statusClass = strtolower((string) $item["status"]);
                ?>

                <article class="feedback-card">

                    <div class="feedback-heading">
                        <div>
                            <h2>
                                <?= escapeHtml((string) $item["category"]) ?>
                                from
                                <?= escapeHtml((string) $item["guardian"]) ?>
                            </h2>

                            <p class="feedback-meta">
                                Received
                                <?= escapeHtml(date(
                                    "F j, Y g:i A",
                                    strtotime((string) $item["created_at"])
                                )) ?>
                            </p>
                        </div>

                        <span class="feedback-status <?= escapeHtml($statusClass) ?>">
                            <?= escapeHtml((string) $item["status"]) ?>
                        </span>
                    </div>

                    <p class="feedback-message">
                        <?= nl2br(escapeHtml((string) $item["message"])) ?>
                    </p>

                    <form
                        class="feedback-actions"
                        method="post"
                        action="feedback.php"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escapeHtml($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="feedback_id"
                            value="<?= (int) $item["id"] ?>"
                        >

                        <?php if ($item["status"] === "Open"): ?>
                            <button
                                type="submit"
                                name="status"
                                value="Resolved"
                            >
                                Mark resolved
                            </button>
                        <?php else: ?>
                            <button
                                class="reopen"
                                type="submit"
                                name="status"
                                value="Open"
                            >
                                Reopen
                            </button>
                        <?php endif; ?>
                    </form>

                </article>
            <?php endforeach; ?>

        </section>
    </main>
</div>
</body>
</html>
