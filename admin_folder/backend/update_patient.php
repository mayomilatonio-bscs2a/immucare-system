
<?php

require_once "../connect.php";
require_once "../age_helper.php";

// ==========================================
// ALLOW POST REQUESTS ONLY
// ==========================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/records.php");
    exit;
}

// ==========================================
// GET PATIENT INFORMATION
// ==========================================

$id = filter_input(
    INPUT_POST,
    "id",
    FILTER_VALIDATE_INT
);

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

// Calculate age from birthday.
// Do not trust age submitted by the form.

$age = calculateBabyAge($birthday);

// ==========================================
// VALIDATE INPUT
// ==========================================

if (!$id || $id < 1) {
    http_response_code(400);
    exit("Invalid patient ID.");
}

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

// ==========================================
// VALIDATE NEXT VISIT
// ==========================================

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
// UPDATE PATIENT
// ==========================================

$sql = "
    UPDATE patients
    SET
        baby_name = ?,
        age = ?,
        birthday = ?,
        guardian = ?,
        relationship = ?,
        phone_number = ?,
        last_vaccine = ?,
        status = ?,
        next_vaccine = ?,
        next_visit = ?
    WHERE id = ?
";

$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    error_log("Prepare failed: " . $mysqli->error);
    http_response_code(500);
    exit("Unable to update patient.");
}

$stmt->bind_param(
    "ssssssssssi",
    $baby_name,
    $age,
    $birthday,
    $guardian,
    $relationship,
    $phone_number,
    $last_vaccine,
    $status,
    $next_vaccine,
    $next_visit,
    $id
);

// ==========================================
// EXECUTE UPDATE
// ==========================================

if ($stmt->execute()) {

    $stmt->close();

    header("Location: ../pages/records.php");
    exit;

} else {

    error_log("Patient update failed: " . $stmt->error);

    $stmt->close();

    http_response_code(500);
    exit("Unable to update patient. Please try again.");
}

?>
