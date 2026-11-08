<?php

require_once "../auth.php";
require_once "../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../login.php");
    exit;
}

function loginFailed() {
    $_SESSION["login_error"] =
        "Invalid username or password.";

    header("Location: ../login.php");
    exit;
}

$token = $_POST["csrf_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["login_csrf"]) ||
    !hash_equals($_SESSION["login_csrf"], $token)
) {
    $_SESSION["login_error"] =
        "Invalid request. Please try again.";

    header("Location: ../login.php");
    exit;
}

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if (
    $username === "" ||
    !is_string($password) ||
    $password === ""
) {
    loginFailed();
}

$stmt = $mysqli->prepare("
    SELECT id, full_name, username, password, role
    FROM users
    WHERE username = ?
      AND status = 'Active'
    LIMIT 1
");

$stmt->bind_param("s", $username);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (
    !$user ||
    !password_verify($password, $user["password"])
) {
    loginFailed();
}

// Login successful.
session_regenerate_id(true);

$_SESSION["user_id"] = (int) $user["id"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["username"] = $user["username"];
$_SESSION["role"] = $user["role"];

unset($_SESSION["login_error"]);
unset($_SESSION["login_csrf"]);

header("Location: ../index.php");
exit;
