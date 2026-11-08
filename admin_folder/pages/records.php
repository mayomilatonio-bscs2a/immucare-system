
<?php

require_once __DIR__ . "/../auth.php";
requireStaffLogin();

if (empty($_SESSION["delete_patient_csrf"])) {
    $_SESSION["delete_patient_csrf"] =
        bin2hex(random_bytes(32));
}

require_once __DIR__ . "/../connect.php";

$result = $mysqli->query("
    SELECT
        p.*,
        (
            SELECT vr.vaccine
            FROM vaccination_records AS vr
            WHERE vr.patient_id = p.id
              AND vr.deleted_at IS NULL
            ORDER BY vr.date_given DESC, vr.id DESC
            LIMIT 1
        ) AS active_last_vaccine
    FROM patients AS p
    ORDER BY p.id ASC
");

$patients = $result->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Patient Records | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>

        .records-container {
            padding: 20px;
            overflow-y: auto;
        }

        .records-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .records-header h1 {
            font-size: 25px;
            color: #315f68;
        }

        .add-btn {
            background: #61bca4;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
            white-space: nowrap;
        }

        .add-btn:hover {
            background: #459d86;
        }

        /* IMMUCARE THEMED SEARCH BAR */

        
.immucare-search {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    width: 100%;
    max-width: none;
    min-width: 0;
    padding: 0 16px;
    background: #ffffff;
    border: 1px solid #cde8df;
    border-radius: 14px;
    box-shadow: 0 3px 12px rgba(49, 95, 104, 0.05);
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}


        .immucare-search:hover {
            border-color: #9bd7c6;
        }

        .immucare-search:focus-within {
            border-color: #61bca4;
            box-shadow:
                0 0 0 3px rgba(97, 188, 164, 0.15);
        }

        .immucare-search .search-icon {
            width: 20px;
            height: 20px;
            color: #5d9d90;
            flex-shrink: 0;
        }

        .immucare-search input {
            width: 100%;
            min-width: 0;
            height: 46px;
            padding: 0;
            margin: 0;
            border: none;
            outline: none;
            background: transparent;
            box-shadow: none;
            color: #315f68;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
        }

        .immucare-search input:focus {
            outline: none;
            border: none;
            box-shadow: none;
            background: transparent;
        }

        .immucare-search input::placeholder {
            color: #8ba9a3;
            opacity: 1;
        }

        .immucare-search input::-webkit-search-cancel-button {
            cursor: pointer;
        }

        /* PATIENT RECORDS TABLE */

        .records-table-wrapper {
            background: white;
            border-radius: 15px;
            padding: 20px;
            overflow-x: auto;
            box-shadow:
                0 4px 12px rgba(70, 110, 110, 0.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        th {
            text-align: left;
            padding: 14px;
            background: #e8f8f3;
            color: #315f68;
            white-space: nowrap;
        }

        td {
            padding: 14px;
            border-bottom: 1px solid #edf2f1;
            color: #55777d;
            white-space: nowrap;
        }

        tr:hover {
            background: #f8fcfb;
        }

        /* ACTION BUTTONS */

        .edit-btn,
        .delete-btn {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }

        .edit-btn {
            background: #e4f5ff;
            color: #377a99;
        }

        .edit-btn:hover {
            background: #cceafa;
        }

        .delete-btn {
            background: #ffe7ec;
            color: #b65369;
        }

        .delete-btn:hover {
            background: #ffd3dd;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            background: #e8f8f3;
            color: #39776f;
        }

        .history-btn {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 7px;
            background: #e8f8f3;
            color: #39776f;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            margin-right: 5px;
        }

        .history-btn:hover {
            background: #cceee2;
        }

        /* RESPONSIVE DESIGN */

        @media (max-width: 768px) {

            .topbar {
                gap: 15px;
                flex-wrap: wrap;
            }

            .immucare-search {
                max-width: 100%;
                flex: 1 1 250px;
            }

            .records-container {
                padding: 15px;
            }

            .records-header h1 {
                font-size: 22px;
            }

            .records-table-wrapper {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {

            .immucare-search {
                flex-basis: 100%;
            }

            .immucare-search input {
                font-size: 13px;
            }

            .add-btn {
                padding: 10px 15px;
                font-size: 13px;
            }
        }

/* LONG SEARCH BAR - IMMUCARE TOPBAR */

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    width: 100%;
    box-sizing: border-box;
}

.topbar .immucare-search {
    display: flex;
    align-items: center;
    flex: 1 1 auto;
    width: auto;
    max-width: none;
    min-width: 0;
    margin: 0;
}

.topbar .immucare-search input {
    flex: 1;
    width: 100%;
    min-width: 0;
}

.topbar .profile {
    flex: 0 0 auto;
    margin-left: 0;
    white-space: nowrap;
}

@media (max-width: 600px) {
    .topbar {
        flex-wrap: wrap;
        gap: 12px;
    }

    .topbar .immucare-search {
        flex: 1 1 100%;
    }

    .topbar .profile {
        margin-left: auto;
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

            <a href="../index.php">
                Dashboard
            </a>

            <a
                href="records.php"
                class="active"
            >
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

            <a href="settings.php">
                Settings
            </a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">

        <!-- TOPBAR -->
        <header class="topbar">

            <!-- THEMED SEARCH BAR -->
            <div class="immucare-search">

                <svg
                    class="search-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle
                        cx="11"
                        cy="11"
                        r="8"
                    ></circle>

                    <path
                        d="m21 21-4.35-4.35"
                    ></path>
                </svg>

                <input
                    type="search"
                    id="searchInput"
                    placeholder="Search patients, guardians, or records..."
                    aria-label="Search patient records"
                    autocomplete="off"
                >

            </div>

            <div class="profile">
                🔔 👤 Profile
            </div>

        </header>

        <!-- PATIENT RECORDS -->
        <div class="records-container">

            <!-- FLASH MESSAGE -->
            <?php if (!empty($_SESSION["patient_flash"])): ?>

                <?php
                    $flash = $_SESSION["patient_flash"];
                    unset($_SESSION["patient_flash"]);
                ?>

                <div style="
                    padding: 14px;
                    margin-bottom: 20px;
                    border-radius: 10px;
                    background: #e8f8f3;
                    color: #315f68;
                ">
                    <?= htmlspecialchars(
                        $flash["message"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

            <?php endif; ?>

            <!-- RECORDS HEADER -->
            <div class="records-header">

                <h1>
                    Patient Records
                </h1>

                <a
                    href="add_patient.php"
                    class="add-btn"
                >
                    + Add Patient
                </a>

            </div>

            <!-- PATIENT TABLE -->
            <div class="records-table-wrapper">

                <table id="patientsTable">

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Baby Name</th>
                            <th>Age</th>
                            <th>Birthday</th>
                            <th>Guardian</th>
                            <th>Relationship</th>
                            <th>Phone Number</th>
                            <th>Last Vaccine</th>
                            <th>Status</th>
                            <th>Next Vaccine</th>
                            <th>Next Visit</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (count($patients) > 0): ?>

                            <?php foreach ($patients as $patient): ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $patient["id"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["baby_name"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["age"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["birthday"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["guardian"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["relationship"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["phone_number"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["active_last_vaccine"] ?? "—",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="status-badge">
                                            <?= htmlspecialchars(
                                                $patient["status"] ?? "",
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["next_vaccine"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $patient["next_visit"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <td>

                                        <!-- EDIT -->
                                        <a
                                            class="edit-btn"
                                            href="edit_patient.php?id=<?= (int) $patient["id"] ?>"
                                        >
                                            Edit
                                        </a>

                                        <!-- VIEW HISTORY -->
                                        <a
                                            class="history-btn"
                                            href="vaccination_history.php?id=<?= (int) $patient["id"] ?>"
                                        >
                                            View History
                                        </a>

                                        <!-- DELETE -->
                                        <form
                                            action="../backend/delete_patient.php"
                                            method="POST"
                                            style="display:inline;"
                                            onsubmit="return confirm('Are you sure you want to delete this patient?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $patient["id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(
                                                    $_SESSION["delete_patient_csrf"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="delete-btn"
                                                style="border:none;cursor:pointer;"
                                            >
                                                Delete
                                            </button>

                                        </form>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="12">
                                    No patient records found.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </main>

</div>

<!-- SEARCH FUNCTIONALITY -->
<script>

const searchInput = document.getElementById("searchInput");

const tableBody = document.querySelector(
    "#patientsTable tbody"
);

const patientRows = Array.from(
    tableBody.querySelectorAll("tr")
).filter(
    row => row.querySelectorAll("td").length === 12
);

const noResultsRow = document.createElement("tr");

noResultsRow.innerHTML = `
    <td
        colspan="12"
        style="
            text-align: center;
            padding: 25px;
            color: #82999c;
        "
    >
        No matching patient records found.
    </td>
`;

noResultsRow.style.display = "none";

tableBody.appendChild(noResultsRow);

searchInput.addEventListener("input", function () {

    const searchValue = this.value.trim().toLowerCase();

    let visibleCount = 0;

    patientRows.forEach(function (row) {

        const searchableColumns = [
            0, // Patient ID
            1, // Baby Name
            4, // Guardian
            6  // Phone Number
        ];

        const matches = searchableColumns.some(index => {

            const cell = row.cells[index];

            return cell &&
                cell.textContent
                    .toLowerCase()
                    .includes(searchValue);

        });

        row.style.display = matches ? "" : "none";

        if (matches) {
            visibleCount++;
        }

    });

    noResultsRow.style.display =
        patientRows.length > 0 && visibleCount === 0
            ? ""
            : "none";

});

</script>

</body>
</html>
