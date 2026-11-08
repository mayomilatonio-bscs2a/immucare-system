<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$currentGuardian = isset($_SESSION["guardian"]) && is_string($_SESSION["guardian"])
    ? trim($_SESSION["guardian"])
    : "";

if ($currentGuardian !== "") {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../backend/guardian_accounts.php";
ensureGuardianAccountsTable($mysqli);

if (!isset($_SESSION["login_csrf_token"]) || !is_string($_SESSION["login_csrf_token"])) {
    $_SESSION["login_csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$username = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    $username = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION["login_csrf_token"], $submittedToken)
    ) {
        http_response_code(400);
        $error = "This sign-in form expired. Refresh the page and try again.";
    } elseif (
        !is_string($username)
        || !is_string($password)
        || trim($username) === ""
        || $password === ""
    ) {
        $error = "Enter your username and password.";
    } else {
        $username = trim($username);
        $stmt = $mysqli->prepare("
            SELECT guardian, password_hash
            FROM guardian_accounts
            WHERE username = ?
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (is_array($account) && password_verify($password, (string) $account["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["guardian"] = (string) $account["guardian"];
            unset($_SESSION["login_csrf_token"]);
            header("Location: ../index.php");
            exit;
        }

        $error = "Those sign-in details couldn't be verified. Check them or contact the clinic.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ImmuCare - User Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-mint: #e8f7f5;
            --accent-mint: #d0f0eb;
            --mint-dark: #2d6a68;
            --pink-pastel: #ffeef2;
            --pink-accent: #f28599;
            --blue-pastel: #e3f2fd;
            --blue-accent: #3381a3;
            --text-dark: #2c3e50;
            --text-muted: #78909c;
            --white: #ffffff;
            --shadow: 0 12px 35px rgba(45, 106, 104, 0.08);
            --border-radius: 24px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg-mint);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow-x: hidden;
        }

        body::before, body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            z-index: 0;
            filter: blur(50px);
            opacity: 0.65;
        }

        body::before {
            width: 340px;
            height: 340px;
            background-color: #ffd6e0;
            top: -60px;
            left: -60px;
        }

        body::after {
            width: 380px;
            height: 380px;
            background-color: #d0e8ff;
            bottom: -90px;
            right: -90px;
        }

        .user-card {
            position: relative;
            z-index: 1;
            background: var(--white);
            width: 100%;
            max-width: 460px;
            padding: 42px 36px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.9);
        }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .brand-logo-container {
            position: relative;
            display: inline-block;
            margin-bottom: 12px;
        }

        .brand-logo-badge {
            width: auto;
            height: auto;
            background: transparent;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            overflow: visible;
        }

        .brand-logo-badge img {
            width: min(220px, 72vw);
            height: auto;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 8px 12px rgba(45, 106, 104, 0.08));
        }

        .brand-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--mint-dark);
            letter-spacing: -0.5px;
        }

        .brand-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .error-message {
            margin: 0 0 18px;
            padding: 12px 14px;
            border: 1px solid #f0c6d4;
            border-radius: 12px;
            background: #fff4f7;
            color: #865969;
            font-size: 13px;
            line-height: 1.45;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            color: var(--text-muted);
            font-size: 15px;
        }

        .form-input {
            width: 100%;
            padding: 13px 16px 13px 46px;
            background-color: var(--bg-mint);
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
            outline: none;
            transition: all 0.25s ease;
        }

        .form-input:focus {
            background-color: var(--white);
            border-color: var(--accent-mint);
            box-shadow: 0 0 0 4px rgba(208, 240, 235, 0.6);
        }

        .form-input::placeholder {
            color: #a0aec0;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            cursor: pointer;
            font-weight: 500;
        }

        .remember-me input {
            accent-color: var(--mint-dark);
            width: 16px;
            height: 16px;
            border-radius: 4px;
            cursor: pointer;
        }

        .forgot-link {
            color: var(--blue-accent);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.2s;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background-color: var(--mint-dark);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(45, 106, 104, 0.25);
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            opacity: 0.93;
            transform: translateY(-1px);
        }

        .switch-prompt {
            text-align: center;
            margin-top: 22px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .switch-prompt a {
            color: var(--mint-dark);
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .switch-prompt a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            body {
                padding: 18px;
            }

            .user-card {
                padding: 30px 22px;
            }

            .brand-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="user-card">
        <div class="brand-header">
            <div class="brand-logo-container">
                <div class="brand-logo-badge">
                    <img src="../images/logo.png" alt="ImmuCare Logo">
                </div>
            </div>
        </div>

        <?php if ($error !== ""): ?>
            <div class="error-message" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <div>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["login_csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fa-regular fa-user"></i>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-input"
                            value="<?= htmlspecialchars($username, ENT_QUOTES, "UTF-8") ?>"
                            maxlength="100"
                            autocomplete="username"
                            placeholder="Enter your username"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-lock"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            required
                        >
                    </div>
                </div>

                <div class="form-actions">
                    <label class="remember-me">
                        <input type="checkbox" id="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</body>
</html>
