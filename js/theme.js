

(function () {

  var COOKIE_NAME = "indiclex_theme";
  var COOKIE_DAYS = 120;

  /* ── Cookie helpers ───────────────────────────────────────── */

  function getCookie(name) {
    var pairs = document.cookie.split(";");
    for (var i = 0; i < pairs.length; i++) {
      var pair = pairs[i].trim();
      var eq   = pair.indexOf("=");
      if (eq === -1) continue;
      if (pair.substring(0, eq).trim() === name) {
        return decodeURIComponent(pair.substring(eq + 1));
      }
    }
    return null;
  }

  function setCookie(name, value, days) {
    document.cookie =
      name + "=" + encodeURIComponent(value) +
      "; path=/; max-age=" + (days * 86400) +
      "; SameSite=Lax";
  }

  /* ── Apply theme to <html> IMMEDIATELY (no flash) ────────── */

  var theme = getCookie(COOKIE_NAME) || "light";

  if (!getCookie(COOKIE_NAME)) {
    setCookie(COOKIE_NAME, "light", COOKIE_DAYS);
  }

  document.documentElement.setAttribute("data-theme", theme);

  /* ── Helpers ──────────────────────────────────────────────── */

  function updateButton(t) {
    var btn = document.getElementById("themeToggleBtn");
    if (!btn) return;
    btn.innerHTML = t === "dark"
      ? '<i class="fas fa-sun me-1"></i><span class="d-none d-md-inline">Light Mode</span>'
      : '<i class="fas fa-moon me-1"></i><span class="d-none d-md-inline">Dark Mode</span>';
    btn.setAttribute("aria-label",
      t === "dark" ? "Switch to light mode" : "Switch to dark mode");
  }

  function applyTheme(t) {
    document.documentElement.setAttribute("data-theme", t);
    updateButton(t);
  }

  function toggleTheme() {
    // Read current state from the DOM — always accurate, never stale
    var current = document.documentElement.getAttribute("data-theme") || "light";
    var next    = current === "dark" ? "light" : "dark";
    setCookie(COOKIE_NAME, next, COOKIE_DAYS);
    applyTheme(next);
  }

  /* ── Wire up button after DOM is ready ───────────────────── */

  document.addEventListener("DOMContentLoaded", function () {
    updateButton(document.documentElement.getAttribute("data-theme") || "light");

    var btn = document.getElementById("themeToggleBtn");
    if (btn) {
      btn.addEventListener("click", toggleTheme);
    }
  });

  /* ── Public API ───────────────────────────────────────────── */

  window.IndicLexTheme = {
    toggle     : toggleTheme,
    apply      : function (t) { setCookie(COOKIE_NAME, t, COOKIE_DAYS); applyTheme(t); },
    getCurrent : function ()  { return document.documentElement.getAttribute("data-theme") || "light"; }
  };

})();
