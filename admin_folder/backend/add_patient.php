
<?php

require_once "../connect.php";
require_once "../age_helper.php";

// Only accept form submissions.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/add_patient.php");
    exit;
}

// ==========================================
// GET FORM DATA
// ==========================================

$baby_name = trim($_POST["baby_name"] ?? "");
$birthday = trim($_POST["birthday"] ?? "");
$guardian = trim($_POST["guardian"] ?? "");
$relationship = trim($_POST["relationship"] ?? "");
$phone_number = trim($_POST["phone_number"] ?? "");
$last_vaccine = trim($_POST["last_vaccine"] ?? "");
$status = trim($_POST["status"] ?? "");
$next_vaccine = trim($_POST["next_vaccine"] ?? "");
$next_visit = trim($_POST["next_visit"] ?? "");

// ==========================================
// AUTOMATIC AGE CALCULATION
// ==========================================

// Calculate age from birthday, not from the form.
$age = calculateBabyAge($birthday);

// ==========================================
// VALIDATION
// ==========================================

if (
    $baby_name === "" ||
    $birthday === "" ||
    $guardian === "" ||
    $relationship === "" ||
    $phone_number === "" ||
    $status === ""
) {
    http_response_code(400);
    exit("Please complete all required fields.");
}

if ($age === "") {
    http_response_code(400);
    exit("Please enter a valid birthday.");
}

$validStatuses = [
    "Up to Date",
    "Pending",
    "Overdue",
    "Completed"
];

if (!in_array($status, $validStatuses, true)) {
    http_response_code(400);
    exit("Invalid vaccination status.");
}

if ($next_visit === "") {
    $next_visit = null;
} else {
    $visitDate = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $next_visit
    );

    if (
        !$visitDate ||
        $visitDate->format("Y-m-d") !== $next_visit
    ) {
        http_response_code(400);
        exit("Invalid next visit date.");
    }
}

// ==========================================
// INSERT PATIENT
// ==========================================

$sql = "
    INSERT INTO patients (
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
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    error_log("Prepare failed: " . $mysqli->error);
    http_response_code(500);
    exit("Unable to save patient.");
}

// All values are strings or NULL.
$stmt->bind_param(
    "ssssssssss",
    $baby_name,
    $age,
    $birthday,
    $guardian,
    $relationship,
    $phone_number,
    $last_vaccine,
    $status,
    $next_vaccine,
    $next_visit
);

// ==========================================
// SAVE AND REDIRECT
// ==========================================

if ($stmt->execute()) {

    $stmt->close();

    header("Location: ../pages/records.php");
    exit;

} else {

    error_log("Patient insert failed: " . $stmt->error);

    $stmt->close();

    http_response_code(500);
    exit("Unable to save patient. Please try again.");
}

?>
