(function () {
    "use strict";

    var sidebar = document.querySelector(".admin-sidebar");
    var layout = document.querySelector(".admin-layout");
    var logo = sidebar ? sidebar.querySelector(".admin-logo") : null;
    if (!sidebar || !layout || !logo) {
        return;
    }

    if (sidebar.getAttribute("data-sidebar-initialized") === "true") {
        return;
    }
    sidebar.setAttribute("data-sidebar-initialized", "true");

    var button = logo.querySelector(".admin-sidebar-toggle");
    if (!button) {
        button = document.createElement("button");
        button.type = "button";
        button.className = "admin-sidebar-toggle";
        button.innerHTML = "<span></span><span></span><span></span>";
        logo.appendChild(button);
    }
    sidebar.id = sidebar.id || "admin-sidebar";
    button.id = button.id || "admin-sidebar-toggle";
    button.setAttribute("aria-controls", sidebar.id);
    button.setAttribute("aria-haspopup", "true");

    var overlay = layout.querySelector(".admin-sidebar-overlay");
    if (!overlay) {
        overlay = document.createElement("button");
        overlay.type = "button";
        overlay.className = "admin-sidebar-overlay";
        overlay.setAttribute("aria-label", "Fermer le menu");
        layout.appendChild(overlay);
    }

    function isMobile() {
        return window.matchMedia("(max-width: 700px)").matches;
    }

    function readCollapsedState() {
        try {
            return window.localStorage.getItem("gaku-admin-sidebar-collapsed") === "true";
        } catch (error) {
            return false;
        }
    }

    function saveCollapsedState(collapsed) {
        try {
            window.localStorage.setItem("gaku-admin-sidebar-collapsed", collapsed ? "true" : "false");
        } catch (error) {
            // Storage may be disabled; the sidebar still works for this page.
        }
    }

    function setCollapsed(collapsed) {
        if (isMobile()) {
            collapsed = false;
        }
        document.body.classList.toggle("admin-sidebar-collapsed", collapsed);
        button.setAttribute("aria-expanded", collapsed ? "false" : "true");
        button.setAttribute("aria-label", collapsed ? "Ouvrir le menu" : "Réduire le menu");
        saveCollapsedState(collapsed);
    }

    function closeMobile() {
        if (!isMobile()) {
            return;
        }
        document.body.classList.remove("admin-sidebar-open");
        button.setAttribute("aria-expanded", "false");
    }

    function syncViewport(forceState) {
        if (isMobile()) {
            document.body.classList.remove("admin-sidebar-collapsed");
            button.setAttribute("aria-label", "Ouvrir le menu");
            button.setAttribute("aria-expanded", document.body.classList.contains("admin-sidebar-open") ? "true" : "false");
        } else if (forceState) {
            document.body.classList.remove("admin-sidebar-open");
            setCollapsed(document.body.classList.contains("admin-sidebar-collapsed"));
        } else {
            button.setAttribute("aria-label", document.body.classList.contains("admin-sidebar-collapsed") ? "Ouvrir le menu" : "Réduire le menu");
            button.setAttribute("aria-expanded", document.body.classList.contains("admin-sidebar-collapsed") ? "false" : "true");
        }
    }

    function expandForContent(event) {
        if (!isMobile() && document.body.classList.contains("admin-sidebar-collapsed")) {
            event.preventDefault();
            setCollapsed(false);
            if (event.currentTarget.parentElement) {
                event.currentTarget.parentElement.open = true;
            }
        }
    }

    button.addEventListener("click", function () {
        if (isMobile()) {
            var open = document.body.classList.toggle("admin-sidebar-open");
            button.setAttribute("aria-expanded", open ? "true" : "false");
        } else {
            setCollapsed(!document.body.classList.contains("admin-sidebar-collapsed"));
        }
    });

    overlay.addEventListener("click", closeMobile);
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && isMobile()) {
            closeMobile();
        }
    });
    sidebar.querySelectorAll("a").forEach(function (link) {
        var label = link.textContent.trim().replace(/\s+/g, " ");
        if (label) {
            link.setAttribute("title", label);
        }
        link.addEventListener("click", closeMobile);
    });
    sidebar.querySelectorAll(".admin-content-menu summary").forEach(function (summary) {
        summary.addEventListener("click", expandForContent);
    });

    if (!isMobile() && readCollapsedState()) {
        document.body.classList.add("admin-sidebar-collapsed");
    }

    syncViewport(false);
    var mobileState = isMobile();
    window.addEventListener("resize", function () {
        var nextMobileState = isMobile();
        if (nextMobileState !== mobileState) {
            mobileState = nextMobileState;
            syncViewport(true);
        }
    });
}());
