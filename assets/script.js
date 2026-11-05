// =========================================================
// FINORA — PREMIUM AUTH UI SCRIPT
// =========================================================

(function () {
    "use strict";

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // ---------------------------------------------------------
    // 1) INTRO (4s total — timing is driven by CSS, JS only
    //    syncs the % counter and cleans up the overlay)
    // ---------------------------------------------------------
    const introOverlay = document.getElementById("introOverlay");
    const introCount = document.getElementById("introCount");

    const INTRO_COUNT_START = 600;   // ms — matches .intro-bar delay
    const INTRO_COUNT_LENGTH = 2500; // ms — matches .intro-bar duration

    function easeInOut(t) {
        return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
    }

    function removeIntro() {
        if (introOverlay && introOverlay.parentNode) {
            introOverlay.parentNode.removeChild(introOverlay);
        }
    }

    if (introOverlay) {
        if (reduceMotion) {
            removeIntro();
        } else {
            const t0 = performance.now();

            (function tick(now) {
                const elapsed = now - t0 - INTRO_COUNT_START;
                const p = Math.min(Math.max(elapsed / INTRO_COUNT_LENGTH, 0), 1);

                if (introCount) {
                    introCount.textContent = Math.round(easeInOut(p) * 100) + "%";
                }

                if (p < 1) requestAnimationFrame(tick);
            })(t0);

            // Curtain animation ends at exactly 4s — remove from DOM after.
            introOverlay.addEventListener("animationend", function (event) {
                if (event.animationName === "finoraCurtain") removeIntro();
            });

            // Safety net
            setTimeout(removeIntro, 4300);
        }
    }

    // ---------------------------------------------------------
    // 2) BALANCE COUNT-UP (runs as the page reveals)
    // ---------------------------------------------------------
    const countEl = document.getElementById("countUp");

    if (countEl && !reduceMotion) {
        const target = parseInt(countEl.dataset.count, 10) || 0;
        const fmt = new Intl.NumberFormat("en-PK");
        countEl.textContent = "0";

        setTimeout(function () {
            const start = performance.now();
            const duration = 1700;

            (function step(now) {
                const p = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - p, 4);
                countEl.textContent = fmt.format(Math.round(target * eased));
                if (p < 1) requestAnimationFrame(step);
            })(start);
        }, 3900);
    }

    // ---------------------------------------------------------
    // 3) 3D TILT ON PREVIEW CARD (desktop only)
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
    // 4) SHOW / HIDE PASSWORD
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
    // 5) PASSWORD STRENGTH + MATCH CHECK + LIVE FIELD STATE
    // ---------------------------------------------------------
    const form = document.getElementById("registerForm");
    const nameInput = document.getElementById("name");
    const emailInput = document.getElementById("email");
    const passInput = document.getElementById("password");
    const confirmInput = document.getElementById("confirm_password");
    const strengthBox = document.getElementById("strength");
    const strengthLabel = document.getElementById("strengthLabel");
    const matchHint = document.getElementById("matchHint");

    const labels = ["Use 8+ characters", "Weak", "Fair", "Good", "Strong"];

    function scorePassword(v) {
        if (!v) return 0;
        if (v.length < 8) return 1;

        let score = 1;
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;

        return Math.min(score, 4);
    }

    function updateStrength() {
        if (!passInput || !strengthBox) return;
        const lvl = scorePassword(passInput.value);
        strengthBox.dataset.level = lvl;
        strengthLabel.textContent = labels[lvl];
    }

    function updateMatch() {
        if (!confirmInput || !matchHint) return;

        if (!confirmInput.value) {
            matchHint.className = "match-hint";
            matchHint.textContent = "";
            confirmInput.classList.remove("is-valid", "is-invalid");
            return;
        }

        const ok = confirmInput.value === passInput.value;
        matchHint.className = "match-hint show " + (ok ? "ok" : "bad");
        matchHint.textContent = ok ? "✓ Passwords match" : "Passwords don't match yet";
        confirmInput.classList.toggle("is-valid", ok);
        confirmInput.classList.toggle("is-invalid", !ok);
    }

    function markField(input, valid) {
        if (!input) return;
        if (!input.value) {
            input.classList.remove("is-valid", "is-invalid");
            return;
        }
        input.classList.toggle("is-valid", valid);
        input.classList.toggle("is-invalid", !valid);
    }

    if (passInput) {
        passInput.addEventListener("input", function () {
            updateStrength();
            markField(passInput, passInput.value.length >= 8);
            updateMatch();
        });
    }

    if (confirmInput) confirmInput.addEventListener("input", updateMatch);

    if (nameInput) {
        nameInput.addEventListener("input", function () {
            markField(nameInput, nameInput.value.trim().length > 1);
        });
    }

    if (emailInput) {
        emailInput.addEventListener("input", function () {
            markField(emailInput, emailInput.checkValidity());
        });
    }

    updateStrength();

    // ---------------------------------------------------------
    // 6) SUBMIT — loading state + double-submit guard
    // ---------------------------------------------------------
    const submitBtn = document.getElementById("submitBtn");
    let submitting = false;

    if (form && submitBtn) {
        form.addEventListener("submit", function (e) {
            if (submitting) {
                e.preventDefault();
                return;
            }

            if (!form.checkValidity()) return; // let the browser show its messages

            submitting = true;
            submitBtn.classList.add("is-loading");
            submitBtn.setAttribute("aria-busy", "true");
        });
    }

    // Reset state when coming back via the browser's back button
    window.addEventListener("pageshow", function (e) {
        if (e.persisted && submitBtn) {
            submitting = false;
            submitBtn.classList.remove("is-loading");
            submitBtn.removeAttribute("aria-busy");
        }
    });
})();