(function () {
    "use strict";

    var protectedContent = document.querySelectorAll(".lesson-content");
    var protectedDocuments = document.querySelectorAll(".student-document-viewer");
    if (!protectedContent.length && !protectedDocuments.length) {
        return;
    }

    ["copy", "cut", "paste", "contextmenu", "selectstart", "dragstart"].forEach(function (eventName) {
        protectedContent.forEach(function (content) {
            content.addEventListener(eventName, function (event) {
                event.preventDefault();
            }, true);
        });
    });

    protectedDocuments.forEach(function (viewer) {
        ["contextmenu", "copy", "cut", "dragstart"].forEach(function (eventName) {
            viewer.addEventListener(eventName, function (event) {
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

    document.addEventListener("keydown", function (event) {
        if ((event.ctrlKey || event.metaKey) && ["p", "s"].indexOf(event.key.toLowerCase()) !== -1) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
}());
