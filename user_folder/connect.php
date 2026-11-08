<?php

/* You should enable error reporting for mysqli before attempting to make a connection */
// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = new mysqli('localhost', 'root','', 'immucare');

/* Set the desired charset after establishing a connection */
$mysqli->set_charset('utf8mb4');

// printf("Success... %s\n", $mysqli->host_info);

// $result = $mysqli->query("SELECT * FROM `patients`");
// printf("Select returned %d rows.\n", $result->num_rows);

// $rows = $result->fetch_all(MYSQLI_ASSOC);
// foreach ($rows as $row) {
//     printf("%s (%s)\n", $row["id"], $row["baby_name"]);

// }