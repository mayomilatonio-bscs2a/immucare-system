
<?php
require_once __DIR__ . "/auth.php";

if (!empty($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION["login_csrf"])) {
    $_SESSION["login_csrf"] = bin2hex(random_bytes(32));
}

$error = $_SESSION["login_error"] ?? "";
unset($_SESSION["login_error"]);

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Admin Login | ImmuCare</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e9efec;
            font-family: Arial, sans-serif;
            color: #315f68;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 35px;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.07);
        }

        .logo {
            display: block;
            width: 85px;
            height: 85px;
            object-fit: contain;
            margin: 0 auto 15px;
        }

        h1, .subtitle {
            text-align: center;
        }

        h1 {
            margin: 0 0 8px;
        }

        .subtitle {
            color: #718c8e;
            margin-bottom: 30px;
        }

        label {
            display: block;
            margin: 15px 0 8px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #dcebea;
            border-radius: 10px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #58af98;
        }

        button {
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #58af98;
            color: white;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: #438e77;
        }

        .error {
            padding: 12px;
            background: #fff0f0;
            color: #a33;
            border-radius: 9px;
            margin-bottom: 15px;
        }
    </style>
</head>

<body>

<div class="login-card">

    <img src="images/logo.png"
         alt="ImmuCare Logo"
         class="logo">

    <h1>ImmuCare</h1>

    <p class="subtitle">
        RHU Administrator Login
    </p>

    <?php if ($error !== ""): ?>
        <div class="error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form action="backend/login_process.php"
          method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION["login_csrf"]) ?>"
        >

        <label for="username">Username</label>

        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            required
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            Login
        </button>

    </form>

</div>

</body>
</html>
