<?php
session_start();

require_once "config/db.php";

$errors = [];
$name = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    // Validate name
    if ($name === "") {
        $errors[] = "Name is required.";
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Validate password
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Check existing email
    if (empty($errors)) {

        $check_sql = "SELECT id FROM users WHERE email = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);

        if (!$check_stmt) {
            $errors[] = "Something went wrong. Please try again.";
        } else {
            mysqli_stmt_bind_param($check_stmt, "s", $email);
            mysqli_stmt_execute($check_stmt);

            $result = mysqli_stmt_get_result($check_stmt);

            if (mysqli_num_rows($result) > 0) {
                $errors[] = "This email is already registered.";
            }

            mysqli_stmt_close($check_stmt);
        }
    }

    // Create account
    if (empty($errors)) {

        $hashed_password = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $insert_sql = "INSERT INTO users (name, email, password)
                       VALUES (?, ?, ?)";

        $insert_stmt = mysqli_prepare($conn, $insert_sql);

        if (!$insert_stmt) {
            $errors[] = "Something went wrong. Please try again.";
        } else {

            mysqli_stmt_bind_param(
                $insert_stmt,
                "sss",
                $name,
                $email,
                $hashed_password
            );

            if (mysqli_stmt_execute($insert_stmt)) {

                mysqli_stmt_close($insert_stmt);

                header("Location: login.php?registered=1");
                exit;

            } else {
                $errors[] = "Account could not be created. Please try again.";
                mysqli_stmt_close($insert_stmt);
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

    <title>Create Account | Finora</title>

    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/script.js" defer></script>
</head>

<body>

<!-- ============ FINORA INTRO (4 SECONDS) ============ -->
<div class="intro-overlay" id="introOverlay" aria-hidden="true">
    <div class="intro-grid"></div>
    <div class="intro-glow"></div>

    <div class="intro-content">
        <div class="intro-ring"></div>

        <div class="intro-logo">
            <span class="l" style="--i:0">f</span><span class="l" style="--i:1">i</span><span class="l" style="--i:2">n</span><span class="l" style="--i:3">o</span><span class="l" style="--i:4">r</span><span class="l" style="--i:5">a</span><span class="l dot" style="--i:6">.</span>
        </div>

        <p class="intro-tagline">PERSONAL FINANCE, MADE SIMPLE.</p>

        <div class="intro-progress"><i class="intro-bar"></i></div>

        <div class="intro-meta">
            <span>PREPARING YOUR SPACE</span>
            <span id="introCount">0%</span>
        </div>
    </div>
</div>

<main class="auth-container">

    <!-- ============ LEFT: BRAND PANEL ============ -->
    <section class="brand-panel" id="brandPanel">

        <div class="aurora aurora-1"></div>
        <div class="aurora aurora-2"></div>
        <div class="brand-grid"></div>
        <div class="brand-grain"></div>

        <a href="register.php" class="brand-logo reveal" style="--d:0s">
            finora<span>.</span>
        </a>

        <div class="brand-content">

            <div class="brand-badge reveal" style="--d:.1s">
                <span class="status-dot"></span>
                YOUR FINANCIAL JOURNEY STARTS HERE
            </div>

            <h1 class="reveal" style="--d:.2s">
                Take control of<br>
                your <span>money.</span>
            </h1>

            <p class="brand-description reveal" style="--d:.3s">
                A clearer view of your money starts with a single step.
                Track your spending, understand your habits, and make
                every rupee count.
            </p>

            <div class="preview-stage reveal" style="--d:.45s" id="previewStage">

                <div class="preview-card">

                    <div class="preview-header">
                        <div>
                            <p class="preview-label">TOTAL BALANCE</p>
                            <h3 class="preview-amount">
                                <small>₨</small><span id="countUp" data-count="248500">248,500</span>
                            </h3>
                        </div>

                        <div class="preview-icon">₨</div>
                    </div>

                    <div class="preview-trend">
                        <span class="trend-chip">&#9650; 12.4%</span>
                        <span class="trend-text">vs last month</span>
                    </div>

                    <div class="preview-chart">
                        <div class="chart-bar bar-1"></div>
                        <div class="chart-bar bar-2"></div>
                        <div class="chart-bar bar-3"></div>
                        <div class="chart-bar bar-4"></div>
                        <div class="chart-bar bar-5"></div>
                        <div class="chart-bar bar-6"></div>
                        <div class="chart-bar bar-7"></div>
                        <div class="chart-bar bar-8"></div>
                    </div>

                    <div class="chart-footer">
                        <span>Track every transaction.</span>
                        <span class="chart-indicator">● FINORA</span>
                    </div>

                </div>

                <!-- floating chip: savings goal -->
                <div class="float-chip chip-goal">
                    <svg class="ring" viewBox="0 0 36 36" aria-hidden="true">
                        <circle class="ring-bg" cx="18" cy="18" r="15"></circle>
                        <circle class="ring-fg" cx="18" cy="18" r="15"></circle>
                    </svg>
                    <div>
                        <p>SAVINGS GOAL</p>
                        <strong>72% reached</strong>
                    </div>
                </div>

                <!-- floating chip: expense -->
                <div class="float-chip chip-spend">
                    <span class="chip-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>
                    </span>
                    <div>
                        <p>GROCERIES</p>
                        <strong>− ₨ 4,200</strong>
                    </div>
                </div>

            </div>
        </div>

        <div class="brand-footer reveal" style="--d:.6s">
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
                <p class="form-eyebrow reveal" style="--d:.1s">GET STARTED — IT'S FREE</p>
                <h2 class="reveal" style="--d:.18s">Create your account</h2>
                <p class="form-subtitle reveal" style="--d:.26s">
                    Start building better financial habits today.
                </p>
            </div>

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

            <form action="register.php" method="POST" id="registerForm">

                <div class="input-group reveal" style="--d:.34s">
                    <label for="name">Full name</label>
                    <div class="field">
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/></svg>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            placeholder="Enter your full name"
                            value="<?= htmlspecialchars(
                                $name,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            autocomplete="name"
                            required
                        >
                    </div>
                </div>

                <div class="input-group reveal" style="--d:.42s">
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

                <div class="input-group reveal" style="--d:.5s">
                    <label for="password">Password</label>
                    <div class="field">
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="At least 8 characters"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <button type="button" class="toggle-pass" data-target="password" aria-label="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"/><path d="M6.6 6.7A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.4-1"/></svg>
                        </button>
                    </div>

                    <div class="strength" id="strength" data-level="0">
                        <div class="strength-bars">
                            <i></i><i></i><i></i><i></i>
                        </div>
                        <span class="strength-label" id="strengthLabel">Use 8+ characters</span>
                    </div>
                </div>

                <div class="input-group reveal" style="--d:.58s">
                    <label for="confirm_password">Confirm password</label>
                    <div class="field">
                        <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.2 7.8 8 9 4.8-1.2 8-4.5 8-9V6z"/><path d="m9 12 2.2 2.2L15.5 10"/></svg>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Enter your password again"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <button type="button" class="toggle-pass" data-target="confirm_password" aria-label="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"/><path d="M6.6 6.7A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.4-1"/></svg>
                        </button>
                    </div>
                    <p class="match-hint" id="matchHint" aria-live="polite"></p>
                </div>

                <div class="reveal" style="--d:.66s">
                    <button type="submit" name="register" id="submitBtn">
                        <span class="btn-label">Create Account</span>
                        <span class="button-arrow">&rarr;</span>
                        <span class="btn-spinner" aria-hidden="true"></span>
                    </button>
                </div>

            </form>

            <p class="login-link reveal" style="--d:.74s">
                Already have an account?
                <a href="login.php">Sign in</a>
            </p>

            <div class="security-note reveal" style="--d:.82s">
                <span class="security-icon">&#10003;</span>
                Your financial information deserves privacy.
            </div>

        </div>

    </section>

</main>

</body>
</html>