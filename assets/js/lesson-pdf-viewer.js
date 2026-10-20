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
        var pageObserver = null;
        var zoomLevel = 1;
        var minimumZoom = 1;
        var maximumZoom = 2.5;
        var zoomStep = 0.25;
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
                return;
            }

            pageObserver = new IntersectionObserver(function (entries) {
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
                threshold: [0.05]
            });
            viewer.querySelectorAll(".student-pdf-page").forEach(function (page) {
                pageObserver.observe(page);
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
            var toolbar = document.createElement("div");
            toolbar.className = "student-pdf-toolbar";
            toolbar.setAttribute("role", "toolbar");
            toolbar.setAttribute("aria-label", "Contrôles de zoom du document");

            var zoomOut = document.createElement("button");
            zoomOut.type = "button";
            zoomOut.className = "student-pdf-zoom-button";
            zoomOut.textContent = "−";
            zoomOut.setAttribute("aria-label", "Réduire le zoom");

            var zoomLabel = document.createElement("span");
            zoomLabel.className = "student-pdf-zoom-label";
            zoomLabel.setAttribute("aria-live", "polite");

            var zoomIn = document.createElement("button");
            zoomIn.type = "button";
            zoomIn.className = "student-pdf-zoom-button";
            zoomIn.textContent = "+";
            zoomIn.setAttribute("aria-label", "Agrandir le document");

            var zoomReset = document.createElement("button");
            zoomReset.type = "button";
            zoomReset.className = "student-pdf-zoom-reset";
            zoomReset.textContent = "Taille adaptée";
            zoomReset.setAttribute("aria-label", "Réinitialiser le zoom à la taille adaptée");

            toolbar.appendChild(zoomOut);
            toolbar.appendChild(zoomLabel);
            toolbar.appendChild(zoomIn);
            toolbar.appendChild(zoomReset);
            viewer.insertBefore(toolbar, viewer.firstChild);

            function updateZoomControls(isRendering) {
                zoomLabel.textContent = Math.round(zoomLevel * 100) + "%";
                zoomOut.disabled = isRendering || zoomLevel <= minimumZoom;
                zoomIn.disabled = isRendering || zoomLevel >= maximumZoom;
                zoomReset.disabled = isRendering || zoomLevel === 1;
            }

            function renderDocumentPages() {
                var currentScroll = viewer.scrollTop;
                if (pageObserver) {
                    pageObserver.disconnect();
                    pageObserver = null;
                }
                visibleTimers.forEach(function (timer) {
                    window.clearTimeout(timer);
                });
                visibleTimers.clear();
                viewer.querySelectorAll(".student-pdf-page").forEach(function (page) {
                    page.remove();
                });
                updateZoomControls(true);

                var renderPage = function (pageNumber) {
                    return pdf.getPage(pageNumber).then(function (page) {
                        var unscaledViewport = page.getViewport({ scale: 1 });
                        var fitScale = Math.min((viewer.clientWidth - 40) / unscaledViewport.width, 1.35);
                        var scale = Math.max(fitScale * zoomLevel, 0.2);
                        var viewport = page.getViewport({ scale: scale });
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
                    viewer.scrollTop = currentScroll;
                    updateZoomControls(false);
                    updateDocumentProgress();
                    observeVisiblePages();
                }).catch(function (error) {
                    updateZoomControls(false);
                    throw error;
                });
            }

            zoomOut.addEventListener("click", function () {
                if (zoomLevel > minimumZoom) {
                    zoomLevel = Math.max(minimumZoom, Math.round((zoomLevel - zoomStep) * 100) / 100);
                    renderDocumentPages().catch(function (error) {
                        console.error("Unable to resize course PDF:", error);
                    });
                }
            });
            zoomIn.addEventListener("click", function () {
                if (zoomLevel < maximumZoom) {
                    zoomLevel = Math.min(maximumZoom, Math.round((zoomLevel + zoomStep) * 100) / 100);
                    renderDocumentPages().catch(function (error) {
                        console.error("Unable to resize course PDF:", error);
                    });
                }
            });
            zoomReset.addEventListener("click", function () {
                zoomLevel = 1;
                renderDocumentPages().catch(function (error) {
                    console.error("Unable to resize course PDF:", error);
                });
            });

            if (status) {
                status.remove();
            }
            updateZoomControls(false);
            return renderDocumentPages();
        }).catch(function (error) {
            console.error("Unable to render course PDF:", error);
            if (status) {
                status.textContent = "Le document n’a pas pu être affiché. Actualisez la page ou contactez votre formateur.";
            }
        });
    });
}());
