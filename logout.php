<?php
session_start();

// Logout works only through the POST form (with CSRF token),
// so nobody can log you out by tricking you into opening a link.
if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")
) {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $p["path"],
            $p["domain"],
            $p["secure"],
            $p["httponly"]
        );
    }

    session_destroy();

    header("Location: login.php");
    exit;
}

header("Location: dashboard.php");
exit;