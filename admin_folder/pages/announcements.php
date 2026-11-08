
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Security token for announcement actions.
$csrfToken = ensureCsrfToken();

// Retrieve announcements and feedback count.
try {

    $result = $mysqli->query("
        SELECT
            id,
            title,
            message,
            date_posted,
            status
        FROM announcements
        ORDER BY date_posted DESC, id DESC
    ");

    $announcements = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    $feedbackResult = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM user_feedback
        WHERE status = 'Open'
    ");

    $feedbackCount = (int) (
        $feedbackResult->fetch_assoc()["total"] ?? 0
    );

    $feedbackResult->free();

} catch (mysqli_sql_exception $e) {

    error_log("Announcements error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load announcements.");
}

// Calculate announcement statistics.
$totalAnnouncements = count($announcements);
$totalPublished = 0;
$totalDraft = 0;
$totalArchived = 0;

foreach ($announcements as $item) {

    $itemStatus = (string) $item["status"];

    if (in_array($itemStatus, ["Active", "Published"], true)) {
        $totalPublished++;
    } elseif ($itemStatus === "Draft") {
        $totalDraft++;
    } elseif ($itemStatus === "Archived") {
        $totalArchived++;
    }
}

// Retrieve success messages, if available.
$flash = takeUserFlash();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Announcements | ImmuCare</title>

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

        .announcement-container {
            padding: 25px;
        }

        .announcement-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .announcement-header h1 {
            margin: 0 0 7px;
            color: #315f68;
            font-size: 26px;
        }

        .announcement-header p {
            margin: 0;
            color: #82999c;
            font-size: 14px;
        }

        .header-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .admin-link {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 9px;
            background: #58af98;
            color: white;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
        }

        .admin-link:hover {
            background: #459d86;
        }

        .admin-link.secondary {
            background: #e4f5ff;
            color: #377a99;
        }

        .admin-link.secondary:hover {
            background: #d2ebf9;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
        }

        .summary-card p {
            color: #708c90;
            font-size: 13px;
            margin: 0 0 10px;
        }

        .summary-card h2 {
            color: #315f68;
            font-size: 29px;
            margin: 0;
        }

        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }

        .filter-container input,
        .filter-container select {
            padding: 12px;
            border: 1px solid #d6e6e0;
            border-radius: 9px;
            background: white;
            color: #315f68;
            font: inherit;
            box-sizing: border-box;
        }

        .filter-container input {
            flex: 1;
            min-width: 220px;
        }

        .filter-container select {
            min-width: 160px;
        }

        .announcement-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .announcement-card {
            padding: 22px;
            border-radius: 15px;
            background: white;
            box-shadow: 0 4px 12px rgba(70,110,110,.08);
            min-width: 0;
        }

        .announcement-card h2 {
            margin: 0 0 12px;
            color: #315f68;
            font-size: 18px;
            overflow-wrap: anywhere;
        }

        .announcement-message {
            color: #637f80;
            font-size: 13px;
            line-height: 1.7;
            overflow-wrap: anywhere;
            margin: 0 0 15px;
        }

        .announcement-info {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding-top: 12px;
            border-top: 1px solid #edf2f1;
            color: #80969a;
            font-size: 12px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            background: #e4f8ef;
            color: #3f8b74;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.draft {
            background: #fff1d8;
            color: #b17b34;
        }

        .status-badge.archived {
            background: #eceff1;
            color: #68777c;
        }

        .announcement-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
        }

        .announcement-actions form {
            margin: 0;
        }

        .action-btn {
            display: inline-block;
            padding: 10px 15px;
            border: none;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            line-height: normal;
        }

        .edit-btn {
            background: #e4f5ff;
            color: #377a99;
        }

        .edit-btn:hover {
            background: #d2ebf9;
        }

        .publish-btn {
            background: #e4f8ef;
            color: #3f8b74;
        }

        .publish-btn:hover {
            background: #d0efdf;
        }

        .unpublish-btn {
            background: #fff1d8;
            color: #a97822;
        }

        .unpublish-btn:hover {
            background: #ffe4b5;
        }

        .delete-btn {
            background: #ffe6eb;
            color: #b85367;
        }

        .delete-btn:hover {
            background: #ffd1dc;
        }

        .empty-message,
        .filter-empty {
            padding: 30px;
            border-radius: 14px;
            background: white;
            color: #80969a;
            text-align: center;
        }

        .empty-message a {
            color: #315f68;
            font-weight: 600;
        }

        .filter-empty {
            display: none;
            margin-top: 15px;
        }

        .flash-message {
            background: #e4f8ef;
            color: #315f68;
            border: 1px solid #c4e9dc;
            border-radius: 10px;
            padding: 13px 16px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        @media (max-width: 1000px) {

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 800px) {

            .announcement-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {

            .announcement-container {
                padding: 15px;
            }

            .summary-grid {
                gap: 10px;
            }

            .summary-card {
                padding: 15px;
            }

            .summary-card h2 {
                font-size: 24px;
            }

            .filter-container input,
            .filter-container select {
                width: 100%;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .admin-link {
                flex: 1;
            }

            .announcement-card {
                padding: 17px;
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

            <a
                href="announcements.php"
                class="active"
            >
                Announcements
            </a>

            <a href="reports.php">
                Reports
            </a>

            <a href="settings.php">
                Settings
            </a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main">

        <header class="topbar">

            <div class="welcome-admin">
                Announcements
            </div>

        </header>

        <section class="announcement-container">

            <!-- HEADER -->

            <div class="announcement-header">

                <div>

                    <h1>Manage Announcements</h1>

                    <p>
                        Create, publish, edit, and manage
                        announcements for ImmuCare guardians.
                    </p>

                </div>

                <div class="header-actions">

                    <a
                        class="admin-link"
                        href="add-announcement.php"
                    >
                        + New Announcement
                    </a>

                    <a
                        class="admin-link secondary"
                        href="feedback.php"
                    >
                        Feedback Inbox
                        <?= $feedbackCount > 0
                            ? "(" . $feedbackCount . ")"
                            : "" ?>
                    </a>

                </div>

            </div>

            <!-- SUCCESS MESSAGE -->

            <?php if (is_string($flash) && $flash !== ""): ?>

                <div class="flash-message" role="status">
                    <?= escapeHtml($flash) ?>
                </div>

            <?php endif; ?>

            <!-- SUMMARY CARDS -->

            <div class="summary-grid">

                <div class="summary-card">

                    <p>Total Announcements</p>

                    <h2><?= $totalAnnouncements ?></h2>

                </div>

                <div class="summary-card">

                    <p>Published</p>

                    <h2><?= $totalPublished ?></h2>

                </div>

                <div class="summary-card">

                    <p>Drafts</p>

                    <h2><?= $totalDraft ?></h2>

                </div>

                <div class="summary-card">

                    <p>Archived</p>

                    <h2><?= $totalArchived ?></h2>

                </div>

            </div>

            <!-- SEARCH AND FILTER -->

            <div class="filter-container">

                <input
                    type="search"
                    id="announcementSearch"
                    placeholder="Search announcements..."
                    aria-label="Search announcements"
                >

                <select
                    id="announcementFilter"
                    aria-label="Filter announcements"
                >

                    <option value="">
                        All Statuses
                    </option>

                    <option value="published">
                        Published
                    </option>

                    <option value="draft">
                        Draft
                    </option>

                    <option value="archived">
                        Archived
                    </option>

                </select>

            </div>

            <!-- ANNOUNCEMENT LIST -->

            <?php if ($announcements === []): ?>

                <div class="empty-message">

                    <p>There are no announcements yet.</p>

                    <a href="add-announcement.php">
                        Create your first announcement
                    </a>

                </div>

            <?php else: ?>

                <div
                    class="announcement-grid"
                    id="announcementGrid"
                >

                    <?php foreach ($announcements as $announcement): ?>

                        <?php

                        $announcementId = (int) $announcement["id"];

                        $status = (string) $announcement["status"];

                        $isPublished = in_array(
                            $status,
                            ["Active", "Published"],
                            true
                        );

                        $statusLabel = $isPublished
                            ? "Published"
                            : $status;

                        $statusClass = strtolower($statusLabel);

                        ?>

                        <article
                            class="announcement-card"
                            data-status="<?= escapeHtml($statusClass) ?>"
                        >

                            <!-- TITLE -->

                            <h2>
                                <?= escapeHtml(
                                    (string) $announcement["title"]
                                ) ?>
                            </h2>

                            <!-- MESSAGE -->

                            <p class="announcement-message">
                                <?= nl2br(
                                    escapeHtml(
                                        (string) $announcement["message"]
                                    )
                                ) ?>
                            </p>

                            <!-- DATE AND STATUS -->

                            <div class="announcement-info">

                                <span>

                                    <?php if (!empty($announcement["date_posted"])): ?>

                                        <?= escapeHtml(
                                            date(
                                                "F j, Y",
                                                strtotime(
                                                    (string) $announcement["date_posted"]
                                                )
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        Date not set

                                    <?php endif; ?>

                                </span>

                                <span
                                    class="status-badge <?= escapeHtml($statusClass) ?>"
                                >
                                    <?= escapeHtml($statusLabel) ?>
                                </span>

                            </div>

                            <!-- ACTION BUTTONS -->

                            <div class="announcement-actions">

                                <!-- EDIT -->

                                <a
                                    class="action-btn edit-btn"
                                    href="edit-announcement.php?id=<?= $announcementId ?>"
                                >
                                    Edit
                                </a>

                                <!-- PUBLISH / UNPUBLISH -->

                                <form
                                    method="POST"
                                    action="update-announcement-status.php"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escapeHtml($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= $announcementId ?>"
                                    >

                                    <?php if ($isPublished): ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="unpublish"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn unpublish-btn"
                                        >
                                            Unpublish
                                        </button>

                                    <?php else: ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="publish"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn publish-btn"
                                        >
                                            Publish
                                        </button>

                                    <?php endif; ?>

                                </form>

                                <!-- DELETE -->

                                <form
                                    method="POST"
                                    action="delete-announcement.php"
                                    onsubmit="return confirm('Are you sure you want to permanently delete this announcement? This action cannot be undone.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escapeHtml($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= $announcementId ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="action-btn delete-btn"
                                    >
                                        Delete
                                    </button>

                                </form>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

                <div
                    class="filter-empty"
                    id="filterEmpty"
                >
                    No announcements match your search or filter.
                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<script>

const announcementSearch = document.getElementById(
    "announcementSearch"
);

const announcementFilter = document.getElementById(
    "announcementFilter"
);

const announcementCards = document.querySelectorAll(
    ".announcement-card"
);

const filterEmpty = document.getElementById(
    "filterEmpty"
);

function filterAnnouncements() {

    const search = announcementSearch.value
        .toLowerCase()
        .trim();

    const selectedStatus = announcementFilter.value;

    let visibleCount = 0;

    announcementCards.forEach(card => {

        const title = card.querySelector("h2")?.textContent || "";

        const message = card.querySelector(
            ".announcement-message"
        )?.textContent || "";

        const searchableText = (
            title + " " + message
        ).toLowerCase();

        const matchesSearch = searchableText.includes(search);

        const matchesStatus =
            selectedStatus === "" ||
            card.dataset.status === selectedStatus;

        const shouldShow = matchesSearch && matchesStatus;

        card.style.display = shouldShow ? "" : "none";

        if (shouldShow) {
            visibleCount++;
        }

    });

    if (filterEmpty) {

        filterEmpty.style.display =
            announcementCards.length > 0 && visibleCount === 0
                ? "block"
                : "none";
    }
}

if (announcementSearch && announcementFilter) {

    announcementSearch.addEventListener(
        "input",
        filterAnnouncements
    );

    announcementFilter.addEventListener(
        "change",
        filterAnnouncements
    );
}

</script>

</body>
</html>
