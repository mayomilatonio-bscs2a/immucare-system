
<?php
require_once __DIR__ . "/connect.php";

// Only allow requests from the local computer.
$ip = $_SERVER["REMOTE_ADDR"] ?? "";

if (!in_array($ip, ["127.0.0.1", "::1"], true)) {
    http_response_code(403);
    exit("Local access only.");
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($name === "" || $username === "" || strlen($password) < 12) {
        $error = "Enter a name, username, and password of at least 12 characters.";
    } else {
        try {
            $mysqli->begin_transaction();

            $check = $mysqli->query(
                "SELECT id FROM users LIMIT 1 FOR UPDATE"
            );

            if ($check->num_rows > 0) {
                $error = "A staff account already exists. Setup is disabled.";
                $mysqli->rollback();
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $mysqli->prepare(
                    "INSERT INTO users
                    (full_name, username, password, role, status)
                    VALUES (?, ?, ?, 'Administrator', 'Active')"
                );

                $stmt->bind_param("sss", $name, $username, $hash);
                $stmt->execute();
                $stmt->close();

                $mysqli->commit();
                $success = "Administrator created successfully. Delete setup_admin.php now.";
            }
        } catch (mysqli_sql_exception $e) {
            $mysqli->rollback();
            error_log($e->getMessage());
            $error = "Unable to create Administrator account.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ImmuCare Admin Setup</title>
</head>
<body>
    <h2>Create ImmuCare Administrator</h2>

    <?php if ($error): ?>
        <p><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p><?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?></p>
    <?php else: ?>
        <form method="post">
            <p>
                <input type="text" name="full_name"
                       placeholder="Full Name" required>
            </p>
            <p>
                <input type="text" name="username"
                       placeholder="Username" required>
            </p>
            <p>
                <input type="password" name="password"
                       placeholder="Password (12+ characters)" required>
            </p>
            <button type="submit">Create Administrator</button>
        </form>
    <?php endif; ?>
</body>
</html>
