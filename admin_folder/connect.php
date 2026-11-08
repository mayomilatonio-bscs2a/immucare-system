<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv("DB_HOST") ?: "localhost";
$port = (int) (getenv("DB_PORT") ?: 3306);
$username = getenv("DB_USER") ?: "root";
$password = getenv("DB_PASSWORD") ?: "";
$database = getenv("DB_NAME") ?: "immucare";

try {
    $mysqli = mysqli_init();

    if ($mysqli === false) {
        throw new RuntimeException(
            "Unable to initialize MySQL connection."
        );
    }

    if (getenv("DB_HOST")) {
        // Require encrypted transport for the hosted database.
        $mysqli->ssl_set(null, null, null, null, null);

        $mysqli->real_connect(
            $host,
            $username,
            $password,
            $database,
            $port,
            null,
            MYSQLI_CLIENT_SSL
        );

        // Confirm the connection is encrypted.
        $result = $mysqli->query(
            "SHOW STATUS LIKE 'Ssl_cipher'"
        );

        $row = $result->fetch_assoc();

        if (empty($row["Value"])) {
            throw new RuntimeException(
                "The database connection is not encrypted."
            );
        }
    } else {
        // Local XAMPP connection.
        $mysqli->real_connect(
            $host,
            $username,
            $password,
            $database,
            $port
        );
    }

    $mysqli->set_charset("utf8mb4");

} catch (Throwable $e) {
    error_log("ImmuCare DB error: " . $e->getMessage());

    http_response_code(503);

    exit(
        "Unable to connect to the ImmuCare database. " .
        "Please contact the system administrator."
    );
}
