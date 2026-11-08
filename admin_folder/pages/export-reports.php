
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

require_once __DIR__ . "/../../user_folder/backend/common.php";

// Select report type.
$type = $_GET["type"] ?? "";

if (!is_string($type) ||
    !in_array($type, ["vaccinations", "appointments"], true)) {
    http_response_code(400);
    exit("Invalid report type.");
}

// Read date filters.
$startDate = $_GET["start_date"] ?? "";
$endDate = $_GET["end_date"] ?? "";

function validExportDate($date) {
    if (!is_string($date)) {
        return false;
    }

    if ($date === "") {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);

    return $parsed !== false
        && $parsed->format("Y-m-d") === $date;
}

if (
    !validExportDate($startDate) ||
    !validExportDate($endDate) ||
    ($startDate !== "" && $endDate !== "" && $startDate > $endDate)
) {
    http_response_code(400);
    exit("Invalid date range.");
}

// Prevent spreadsheet programs from interpreting
// user-controlled text as formulas.
function safeCsvValue($value) {

    $value = (string) ($value ?? "");

    if (preg_match('/^\s*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

try {

    if ($type === "vaccinations") {

$sql = "
    SELECT
        p.baby_name,
        p.guardian,
        vr.vaccine,
        vr.date_given,
        vr.remarks
    FROM vaccination_records vr
    INNER JOIN patients p
        ON p.id = vr.patient_id
    WHERE vr.deleted_at IS NULL
";

    } else {

        $sql = "
            SELECT
                p.baby_name,
                p.guardian,
                a.vaccine,
                a.appointment_date,
                a.appointment_time,
                a.status
            FROM appointments a
            INNER JOIN patients p
                ON p.id = a.patient_id
            WHERE 1 = 1
        ";
    }

    $dateColumn = $type === "vaccinations"
        ? "vr.date_given"
        : "a.appointment_date";

    $types = "";
    $params = [];

    if ($startDate !== "") {
        $sql .= " AND $dateColumn >= ?";
        $types .= "s";
        $params[] = $startDate;
    }

    if ($endDate !== "") {
        $sql .= " AND $dateColumn <= ?";
        $types .= "s";
        $params[] = $endDate;
    }

    $sql .= " ORDER BY $dateColumn DESC";

    $stmt = $mysqli->prepare($sql);

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $result = $stmt->get_result();

} catch (mysqli_sql_exception $e) {

    error_log("Report export failed: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to export report.");
}

// Generate filename.
$filename = "ImmuCare_" . $type . "_"
    . date("Y-m-d_H-i-s") . ".csv";

// CSV download headers.
header("Content-Type: text/csv; charset=UTF-8");
header('Content-Disposition: attachment; filename="' . $filename . '"');
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");

// Write CSV output.
$output = fopen("php://output", "w");

// UTF-8 BOM for Excel compatibility.
fwrite($output, "\xEF\xBB\xBF");

if ($type === "vaccinations") {

    fputcsv($output, [
        "Infant Name",
        "Guardian",
        "Vaccine",
        "Date Given",
        "Remarks"
    ]);

} else {

    fputcsv($output, [
        "Infant Name",
        "Guardian",
        "Vaccine",
        "Appointment Date",
        "Appointment Time",
        "Status"
    ]);
}

while ($row = $result->fetch_assoc()) {

    $safeRow = array_map("safeCsvValue", array_values($row));

    fputcsv($output, $safeRow);
}

fclose($output);

$result->free();
$stmt->close();

exit;
