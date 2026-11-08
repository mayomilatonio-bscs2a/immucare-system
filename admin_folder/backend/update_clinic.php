
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../connect.php";

function clinicRedirect($message, $type = "error") {
    $_SESSION["clinic_message"] = $message;
    $_SESSION["clinic_message_type"] = $type;

    header("Location: ../pages/settings.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/settings.php");
    exit;
}

$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["settings_csrf"]) ||
    !hash_equals($_SESSION["settings_csrf"], $token)
) {
    clinicRedirect("Invalid request. Please refresh and try again.");
}

$fields = [
    "clinic_name" => 150,
    "address" => 255,
    "contact_number" => 30,
    "email" => 150,
    "operating_hours" => 150
];

$data = [];

foreach ($fields as $field => $maxLength) {
    $value = $_POST[$field] ?? "";

    if (!is_string($value)) {
        clinicRedirect("Invalid clinic information.");
    }

    $value = trim($value);

    if (mb_strlen($value) > $maxLength) {
        clinicRedirect("One or more fields exceed the allowed length.");
    }

    $data[$field] = $value;
}

if ($data["clinic_name"] === "") {
    clinicRedirect("Clinic name is required.");
}

if (
    $data["email"] !== "" &&
    !filter_var($data["email"], FILTER_VALIDATE_EMAIL)
) {
    clinicRedirect("Please enter a valid email address.");
}

try {

    $stmt = $mysqli->prepare("
        INSERT INTO clinic_settings
            (
                id,
                clinic_name,
                address,
                contact_number,
                email,
                operating_hours
            )
        VALUES (1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            clinic_name = VALUES(clinic_name),
            address = VALUES(address),
            contact_number = VALUES(contact_number),
            email = VALUES(email),
            operating_hours = VALUES(operating_hours)
    ");

    $stmt->bind_param(
        "sssss",
        $data["clinic_name"],
        $data["address"],
        $data["contact_number"],
        $data["email"],
        $data["operating_hours"]
    );

    $stmt->execute();
    $stmt->close();

    clinicRedirect(
        "Clinic information updated successfully.",
        "success"
    );

} catch (Throwable $e) {

    error_log("Clinic settings error: " . $e->getMessage());

    clinicRedirect("Unable to save clinic information.");
}
