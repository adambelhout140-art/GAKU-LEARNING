(function () {
    "use strict";

    var protectedContent = document.querySelectorAll(".lesson-content");
    if (!protectedContent.length) {
        return;
    }

    ["copy", "cut", "paste", "contextmenu", "selectstart", "dragstart"].forEach(function (eventName) {
        protectedContent.forEach(function (content) {
            content.addEventListener(eventName, function (event) {
                event.preventDefault();
            }, true);
        });
    });

    protectedContent.forEach(function (content) {
        content.addEventListener("keydown", function (event) {
            if ((event.ctrlKey || event.metaKey) && ["c", "x", "v"].indexOf(event.key.toLowerCase()) !== -1) {
                event.preventDefault();
            }
        });
    });
}());
