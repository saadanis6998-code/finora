<?php


header("X-Content-Type-Options: nosniff");

header("X-Frame-Options: SAMEORIGIN");

header("Referrer-Policy: strict-origin-when-cross-origin");

header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

session_start();

require_once "config/db.php";

// Already logged in? Go straight to the dashboard.
if (!empty($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

// ---------- Settings ----------
const MAX_ATTEMPTS = 5;      // wrong tries allowed
const LOCK_SECONDS = 60;     // lock time after too many wrong tries

// A valid bcrypt hash used when the email doesn't exist, so response time
// stays the same and attackers can't guess which emails are registered.
const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

$errors = [];
$email = "";
$registered = isset($_GET["registered"]) && $_GET["registered"] === "1";

// ---------- CSRF token ----------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// ---------- Lock status ----------
$locked_for = 0;

if (!empty($_SESSION["lock_until"])) {
    if ($_SESSION["lock_until"] > time()) {
        $locked_for = $_SESSION["lock_until"] - time();
    } else {
        unset($_SESSION["lock_until"], $_SESSION["login_attempts"]);
    }
}

// ---------- Handle login ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $token = $_POST["csrf_token"] ?? "";

    if ($locked_for > 0) {

        $errors[] = "Too many attempts. Please try again in {$locked_for} seconds.";

    } elseif (!hash_equals($_SESSION["csrf_token"], $token)) {

        $errors[] = "Your session expired. Please try again.";
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

    } else {

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address.";
        }

        if ($password === "") {
            $errors[] = "Please enter your password.";
        }

        if (empty($errors)) {

            $sql = "SELECT id, name, password FROM users WHERE email = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $sql);

            if (!$stmt) {

                $errors[] = "Something went wrong. Please try again.";

            } else {

                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);

                $result = mysqli_stmt_get_result($stmt);
                $user = mysqli_fetch_assoc($result);

                mysqli_stmt_close($stmt);

                $hash = $user["password"] ?? DUMMY_HASH;
                $valid = password_verify($password, $hash) && $user;

                if ($valid) {

                    // Upgrade old hashes automatically
                    if (password_needs_rehash($user["password"], PASSWORD_DEFAULT)) {
                        $new_hash = password_hash($password, PASSWORD_DEFAULT);
                        $up = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");

                        if ($up) {
                            mysqli_stmt_bind_param($up, "si", $new_hash, $user["id"]);
                            mysqli_stmt_execute($up);
                            mysqli_stmt_close($up);
                        }
                    }

                    // Fresh session ID prevents session fixation
                    session_regenerate_id(true);

                    unset($_SESSION["login_attempts"], $_SESSION["lock_until"]);

                    $_SESSION["user_id"] = (int) $user["id"];
                    $_SESSION["user_name"] = $user["name"];
                    $_SESSION["logged_in_at"] = time();
                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                    header("Location: dashboard.php");
                    exit;

                } else {

                    $_SESSION["login_attempts"] = ($_SESSION["login_attempts"] ?? 0) + 1;

                    if ($_SESSION["login_attempts"] >= MAX_ATTEMPTS) {
                        $_SESSION["lock_until"] = time() + LOCK_SECONDS;
                        $locked_for = LOCK_SECONDS;
                        $errors[] = "Too many attempts. Please try again in " . LOCK_SECONDS . " seconds.";
                    } else {
                        // Same message for wrong email OR wrong password
                        $errors[] = "Incorrect email or password.";
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b1424">

    <title>Sign In | Finora</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/login.css">
    <script src="assets/login.js" defer></script>
</head>

<body>

<!-- ============ WELCOME INTRO (2 SECONDS) ============ -->
<div class="welcome-overlay" id="welcomeOverlay" aria-hidden="true">
    <div class="welcome-content">
        <div class="welcome-logo">finora<span>.</span></div>
        <div class="welcome-line"><i></i></div>
        <p class="welcome-text">WELCOME BACK</p>
    </div>
</div>

<main class="auth-container">

    <!-- ============ LEFT: BRAND PANEL ============ -->
    <section class="brand-panel" id="brandPanel">

        <div class="aurora aurora-1"></div>
        <div class="aurora aurora-2"></div>
        <div class="brand-grid"></div>
        <div class="brand-grain"></div>

        <a href="login.php" class="brand-logo reveal" style="--d:0s">
            finora<span>.</span>
        </a>

        <div class="brand-content">

            <div class="brand-badge reveal" style="--d:.08s">
                <span class="status-dot"></span>
                SECURE SIGN IN
            </div>

            <h1 class="reveal" style="--d:.16s">
                Welcome back to<br>
                your <span>money.</span>
            </h1>

            <p class="brand-description reveal" style="--d:.24s">
                Your numbers are right where you left them. Sign in to see
                where your money went and where it's heading next.
            </p>

            <div class="preview-stage reveal" style="--d:.36s" id="previewStage">

                <div class="preview-card">

                    <div class="preview-header">
                        <div>
                            <p class="preview-label">THIS MONTH</p>
                            <h3 class="preview-title">Spending Overview</h3>
                        </div>

                        <div class="preview-icon">₨</div>
                    </div>

                    <div class="mix-list">

                        <div class="mix-row" style="--w:62%; --k:0">
                            <div class="mix-top"><span>Groceries</span><b>62%</b></div>
                            <div class="mix-track"><i></i></div>
                        </div>

                        <div class="mix-row" style="--w:38%; --k:1">
                            <div class="mix-top"><span>Transport</span><b>38%</b></div>
                            <div class="mix-track"><i></i></div>
                        </div>

                        <div class="mix-row" style="--w:81%; --k:2">
                            <div class="mix-top"><span>Savings</span><b>81%</b></div>
                            <div class="mix-track"><i></i></div>
                        </div>

                    </div>

                    <div class="chart-footer">
                        <span>Pick up where you left off.</span>
                        <span class="chart-indicator">● FINORA</span>
                    </div>

                </div>

                <div class="float-chip chip-goal">
                    <span class="chip-icon green">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                    </span>
                    <div>
                        <p>BUDGET</p>
                        <strong>On track</strong>
                    </div>
                </div>

                <div class="float-chip chip-spend">
                    <span class="chip-icon green">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                    </span>
                    <div>
                        <p>SAVED THIS WEEK</p>
                        <strong>+ ₨ 3,500</strong>
                    </div>
                </div>

            </div>
        </div>

        <div class="brand-footer reveal" style="--d:.5s">
            <span>PERSONAL FINANCE, MADE SIMPLE.</span>
            <span>© 2026 FINORA</span>
        </div>

    </section>

    <!-- ============ RIGHT: FORM PANEL ============ -->
    <section class="form-panel">

        <div class="form-wrapper">

            <div class="mobile-logo reveal" style="--d:0s">
                finora<span>.</span>
            </div>

            <div class="form-heading">
                <p class="form-eyebrow reveal" style="--d:.08s">WELCOME BACK</p>
                <h2 class="reveal" style="--d:.14s">Sign in to your account</h2>
                <p class="form-subtitle reveal" style="--d:.2s">
                    Enter your details to continue tracking your money.
                </p>
            </div>

            <?php if ($registered && empty($errors)): ?>
                <div class="success-message reveal" style="--d:.24s" role="status">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.8"/></svg>
                    <div>
                        <p><strong>Account created!</strong> Sign in to get started.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-message" role="alert">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/></svg>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p><?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form
                action="login.php"
                method="POST"
                id="loginForm"
                data-lock="<?= (int) $locked_for ?>"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION["csrf_token"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <div class="input-group reveal" style="--d:.28s">
                    <label for="email">Email address</label>
                    <div class="field">
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 8 8 6 8-6"/></svg>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="you@example.com"
                            value="<?= htmlspecialchars(
                                $email,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            autocomplete="email"
                            required
                        >
                    </div>
                </div>

                <div class="input-group reveal" style="--d:.36s">
                    <label for="password">Password</label>
                    <div class="field">
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="toggle-pass" data-target="password" aria-label="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"/><path d="M6.6 6.7A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.4-1"/></svg>
                        </button>
                    </div>
                    <p class="caps-hint" id="capsHint" aria-live="polite">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4 5 12h4v4h6v-4h4z"/><path d="M9 20h6"/></svg>
                        Caps Lock is on
                    </p>
                </div>

                <div class="reveal" style="--d:.44s">
                    <button type="submit" name="login" id="submitBtn">
                        <span class="btn-label">Sign In</span>
                        <span class="button-arrow">&rarr;</span>
                        <span class="btn-spinner" aria-hidden="true"></span>
                    </button>
                </div>

            </form>

            <p class="login-link reveal" style="--d:.52s">
                New to Finora?
                <a href="register.php">Create an account</a>
            </p>

            <div class="security-note reveal" style="--d:.6s">
                <span class="security-icon">&#10003;</span>
                Your financial information deserves privacy.
            </div>

        </div>

    </section>

</main>

</body>
</html>