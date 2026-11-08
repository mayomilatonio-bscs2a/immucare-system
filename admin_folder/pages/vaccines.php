
<?php
require_once __DIR__ . "/../auth.php";
requireStaffLogin();

require_once __DIR__ . "/../connect.php";

function h($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$vaccines = [];
$error = "";

try {
    $result = $mysqli->query("
        SELECT
            id,
            vaccine_name,
            dose,
            recommended_age,
            description,
            status,
            created_at
        FROM vaccines
        ORDER BY vaccine_name ASC, id ASC
    ");

    $vaccines = $result->fetch_all(MYSQLI_ASSOC);

} catch (mysqli_sql_exception $e) {
    error_log("Vaccine list error: " . $e->getMessage());
    $error = "Unable to load vaccines.";
}

$totalVaccines = count($vaccines);
$activeVaccines = 0;
$inactiveVaccines = 0;

foreach ($vaccines as $vaccine) {
    if (strcasecmp($vaccine["status"] ?? "", "Active") === 0) {
        $activeVaccines++;
    } else {
        $inactiveVaccines++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Vaccines | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>
        html, body {
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

        .vaccine-content {
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: #315f68;
            font-size: 26px;
        }

        .add-btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 10px;
            background: #61bca4;
            color: white;
            text-decoration: none;
            font-weight: 600;
        }

        .add-btn:hover {
            background: #459d86;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(70,110,110,.07);
        }

        .summary-card p {
            color: #789297;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .summary-card h2 {
            color: #315f68;
            font-size: 29px;
        }

        .table-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(70,110,110,.07);
        }

        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .table-toolbar h2 {
            color: #315f68;
            font-size: 19px;
        }

        .search-input {
            padding: 12px 15px;
            border: 1px solid #d6e4e1;
            border-radius: 10px;
            font: inherit;
            width: 280px;
            max-width: 100%;
            box-sizing: border-box;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .vaccine-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .vaccine-table th {
            padding: 14px;
            background: #e8f8f3;
            color: #315f68;
            font-size: 13px;
            white-space: nowrap;
        }

        .vaccine-table td {
            padding: 15px 14px;
            border-bottom: 1px solid #edf2f1;
            color: #55777d;
            font-size: 14px;
            vertical-align: middle;
        }

        .vaccine-table td.description-cell {
            min-width: 180px;
            max-width: 280px;
            overflow-wrap: anywhere;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #e0f5e9;
            color: #2f8b63;
        }

        .status-inactive {
            background: #f2e8e8;
            color: #9b6262;
        }

        .edit-btn {
            color: #315f68;
            text-decoration: none;
            font-weight: 600;
        }

        .edit-btn:hover {
            text-decoration: underline;
        }

        .empty-state {
            text-align: center;
            padding: 35px;
            color: #789297;
        }

        .error-message {
            padding: 15px;
            background: #ffe7ec;
            color: #a63f56;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .vaccine-content {
                padding: 15px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .table-card {
                padding: 15px;
            }

            .search-input {
                width: 100%;
            }

            .page-back {
                margin-bottom: 18px;
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

    <main class="main">

        <header class="topbar">
            <div class="welcome-admin">
                Vaccination Management
            </div>
        </header>

        <section class="vaccine-content">

            <!-- Upper-left Back button -->
            <a
                href="management.php"
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
                Back to Management
            </a>

            <div class="page-header">
                <h1>Vaccines</h1>

                <?php if (($_SESSION["role"] ?? "") === "Administrator"): ?>
                    <a href="add_vaccine.php" class="add-btn">
                        + Add Vaccine
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($error !== ""): ?>
                <div class="error-message" role="alert">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <div class="summary-grid">

                <div class="summary-card">
                    <p>Total Vaccines</p>
                    <h2><?= $totalVaccines ?></h2>
                </div>

                <div class="summary-card">
                    <p>Active Vaccines</p>
                    <h2><?= $activeVaccines ?></h2>
                </div>

                <div class="summary-card">
                    <p>Inactive Vaccines</p>
                    <h2><?= $inactiveVaccines ?></h2>
                </div>

            </div>

            <div class="table-card">

                <div class="table-toolbar">
                    <h2>Vaccine List</h2>

                    <input
                        type="search"
                        id="vaccineSearch"
                        class="search-input"
                        placeholder="Search vaccines..."
                        aria-label="Search vaccines"
                    >
                </div>

                <div class="table-wrapper">

                    <table class="vaccine-table" id="vaccineTable">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vaccine</th>
                                <th>Dose</th>
                                <th>Recommended Age</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($vaccines as $vaccine): ?>

                            <?php
                                $isActive = strcasecmp(
                                    $vaccine["status"] ?? "",
                                    "Active"
                                ) === 0;
                            ?>

                            <tr class="vaccine-row">
                                <td><?= (int) $vaccine["id"] ?></td>

                                <td>
                                    <strong>
                                        <?= h($vaccine["vaccine_name"]) ?>
                                    </strong>
                                </td>

                                <td><?= h($vaccine["dose"]) ?></td>

                                <td>
                                    <?= h($vaccine["recommended_age"]) ?>
                                </td>

                                <td class="description-cell">
                                    <?= h($vaccine["description"] ?: "—") ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= $isActive
                                        ? "status-active"
                                        : "status-inactive" ?>">
                                        <?= h($vaccine["status"] ?: "Unknown") ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (($_SESSION["role"] ?? "") === "Administrator"): ?>
                                        <a
                                            class="edit-btn"
                                            href="edit_vaccine.php?id=<?= (int) $vaccine["id"] ?>"
                                        >
                                            Edit
                                        </a>
                                    <?php else: ?>
                                        View Only
                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <p
                    id="noResults"
                    class="empty-state"
                    style="display:none;"
                >
                    No matching vaccines found.
                </p>

                <?php if (empty($vaccines) && $error === ""): ?>
                    <p class="empty-state">
                        No vaccines registered yet.
                    </p>
                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<script>
const searchInput = document.getElementById("vaccineSearch");
const rows = document.querySelectorAll(".vaccine-row");
const noResults = document.getElementById("noResults");

searchInput.addEventListener("input", function () {
    const search = this.value.toLowerCase().trim();
    let visibleCount = 0;

    rows.forEach(function (row) {
        const matches = row.textContent.toLowerCase().includes(search);

        row.style.display = matches ? "" : "none";

        if (matches) {
            visibleCount++;
        }
    });

    noResults.style.display =
        rows.length > 0 && visibleCount === 0
            ? "block"
            : "none";
});
</script>

</body>
</html>
