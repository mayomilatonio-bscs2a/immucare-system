<?php

require_once __DIR__ . "/../backend/common.php";

$guardian = currentGuardian();
$categories = ["Question", "Suggestion", "Problem"];
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $category = $_POST["category"] ?? "";
    $message = $_POST["message"] ?? "";

    if ($guardian === "") {
        http_response_code(401);
        $error = "Sign in before sending feedback.";
    } elseif (!validateCsrfToken()) {
        http_response_code(400);
        $error = "This form expired. Reload the page and try again.";
    } elseif (
        !is_string($category)
        || !in_array($category, $categories, true)
        || !is_string($message)
        || trim($message) === ""
        || strlen(trim($message)) > 2000
    ) {
        $error = "Choose a category and enter a message of up to 2,000 characters.";
    } else {
        $message = trim($message);
        $stmt = $mysqli->prepare("
            INSERT INTO user_feedback (guardian, category, message)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("sss", $guardian, $category, $message);
        $stmt->execute();
        $stmt->close();

        setUserFlash("Your feedback was sent to the clinic team.");
        header("Location: send-feedback.php");
        exit;
    }
}

$feedback = [];
if ($guardian !== "") {
    $stmt = $mysqli->prepare("
        SELECT category, message, status, created_at
        FROM user_feedback
        WHERE guardian = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("s", $guardian);
    $stmt->execute();
    $feedback = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = "Send Feedback";
$activePage = "settings";
require __DIR__ . "/../backend/page_header.php";

?>
<section class="content-card">
    <p class="page-intro">Send a question, suggestion, or problem to the clinic team. Your message is visible to ImmuCare administrators.</p>
    <?php if ($error !== ""): ?>
        <div class="notice notice-error" role="alert"><?= escapeHtml($error) ?></div>
    <?php endif; ?>
    <?php if ($guardian !== ""): ?>
        <form class="form-card" method="post" action="send-feedback.php">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(ensureCsrfToken()) ?>">
            <label>
                Feedback type
                <select name="category" required>
                    <option value="">Choose a type</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= escapeHtml($category) ?>" <?= ($_POST["category"] ?? "") === $category ? "selected" : "" ?>>
                            <?= escapeHtml($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Message
                <textarea name="message" rows="5" maxlength="2000" required><?= escapeHtml(is_string($_POST["message"] ?? null) ? (string) $_POST["message"] : "") ?></textarea>
            </label>
            <p class="muted">Maximum 2,000 characters.</p>
            <button class="primary-button" type="submit">
                <i class="fa-solid fa-paper-plane"></i> Send to clinic
            </button>
        </form>
    <?php endif; ?>
</section>

<?php if ($feedback !== []): ?>
    <section class="content-card">
        <h2>Your recent feedback</h2>
        <div class="notice-list">
            <?php foreach ($feedback as $item): ?>
                <article class="notice <?= $item["status"] === "Resolved" ? "notice-info" : "" ?>">
                    <h2><?= escapeHtml((string) $item["category"]) ?> · <?= escapeHtml((string) $item["status"]) ?></h2>
                    <p><?= nl2br(escapeHtml((string) $item["message"])) ?></p>
                    <p class="muted"><?= escapeHtml(date("M j, Y g:i A", strtotime((string) $item["created_at"]))) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . "/../backend/page_footer.php"; ?>
