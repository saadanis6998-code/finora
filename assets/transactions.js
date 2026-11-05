// =========================================================
// FINORA — TRANSACTIONS PAGE SCRIPT
// =========================================================

(function () {
    "use strict";

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // ---------------------------------------------------------
    // 1) INTRO — CSS runs the timing, JS removes it afterwards
    // ---------------------------------------------------------
    const intro = document.getElementById("pgIntro");

    if (intro) {
        if (reduceMotion) intro.remove();
        else setTimeout(function () { intro.remove(); }, 2600);
    }

    // ---------------------------------------------------------
    // 2) MODAL (add + edit)
    // ---------------------------------------------------------
    const modal = document.getElementById("txModal");
    const form = document.getElementById("txForm");
    const select = document.getElementById("category_id");
    const saveBtn = document.getElementById("saveBtn");
    const saveLabel = document.getElementById("saveLabel");
    const title = document.getElementById("modalTitle");

    const fAction = document.getElementById("fAction");
    const fId = document.getElementById("fId");
    const amount = document.getElementById("amount");
    const dateInput = document.getElementById("date");
    const note = document.getElementById("note");

    // Category list gets filtered by the chosen type
    let allOptions = [];

    if (select) {
        allOptions = Array.prototype.slice.call(select.querySelectorAll("option[data-type]"))
            .map(function (o) {
                return { value: o.value, text: o.textContent.trim(), type: o.dataset.type };
            });
    }

    function currentType() {
        const checked = form ? form.querySelector('input[name="type"]:checked') : null;
        return checked ? checked.value : "expense";
    }

    function buildOptions(keepValue) {
        if (!select) return;

        const type = currentType();
        select.innerHTML = "";

        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Choose…";
        select.appendChild(placeholder);

        allOptions.forEach(function (o) {
            if (o.type !== type) return;
            const opt = document.createElement("option");
            opt.value = o.value;
            opt.textContent = o.text;
            opt.dataset.type = o.type;
            if (keepValue && o.value === String(keepValue)) opt.selected = true;
            select.appendChild(opt);
        });
    }

    function setType(type) {
        const radio = form.querySelector('input[name="type"][value="' + type + '"]');
        if (radio) radio.checked = true;
    }

    function showModal() {
        if (!modal) return;
        if (typeof modal.showModal === "function") modal.showModal();
        else modal.setAttribute("open", "");
    }

    function closeModal() {
        if (!modal) return;
        if (typeof modal.close === "function") modal.close();
        else modal.removeAttribute("open");
    }

    function openAdd() {
        fAction.value = "add";
        fId.value = "0";
        title.textContent = "Add transaction";
        saveLabel.textContent = "Save transaction";

        setType("expense");
        buildOptions("");
        amount.value = "";
        dateInput.value = modal.dataset.today || "";
        note.value = "";

        showModal();
        setTimeout(function () { amount.focus(); }, 120);
    }

    function openEdit(btn) {
        fAction.value = "edit";
        fId.value = btn.dataset.id;
        title.textContent = "Edit transaction";
        saveLabel.textContent = "Save changes";

        setType(btn.dataset.type);
        buildOptions(btn.dataset.category);
        amount.value = btn.dataset.amount;
        dateInput.value = btn.dataset.date;
        note.value = btn.dataset.note;

        showModal();
        setTimeout(function () { amount.focus(); amount.select(); }, 120);
    }

    if (select && form) {
        buildOptions(select.dataset.selected);

        form.querySelectorAll('input[name="type"]').forEach(function (r) {
            r.addEventListener("change", function () { buildOptions(""); });
        });
    }

    document.querySelectorAll("#openModal, [data-open-modal]").forEach(function (b) {
        b.addEventListener("click", openAdd);
    });

    document.querySelectorAll(".tx-edit").forEach(function (b) {
        b.addEventListener("click", function () { openEdit(b); });
    });

    const closeBtn = document.getElementById("closeModal");
    if (closeBtn) closeBtn.addEventListener("click", closeModal);

    if (modal) {
        // Click on the dark backdrop closes it
        modal.addEventListener("click", function (e) {
            if (e.target === modal) closeModal();
        });

        // Server sent back validation errors → reopen with the typed values
        if (modal.dataset.open === "1") {
            showModal();
        }
    }

    // Loading state + double-submit guard
    let saving = false;

    if (form && saveBtn) {
        form.addEventListener("submit", function (e) {
            if (saving) {
                e.preventDefault();
                return;
            }
            if (!form.checkValidity()) return;

            saving = true;
            saveBtn.classList.add("is-loading");
        });
    }

    // ---------------------------------------------------------
    // 3) FILTERS — auto-apply
    // ---------------------------------------------------------
    const filterForm = document.getElementById("filterForm");
    const searchInput = document.getElementById("searchInput");

    if (filterForm) {
        filterForm.querySelectorAll("select, input[type='date']").forEach(function (el) {
            el.addEventListener("change", function () { filterForm.submit(); });
        });

        if (searchInput) {
            let timer = null;

            searchInput.addEventListener("input", function () {
                clearTimeout(timer);
                timer = setTimeout(function () { filterForm.submit(); }, 550);
            });

            // Keep the cursor at the end after the page reloads with a search
            if (searchInput.value) {
                const len = searchInput.value.length;
                if (document.activeElement === document.body) {
                    searchInput.focus({ preventScroll: true });
                    searchInput.setSelectionRange(len, len);
                }
            }
        }
    }

    // ---------------------------------------------------------
    // 4) DELETE CONFIRMATION
    // ---------------------------------------------------------
    document.querySelectorAll("form[data-confirm]").forEach(function (f) {
        f.addEventListener("submit", function (e) {
            if (!window.confirm(f.dataset.confirm)) e.preventDefault();
        });
    });

    // ---------------------------------------------------------
    // 5) FLASH AUTO-HIDE
    // ---------------------------------------------------------
    const flash = document.getElementById("flash");

    if (flash) {
        setTimeout(function () {
            flash.classList.add("hide");
            setTimeout(function () { flash.remove(); }, 600);
        }, 4500);
    }

    // ---------------------------------------------------------
    // 6) LOGOUT feel
    // ---------------------------------------------------------
    const logoutForm = document.getElementById("logoutForm");

    if (logoutForm) {
        logoutForm.addEventListener("submit", function () {
            const btn = logoutForm.querySelector("button");
            if (btn) {
                btn.style.opacity = ".6";
                btn.style.pointerEvents = "none";
            }
        });
    }

    // Reset state when returning with the browser back button
    window.addEventListener("pageshow", function (e) {
        if (e.persisted) {
            saving = false;
            if (saveBtn) saveBtn.classList.remove("is-loading");
        }
    });
})();