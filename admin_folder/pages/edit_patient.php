
<?php
require_once "../connect.php";

// Get the patient ID from the URL.
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: records.php");
    exit;
}

// Retrieve the selected patient's information.
$stmt = $mysqli->prepare("
    SELECT
        id,
        baby_name,
        age,
        birthday,
        guardian,
        relationship,
        phone_number,
        last_vaccine,
        status,
        next_vaccine,
        next_visit
    FROM patients
    WHERE id = ?
");

$stmt->bind_param("i", $id);
$stmt->execute();

$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    http_response_code(404);
    exit("Patient not found.");
}

// Safely display database values in HTML.
function field($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
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

    <title>Edit Patient | ImmuCare</title>

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
            overflow: visible;
            min-width: 0;
        }

        .form-page {
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

        .form-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 18px;
            box-sizing: border-box;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .form-container h1 {
            margin-bottom: 24px;
            color: #315f68;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #416f72;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dcebea;
            border-radius: 9px;
            font: inherit;
            box-sizing: border-box;
            background: white;
            color: #315f68;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #58af98;
        }

        .form-group input[readonly] {
            background: #f0f7f5;
            color: #315f68;
            cursor: not-allowed;
        }

        .submit-btn {
            margin-top: 20px;
            background: #58af98;
            color: white;
            border: 0;
            padding: 13px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: bold;
        }

        .submit-btn:hover {
            background: #438e77;
        }

        .cancel-btn {
            margin-left: 10px;
            color: #677f82;
            text-decoration: none;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

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
            <a href="records.php" class="active">Records</a>
            <a href="guardian_accounts.php">Guardian Accounts</a>
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
                Edit Patient
            </div>
        </header>

        <section class="form-page">

            <!-- Upper-left Back button -->
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
                Back to Records
            </a>

            <div class="form-container">

                <h1>Edit Patient</h1>

                <form
                    action="../backend/update_patient.php"
                    method="POST"
                    id="patientForm"
                >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= field($patient['id']) ?>"
                    >

                    <div class="form-grid">

                        <!-- BABY NAME -->
                        <div class="form-group full">
                            <label for="baby_name">Baby Name</label>

                            <input
                                type="text"
                                id="baby_name"
                                name="baby_name"
                                value="<?= field($patient['baby_name']) ?>"
                                maxlength="100"
                                required
                            >
                        </div>

                        <!-- BIRTHDAY -->
                        <div class="form-group">
                            <label for="birthday">Birthday</label>

                            <input
                                type="date"
                                id="birthday"
                                name="birthday"
                                value="<?= field($patient['birthday']) ?>"
                                required
                            >
                        </div>

                        <!-- AUTOMATIC AGE -->
                        <div class="form-group">
                            <label for="age">Age (Automatic)</label>

                            <input
                                type="text"
                                id="age"
                                name="age"
                                value="<?= field($patient['age']) ?>"
                                placeholder="Select birthday first"
                                readonly
                            >
                        </div>

                        <!-- GUARDIAN -->
                        <div class="form-group">
                            <label for="guardian">Guardian</label>

                            <input
                                type="text"
                                id="guardian"
                                name="guardian"
                                value="<?= field($patient['guardian']) ?>"
                                maxlength="100"
                                required
                            >
                        </div>

                        <!-- RELATIONSHIP -->
                        <div class="form-group">
                            <label for="relationship">Relationship</label>

                            <input
                                type="text"
                                id="relationship"
                                name="relationship"
                                value="<?= field($patient['relationship']) ?>"
                                maxlength="50"
                                required
                            >
                        </div>

                        <!-- PHONE NUMBER -->
                        <div class="form-group">
                            <label for="phone_number">Phone Number</label>

                            <input
                                type="tel"
                                id="phone_number"
                                name="phone_number"
                                value="<?= field($patient['phone_number']) ?>"
                                maxlength="20"
                                required
                            >
                        </div>

                        <!-- LAST VACCINE -->
                        
<div class="form-group">
    <label for="last_vaccine">Last Vaccine</label>

    <select
        id="last_vaccine"
        name="last_vaccine"
        class="form-control"
    >
        <option value="">Select Last Vaccine</option>

        <?php
        $availableVaccines = [
            "BCG",
            "Hepatitis B",
            "IPV",
            "MMR",
            "OPV",
            "PCV",
            "Pentavalent"
        ];

        $selectedLastVaccine = $patient["last_vaccine"] ?? "";

        foreach ($availableVaccines as $vaccine):
        ?>
            <option
                value="<?= htmlspecialchars($vaccine, ENT_QUOTES, 'UTF-8') ?>"
                <?= $selectedLastVaccine === $vaccine ? "selected" : "" ?>
            >
                <?= htmlspecialchars($vaccine, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>


                        <!-- VACCINATION STATUS -->
                        <div class="form-group">
                            <label for="status">Vaccination Status</label>

                            <select
                                id="status"
                                name="status"
                                required
                            >

                                <?php
                                $statuses = [
                                    "Up to Date",
                                    "Pending",
                                    "Overdue",
                                    "Completed"
                                ];

                                foreach ($statuses as $status):
                                ?>

                                    <option
                                        value="<?= field($status) ?>"
                                        <?= $patient["status"] === $status
                                            ? "selected"
                                            : "" ?>
                                    >
                                        <?= field($status) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <!-- NEXT VACCINE -->
                     
<div class="form-group">
    <label for="next_vaccine">Next Vaccine</label>

    <select
        id="next_vaccine"
        name="next_vaccine"
        class="form-control"
    >
        <option value="">Select Next Vaccine</option>

        <?php
        $availableVaccines = [
            "BCG",
            "Hepatitis B",
            "IPV",
            "MMR",
            "OPV",
            "PCV",
            "Pentavalent"
        ];

        $selectedNextVaccine = $patient["next_vaccine"] ?? "";

        foreach ($availableVaccines as $vaccine):
        ?>
            <option
                value="<?= htmlspecialchars($vaccine, ENT_QUOTES, 'UTF-8') ?>"
                <?= $selectedNextVaccine === $vaccine ? "selected" : "" ?>
            >
                <?= htmlspecialchars($vaccine, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>


                        <!-- NEXT VISIT -->
                        <div class="form-group">
                            <label for="next_visit">Next Visit</label>

                            <input
                                type="date"
                                id="next_visit"
                                name="next_visit"
                                value="<?= field($patient['next_visit']) ?>"
                            >
                        </div>

                    </div>

                    <button
                        type="submit"
                        class="submit-btn"
                    >
                        Save Changes
                    </button>

                    <a href="records.php" class="cancel-btn">
                        Cancel
                    </a>

                </form>

            </div>

        </section>

    </main>

</div>

<script>

// ==========================================
// AUTOMATIC AGE CALCULATION
// ==========================================

function calculateAge(birthday) {

    if (!birthday) {
        return "";
    }

    const parts = birthday.split("-").map(Number);

    if (parts.length !== 3) {
        return "";
    }

    const [year, month, day] = parts;

    const birthDate = new Date(year, month - 1, day);
    const today = new Date();

    today.setHours(0, 0, 0, 0);

    // Check for invalid or future birthdays.
    if (
        birthDate.getFullYear() !== year ||
        birthDate.getMonth() !== month - 1 ||
        birthDate.getDate() !== day ||
        birthDate > today
    ) {
        return "";
    }

    // Calculate completed years.
    let years = today.getFullYear() - year;

    if (
        today.getMonth() < month - 1 ||
        (
            today.getMonth() === month - 1 &&
            today.getDate() < day
        )
    ) {
        years--;
    }

    if (years >= 1) {
        return years +
            (years === 1 ? " year" : " years");
    }

    // Calculate completed calendar months.
    let months =
        (today.getFullYear() - year) * 12 +
        (today.getMonth() - (month - 1));

    if (today.getDate() < day) {
        months--;
    }

    if (months >= 1) {
        return months +
            (months === 1 ? " month" : " months");
    }

    // Calculate days using calendar dates.
    const days = Math.floor(
        (
            Date.UTC(
                today.getFullYear(),
                today.getMonth(),
                today.getDate()
            ) -
            Date.UTC(year, month - 1, day)
        ) / 86400000
    );

    // Display weeks for babies under one month.
    if (days >= 7) {
        const weeks = Math.floor(days / 7);

        return weeks +
            (weeks === 1 ? " week" : " weeks");
    }

    // Display days for newborn babies.
    return days +
        (days === 1 ? " day" : " days");
}

// ==========================================
// CONNECT BIRTHDAY AND AGE FIELDS
// ==========================================

const birthdayInput = document.getElementById("birthday");
const ageInput = document.getElementById("age");
const patientForm = document.getElementById("patientForm");

// Set maximum birthday to today.
const currentDate = new Date();

const localToday =
    currentDate.getFullYear() + "-" +
    String(currentDate.getMonth() + 1).padStart(2, "0") + "-" +
    String(currentDate.getDate()).padStart(2, "0");

birthdayInput.max = localToday;

// Automatically update age.
function updateAge() {
    ageInput.value = calculateAge(birthdayInput.value);
}

birthdayInput.addEventListener("change", updateAge);
birthdayInput.addEventListener("input", updateAge);

// Validate before saving.
patientForm.addEventListener("submit", function(event) {

    updateAge();

    if (ageInput.value === "") {
        event.preventDefault();

        alert("Please select a valid birthday.");
        birthdayInput.focus();
    }

});

// Calculate age on initial page load.
updateAge();

</script>

</body>
</html>
