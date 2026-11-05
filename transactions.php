<?php
require_once "config/db.php";
require_once "config/auth.php";

$uid = (int) $_SESSION["user_id"];
$user_name = trim($_SESSION["user_name"] ?? "there");
$first_name = explode(" ", $user_name)[0] ?: "there";
$initial = mb_strtoupper(mb_substr($first_name, 0, 1));

$today = date("Y-m-d");
$per_page = 12;

// Page intro: plays when you ARRIVE here from another page (or right after login),
// but not on reload / filtering / saving on this same page.
$show_intro = ($_SESSION["last_page"] ?? "") !== "transactions";
$_SESSION["last_page"] = "transactions";

// =========================================================
// FILTER HELPERS
// =========================================================
$sort_map = [
    "newest"  => "t.transaction_date DESC, t.id DESC",
    "oldest"  => "t.transaction_date ASC, t.id ASC",
    "highest" => "t.amount DESC, t.id DESC",
    "lowest"  => "t.amount ASC, t.id ASC",
];

function clean_date($v): string
{
    $v = trim((string) $v);
    $d = DateTime::createFromFormat("Y-m-d", $v);
    return ($d && $d->format("Y-m-d") === $v) ? $v : "";
}

function read_filters(array $src, array $sort_map): array
{
    $type = $src["type"] ?? "";
    $sort = $src["sort"] ?? "newest";

    return [
        "q"        => mb_substr(trim((string) ($src["q"] ?? "")), 0, 60),
        "type"     => in_array($type, ["income", "expense"], true) ? $type : "",
        "category" => max(0, (int) ($src["category"] ?? 0)),
        "from"     => clean_date($src["from"] ?? ""),
        "to"       => clean_date($src["to"] ?? ""),
        "sort"     => isset($sort_map[$sort]) ? $sort : "newest",
        "page"     => max(1, (int) ($src["page"] ?? 1)),
    ];
}

function build_qs(array $f): string
{
    $q = [];
    if ($f["q"] !== "")        $q["q"] = $f["q"];
    if ($f["type"] !== "")     $q["type"] = $f["type"];
    if ($f["category"] > 0)    $q["category"] = $f["category"];
    if ($f["from"] !== "")     $q["from"] = $f["from"];
    if ($f["to"] !== "")       $q["to"] = $f["to"];
    if ($f["sort"] !== "newest") $q["sort"] = $f["sort"];
    if ($f["page"] > 1)        $q["page"] = $f["page"];
    return http_build_query($q);
}

function page_url(array $f, int $page): string
{
    $f["page"] = $page;
    $qs = build_qs($f);
    return "transactions.php" . ($qs !== "" ? "?" . $qs : "");
}

function csv_safe(string $v): string
{
    // Stops Excel from running a cell as a formula
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// =========================================================
// ACTIONS (add / edit / delete)
// =========================================================
$errors = [];
$open_modal = false;
$modal_mode = "add";
$old = [
    "id" => 0,
    "type" => "expense",
    "amount" => "",
    "category_id" => 0,
    "date" => $today,
    "note" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    parse_str((string) ($_POST["qs"] ?? ""), $back_src);
    $back_f = read_filters(is_array($back_src) ? $back_src : [], $sort_map);
    $back_qs = build_qs($back_f);
    $back_url = "transactions.php" . ($back_qs !== "" ? "?" . $back_qs : "");

    if (!csrf_ok()) {

        $errors[] = "Your session expired. Please try again.";
        $open_modal = in_array($action, ["add", "edit"], true);
        $modal_mode = $action === "edit" ? "edit" : "add";

    } elseif ($action === "add" || $action === "edit") {

        $modal_mode = $action;

        $old["id"] = (int) ($_POST["id"] ?? 0);
        $old["type"] = ($_POST["type"] ?? "") === "income" ? "income" : "expense";
        $old["amount"] = trim($_POST["amount"] ?? "");
        $old["category_id"] = (int) ($_POST["category_id"] ?? 0);
        $old["date"] = trim($_POST["date"] ?? "");
        $old["note"] = trim($_POST["note"] ?? "");

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

        if (clean_date($old["date"]) === "") {
            $errors[] = "Please choose a valid date.";
        } elseif ($old["date"] > $today) {
            $errors[] = "Date can't be in the future.";
        }

        if (mb_strlen($old["note"]) > 120) {
            $errors[] = "Note must be 120 characters or less.";
        }

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

        if (empty($errors) && $action === "edit") {
            $own = db_all(
                $conn,
                "SELECT id FROM transactions WHERE id = ? AND user_id = ? LIMIT 1",
                "ii",
                [$old["id"], $uid]
            );

            if (!$own) {
                $errors[] = "That transaction no longer exists.";
            }
        }

        if (empty($errors)) {

            if ($action === "add") {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO transactions (user_id, category_id, type, amount, note, transaction_date)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt, "iisdss",
                        $uid, $old["category_id"], $old["type"], $amount, $old["note"], $old["date"]
                    );
                }
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE transactions
                     SET category_id = ?, type = ?, amount = ?, note = ?, transaction_date = ?
                     WHERE id = ? AND user_id = ?"
                );

                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt, "isdssii",
                        $old["category_id"], $old["type"], $amount, $old["note"], $old["date"], $old["id"], $uid
                    );
                }
            }

            if (!empty($stmt) && mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION["flash"] = $action === "add"
                    ? ucfirst($old["type"]) . " of " . money($amount) . " added."
                    : "Transaction updated.";
                header("Location: " . $back_url);
                exit;
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

        header("Location: " . $back_url);
        exit;
    }
}

$page_error = (!$open_modal && $errors) ? $errors[0] : null;

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

// =========================================================
// QUERY (filters)
// =========================================================
$f = read_filters($_GET, $sort_map);

$from_sql = "transactions t JOIN categories c ON c.id = t.category_id";
$where = "t.user_id = ?";
$types = "i";
$params = [$uid];

if ($f["q"] !== "") {
    $like = "%" . addcslashes($f["q"], "%_\\") . "%";
    $where .= " AND (t.note LIKE ? OR c.name LIKE ?)";
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}

if ($f["type"] !== "") {
    $where .= " AND t.type = ?";
    $types .= "s";
    $params[] = $f["type"];
}

if ($f["category"] > 0) {
    $where .= " AND t.category_id = ?";
    $types .= "i";
    $params[] = $f["category"];
}

if ($f["from"] !== "") {
    $where .= " AND t.transaction_date >= ?";
    $types .= "s";
    $params[] = $f["from"];
}

if ($f["to"] !== "") {
    $where .= " AND t.transaction_date <= ?";
    $types .= "s";
    $params[] = $f["to"];
}

$order = $sort_map[$f["sort"]];

// ---------- CSV export ----------
if (($_GET["export"] ?? "") === "csv") {

    $rows = db_all(
        $conn,
        "SELECT t.transaction_date, t.type, c.name AS category, t.note, t.amount
         FROM $from_sql WHERE $where ORDER BY $order",
        $types,
        $params
    );

    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="finora-transactions-' . date("Y-m-d") . '.csv"');

    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it properly
    fputcsv($out, ["Date", "Type", "Category", "Note", "Amount (PKR)"], ",", '"', "\\");

    foreach ($rows as $r) {
        fputcsv($out, [
            $r["transaction_date"],
            ucfirst($r["type"]),
            csv_safe($r["category"]),
            csv_safe($r["note"]),
            $r["amount"],
        ], ",", '"', "\\");
    }

    fclose($out);
    exit;
}

// ---------- Summary of the filtered result ----------
$sum = [
    "income"  => ["n" => 0, "total" => 0.0],
    "expense" => ["n" => 0, "total" => 0.0],
];

foreach (db_all(
    $conn,
    "SELECT t.type, COUNT(*) AS n, SUM(t.amount) AS total FROM $from_sql WHERE $where GROUP BY t.type",
    $types,
    $params
) as $r) {
    $sum[$r["type"]] = ["n" => (int) $r["n"], "total" => (float) $r["total"]];
}

$total_count = $sum["income"]["n"] + $sum["expense"]["n"];
$net = $sum["income"]["total"] - $sum["expense"]["total"];

$pages = max(1, (int) ceil($total_count / $per_page));
$f["page"] = min($f["page"], $pages);
$offset = ($f["page"] - 1) * $per_page;

$rows = db_all(
    $conn,
    "SELECT t.id, t.type, t.amount, t.note, t.transaction_date, t.category_id,
            c.name AS category, c.color
     FROM $from_sql WHERE $where ORDER BY $order
     LIMIT " . (int) $per_page . " OFFSET " . (int) $offset,
    $types,
    $params
);

$categories = db_all(
    $conn,
    "SELECT id, name, type FROM categories ORDER BY FIELD(type, 'expense', 'income'), name"
);

$has_filters = $f["q"] !== "" || $f["type"] !== "" || $f["category"] > 0
    || $f["from"] !== "" || $f["to"] !== "";

$current_qs = build_qs($f);

function nice_date(string $d): string
{
    if ($d === date("Y-m-d")) {
        return "Today";
    }
    if ($d === date("Y-m-d", strtotime("-1 day"))) {
        return "Yesterday";
    }
    return date("d M Y", strtotime($d));
}
?>

<!DOCTYPE html>
<html lang="en"<?= $show_intro ? "" : ' class="no-intro"' ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b1424">

    <title>Transactions | Finora</title>

    <link rel="stylesheet" href="assets/dashboard.css">
    <link rel="stylesheet" href="assets/transactions.css">
    <script src="assets/transactions.js" defer></script>
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

        <h2 class="pi-title"><span>Transactions</span></h2>
        <p class="pi-sub">Every rupee, accounted for.</p>

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

            <a href="transactions.php" class="nav-item active">
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
                <p class="eyebrow">YOUR ACTIVITY</p>
                <h1>Transactions</h1>
            </div>

            <div class="top-actions">
                <a class="btn-ghost" href="transactions.php?<?= e($current_qs !== "" ? $current_qs . "&" : "") ?>export=csv">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 11l5 5 5-5"/><path d="M5 20h14"/></svg>
                    Export CSV
                </a>

                <button type="button" class="btn-primary" id="openModal">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Add transaction
                </button>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash rv" style="--d:.05s" id="flash" role="status">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.8"/></svg>
                <?= e($flash) ?>
            </div>
        <?php endif; ?>

        <?php if ($page_error): ?>
            <div class="flash flash-error" role="alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/></svg>
                <?= e($page_error) ?>
            </div>
        <?php endif; ?>

        <!-- ============ SUMMARY ============ -->
        <section class="mini-stats">

            <article class="mini rv" style="--d:.06s">
                <p>RESULTS</p>
                <h2><?= (int) $total_count ?></h2>
                <span><?= $has_filters ? "matching filters" : "transactions" ?></span>
            </article>

            <article class="mini rv" style="--d:.12s">
                <p>INCOME</p>
                <h2 class="pos"><?= e(money($sum["income"]["total"])) ?></h2>
                <span><?= (int) $sum["income"]["n"] ?> entries</span>
            </article>

            <article class="mini rv" style="--d:.18s">
                <p>EXPENSES</p>
                <h2><?= e(money($sum["expense"]["total"])) ?></h2>
                <span><?= (int) $sum["expense"]["n"] ?> entries</span>
            </article>

            <article class="mini rv" style="--d:.24s">
                <p>NET</p>
                <h2 class="<?= $net >= 0 ? "pos" : "neg" ?>"><?= e(money($net)) ?></h2>
                <span><?= $net >= 0 ? "saved" : "overspent" ?></span>
            </article>

        </section>

        <!-- ============ FILTERS ============ -->
        <form method="GET" action="transactions.php" class="filters card rv" style="--d:.3s" id="filterForm">

            <div class="f-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4-4"/></svg>
                <input type="search" name="q" id="searchInput" placeholder="Search notes or categories…"
                       maxlength="60" value="<?= e($f["q"]) ?>" autocomplete="off">
            </div>

            <div class="f-item">
                <select name="type" aria-label="Type">
                    <option value="">All types</option>
                    <option value="income" <?= $f["type"] === "income" ? "selected" : "" ?>>Income</option>
                    <option value="expense" <?= $f["type"] === "expense" ? "selected" : "" ?>>Expenses</option>
                </select>
            </div>

            <div class="f-item">
                <select name="category" aria-label="Category">
                    <option value="0">All categories</option>
                    <?php foreach (["expense" => "Expenses", "income" => "Income"] as $t => $label): ?>
                        <optgroup label="<?= e($label) ?>">
                            <?php foreach ($categories as $c): if ($c["type"] !== $t) continue; ?>
                                <option value="<?= (int) $c["id"] ?>" <?= $f["category"] === (int) $c["id"] ? "selected" : "" ?>>
                                    <?= e($c["name"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="f-item f-date">
                <input type="date" name="from" aria-label="From date" max="<?= e($today) ?>" value="<?= e($f["from"]) ?>">
                <span>→</span>
                <input type="date" name="to" aria-label="To date" max="<?= e($today) ?>" value="<?= e($f["to"]) ?>">
            </div>

            <div class="f-item">
                <select name="sort" aria-label="Sort">
                    <option value="newest" <?= $f["sort"] === "newest" ? "selected" : "" ?>>Newest first</option>
                    <option value="oldest" <?= $f["sort"] === "oldest" ? "selected" : "" ?>>Oldest first</option>
                    <option value="highest" <?= $f["sort"] === "highest" ? "selected" : "" ?>>Highest amount</option>
                    <option value="lowest" <?= $f["sort"] === "lowest" ? "selected" : "" ?>>Lowest amount</option>
                </select>
            </div>

            <?php if ($has_filters): ?>
                <a href="transactions.php" class="f-reset">Reset</a>
            <?php endif; ?>

        </form>

        <!-- ============ LIST ============ -->
        <section class="card list-card rv" style="--d:.36s">

            <?php if ($rows): ?>

                <ul class="tx-list">
                    <?php foreach ($rows as $i => $t):
                        $is_in = $t["type"] === "income";
                        $title = $t["note"] !== "" ? $t["note"] : $t["category"];
                    ?>
                        <li class="tx" style="--r:<?= (int) $i ?>">
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

                            <div class="tx-actions">
                                <button type="button" class="tx-edit" aria-label="Edit transaction"
                                        data-id="<?= (int) $t["id"] ?>"
                                        data-type="<?= e($t["type"]) ?>"
                                        data-amount="<?= e($t["amount"]) ?>"
                                        data-category="<?= (int) $t["category_id"] ?>"
                                        data-date="<?= e($t["transaction_date"]) ?>"
                                        data-note="<?= e($t["note"]) ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></svg>
                                </button>

                                <form method="POST" action="transactions.php" class="tx-del" data-confirm="Delete this transaction?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $t["id"] ?>">
                                    <input type="hidden" name="qs" value="<?= e($current_qs) ?>">
                                    <button type="submit" aria-label="Delete transaction">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/></svg>
                                    </button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($pages > 1): ?>
                    <nav class="pager" aria-label="Pages">
                        <span class="pager-info">
                            Showing <?= $offset + 1 ?>–<?= $offset + count($rows) ?> of <?= (int) $total_count ?>
                        </span>

                        <div class="pager-links">
                            <?php if ($f["page"] > 1): ?>
                                <a href="<?= e(page_url($f, $f["page"] - 1)) ?>" aria-label="Previous page">&larr;</a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $f["page"] - 2);
                            $end = min($pages, $f["page"] + 2);
                            for ($p = $start; $p <= $end; $p++):
                            ?>
                                <a href="<?= e(page_url($f, $p)) ?>" class="<?= $p === $f["page"] ? "current" : "" ?>"><?= $p ?></a>
                            <?php endfor; ?>

                            <?php if ($f["page"] < $pages): ?>
                                <a href="<?= e(page_url($f, $f["page"] + 1)) ?>" aria-label="Next page">&rarr;</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>

            <?php elseif ($has_filters): ?>

                <div class="empty">
                    <div class="empty-art"></div>
                    <p><strong>No matching transactions</strong></p>
                    <p>Try a different search or clear your filters.</p>
                    <a href="transactions.php" class="btn-primary small">Reset filters</a>
                </div>

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

<!-- ================= ADD / EDIT MODAL ================= -->
<dialog class="modal" id="txModal"
        data-open="<?= $open_modal ? "1" : "0" ?>"
        data-mode="<?= e($modal_mode) ?>"
        data-today="<?= e($today) ?>">

    <form method="POST" action="transactions.php" id="txForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="fAction" value="<?= e($modal_mode) ?>">
        <input type="hidden" name="id" id="fId" value="<?= (int) $old["id"] ?>">
        <input type="hidden" name="qs" value="<?= e($current_qs) ?>">

        <div class="modal-head">
            <div>
                <h3 id="modalTitle"><?= $modal_mode === "edit" ? "Edit transaction" : "Add transaction" ?></h3>
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
            <span id="saveLabel"><?= $modal_mode === "edit" ? "Save changes" : "Save transaction" ?></span>
        </button>
    </form>

</dialog>

</body>
</html>