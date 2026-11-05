// =========================================================
// FINORA — DASHBOARD SCRIPT
// =========================================================

(function () {
    "use strict";

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // ---------------------------------------------------------
    // 0) PAGE INTRO — CSS runs the 2.4s timing, JS removes it afterwards
    // ---------------------------------------------------------
    const intro = document.getElementById("pgIntro");
    const INTRO_MS = (intro && !reduceMotion) ? 2000 : 0;

    if (intro) {
        if (reduceMotion) intro.remove();
        else setTimeout(function () { intro.remove(); }, 2600);
    }

    // ---------------------------------------------------------
    // 1) COUNT-UP NUMBERS
    // ---------------------------------------------------------
    function format(n) {
        const neg = n < 0;
        const abs = Math.abs(n);
        const whole = Math.abs(abs - Math.round(abs)) < 0.005;
        const text = new Intl.NumberFormat("en-PK", {
            minimumFractionDigits: whole ? 0 : 2,
            maximumFractionDigits: whole ? 0 : 2
        }).format(abs);

        return (neg ? "−" : "") + "₨ " + text;
    }

    if (!reduceMotion) {
        document.querySelectorAll("[data-count]").forEach(function (el, i) {
            const target = parseFloat(el.dataset.count);
            if (isNaN(target) || target === 0) return;

            const delay = INTRO_MS + 350 + i * 90;
            const duration = 1300;

            el.textContent = format(0);

            setTimeout(function () {
                const t0 = performance.now();

                (function step(now) {
                    const p = Math.min((now - t0) / duration, 1);
                    const eased = 1 - Math.pow(1 - p, 4);
                    el.textContent = format(target * eased);
                    if (p < 1) requestAnimationFrame(step);
                    else el.textContent = format(target);
                })(t0);
            }, delay);
        });
    }

    // ---------------------------------------------------------
    // 2) ADD-TRANSACTION MODAL
    // ---------------------------------------------------------
    const modal = document.getElementById("txModal");
    const openers = document.querySelectorAll("#openModal, [data-open-modal]");
    const closeBtn = document.getElementById("closeModal");
    const form = document.getElementById("txForm");
    const select = document.getElementById("category_id");
    const saveBtn = document.getElementById("saveBtn");

    // Category list is filtered by the chosen type (Expense / Income)
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

    if (select && form) {
        buildOptions(select.dataset.selected);

        form.querySelectorAll('input[name="type"]').forEach(function (r) {
            r.addEventListener("change", function () { buildOptions(""); });
        });
    }

    function openModal() {
        if (!modal) return;
        if (typeof modal.showModal === "function") modal.showModal();
        else modal.setAttribute("open", "");

        const amount = document.getElementById("amount");
        if (amount) setTimeout(function () { amount.focus(); }, 120);
    }

    function closeModal() {
        if (!modal) return;
        if (typeof modal.close === "function") modal.close();
        else modal.removeAttribute("open");
    }

    openers.forEach(function (b) { b.addEventListener("click", openModal); });
    if (closeBtn) closeBtn.addEventListener("click", closeModal);

    // Click on the dark backdrop closes the modal
    if (modal) {
        modal.addEventListener("click", function (e) {
            if (e.target === modal) closeModal();
        });

        // If the server sent back validation errors, reopen the modal
        if (modal.dataset.open === "1") openModal();
    }

    // Loading state on save (+ double-submit guard)
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
    // 3) DELETE CONFIRMATION
    // ---------------------------------------------------------
    document.querySelectorAll("form[data-confirm]").forEach(function (f) {
        f.addEventListener("submit", function (e) {
            if (!window.confirm(f.dataset.confirm)) e.preventDefault();
        });
    });

    // ---------------------------------------------------------
    // 4) FLASH MESSAGE AUTO-HIDE
    // ---------------------------------------------------------
    const flash = document.getElementById("flash");

    if (flash) {
        setTimeout(function () {
            flash.classList.add("hide");
            setTimeout(function () { flash.remove(); }, 600);
        }, 4500);
    }

    // ---------------------------------------------------------
    // 5) LOGOUT — small loading feel
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

    // Reset loading state if user comes back with the back button
    window.addEventListener("pageshow", function (e) {
        if (e.persisted) {
            saving = false;
            if (saveBtn) saveBtn.classList.remove("is-loading");
        }
    });
})();