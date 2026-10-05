(function () {
    "use strict";

    var form = document.querySelector("[data-dashboard-search]");
    if (!form) {
        return;
    }

    var query = form.querySelector("[data-search-query]");
    var status = form.querySelector("[data-search-status]");
    var items = Array.prototype.slice.call(document.querySelectorAll("[data-dashboard-search-item]"));
    var count = form.querySelector("[data-search-count]");

    function applyFilters() {
        var term = (query.value || "").trim().toLowerCase();
        var selectedStatus = status ? status.value : "";
        var visible = 0;

        items.forEach(function (item) {
            var text = (item.textContent || "").toLowerCase();
            var itemStatus = item.getAttribute("data-search-status") || "";
            var matches = (!term || text.indexOf(term) !== -1) &&
                (!selectedStatus || itemStatus === selectedStatus);
            item.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        if (count) {
            count.textContent = visible + " résultat(s)";
        }
    }

    form.addEventListener("input", applyFilters);
    form.addEventListener("change", applyFilters);
    form.addEventListener("reset", function () {
        window.setTimeout(applyFilters, 0);
    });
    applyFilters();
}());
