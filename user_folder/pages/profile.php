<?php

require_once __DIR__ . "/../backend/common.php";

$guardian = currentGuardian();
$avatarFilename = guardianAvatarFilename($guardian);
$avatarDirectory = __DIR__ . "/../uploads/avatars";
$avatarRelativePath = "uploads/avatars/";
$records = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($guardian === "") {
        http_response_code(403);
        setUserFlash("Sign in before changing your profile picture.", "error");
        header("Location: profile.php");
        exit;
    }

    if (!validateCsrfToken()) {
        http_response_code(400);
        setUserFlash("This form expired. Refresh the page and try again.", "error");
        header("Location: profile.php");
        exit;
    }

    $action = $_POST["action"] ?? "";
    if ($action === "remove") {
        if ($avatarFilename !== "") {
            $stmt = $mysqli->prepare("DELETE FROM guardian_profiles WHERE guardian = ?");
            $stmt->bind_param("s", $guardian);
            $stmt->execute();
            $stmt->close();

            $oldAvatarPath = $avatarDirectory . DIRECTORY_SEPARATOR . $avatarFilename;
            if (is_file($oldAvatarPath) && !unlink($oldAvatarPath)) {
                error_log("Unable to remove old guardian avatar: " . $oldAvatarPath);
            }
        }
        setUserFlash("Your profile picture has been removed.");
    } elseif ($action === "upload") {
        $upload = $_FILES["avatar"] ?? null;
        $uploadError = is_array($upload) && isset($upload["error"]) && is_int($upload["error"])
            ? $upload["error"]
            : UPLOAD_ERR_NO_FILE;
        $errorMessage = "";

        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMessage = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Choose an image smaller than 5 MB.",
                UPLOAD_ERR_NO_FILE => "Choose an image to upload.",
                default => "The image could not be uploaded. Please try again.",
            };
        } elseif (
            !is_array($upload)
            || !isset($upload["tmp_name"], $upload["size"])
            || !is_string($upload["tmp_name"])
            || !is_numeric($upload["size"])
            || !is_uploaded_file($upload["tmp_name"])
        ) {
            $errorMessage = "The uploaded image could not be verified.";
        } elseif ((int) $upload["size"] > 5 * 1024 * 1024) {
            $errorMessage = "Choose an image smaller than 5 MB.";
        } else {
            $imageInfo = @getimagesize($upload["tmp_name"]);
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($upload["tmp_name"]);
            $allowedImages = [
                "image/jpeg" => ["extension" => "jpg", "type" => IMAGETYPE_JPEG],
                "image/png" => ["extension" => "png", "type" => IMAGETYPE_PNG],
                "image/webp" => ["extension" => "webp", "type" => IMAGETYPE_WEBP],
            ];

            if (
                !is_array($imageInfo)
                || !is_string($mimeType)
                || !isset($allowedImages[$mimeType])
                || $imageInfo[2] !== $allowedImages[$mimeType]["type"]
                || $imageInfo[0] < 1
                || $imageInfo[1] < 1
                || $imageInfo[0] > 6000
                || $imageInfo[1] > 6000
            ) {
                $errorMessage = "Upload a valid JPEG, PNG, or WebP image no larger than 6000 × 6000 pixels.";
            }
        }

        if ($errorMessage === "") {
            if (!is_dir($avatarDirectory) && !@mkdir($avatarDirectory, 0755, true) && !is_dir($avatarDirectory)) {
                $errorMessage = "The profile picture could not be saved. Please try again later.";
            } elseif (!is_writable($avatarDirectory)) {
                $errorMessage = "The profile picture could not be saved. Please try again later.";
            } else {
                $extension = $allowedImages[$mimeType]["extension"];
                $newFilename = bin2hex(random_bytes(16)) . "." . $extension;
                $newAvatarPath = $avatarDirectory . DIRECTORY_SEPARATOR . $newFilename;

                if (!move_uploaded_file($upload["tmp_name"], $newAvatarPath)) {
                    $errorMessage = "The profile picture could not be saved. Please try again.";
                } else {
                    try {
                        $stmt = $mysqli->prepare("
                            INSERT INTO guardian_profiles (guardian, avatar_filename)
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE avatar_filename = VALUES(avatar_filename)
                        ");
                        $stmt->bind_param("ss", $guardian, $newFilename);
                        $saved = $stmt->execute();
                        $stmt->close();

                        if (!$saved) {
                            unlink($newAvatarPath);
                            $errorMessage = "The profile picture could not be saved. Please try again.";
                        } else {
                            if ($avatarFilename !== "") {
                                $oldAvatarPath = $avatarDirectory . DIRECTORY_SEPARATOR . $avatarFilename;
                                if (is_file($oldAvatarPath) && !unlink($oldAvatarPath)) {
                                    error_log("Unable to remove replaced guardian avatar: " . $oldAvatarPath);
                                }
                            }
                            setUserFlash("Your profile picture has been updated.");
                            header("Location: profile.php");
                            exit;
                        }
                    } catch (mysqli_sql_exception $exception) {
                        if (is_file($newAvatarPath)) {
                            unlink($newAvatarPath);
                        }
                        error_log("Guardian avatar update failed: " . $exception->getMessage());
                        $errorMessage = "The profile picture could not be saved. Please try again later.";
                    }
                }
            }
        }

        if ($errorMessage !== "") {
            setUserFlash($errorMessage, "error");
        }
    } else {
        http_response_code(400);
        setUserFlash("Choose a valid profile picture action.", "error");
    }

    header("Location: profile.php");
    exit;
}

if ($guardian !== "") {
    $stmt = $mysqli->prepare("
        SELECT baby_name, birthday, relationship, phone_number
        FROM patients
        WHERE guardian = ?
        ORDER BY baby_name ASC
    ");
    $stmt->bind_param("s", $guardian);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = "Edit profile";
$activePage = "profile";
require __DIR__ . "/../backend/page_header.php";

?>
<div class="profile-edit-topbar">
    <a class="profile-edit-back" href="../index.php" aria-label="Back to home">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <h1>Edit profile</h1>
</div>

<section class="profile-photo-editor" aria-label="Profile picture">
    <?php if ($guardian !== ""): ?>
        <form class="profile-photo-form" id="avatar-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(ensureCsrfToken()) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
            <input class="avatar-file-input" id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required>
            <span class="profile-photo-wrap">
                <button class="profile-photo-circle" type="button" data-open-photo-options aria-haspopup="dialog" aria-label="Profile photo options">
                    <?php if ($avatarFilename !== ""): ?>
                        <img class="profile-photo-image" src="../<?= escapeHtml($avatarRelativePath . $avatarFilename) ?>" alt="Your profile picture">
                    <?php else: ?>
                        <span class="profile-photo-placeholder" aria-hidden="true">👩🏻</span>
                    <?php endif; ?>
                </button>
                <button class="profile-photo-camera" type="button" data-open-photo-options aria-haspopup="dialog" aria-label="Open profile photo options">
                    <i class="fa-solid fa-camera"></i>
                </button>
            </span>
            <button class="profile-change-photo" type="button" data-open-photo-options aria-haspopup="dialog">Change photo</button>
            <button class="profile-photo-submit" type="submit">Save photo</button>
        </form>
    <?php else: ?>
        <div class="profile-photo-circle">
            <span class="profile-photo-placeholder" aria-hidden="true">👩🏻</span>
        </div>
        <a class="profile-change-photo" href="../user_login/">Sign in to change photo</a>
    <?php endif; ?>
    <p class="profile-photo-hint">JPEG, PNG, or WebP · up to 5 MB</p>
</section>

<?php if ($guardian !== ""): ?>
    <dialog class="profile-photo-dialog" id="profile-photo-dialog" aria-labelledby="profile-photo-dialog-title">
        <div class="profile-photo-dialog-heading">
            <h2 id="profile-photo-dialog-title">Profile photo</h2>
            <button class="profile-photo-dialog-close" type="button" data-close-photo-options aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <p>What would you like to do with your profile photo?</p>
        <div class="profile-photo-dialog-actions">
            <button class="primary-button" type="button" data-change-photo>
                <i class="fa-solid fa-camera"></i>
                <?= $avatarFilename !== "" ? "Change photo" : "Choose photo" ?>
            </button>
            <?php if ($avatarFilename !== ""): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(ensureCsrfToken()) ?>">
                    <input type="hidden" name="action" value="remove">
                    <button class="profile-remove-photo-button" type="submit">
                        <i class="fa-solid fa-trash-can"></i>
                        Remove photo
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </dialog>
<?php endif; ?>

<section class="profile-details-card">
    <h2>Guardian profile</h2>
    <dl class="detail-grid">
        <div><dt>Guardian</dt><dd><?= escapeHtml($guardian !== "" ? $guardian : "Not signed in") ?></dd></div>
        <div><dt>Linked children</dt><dd><?= count($records) ?></dd></div>
    </dl>
</section>

<section class="profile-details-card profile-children-card">
    <h2>Linked children</h2>
    <?php if ($records === [] && $guardian !== ""): ?>
        <div class="empty-state"><p>No children are linked to your account yet.</p></div>
    <?php endif; ?>
    <?php foreach ($records as $record): ?>
        <article class="simple-row">
            <div>
                <strong><?= escapeHtml((string) $record["baby_name"]) ?></strong>
                <span><?= escapeHtml((string) $record["relationship"]) ?></span>
            </div>
            <div class="align-right">
                <span><?= escapeHtml((string) ($record["birthday"] ?: "Birthday not recorded")) ?></span>
                <span><?= escapeHtml((string) ($record["phone_number"] ?: "Phone not recorded")) ?></span>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<script>
    document.querySelector("#avatar")?.addEventListener("change", (event) => {
        const input = event.currentTarget;
        if (input.files && input.files.length > 0) {
            input.form.requestSubmit();
        }
    });

    const avatarForm = document.querySelector("#avatar-form");
    const photoDialog = document.querySelector("#profile-photo-dialog");
    if (avatarForm) {
        avatarForm.querySelector(".profile-photo-submit").hidden = true;
    }
    if (avatarForm && photoDialog) {
        const avatarInput = avatarForm.querySelector("#avatar");
        document.querySelectorAll("[data-open-photo-options]").forEach((button) => {
            button.addEventListener("click", () => photoDialog.showModal());
        });
        photoDialog.querySelector("[data-close-photo-options]")?.addEventListener("click", () => photoDialog.close());
        photoDialog.querySelector("[data-change-photo]")?.addEventListener("click", () => {
            photoDialog.close();
            avatarInput.value = "";
            avatarInput.click();
        });
        photoDialog.addEventListener("click", (event) => {
            if (event.target === photoDialog) {
                photoDialog.close();
            }
        });
    }
</script>
<?php require __DIR__ . "/../backend/page_footer.php"; ?>
