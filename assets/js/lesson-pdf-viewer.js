import * as pdfjsLib from "./vendor/pdfjs/pdf.js";

(function () {
    "use strict";

    var viewers = Array.prototype.slice.call(
        document.querySelectorAll(".student-document-viewer[data-pdf-url]")
    );
    if (!viewers.length) {
        return;
    }

    pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
        "./vendor/pdfjs/pdf.worker.js",
        import.meta.url
    ).href;

    viewers.forEach(function (viewer) {
        ["contextmenu", "copy", "cut", "dragstart", "selectstart"].forEach(function (eventName) {
            viewer.addEventListener(eventName, function (event) {
                event.preventDefault();
            }, true);
        });

        var status = viewer.querySelector(".student-document-status");
        var pageCount = 0;
        var pagesSeen = new Set();
        var pendingPages = new Set();
        var visibleTimers = new Map();
        try {
            JSON.parse(viewer.dataset.pagesSeen || "[]").forEach(function (pageNumber) {
                pagesSeen.add(Number(pageNumber));
            });
        } catch (error) {
            console.error("Unable to read saved course document progress:", error);
        }

        function updateDocumentProgress() {
            var viewed = pageCount > 0 && pagesSeen.size >= pageCount;
            viewer.dataset.viewed = viewed ? "1" : "0";
            var allViewed = viewers.every(function (item) {
                return item.dataset.viewed === "1";
            });
            document.dispatchEvent(new CustomEvent("lesson-document-progress", {
                detail: {
                    materialId: viewer.dataset.materialId,
                    viewed: viewed,
                    pagesViewed: pagesSeen.size,
                    pageCount: pageCount,
                    allViewed: allViewed
                }
            }));
        }

        function markPageViewed(pageNumber) {
            if (pagesSeen.has(pageNumber) || pendingPages.has(pageNumber)) {
                return;
            }
            pendingPages.add(pageNumber);
            var body = new URLSearchParams({
                ajax: "document_page_view",
                csrf_token: viewer.dataset.csrf,
                material_id: viewer.dataset.materialId,
                page_number: String(pageNumber),
                page_count: String(pageCount)
            });
            fetch(viewer.dataset.viewUrl, {
                method: "POST",
                body: body,
                credentials: "same-origin"
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error("Page progress was rejected.");
                }
                return response.json();
            }).then(function (data) {
                if (!data.ok) {
                    throw new Error("Page progress could not be saved.");
                }
                pagesSeen.add(pageNumber);
                viewer.dataset.pagesViewed = String(data.pages_viewed);
                viewer.dataset.viewed = data.viewed ? "1" : "0";
                updateDocumentProgress();
            }).catch(function (error) {
                console.error("Unable to save course document page progress:", error);
            }).finally(function () {
                pendingPages.delete(pageNumber);
            });
        }

        function observeVisiblePages() {
            if (!("IntersectionObserver" in window)) {
                if (status) {
                    status.textContent = "Le suivi des pages nécessite un navigateur récent.";
                }
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var pageNumber = Number(entry.target.dataset.pageNumber);
                    if (entry.isIntersecting && entry.intersectionRatio >= 0.2) {
                        if (!pagesSeen.has(pageNumber) && !visibleTimers.has(pageNumber)) {
                            visibleTimers.set(pageNumber, window.setTimeout(function () {
                                visibleTimers.delete(pageNumber);
                                markPageViewed(pageNumber);
                            }, 1200));
                        }
                    } else if (visibleTimers.has(pageNumber)) {
                        window.clearTimeout(visibleTimers.get(pageNumber));
                        visibleTimers.delete(pageNumber);
                    }
                });
            }, {
                root: viewer,
                threshold: [0.2]
            });
            viewer.querySelectorAll(".student-pdf-page").forEach(function (page) {
                observer.observe(page);
            });
        }

        pdfjsLib.getDocument({
            url: viewer.dataset.pdfUrl,
            httpHeaders: {
                "X-Requested-With": "XMLHttpRequest"
            },
            disableRange: true,
            disableAutoFetch: true,
            disableStream: true
        }).promise.then(function (pdf) {
            pageCount = pdf.numPages;
            if (pageCount < 1 || pageCount > 1000) {
                throw new Error("The document has an unsupported page count.");
            }
            var openedBody = new URLSearchParams({
                ajax: "document_opened",
                csrf_token: viewer.dataset.csrf,
                material_id: viewer.dataset.materialId,
                page_count: String(pageCount)
            });
            return fetch(viewer.dataset.viewUrl, {
                method: "POST",
                body: openedBody,
                credentials: "same-origin"
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error("Document view could not be recorded.");
                }
                return response.json();
            }).then(function (result) {
                if (!result.ok) {
                    throw new Error("Document view could not be recorded.");
                }
                if (result.reset) {
                    pagesSeen.clear();
                }
                return pdf;
            });
        }).then(function (pdf) {
            var pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
            var renderPage = function (pageNumber) {
                return pdf.getPage(pageNumber).then(function (page) {
                    var unscaledViewport = page.getViewport({ scale: 1 });
                    var scale = Math.min((viewer.clientWidth - 40) / unscaledViewport.width, 1.35);
                    var viewport = page.getViewport({ scale: Math.max(scale, 0.2) });
                    var pageContainer = document.createElement("div");
                    var canvas = document.createElement("canvas");
                    var context = canvas.getContext("2d", { alpha: false });

                    pageContainer.className = "student-pdf-page";
                    pageContainer.setAttribute("aria-label", "Page " + pageNumber);
                    pageContainer.dataset.pageNumber = String(pageNumber);
                    canvas.width = Math.ceil(viewport.width * pixelRatio);
                    canvas.height = Math.ceil(viewport.height * pixelRatio);
                    canvas.style.width = Math.ceil(viewport.width) + "px";
                    canvas.style.height = Math.ceil(viewport.height) + "px";
                    pageContainer.appendChild(canvas);
                    viewer.appendChild(pageContainer);

                    return page.render({
                        canvasContext: context,
                        viewport: viewport,
                        transform: pixelRatio === 1 ? null : [pixelRatio, 0, 0, pixelRatio, 0, 0]
                    }).promise;
                });
            };

            var pageJobs = [];
            for (var pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                pageJobs.push(pageNumber);
            }
            return pageJobs.reduce(function (previousPage, currentPage) {
                return previousPage.then(function () {
                    return renderPage(currentPage);
                });
            }, Promise.resolve()).then(function () {
                if (status) {
                    status.remove();
                }
                updateDocumentProgress();
                observeVisiblePages();
            });
        }).catch(function (error) {
            console.error("Unable to render course PDF:", error);
            if (status) {
                status.textContent = "Le document n’a pas pu être affiché. Actualisez la page ou contactez votre formateur.";
            }
        });
    });
}());
