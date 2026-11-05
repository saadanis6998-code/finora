<?php
require_once "config/db.php";
require_once "config/auth.php";

$uid = (int) $_SESSION["user_id"];
$user_name = trim($_SESSION["user_name"] ?? "there");
$first_name = explode(" ", $user_name)[0] ?: "there";
$initial = mb_strtoupper(mb_substr($first_name, 0, 1));

// Page intro: plays when you ARRIVE here from another page (or right after login),
// but not on reload / filtering / saving on this same page.
$show_intro = ($_SESSION["last_page"] ?? "") !== "dashboard";
$_SESSION["last_page"] = "dashboard";

$today = date("Y-m-d");
$month_start = date("Y-m-01");
$month_end = date("Y-m-t");

$errors = [];
$open_modal = false;
$old = [
    "type" => "expense",
    "amount" => "",
    "category_id" => "",
    "date" => $today,
    "note" => "",
];

// =========================================================
// ACTIONS (add / delete)
// =========================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if (!csrf_ok()) {

        $errors[] = "Your session expired. Please try again.";
        $open_modal = ($action === "add");

    } elseif ($action === "add") {

        $old["type"] = ($_POST["type"] ?? "") === "income" ? "income" : "expense";
        $old["amount"] = trim($_POST["amount"] ?? "");
        $old["category_id"] = (int) ($_POST["category_id"] ?? 0);
        $old["date"] = trim($_POST["date"] ?? "");
        $old["note"] = trim($_POST["note"] ?? "");

        // Amount
        $amount = 0.0;

        if (!is_numeric($old["amount"])) {
            $errors[] = "Please enter a valid amount.";
        } else {
            $amount = round((float) $old["amount"], 2);

            if ($amount <= 0) {
                $errors[] = "Amount must be greater than zero.";
            } elseif ($amount > 999999999.99) {
                $errors[] = "That amount is too large.";
            }
        }

        // Date
        $d = DateTime::createFromFormat("Y-m-d", $old["date"]);

        if (!$d || $d->format("Y-m-d") !== $old["date"]) {
            $errors[] = "Please choose a valid date.";
        } elseif ($old["date"] > $today) {
            $errors[] = "Date can't be in the future.";
        }

        // Note
        if (mb_strlen($old["note"]) > 120) {
            $errors[] = "Note must be 120 characters or less.";
        }

        // Category must exist AND match the chosen type
        if (empty($errors)) {
            $cat = db_all(
                $conn,
                "SELECT id FROM categories WHERE id = ? AND type = ? LIMIT 1",
                "is",
                [$old["category_id"], $old["type"]]
            );

            if (!$cat) {
                $errors[] = "Please choose a category.";
            }
        }

        if (empty($errors)) {

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO transactions (user_id, category_id, type, amount, note, transaction_date)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "iisdss",
                    $uid,
                    $old["category_id"],
                    $old["type"],
                    $amount,
                    $old["note"],
                    $old["date"]
                );

                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $_SESSION["flash"] = ucfirst($old["type"]) . " of " . money($amount) . " added.";
                    header("Location: dashboard.php");
                    exit;
                }

                mysqli_stmt_close($stmt);
            }

            $errors[] = "Could not save the transaction. Please try again.";
        }

        $open_modal = true;

    } elseif ($action === "delete") {

        $id = (int) ($_POST["id"] ?? 0);

        $stmt = mysqli_prepare($conn, "DELETE FROM transactions WHERE id = ? AND user_id = ?");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $id, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION["flash"] = "Transaction deleted.";
        }

        header("Location: dashboard.php");
        exit;
    }
}

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

// =========================================================
// DATA
// =========================================================

// All-time totals -> balance
$all = ["income" => 0.0, "expense" => 0.0];

foreach (db_all(
    $conn,
    "SELECT type, SUM(amount) AS total FROM transactions WHERE user_id = ? GROUP BY type",
    "i",
    [$uid]
) as $r) {
    $all[$r["type"]] = (float) $r["total"];
}

$balance = $all["income"] - $all["expense"];

// This month
$month = ["income" => 0.0, "expense" => 0.0];

foreach (db_all(
    $conn,
    "SELECT type, SUM(amount) AS total FROM transactions
     WHERE user_id = ? AND transaction_date BETWEEN ? AND ? GROUP BY type",
    "iss",
    [$uid, $month_start, $month_end]
) as $r) {
    $month[$r["type"]] = (float) $r["total"];
}

$savings_rate = $month["income"] > 0
    ? (int) round(($month["income"] - $month["expense"]) / $month["income"] * 100)
    : 0;

$savings_rate = max(-100, min(100, $savings_rate));

// Last 6 months (income vs expense)
$months = [];

for ($i = 5; $i >= 0; $i--) {
    $key = date("Y-m", strtotime("first day of -$i month"));
    $months[$key] = [
        "label" => date("M", strtotime($key . "-01")),
        "full" => date("F Y", strtotime($key . "-01")),
        "income" => 0.0,
        "expense" => 0.0,
    ];
}

$keys = array_keys($months);
$six_from = $keys[0] . "-01";

foreach (db_all(
    $conn,
    "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym, type, SUM(amount) AS total
     FROM transactions WHERE user_id = ? AND transaction_date >= ?
     GROUP BY ym, type",
    "is",
    [$uid, $six_from]
) as $r) {
    if (isset($months[$r["ym"]])) {
        $months[$r["ym"]][$r["type"]] = (float) $r["total"];
    }
}

$chart_max = 1.0;
$has_chart_data = false;

foreach ($months as $m) {
    $chart_max = max($chart_max, $m["income"], $m["expense"]);
    if ($m["income"] > 0 || $m["expense"] > 0) {
        $has_chart_data = true;
    }
}

// Change vs last month
$prev_key = $keys[count($keys) - 2];
$prev = $months[$prev_key];

function change_pct(float $cur, float $before): ?int
{
    if ($before <= 0) {
        return null;
    }
    return (int) round(($cur - $before) / $before * 100);
}

$income_change = change_pct($month["income"], $prev["income"]);
$expense_change = change_pct($month["expense"], $prev["expense"]);

// Spending breakdown (this month, top 5)
$breakdown = db_all(
    $conn,
    "SELECT c.name, c.color, SUM(t.amount) AS total
     FROM transactions t JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.type = 'expense' AND t.transaction_date BETWEEN ? AND ?
     GROUP BY c.id, c.name, c.color ORDER BY total DESC LIMIT 5",
    "iss",
    [$uid, $month_start, $month_end]
);

// Recent transactions
$recent = db_all(
    $conn,
    "SELECT t.id, t.type, t.amount, t.note, t.transaction_date, c.name AS category, c.color
     FROM transactions t JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ?
     ORDER BY t.transaction_date DESC, t.id DESC LIMIT 8",
    "i",
    [$uid]
);

// Categories for the modal
$categories = db_all(
    $conn,
    "SELECT id, name, type FROM categories ORDER BY FIELD(type, 'expense', 'income'), name"
);

// Greeting
$hour = (int) date("G");
$greeting = $hour < 12 ? "Good morning" : ($hour < 17 ? "Good afternoon" : "Good evening");

function nice_date(string $d): string
{
    if ($d === date("Y-m-d")) {
        return "Today";
    }
    if ($d === date("Y-m-d", strtotime("-1 day"))) {
        return "Yesterday";
    }
    return date("d M", strtotime($d));
}
?>

<!DOCTYPE html>
<html lang="en"<?= $show_intro ? "" : ' class="no-intro"' ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b1424">

    <title>Dashboard | Finora</title>

    <link rel="stylesheet" href="assets/dashboard.css">
    <script src="assets/dashboard.js" defer></script>
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

        <h2 class="pi-title"><span>Dashboard</span></h2>
        <p class="pi-sub">Your money, at a glance.</p>

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
            <a href="dashboard.php" class="nav-item active">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7.5" height="9" rx="2"/><rect x="13.5" y="3" width="7.5" height="5.5" rx="2"/><rect x="13.5" y="11.5" width="7.5" height="9.5" rx="2"/><rect x="3" y="15" width="7.5" height="6" rx="2"/></svg>
                Dashboard
            </a>

            <a href="transactions.php" class="nav-item">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                Transactions
            </a>

            <a href="reports.php" class="nav-item">
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
                <p class="eyebrow"><?= e(date("l, d F Y")) ?></p>
                <h1><?= e($greeting) ?>, <span><?= e($first_name) ?></span></h1>
            </div>

            <button type="button" class="btn-primary" id="openModal">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Add transaction
            </button>
        </header>

        <?php if ($flash): ?>
            <div class="flash rv" style="--d:.05s" id="flash" role="status">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.8"/></svg>
                <?= e($flash) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors) && !$open_modal): ?>
            <div class="flash flash-error" role="alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/></svg>
                <?= e($errors[0]) ?>
            </div>
        <?php endif; ?>

        <!-- ============ STATS ============ -->
        <section class="stats">

            <article class="stat stat-featured rv" style="--d:.08s">
                <div class="stat-glow"></div>
                <p class="stat-label">TOTAL BALANCE</p>
                <h2 class="stat-value" data-count="<?= e(round($balance, 2)) ?>"><?= e(money($balance)) ?></h2>
                <p class="stat-foot">Income − expenses, all time</p>
            </article>

            <article class="stat rv" style="--d:.14s">
                <div class="stat-top">
                    <p class="stat-label">INCOME · THIS MONTH</p>
                    <span class="stat-icon up"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg></span>
                </div>
                <h2 class="stat-value" data-count="<?= e(round($month["income"], 2)) ?>"><?= e(money($month["income"])) ?></h2>
                <?php if ($income_change !== null): ?>
                    <p class="stat-foot">
                        <span class="chip <?= $income_change >= 0 ? "good" : "bad" ?>"><?= $income_change >= 0 ? "▲" : "▼" ?> <?= abs($income_change) ?>%</span>
                        vs last month
                    </p>
                <?php else: ?>
                    <p class="stat-foot">Money in this month</p>
                <?php endif; ?>
            </article>

            <article class="stat rv" style="--d:.2s">
                <div class="stat-top">
                    <p class="stat-label">EXPENSES · THIS MONTH</p>
                    <span class="stat-icon down"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span>
                </div>
                <h2 class="stat-value" data-count="<?= e(round($month["expense"], 2)) ?>"><?= e(money($month["expense"])) ?></h2>
                <?php if ($expense_change !== null): ?>
                    <p class="stat-foot">
                        <span class="chip <?= $expense_change <= 0 ? "good" : "bad" ?>"><?= $expense_change >= 0 ? "▲" : "▼" ?> <?= abs($expense_change) ?>%</span>
                        vs last month
                    </p>
                <?php else: ?>
                    <p class="stat-foot">Money out this month</p>
                <?php endif; ?>
            </article>

            <article class="stat rv" style="--d:.26s">
                <div class="stat-top">
                    <p class="stat-label">SAVINGS RATE</p>
                </div>
                <div class="rate">
                    <svg class="rate-ring" viewBox="0 0 36 36" aria-hidden="true">
                        <circle class="rate-bg" cx="18" cy="18" r="15"></circle>
                        <circle class="rate-fg" cx="18" cy="18" r="15"
                                style="--p:<?= max(0, $savings_rate) ?>"></circle>
                    </svg>
                    <h2 class="stat-value"><?= $savings_rate ?><small>%</small></h2>
                </div>
                <p class="stat-foot">Of this month's income kept</p>
            </article>

        </section>

        <!-- ============ CHARTS ============ -->
        <section class="grid">

            <article class="card rv" style="--d:.32s">
                <div class="card-head">
                    <div>
                        <h3>Cash flow</h3>
                        <p>Last 6 months</p>
                    </div>
                    <div class="legend">
                        <span><i class="dot in"></i>Income</span>
                        <span><i class="dot out"></i>Expenses</span>
                    </div>
                </div>

                <?php if ($has_chart_data): ?>
                    <div class="chart">
                        <?php $k = 0; foreach ($months as $m):
                            $ih = $m["income"] > 0 ? max(3, round($m["income"] / $chart_max * 100, 1)) : 0;
                            $eh = $m["expense"] > 0 ? max(3, round($m["expense"] / $chart_max * 100, 1)) : 0;
                        ?>
                            <div class="month" style="--k:<?= $k++ ?>" tabindex="0">
                                <div class="tip">
                                    <strong><?= e($m["full"]) ?></strong>
                                    <span><i class="dot in"></i><?= e(money($m["income"])) ?></span>
                                    <span><i class="dot out"></i><?= e(money($m["expense"])) ?></span>
                                </div>
                                <div class="pair">
                                    <div class="bar in" style="--h:<?= $ih ?>%"></div>
                                    <div class="bar out" style="--h:<?= $eh ?>%"></div>
                                </div>
                                <span class="month-label"><?= e($m["label"]) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-art"></div>
                        <p><strong>Nothing to chart yet</strong></p>
                        <p>Add your first transaction to see your cash flow.</p>
                    </div>
                <?php endif; ?>
            </article>

            <article class="card rv" style="--d:.38s">
                <div class="card-head">
                    <div>
                        <h3>Where it went</h3>
                        <p>Top spending · <?= e(date("F")) ?></p>
                    </div>
                </div>

                <?php if ($breakdown): ?>
                    <?php $spent = max($month["expense"], 0.01); $j = 0; ?>
                    <div class="breakdown">
                        <?php foreach ($breakdown as $b):
                            $pct = round((float) $b["total"] / $spent * 100);
                        ?>
                            <div class="bd-row" style="--c:<?= e($b["color"]) ?>; --w:<?= $pct ?>%; --k:<?= $j++ ?>">
                                <div class="bd-top">
                                    <span><i class="dot"></i><?= e($b["name"]) ?></span>
                                    <b><?= e(money((float) $b["total"])) ?></b>
                                </div>
                                <div class="bd-track"><i></i></div>
                                <small><?= $pct ?>% of spending</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-art"></div>
                        <p><strong>No expenses this month</strong></p>
                        <p>Your top categories will show up here.</p>
                    </div>
                <?php endif; ?>
            </article>

        </section>

        <!-- ============ RECENT ============ -->
        <section class="card rv" style="--d:.44s">
            <div class="card-head">
                <div>
                    <h3>Recent transactions</h3>
                    <p>Your latest activity</p>
                </div>
                <a href="transactions.php" class="link-all">View all &rarr;</a>
            </div>

            <?php if ($recent): ?>
                <ul class="tx-list">
                    <?php foreach ($recent as $t):
                        $is_in = $t["type"] === "income";
                        $title = $t["note"] !== "" ? $t["note"] : $t["category"];
                    ?>
                        <li class="tx">
                            <span class="tx-icon <?= $is_in ? "up" : "down" ?>">
                                <?php if ($is_in): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                                <?php endif; ?>
                            </span>

                            <div class="tx-main">
                                <strong><?= e($title) ?></strong>
                                <span>
                                    <i class="dot" style="--c:<?= e($t["color"]) ?>"></i><?= e($t["category"]) ?>
                                    <em>·</em> <?= e(nice_date($t["transaction_date"])) ?>
                                </span>
                            </div>

                            <b class="tx-amount <?= $is_in ? "plus" : "" ?>">
                                <?= $is_in ? "+" : "−" ?> <?= e(money((float) $t["amount"])) ?>
                            </b>

                            <form method="POST" action="dashboard.php" class="tx-del" data-confirm="Delete this transaction?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $t["id"] ?>">
                                <button type="submit" aria-label="Delete transaction">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/></svg>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-art"></div>
                    <p><strong>No transactions yet</strong></p>
                    <p>Track your first income or expense to get started.</p>
                    <button type="button" class="btn-primary small" data-open-modal>Add transaction</button>
                </div>
            <?php endif; ?>
        </section>

    </main>
</div>

<!-- ================= ADD TRANSACTION MODAL ================= -->
<dialog class="modal" id="txModal" data-open="<?= $open_modal ? "1" : "0" ?>">

    <form method="POST" action="dashboard.php" id="txForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">

        <div class="modal-head">
            <div>
                <h3>Add transaction</h3>
                <p>Record money coming in or going out.</p>
            </div>
            <button type="button" class="modal-x" id="closeModal" aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <?php if (!empty($errors) && $open_modal): ?>
            <div class="flash flash-error" role="alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/></svg>
                <div>
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="seg-wrap" role="radiogroup" aria-label="Type">
            <label class="seg">
                <input type="radio" name="type" value="expense" <?= $old["type"] === "expense" ? "checked" : "" ?>>
                <span>Expense</span>
            </label>
            <label class="seg">
                <input type="radio" name="type" value="income" <?= $old["type"] === "income" ? "checked" : "" ?>>
                <span>Income</span>
            </label>
        </div>

        <div class="m-field">
            <label for="amount">Amount</label>
            <div class="m-input amount">
                <span>₨</span>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01"
                       placeholder="0" inputmode="decimal" required
                       value="<?= e($old["amount"]) ?>">
            </div>
        </div>

        <div class="m-row">
            <div class="m-field">
                <label for="category_id">Category</label>
                <div class="m-input">
                    <select id="category_id" name="category_id" required data-selected="<?= (int) $old["category_id"] ?>">
                        <option value="">Choose…</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c["id"] ?>" data-type="<?= e($c["type"]) ?>"
                                <?= (int) $old["category_id"] === (int) $c["id"] ? "selected" : "" ?>>
                                <?= e($c["name"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="m-field">
                <label for="date">Date</label>
                <div class="m-input">
                    <input type="date" id="date" name="date" max="<?= e($today) ?>"
                           value="<?= e($old["date"]) ?>" required>
                </div>
            </div>
        </div>

        <div class="m-field">
            <label for="note">Note <em>(optional)</em></label>
            <div class="m-input">
                <input type="text" id="note" name="note" maxlength="120"
                       placeholder="e.g. Weekly groceries" value="<?= e($old["note"]) ?>">
            </div>
        </div>

        <button type="submit" class="btn-primary wide" id="saveBtn">
            <span>Save transaction</span>
        </button>
    </form>

</dialog>

</body>
</html> 