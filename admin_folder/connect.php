
<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Detect whether hosted database settings are available.
$usingHostedDatabase = getenv("DB_HOST") !== false
    && getenv("DB_HOST") !== "";

// Hosted MySQL settings (Render).
// Fall back to local XAMPP settings during development.
$host = $usingHostedDatabase
    ? getenv("DB_HOST")
    : "localhost";

$username = $usingHostedDatabase
    ? getenv("DB_USER")
    : "root";

$password = $usingHostedDatabase
    ? getenv("DB_PASSWORD")
    : "";

$database = $usingHostedDatabase
    ? getenv("DB_NAME")
    : "immucare";

$port = $usingHostedDatabase
    ? (int) (getenv("DB_PORT") ?: 3306)
    : 3306;

try {

    $mysqli = new mysqli(
        $host,
        $username,
        $password,
        $database,
        $port
    );

    $mysqli->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {

    // Log technical details privately.
    error_log(
        "ImmuCare database connection error: "
        . $e->getMessage()
    );

    http_response_code(500);

    exit(
        "Unable to connect to the ImmuCare database. "
        . "Please contact the system administrator."
    );
}
