// =========================================================
// FINORA — LOGIN PAGE SCRIPT
// =========================================================

(function () {
    "use strict";

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // ---------------------------------------------------------
    // 1) WELCOME INTRO — CSS drives the 2s timing,
    //    JS just removes the overlay afterwards
    // ---------------------------------------------------------
    const overlay = document.getElementById("welcomeOverlay");

    function removeOverlay() {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }

    if (overlay) {
        if (reduceMotion) removeOverlay();
        else setTimeout(removeOverlay, 2200);
    }

    // ---------------------------------------------------------
    // 2) 3D TILT ON PREVIEW CARD (desktop only)
    // ---------------------------------------------------------
    const brandPanel = document.getElementById("brandPanel");
    const stage = document.getElementById("previewStage");
    const card = stage ? stage.querySelector(".preview-card") : null;

    if (brandPanel && card && !reduceMotion && window.matchMedia("(hover: hover)").matches) {
        brandPanel.addEventListener("mousemove", function (e) {
            const r = brandPanel.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width - 0.5;
            const y = (e.clientY - r.top) / r.height - 0.5;
            card.style.setProperty("--ry", (x * 8).toFixed(2) + "deg");
            card.style.setProperty("--rx", (-y * 8).toFixed(2) + "deg");
        });

        brandPanel.addEventListener("mouseleave", function () {
            card.style.setProperty("--ry", "0deg");
            card.style.setProperty("--rx", "0deg");
        });
    }

    // ---------------------------------------------------------
    // 3) SHOW / HIDE PASSWORD
    // ---------------------------------------------------------
    document.querySelectorAll(".toggle-pass").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;

            const show = input.type === "password";
            input.type = show ? "text" : "password";
            btn.classList.toggle("is-visible", show);
            btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
            input.focus();
        });
    });

    // ---------------------------------------------------------
    // 4) CAPS LOCK WARNING
    // ---------------------------------------------------------
    const passInput = document.getElementById("password");
    const capsHint = document.getElementById("capsHint");

    function checkCaps(e) {
        if (!capsHint || typeof e.getModifierState !== "function") return;
        capsHint.classList.toggle("show", e.getModifierState("CapsLock"));
    }

    if (passInput) {
        passInput.addEventListener("keydown", checkCaps);
        passInput.addEventListener("keyup", checkCaps);
        passInput.addEventListener("blur", function () {
            if (capsHint) capsHint.classList.remove("show");
        });
    }

    // ---------------------------------------------------------
    // 5) LIVE FIELD STATE
    // ---------------------------------------------------------
    const emailInput = document.getElementById("email");

    if (emailInput) {
        emailInput.addEventListener("input", function () {
            if (!emailInput.value) {
                emailInput.classList.remove("is-valid", "is-invalid");
                return;
            }
            const ok = emailInput.checkValidity();
            emailInput.classList.toggle("is-valid", ok);
            emailInput.classList.toggle("is-invalid", !ok);
        });
    }

    // ---------------------------------------------------------
    // 6) SUBMIT — loading state, double-submit guard,
    //    and lock countdown (after too many wrong tries)
    // ---------------------------------------------------------
    const form = document.getElementById("loginForm");
    const submitBtn = document.getElementById("submitBtn");
    const btnLabel = submitBtn ? submitBtn.querySelector(".btn-label") : null;
    let submitting = false;

    if (form && submitBtn) {

        form.addEventListener("submit", function (e) {
            if (submitting || submitBtn.disabled) {
                e.preventDefault();
                return;
            }

            if (!form.checkValidity()) return; // browser shows its own messages

            submitting = true;
            submitBtn.classList.add("is-loading");
            submitBtn.setAttribute("aria-busy", "true");
        });

        // Lock countdown
        let remaining = parseInt(form.dataset.lock, 10) || 0;

        if (remaining > 0 && btnLabel) {
            const original = btnLabel.textContent;
            submitBtn.disabled = true;

            const timer = setInterval(function () {
                remaining--;

                if (remaining <= 0) {
                    clearInterval(timer);
                    submitBtn.disabled = false;
                    btnLabel.textContent = original;
                    return;
                }

                btnLabel.textContent = "Try again in " + remaining + "s";
            }, 1000);

            btnLabel.textContent = "Try again in " + remaining + "s";
        }
    }

    // ---------------------------------------------------------
    // 7) AUTOFOCUS (after the doors open)
    // ---------------------------------------------------------
    setTimeout(function () {
        if (!emailInput || !passInput) return;
        if (submitBtn && submitBtn.disabled) return;
        (emailInput.value ? passInput : emailInput).focus({ preventScroll: true });
    }, reduceMotion ? 100 : 1700);

    // Reset state when returning via the browser's back button
    window.addEventListener("pageshow", function (e) {
        if (e.persisted && submitBtn) {
            submitting = false;
            submitBtn.classList.remove("is-loading");
            submitBtn.removeAttribute("aria-busy");
        }
    });
})();