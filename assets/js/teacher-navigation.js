(function () {
    "use strict";

    if (!document.body.classList.contains("teacher-view")) {
        return;
    }

    var sidebar = document.querySelector(".admin-sidebar");
    var currentPath = window.location.pathname;
    var rootMatch = currentPath.match(/^(.*)\/(?:admin|teacher)\//);
    var root = rootMatch ? rootMatch[1] : "";
    var links = [
        ["📊", "Tableau de bord", root + "/teacher/dashboard.php", "/teacher/dashboard.php"],
        ["📈", "Résultats", root + "/teacher/results.php", "/teacher/results.php"],
        ["✉️", "Messages", root + "/teacher/messages.php", "/teacher/messages.php"],
        ["📝", "Écrire un message", root + "/teacher/message.php", "/teacher/message.php"],
        ["🔔", "Notifications", root + "/teacher/notifications.php", "/teacher/notifications.php"],
        ["🔐", "Sécurité étudiants", root + "/teacher/security_logs.php", "/teacher/security_logs.php"]
    ];

    if (!sidebar) {
        return;
    }

    var navigation = sidebar.querySelector(".admin-navigation");
    if (!navigation) {
        return;
    }

    // Pages using the shared role-aware sidebar already have the correct
    // teacher navigation and expandable content menu.
    if (navigation.querySelector(".admin-content-menu")) {
        return;
    }

    navigation.replaceChildren();
    links.forEach(function (item) {
        var link = document.createElement("a");
        link.className = "admin-nav-link" + (currentPath.indexOf(item[3]) !== -1 ? " active" : "");
        link.href = item[2];
        link.innerHTML = "<span>" + item[0] + "</span> " + item[1];
        navigation.appendChild(link);
    });

    var contentPaths = [
        ["📚", "Contenu", "", ""],
        ["🧩", "Modules", root + "/admin/modules/modules.php", "/admin/modules/"],
        ["📖", "Leçons", root + "/admin/lessons/lessons.php", "/admin/lessons/"],
        ["🧠", "Quiz", root + "/admin/quizzes/quizzes.php", "/admin/quizzes/"],
        ["📝", "Examens", root + "/admin/exams/exams.php", "/admin/exams/"]
    ];
    var contentMenu = document.createElement("details");
    contentMenu.className = "admin-content-menu";
    var contentIsActive = contentPaths.slice(1).some(function (item) {
        return currentPath.indexOf(item[3]) !== -1;
    });
    if (contentIsActive) {
        contentMenu.open = true;
    }

    var summary = document.createElement("summary");
    summary.innerHTML = contentPaths[0][0] + " <span>" + contentPaths[0][1] + "</span><b aria-hidden=\"true\">⌄</b>";
    contentMenu.appendChild(summary);

    var contentLinks = document.createElement("div");
    contentLinks.className = "admin-content-menu-links";
    contentPaths.slice(1).forEach(function (item) {
        var contentLink = document.createElement("a");
        contentLink.href = item[2];
        contentLink.textContent = item[1];
        if (currentPath.indexOf(item[3]) !== -1) {
            contentLink.className = "active";
        }
        contentLinks.appendChild(contentLink);
    });
    contentMenu.appendChild(contentLinks);
    navigation.insertBefore(contentMenu, navigation.children[1] || null);
}());
