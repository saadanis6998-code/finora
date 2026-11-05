(function () {
    "use strict";

    /* ----------------------------------------------------------
       HELPERS
    ---------------------------------------------------------- */
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* ----------------------------------------------------------
       PAGE INTRO — same system as dashboard / transactions
    ---------------------------------------------------------- */
    var intro    = document.getElementById("pgIntro");
    var INTRO_MS = (intro && !reduceMotion) ? 2000 : 0;

    if (intro) {
        if (reduceMotion) intro.remove();
        else setTimeout(function () { intro.remove(); }, 2600);
    }

    /* ----------------------------------------------------------
       CUSTOM RANGE — show/hide based on URL
    ---------------------------------------------------------- */
    var customRange = document.getElementById("customRange");
    if (customRange) {
        var params = new URLSearchParams(window.location.search);
        var activeRange = params.get("range") || "this_month";
        if (activeRange === "custom") {
            customRange.style.display = "flex";
        } else {
            customRange.style.display = "none";
        }
    }

    /* ----------------------------------------------------------
       COUNT-UP for stat values  (data-count attribute)
    ---------------------------------------------------------- */
    function easeOut(t) { return 1 - Math.pow(1 - t, 3); }

    function formatMoney(n) {
        if (n === 0) return "₨ 0";
        var abs = Math.abs(n);
        var str;
        if (abs >= 1e6)       str = (n / 1e6).toFixed(1) + "M";
        else if (abs >= 1000) str = (n / 1000).toFixed(1) + "k";
        else                  str = n.toFixed(0);
        return (n < 0 ? "−" : "") + "₨ " + str;
    }

    var statEls = document.querySelectorAll(".stat-value[data-count]");
    statEls.forEach(function (el, i) {
        var target = parseFloat(el.dataset.count);
        if (isNaN(target)) return;

        // If element has a <small> child (savings rate %), preserve it
        var smallEl = el.querySelector("small");
        var isRate   = smallEl !== null;

        var duration = 900;
        var delay    = INTRO_MS + 400 + i * 90;

        setTimeout(function () {
            var t0 = null;
            function tick(ts) {
                if (!t0) t0 = ts;
                var prog = Math.min((ts - t0) / duration, 1);
                var val  = target * easeOut(prog);

                if (isRate) {
                    el.textContent = Math.round(val);
                    el.appendChild(smallEl);   // keep the <small>%</small>
                } else {
                    el.textContent = formatMoney(val);
                }

                if (prog < 1) requestAnimationFrame(tick);
                else {
                    if (isRate) {
                        el.textContent = Math.round(target);
                        el.appendChild(smallEl);
                    } else {
                        el.textContent = formatMoney(target);
                    }
                }
            }
            requestAnimationFrame(tick);
        }, delay);
    });

    /* ----------------------------------------------------------
       TREND CHART — hover tooltip
       Data comes from #trend[data-points] as JSON
       Points: [{l, ti, te, x (0-100%), yi (0-100%), ye (0-100%)}]
    ---------------------------------------------------------- */
    var trendEl  = document.getElementById("trend");
    var plotEl   = document.getElementById("plot");
    var cursor   = document.getElementById("cursor");
    var ptIn     = document.getElementById("ptIn");
    var ptEx     = document.getElementById("ptEx");
    var tTip     = document.getElementById("tTip");
    var tLabel   = document.getElementById("tLabel");
    var tIn      = document.getElementById("tIn");
    var tEx      = document.getElementById("tEx");

    if (trendEl && plotEl && cursor && tTip) {
        var raw    = trendEl.dataset.points || "[]";
        var points = [];
        try { points = JSON.parse(raw); } catch (e) {}

        if (points.length === 0) {
            // nothing to do
        } else {
            plotEl.addEventListener("mousemove", function (e) {
                showTip(e.clientX, e.clientY);
            });
            plotEl.addEventListener("mouseleave", hideTip);
            plotEl.addEventListener("touchmove", function (e) {
                e.preventDefault();
                var t = e.touches[0];
                showTip(t.clientX, t.clientY);
            }, { passive: false });
            plotEl.addEventListener("touchend", hideTip);
        }

        function nearest(pct) {
            var best = 0, bestDist = Infinity;
            for (var i = 0; i < points.length; i++) {
                var d = Math.abs(points[i].x - pct);
                if (d < bestDist) { bestDist = d; best = i; }
            }
            return best;
        }

        function showTip(clientX, clientY) {
            var rect = plotEl.getBoundingClientRect();
            var pct  = ((clientX - rect.left) / rect.width) * 100;
            var idx  = nearest(pct);
            var p    = points[idx];

            /* position cursor line (absolute, % of plot width) */
            var leftPx = (p.x / 100) * rect.width;
            cursor.style.left    = leftPx + "px";
            cursor.style.opacity = "1";

            /* income dot */
            if (ptIn) {
                ptIn.style.left    = leftPx + "px";
                ptIn.style.top     = (p.yi / 100 * rect.height) + "px";
                ptIn.style.opacity = "1";
            }

            /* expense dot */
            if (ptEx) {
                ptEx.style.left    = leftPx + "px";
                ptEx.style.top     = (p.ye / 100 * rect.height) + "px";
                ptEx.style.opacity = "1";
            }

            /* tooltip content */
            if (tLabel) tLabel.textContent = p.l;
            if (tIn)    tIn.textContent    = p.ti;
            if (tEx)    tEx.textContent    = p.te;

            /* tooltip position — keep inside plot */
            var tipW   = tTip.offsetWidth || 160;
            var tipLeft = leftPx - tipW / 2;
            if (tipLeft < 4)             tipLeft = 4;
            if (tipLeft + tipW > rect.width - 4) tipLeft = rect.width - tipW - 4;

            tTip.style.left    = tipLeft + "px";
            tTip.style.opacity = "1";
        }

        function hideTip() {
            cursor.style.opacity = "0";
            tTip.style.opacity   = "0";
            if (ptIn) ptIn.style.opacity = "0";
            if (ptEx) ptEx.style.opacity = "0";
        }
    }

    /* ----------------------------------------------------------
       PRINT BUTTON
    ---------------------------------------------------------- */
    var printBtn = document.getElementById("printBtn");
    if (printBtn) {
        printBtn.addEventListener("click", function () {
            window.print();
        });
    }

    /* ----------------------------------------------------------
       ACTIVE NAV (already set in PHP but just in case)
    ---------------------------------------------------------- */
    document.querySelectorAll(".nav-item").forEach(function (el) {
        if ((el.getAttribute("href") || "").indexOf("reports.php") !== -1) {
            el.classList.add("active");
        }
    });

}());