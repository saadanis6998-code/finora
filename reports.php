<?php
require_once "config/db.php";
require_once "config/auth.php";

$uid = (int) $_SESSION["user_id"];
$user_name = trim($_SESSION["user_name"] ?? "there");
$first_name = explode(" ", $user_name)[0] ?: "there";
$initial = mb_strtoupper(mb_substr($first_name, 0, 1));

// Page intro: plays when you ARRIVE here from another page,
// but not when you change the period on this same page.
$show_intro = ($_SESSION["last_page"] ?? "") !== "reports";
$_SESSION["last_page"] = "reports";

// =========================================================
// HELPERS
// =========================================================

function valid_date(?string $d): bool
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    $dt = DateTime::createFromFormat("Y-m-d", $d);
    return $dt && $dt->format("Y-m-d") === $d;
}

function period_totals(mysqli $conn, int $uid, string $from, string $to): array
{
    $rows = db_all(
        $conn,
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS inc,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS exp,
            COUNT(*) AS cnt
         FROM transactions
         WHERE user_id = ? AND transaction_date BETWEEN ? AND ?",
        "iss",
        [$uid, $from, $to]
    );

    $r = $rows[0] ?? ["inc" => 0, "exp" => 0, "cnt" => 0];

    return [
        "income" => (float) $r["inc"],
        "expense" => (float) $r["exp"],
        "count" => (int) $r["cnt"],
    ];
}

function pct_change(float $now, float $before): ?int
{
    if (abs($before) < 0.005) return null;
    return (int) round((($now - $before) / abs($before)) * 100);
}

function short_money(float $n): string
{
    $a = abs($n);
    if ($a >= 1000000) $t = rtrim(rtrim(number_format($a / 1000000, 1), "0"), ".") . "M";
    elseif ($a >= 1000) $t = rtrim(rtrim(number_format($a / 1000, 1), "0"), ".") . "K";
    else $t = (string) round($a);

    return ($n < 0 ? "−" : "") . "₨ " . $t;
}

/** Smooth cubic line through points (horizontal tangents = no overshoot). */
function smooth_path(array $pts): string
{
    $n = count($pts);
    if ($n === 0) return "";
    if ($n === 1) {
        return sprintf("M0 %F L800 %F", $pts[0][1], $pts[0][1]);
    }

    $d = sprintf("M%F %F", $pts[0][0], $pts[0][1]);

    for ($i = 1; $i < $n; $i++) {
        $mx = ($pts[$i - 1][0] + $pts[$i][0]) / 2;
        $d .= sprintf(
            " C%F %F %F %F %F %F",
            $mx, $pts[$i - 1][1],
            $mx, $pts[$i][1],
            $pts[$i][0], $pts[$i][1]
        );
    }

    return $d;
}

// =========================================================
// PERIOD
// =========================================================

$today = date("Y-m-d");
$ranges = [
    "this_month" => "This month",
    "last_month" => "Last month",
    "3m" => "3 months",
    "6m" => "6 months",
    "year" => "This year",
    "all" => "All time",
];

$range = $_GET["range"] ?? "this_month";
$custom_from = "";
$custom_to = "";
$notice = "";

if (!isset($ranges[$range]) && $range !== "custom") $range = "this_month";

switch ($range) {
    case "last_month":
        $from = date("Y-m-01", strtotime("first day of last month"));
        $to = date("Y-m-t", strtotime("first day of last month"));
        break;
    case "3m":
        $from = date("Y-m-01", strtotime("first day of -2 months"));
        $to = $today;
        break;
    case "6m":
        $from = date("Y-m-01", strtotime("first day of -5 months"));
        $to = $today;
        break;
    case "year":
        $from = date("Y-01-01");
        $to = $today;
        break;
    case "all":
        $first = db_all($conn, "SELECT MIN(transaction_date) AS d FROM transactions WHERE user_id = ?", "i", [$uid]);
        $from = !empty($first[0]["d"]) ? $first[0]["d"] : $today;
        $to = $today;
        break;
    case "custom":
        $cf = $_GET["from"] ?? "";
        $ct = $_GET["to"] ?? "";

        if (valid_date($cf) && valid_date($ct)) {
            if ($ct > $today) $ct = $today;

            if ($cf > $ct) {
                $notice = "“From” date must be before “To” date — showing this month instead.";
                $range = "this_month";
            } elseif ((strtotime($ct) - strtotime($cf)) / 86400 > 1830) {
                $notice = "Please pick a range of 5 years or less — showing this month instead.";
                $range = "this_month";
            } else {
                $from = $cf;
                $to = $ct;
                $custom_from = $cf;
                $custom_to = $ct;
            }
        } else {
            $notice = "Please choose both dates — showing this month instead.";
            $range = "this_month";
        }
        break;
    default:
        $range = "this_month";
}

if ($range === "this_month") {
    $from = date("Y-m-01");
    $to = $today;
}

$days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
$days = max(1, $days);

$has_prev = ($range !== "all");
$prev_to = date("Y-m-d", strtotime($from . " -1 day"));
$prev_from = date("Y-m-d", strtotime($prev_to . " -" . ($days - 1) . " days"));

$period_label = ($range === "custom" || $range === "all" || true)
    ? date("d M Y", strtotime($from)) . "  —  " . date("d M Y", strtotime($to))
    : "";

// =========================================================
// TOTALS
// =========================================================

$cur = period_totals($conn, $uid, $from, $to);
$prev = $has_prev ? period_totals($conn, $uid, $prev_from, $prev_to) : null;

$income = $cur["income"];
$expense = $cur["expense"];
$net = $income - $expense;
$tx_count = $cur["count"];
$has_data = $tx_count > 0;

$savings_rate = $income > 0 ? (int) round(($net / $income) * 100) : 0;
$savings_rate = max(-100, min(100, $savings_rate));

$income_change = $prev ? pct_change($income, $prev["income"]) : null;
$expense_change = $prev ? pct_change($expense, $prev["expense"]) : null;
$net_change = $prev ? pct_change($net, $prev["income"] - $prev["expense"]) : null;

$prev_rate = null;
if ($prev && $prev["income"] > 0) {
    $prev_rate = (int) round((($prev["income"] - $prev["expense"]) / $prev["income"]) * 100);
}
$rate_diff = $prev_rate !== null ? $savings_rate - $prev_rate : null;

// =========================================================
// TIME SERIES (daily for short periods, monthly for long)
// =========================================================

$daily = $days <= 62;
$series = []; // key => [label, income, expense]

if ($daily) {
    for ($i = 0; $i < $days; $i++) {
        $ts = strtotime($from . " +$i days");
        $series[date("Y-m-d", $ts)] = [date("d M", $ts), 0.0, 0.0];
    }

    $rows = db_all(
        $conn,
        "SELECT transaction_date AS k, type, SUM(amount) AS total
         FROM transactions
         WHERE user_id = ? AND transaction_date BETWEEN ? AND ?
         GROUP BY transaction_date, type",
        "iss",
        [$uid, $from, $to]
    );
} else {
    $cursor = strtotime(date("Y-m-01", strtotime($from)));
    $last = strtotime(date("Y-m-01", strtotime($to)));

    while ($cursor <= $last) {
        $series[date("Y-m", $cursor)] = [date("M Y", $cursor), 0.0, 0.0];
        $cursor = strtotime("+1 month", $cursor);
    }

    $rows = db_all(
        $conn,
        "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS k, type, SUM(amount) AS total
         FROM transactions
         WHERE user_id = ? AND transaction_date BETWEEN ? AND ?
         GROUP BY DATE_FORMAT(transaction_date, '%Y-%m'), type",
        "iss",
        [$uid, $from, $to]
    );
}

foreach ($rows as $r) {
    if (!isset($series[$r["k"]])) continue;
    $idx = $r["type"] === "income" ? 1 : 2;
    $series[$r["k"]][$idx] = (float) $r["total"];
}

$series = array_values($series);
$n = count($series);

$max_val = 1.0;
foreach ($series as $s) $max_val = max($max_val, $s[1], $s[2]);

// nice ceiling for the y-axis
$mag = pow(10, floor(log10($max_val)));
$max_nice = ceil($max_val / $mag * 2) / 2 * $mag;
if ($max_nice < $max_val) $max_nice = $max_val;

$pts_in = [];
$pts_ex = [];
$points = [];

foreach ($series as $i => $s) {
    $x = $n === 1 ? 400.0 : ($i / ($n - 1)) * 800;
    $yi = 244 - ($s[1] / $max_nice) * 228;
    $ye = 244 - ($s[2] / $max_nice) * 228;

    $pts_in[] = [$x, $yi];
    $pts_ex[] = [$x, $ye];

    $points[] = [
        "l" => $s[0],
        "ti" => money($s[1]),
        "te" => money($s[2]),
        "x" => round($x / 800 * 100, 3),
        "yi" => round($yi / 260 * 100, 3),
        "ye" => round($ye / 260 * 100, 3),
    ];
}

$line_in = smooth_path($pts_in);
$line_ex = smooth_path($pts_ex);

$area_in = $area_ex = "";
if ($n >= 1) {
    $x0 = $n === 1 ? 0 : $pts_in[0][0];
    $x1 = $n === 1 ? 800 : $pts_in[$n - 1][0];
    $area_in = $line_in . sprintf(" L%F 244 L%F 244 Z", $x1, $x0);
    $area_ex = $line_ex . sprintf(" L%F 244 L%F 244 Z", $x1, $x0);
}

// x-axis labels (max 7)
$x_labels = [];
$step = max(1, (int) ceil($n / 7));
foreach ($series as $i => $s) {
    if ($i % $step === 0 || $i === $n - 1) {
        // avoid crowding the last two labels
        if ($i === $n - 1 && $i % $step !== 0 && ($i - (int) (floor($i / $step) * $step)) < $step * 0.6) continue;
        $x_labels[] = [$n === 1 ? 50 : round($i / ($n - 1) * 100, 2), $s[0]];
    }
}

$y_ticks = [];
foreach ([16, 73, 130, 187, 244] as $gy) {
    $y_ticks[] = [round($gy / 260 * 100, 2), short_money(($max_nice) * (244 - $gy) / 228)];
}

// =========================================================
// CATEGORIES
// =========================================================

$palette = ["#35d39a", "#4f8cff", "#f5b942", "#ef6b6b", "#9b7bff", "#2ec4d6"];

$exp_cats = db_all(
    $conn,
    "SELECT COALESCE(c.name, 'Uncategorised') AS name, c.color AS color, SUM(t.amount) AS total, COUNT(*) AS cnt
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.type = 'expense' AND t.transaction_date BETWEEN ? AND ?
     GROUP BY t.category_id, c.name, c.color
     ORDER BY total DESC",
    "iss",
    [$uid, $from, $to]
);

$donut = [];
$others_total = 0.0;
$others_cnt = 0;

foreach ($exp_cats as $i => $c) {
    if ($i < 6) {
        $donut[] = [
            "name" => $c["name"],
            "total" => (float) $c["total"],
            "cnt" => (int) $c["cnt"],
            "color" => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c["color"]) ? $c["color"] : $palette[$i % 6],
        ];
    } else {
        $others_total += (float) $c["total"];
        $others_cnt += (int) $c["cnt"];
    }
}

if ($others_total > 0) {
    $donut[] = ["name" => "Others", "total" => $others_total, "cnt" => $others_cnt, "color" => "#94a3b8"];
}

$circ = 2 * M_PI * 48;
$offset = 0.0;
$segments = [];
$exp_sum = max($expense, 0.01);

foreach ($donut as $i => $d) {
    $share = $d["total"] / $exp_sum;
    $len = $share * $circ;
    $gap = count($donut) > 1 ? 2.5 : 0;

    $segments[] = [
        "len" => max(0.5, $len - $gap),
        "off" => -$offset,
        "pct" => round($share * 100),
    ] + $d;

    $offset += $len;
}

$inc_cats = db_all(
    $conn,
    "SELECT COALESCE(c.name, 'Uncategorised') AS name, c.color AS color, SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.type = 'income' AND t.transaction_date BETWEEN ? AND ?
     GROUP BY t.category_id, c.name, c.color
     ORDER BY total DESC
     LIMIT 5",
    "iss",
    [$uid, $from, $to]
);

// =========================================================
// INSIGHT DATA
// =========================================================

$peak = db_all(
    $conn,
    "SELECT transaction_date AS d, SUM(amount) AS total
     FROM transactions
     WHERE user_id = ? AND type = 'expense' AND transaction_date BETWEEN ? AND ?
     GROUP BY transaction_date
     ORDER BY total DESC
     LIMIT 1",
    "iss",
    [$uid, $from, $to]
);
$peak = $peak[0] ?? null;

$spend_days_row = db_all(
    $conn,
    "SELECT COUNT(DISTINCT transaction_date) AS c
     FROM transactions
     WHERE user_id = ? AND type = 'expense' AND transaction_date BETWEEN ? AND ?",
    "iss",
    [$uid, $from, $to]
);
$spend_days = (int) ($spend_days_row[0]["c"] ?? 0);
$no_spend_days = max(0, $days - $spend_days);
$avg_daily = $expense / $days;

$top_expenses = db_all(
    $conn,
    "SELECT t.amount, t.note, t.transaction_date AS d,
            COALESCE(c.name, 'Uncategorised') AS cat, c.color AS color
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.type = 'expense' AND t.transaction_date BETWEEN ? AND ?
     ORDER BY t.amount DESC, t.transaction_date DESC
     LIMIT 5",
    "iss",
    [$uid, $from, $to]
);

$insights = [];

if ($segments) {
    $top = $segments[0];
    $insights[] = [
        "icon" => "pie",
        "title" => "Biggest category: " . $top["name"],
        "text" => $top["pct"] . "% of your spending (" . money($top["total"]) . ") went here.",
    ];
}

if ($peak) {
    $insights[] = [
        "icon" => "flame",
        "title" => "Peak spending day",
        "text" => date("l, d M", strtotime($peak["d"])) . " — " . money((float) $peak["total"]) . " in a single day.",
    ];
}

if ($expense > 0) {
    $insights[] = [
        "icon" => "clock",
        "title" => "Daily average: " . money($avg_daily),
        "text" => "Spread over " . $days . " day" . ($days === 1 ? "" : "s") . " in this period.",
    ];
}

if ($has_data && $days > 1) {
    $insights[] = [
        "icon" => "leaf",
        "title" => $no_spend_days . " no-spend day" . ($no_spend_days === 1 ? "" : "s"),
        "text" => $no_spend_days > 0
            ? "Days where you didn't spend anything — nice discipline."
            : "You spent something every single day.",
    ];
}

if ($income > 0 && $net >= 0) {
    $insights[] = [
        "icon" => "save",
        "title" => "You saved " . $savings_rate . "% of your income",
        "text" => money($net) . " left after expenses." . ($savings_rate >= 20 ? " That's above the 20% rule of thumb." : " Aim for 20% when you can."),
    ];
} elseif ($has_data && $net < 0) {
    $insights[] = [
        "icon" => "alert",
        "title" => "You overspent by " . money(abs($net)),
        "text" => "Expenses were higher than income in this period.",
        "warn" => true,
    ];
}

if ($prev && $expense_change !== null) {
    $insights[] = [
        "icon" => $expense_change <= 0 ? "down" : "up",
        "title" => "Spending " . ($expense_change <= 0 ? "down " : "up ") . abs($expense_change) . "%",
        "text" => "Compared with the previous " . $days . " day" . ($days === 1 ? "" : "s") . " (" . money($prev["expense"]) . ").",
        "warn" => $expense_change > 0,
    ];
}

$icons = [
    "pie" => '<path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
    "flame" => '<path d="M12 3c1 4 5 5.5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 .3 1.2 1 2 2 2 0-3-.5-5 1-8z"/>',
    "clock" => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    "leaf" => '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c3-4 6-7 9-9"/>',
    "save" => '<path d="M4 8h16v11H4z"/><path d="M4 8l2-4h12l2 4"/><path d="M12 12v4"/>',
    "alert" => '<path d="M12 4 3 20h18z"/><path d="M12 10v4M12 17h.01"/>',
    "down" => '<path d="M12 5v14M6 13l6 6 6-6"/>',
    "up" => '<path d="M12 19V5M6 11l6-6 6 6"/>',
];

$hour = (int) date("G");
?>
<!DOCTYPE html>
<html lang="en"<?= $show_intro ? "" : ' class="no-intro"' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b1424">

    <title>Reports | Finora</title>

    <link rel="stylesheet" href="assets/dashboard.css">
    <link rel="stylesheet" href="assets/reports.css">
    <script src="assets/reports.js" defer></script>
</head>

<body>

<?php if ($show_intro): ?>
<!-- ============ PAGE INTRO (2.4s) ============ -->
<div class="pg-intro" id="pgIntro" aria-hidden="true">
    <div class="pi-content">

        <div class="pi-lines">
            <i style="--i:0"></i>
            <i style="--i:1"></i>
            <i style="--i:2"></i>
            <i style="--i:3"></i>
        </div>

        <h2 class="pi-title"><span>Reports</span></h2>
        <p class="pi-sub">Understand where it all goes.</p>

        <div class="pi-bar"><i></i></div>

    </div>
</div>
<?php endif; ?>

<div class="app">

    <!-- ================= SIDEBAR ================= -->
    <aside class="sidebar">

        <div class="aurora"></div>

        <a href="dashboard.php" class="logo">finora<span>.</span></a>

        <nav class="nav" aria-label="Main">
            <a href="dashboard.php" class="nav-item">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7.5" height="9" rx="2"/><rect x="13.5" y="3" width="7.5" height="5.5" rx="2"/><rect x="13.5" y="11.5" width="7.5" height="9.5" rx="2"/><rect x="3" y="15" width="7.5" height="6" rx="2"/></svg>
                Dashboard
            </a>

            <a href="transactions.php" class="nav-item">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                Transactions
            </a>

            <a href="reports.php" class="nav-item active">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                Reports
            </a>

            <span class="nav-item disabled" aria-disabled="true">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                Budgets <em>SOON</em>
            </span>
        </nav>

        <div class="sidebar-foot">
            <div class="user-card">
                <div class="avatar"><?= e($initial) ?></div>
                <div class="user-meta">
                    <strong><?= e($user_name) ?></strong>
                    <span>Signed in</span>
                </div>
            </div>

            <form action="logout.php" method="POST" id="logoutForm">
                <?= csrf_field() ?>
                <button type="submit" class="logout-btn">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 8l4 4-4 4"/><path d="M20 12H9"/></svg>
                    Log out
                </button>
            </form>
        </div>

    </aside>

    <!-- ================= MAIN ================= -->
    <main class="main">

        <header class="topbar rv" style="--d:0s">
            <div>
                <p class="eyebrow">Insights</p>
                <h1>Reports</h1>
            </div>

            <button type="button" class="btn-ghost" id="printBtn">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="8" rx="2"/><path d="M7 14h10v6H7z"/></svg>
                Print / Save PDF
            </button>
        </header>

        <!-- ============ PERIOD ============ -->
        <section class="period rv" style="--d:.05s">

            <div class="pills" role="tablist" aria-label="Period">
                <?php foreach ($ranges as $key => $label): ?>
                    <a href="reports.php?range=<?= e($key) ?>"
                       class="pill<?= $range === $key ? " active" : "" ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>

            <form method="GET" action="reports.php" class="custom-range" id="customRange">
                <input type="hidden" name="range" value="custom">
                <label>
                    <span>From</span>
                    <input type="date" name="from" value="<?= e($custom_from ?: $from) ?>" max="<?= e($today) ?>" required>
                </label>
                <label>
                    <span>To</span>
                    <input type="date" name="to" value="<?= e($custom_to ?: $to) ?>" max="<?= e($today) ?>" required>
                </label>
                <button type="submit" class="btn-apply<?= $range === "custom" ? " active" : "" ?>">Apply</button>
            </form>

        </section>

        <p class="period-label rv" style="--d:.08s">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="3"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
            <?= e($period_label) ?>
            <span><?= $days ?> day<?= $days === 1 ? "" : "s" ?> · <?= $tx_count ?> transaction<?= $tx_count === 1 ? "" : "s" ?></span>
        </p>

        <?php if ($notice): ?>
            <div class="flash flash-error" role="alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/></svg>
                <?= e($notice) ?>
            </div>
        <?php endif; ?>

        <?php if (!$has_data): ?>

            <section class="card empty-report rv" style="--d:.12s">
                <div class="empty-art">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                </div>
                <h3>Nothing to report for this period</h3>
                <p>Try a wider period, or add a few transactions — your charts will appear here.</p>
                <div class="empty-actions">
                    <a href="reports.php?range=all" class="btn-apply active">View all time</a>
                    <a href="transactions.php" class="btn-ghost">Go to transactions</a>
                </div>
            </section>

        <?php else: ?>

        <!-- ============ STATS ============ -->
        <section class="stats r-stats">

            <article class="stat rv" style="--d:.12s">
                <div class="stat-top">
                    <p class="stat-label">INCOME</p>
                    <span class="stat-icon up"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg></span>
                </div>
                <h2 class="stat-value" data-count="<?= e(round($income, 2)) ?>"><?= e(money($income)) ?></h2>
                <p class="stat-foot">
                    <?php if ($income_change !== null): ?>
                        <span class="chip <?= $income_change >= 0 ? "good" : "bad" ?>"><?= $income_change >= 0 ? "▲" : "▼" ?> <?= abs($income_change) ?>%</span> vs previous
                    <?php else: ?>
                        <?= $has_prev ? "No data in previous period" : "All-time total" ?>
                    <?php endif; ?>
                </p>
            </article>

            <article class="stat rv" style="--d:.18s">
                <div class="stat-top">
                    <p class="stat-label">EXPENSES</p>
                    <span class="stat-icon down"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span>
                </div>
                <h2 class="stat-value" data-count="<?= e(round($expense, 2)) ?>"><?= e(money($expense)) ?></h2>
                <p class="stat-foot">
                    <?php if ($expense_change !== null): ?>
                        <!-- for expenses, going DOWN is the good direction -->
                        <span class="chip <?= $expense_change <= 0 ? "good" : "bad" ?>"><?= $expense_change >= 0 ? "▲" : "▼" ?> <?= abs($expense_change) ?>%</span> vs previous
                    <?php else: ?>
                        <?= $has_prev ? "No data in previous period" : "All-time total" ?>
                    <?php endif; ?>
                </p>
            </article>

            <article class="stat rv" style="--d:.24s">
                <div class="stat-top">
                    <p class="stat-label">NET SAVINGS</p>
                    <span class="stat-icon <?= $net >= 0 ? "up" : "down" ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16v11H4z"/><path d="M4 8l2-4h12l2 4"/></svg></span>
                </div>
                <h2 class="stat-value<?= $net < 0 ? " neg" : "" ?>" data-count="<?= e(round($net, 2)) ?>"><?= e(money($net)) ?></h2>
                <p class="stat-foot">
                    <?php if ($net_change !== null): ?>
                        <span class="chip <?= $net_change >= 0 ? "good" : "bad" ?>"><?= $net_change >= 0 ? "▲" : "▼" ?> <?= abs($net_change) ?>%</span> vs previous
                    <?php else: ?>
                        Income − expenses
                    <?php endif; ?>
                </p>
            </article>

            <article class="stat rv" style="--d:.30s">
                <p class="stat-label">SAVINGS RATE</p>
                <div class="rate">
                    <svg class="rate-ring" viewBox="0 0 36 36" aria-hidden="true">
                        <circle class="rate-bg" cx="18" cy="18" r="15" fill="none"></circle>
                        <circle class="rate-fg" cx="18" cy="18" r="15" fill="none" stroke-linecap="round"
                                style="--p:<?= max(0, $savings_rate) ?>"></circle>
                    </svg>
                    <h2 class="stat-value"><?= $savings_rate ?><small>%</small></h2>
                </div>
                <p class="stat-foot">
                    <?php if ($rate_diff !== null): ?>
                        <span class="chip <?= $rate_diff >= 0 ? "good" : "bad" ?>"><?= $rate_diff >= 0 ? "▲" : "▼" ?> <?= abs($rate_diff) ?> pts</span> vs previous
                    <?php else: ?>
                        Of income kept
                    <?php endif; ?>
                </p>
            </article>

        </section>

        <!-- ============ TREND ============ -->
        <section class="card rv" style="--d:.36s">
            <div class="card-head">
                <div>
                    <h3>Income vs expenses</h3>
                    <p><?= $daily ? "Day by day" : "Month by month" ?> · hover the chart for details</p>
                </div>
                <div class="legend">
                    <span><i class="dot in"></i>Income</span>
                    <span><i class="dot out"></i>Expenses</span>
                </div>
            </div>

            <div class="trend" id="trend" data-points="<?= e(json_encode($points, JSON_UNESCAPED_UNICODE)) ?>">

                <div class="y-axis" aria-hidden="true">
                    <?php foreach ($y_ticks as $t): ?>
                        <span style="top:<?= $t[0] ?>%"><?= e($t[1]) ?></span>
                    <?php endforeach; ?>
                </div>

                <div class="plot" id="plot">
                    <svg viewBox="0 0 800 260" preserveAspectRatio="none" role="img"
                         aria-label="Income and expenses over time">
                        <defs>
                            <linearGradient id="gIn" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#35d39a" stop-opacity=".30"/>
                                <stop offset="1" stop-color="#35d39a" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="gEx" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#17263c" stop-opacity=".16"/>
                                <stop offset="1" stop-color="#17263c" stop-opacity="0"/>
                            </linearGradient>
                        </defs>

                        <g class="grid-lines">
                            <?php foreach ([16, 73, 130, 187, 244] as $gy): ?>
                                <line x1="0" y1="<?= $gy ?>" x2="800" y2="<?= $gy ?>"/>
                            <?php endforeach; ?>
                        </g>

                        <g class="trend-draw">
                            <?php if ($n >= 2): ?>
                                <path d="<?= e($area_ex) ?>" fill="url(#gEx)"/>
                                <path d="<?= e($area_in) ?>" fill="url(#gIn)"/>
                            <?php endif; ?>
                            <path d="<?= e($line_ex) ?>" class="ln ln-ex" fill="none"/>
                            <path d="<?= e($line_in) ?>" class="ln ln-in" fill="none"/>
                        </g>
                    </svg>

                    <div class="cursor" id="cursor"></div>
                    <i class="pt pt-in" id="ptIn"></i>
                    <i class="pt pt-ex" id="ptEx"></i>

                    <div class="t-tip" id="tTip" role="status">
                        <strong id="tLabel"></strong>
                        <span><i class="dot in"></i><em>Income</em><b id="tIn"></b></span>
                        <span><i class="dot out"></i><em>Expenses</em><b id="tEx"></b></span>
                    </div>
                </div>

                <div class="x-axis" aria-hidden="true">
                    <?php foreach ($x_labels as $xl): ?>
                        <span style="left:<?= $xl[0] ?>%"><?= e($xl[1]) ?></span>
                    <?php endforeach; ?>
                </div>

            </div>
        </section>

        <!-- ============ CATEGORIES + INSIGHTS ============ -->
        <section class="r-grid">

            <article class="card rv" style="--d:.42s">
                <div class="card-head">
                    <div>
                        <h3>Spending by category</h3>
                        <p><?= count($exp_cats) ?> categor<?= count($exp_cats) === 1 ? "y" : "ies" ?> · <?= e(money($expense)) ?> total</p>
                    </div>
                </div>

                <?php if ($segments): ?>
                    <div class="donut-wrap">

                        <div class="donut">
                            <svg viewBox="0 0 120 120" aria-hidden="true">
                                <circle class="donut-bg" cx="60" cy="60" r="48" fill="none"/>
                                <?php foreach ($segments as $k => $s): ?>
                                    <circle class="seg" cx="60" cy="60" r="48" fill="none"
                                            stroke="<?= e($s["color"]) ?>"
                                            style="--len:<?= round($s["len"], 2) ?>;--off:<?= round($s["off"], 2) ?>;--k:<?= $k ?>"/>
                                <?php endforeach; ?>
                            </svg>
                            <div class="donut-center">
                                <small>SPENT</small>
                                <strong><?= e(short_money($expense)) ?></strong>
                            </div>
                        </div>

                        <ul class="donut-legend">
                            <?php foreach ($segments as $k => $s): ?>
                                <li class="rv" style="--d:<?= round(.55 + $k * .06, 2) ?>s">
                                    <span class="dl-name"><i class="dot" style="background:<?= e($s["color"]) ?>"></i><?= e($s["name"]) ?></span>
                                    <span class="dl-val"><b><?= e(money($s["total"])) ?></b><em><?= $s["pct"] ?>%</em></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                    </div>
                <?php else: ?>
                    <p class="muted-note">No expenses in this period.</p>
                <?php endif; ?>
            </article>

            <article class="card rv" style="--d:.48s">
                <div class="card-head">
                    <div>
                        <h3>Smart insights</h3>
                        <p>What stands out in this period</p>
                    </div>
                </div>

                <ul class="insights">
                    <?php foreach ($insights as $k => $in): ?>
                        <li class="insight<?= !empty($in["warn"]) ? " warn" : "" ?> rv" style="--d:<?= round(.6 + $k * .07, 2) ?>s">
                            <span class="in-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$in["icon"]] ?></svg></span>
                            <div>
                                <strong><?= e($in["title"]) ?></strong>
                                <p><?= e($in["text"]) ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>

        </section>

        <!-- ============ TOP EXPENSES + INCOME SOURCES ============ -->
        <section class="r-grid r-grid-b">

            <article class="card rv" style="--d:.54s">
                <div class="card-head">
                    <div>
                        <h3>Largest expenses</h3>
                        <p>Top <?= count($top_expenses) ?> in this period</p>
                    </div>
                </div>

                <?php if ($top_expenses): ?>
                    <ol class="top-list">
                        <?php foreach ($top_expenses as $k => $t):
                            $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $t["color"]) ? $t["color"] : "#94a3b8";
                            $label = trim((string) $t["note"]) !== "" ? $t["note"] : $t["cat"];
                        ?>
                            <li class="rv" style="--d:<?= round(.66 + $k * .07, 2) ?>s">
                                <span class="rank"><?= $k + 1 ?></span>
                                <div class="top-meta">
                                    <strong><?= e($label) ?></strong>
                                    <small><i class="dot" style="background:<?= e($col) ?>"></i><?= e($t["cat"]) ?> · <?= e(date("d M", strtotime($t["d"]))) ?></small>
                                </div>
                                <b class="top-amt"><?= e(money((float) $t["amount"])) ?></b>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="muted-note">No expenses in this period.</p>
                <?php endif; ?>
            </article>

            <article class="card rv" style="--d:.6s">
                <div class="card-head">
                    <div>
                        <h3>Income sources</h3>
                        <p>Where your money came from</p>
                    </div>
                </div>

                <?php if ($inc_cats): ?>
                    <div class="breakdown">
                        <?php $inc_sum = max($income, 0.01); foreach ($inc_cats as $k => $c):
                            $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c["color"]) ? $c["color"] : $palette[$k % 6];
                            $pct = round(((float) $c["total"] / $inc_sum) * 100);
                        ?>
                            <div class="bd-row">
                                <div class="bd-top">
                                    <span><i class="dot" style="background:<?= e($col) ?>"></i><?= e($c["name"]) ?></span>
                                    <b><?= e(money((float) $c["total"])) ?></b>
                                </div>
                                <div class="bd-track"><i style="--w:<?= $pct ?>%;--c:<?= e($col) ?>;--k:<?= $k ?>"></i></div>
                                <small><?= $pct ?>% of income</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted-note">No income in this period.</p>
                <?php endif; ?>
            </article>

        </section>

        <?php endif; ?>

    </main>
</div>

</body>
</html>