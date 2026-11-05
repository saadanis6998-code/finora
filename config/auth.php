<?php
// =========================================================
// config/auth.php — include at the TOP of every private page:
//   require_once "config/db.php";
//   require_once "config/auth.php";
// =========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set("Asia/Karachi");

// ---------- Must be logged in ----------
if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// ---------- Auto logout after 2 hours of no activity ----------
$idle_limit = 7200;

if (isset($_SESSION["last_activity"]) && (time() - $_SESSION["last_activity"]) > $idle_limit) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

$_SESSION["last_activity"] = time();

// ---------- CSRF token ----------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// =========================================================
// HELPERS
// =========================================================

/** Safe output */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/** Hidden CSRF input for forms */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION["csrf_token"]) . '">';
}

/** Check CSRF token on POST */
function csrf_ok(): bool
{
    return hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "");
}

/** 12500 -> "₨ 12,500"   |   1234.5 -> "₨ 1,234.50"   |   -300 -> "−₨ 300" */
function money(float $n): string
{
    $neg = $n < 0;
    $n = round(abs($n), 2);
    $text = number_format($n, $n == floor($n) ? 0 : 2);

    return ($neg ? "−" : "") . "₨ " . $text;
}

/** Run a prepared SELECT and return all rows */
function db_all(mysqli $conn, string $sql, string $types = "", array $params = []): array
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return [];
    }

    if ($types !== "") {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    mysqli_stmt_close($stmt);

    return $rows;
}