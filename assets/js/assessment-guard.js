(function () {
    "use strict";

    var form = document.querySelector(".assessment-page > form");
    if (!form) {
        return;
    }

    var submitted = false;
    var leftAssessment = false;
    var fullscreenRequested = false;
    var fullscreenActive = false;
    var csrfInput = form.querySelector("input[name='csrf_token']");
    var assessmentId = new URLSearchParams(window.location.search).get("id");
    var assessmentType = window.location.pathname.indexOf("/exams/") !== -1 ? "exam" : "quiz";
    var sessionInput = form.querySelector("input[name='assessment_session_token']");
    var sessionToken = sessionInput ? sessionInput.value : "";
    var autosaveTimer = null;
    var draftInput = form.querySelector("input[name='assessment_draft']");
    var durationMinutes = parseInt(form.getAttribute("data-duration-minutes") || "0", 10);
    var overlay = document.createElement("div");
    overlay.className = "assessment-guard-overlay";
    overlay.setAttribute("role", "alertdialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.hidden = true;
    overlay.innerHTML = "<div class=\"assessment-guard-dialog\"><strong>Assessment paused</strong><p>You left the assessment window. Return to the assessment before continuing.</p><button type=\"button\" class=\"button primary-button\">Resume assessment</button></div>";
    document.body.appendChild(overlay);

    function logEvent(action) {
        if (!csrfInput) {
            return;
        }
        var body = new URLSearchParams({
            csrf_token: csrfInput.value,
            action: action,
            assessment_id: assessmentId || "",
            assessment_type: assessmentType
            , session_token: sessionToken
        });
        fetch("../../auth/security_event.php", {
            method: "POST",
            body: body,
            credentials: "same-origin",
            keepalive: true
        }).catch(function () {});
    }

    function collectAnswers() {
        var answers = {};
        Array.prototype.forEach.call(form.querySelectorAll("[name^='answers[']"), function (input) {
            var match = input.name.match(/^answers\[([^\]]+)\](?:\[\])?$/);
            if (!match) {
                return;
            }
            if ((input.type === "checkbox" || input.type === "radio") && !input.checked) {
                return;
            }
            if (input.name.slice(-2) === "[]") {
                if (!Array.isArray(answers[match[1]])) {
                    answers[match[1]] = [];
                }
                answers[match[1]].push(input.value);
            } else {
                answers[match[1]] = input.value;
            }
        });
        return answers;
    }

    function saveAnswers() {
        if (!sessionToken || submitted || !csrfInput) {
            return;
        }
        var body = new URLSearchParams({
            csrf_token: csrfInput.value,
            assessment_id: assessmentId || "",
            assessment_type: assessmentType,
            session_token: sessionToken
        });
        var currentAnswers = collectAnswers();
        Object.keys(currentAnswers).forEach(function (key) {
            var value = currentAnswers[key];
            body.append("answers[" + key + "]" + (Array.isArray(value) ? "[]" : ""), value);
        });
        fetch("../../auth/assessment_autosave.php", {
            method: "POST",
            body: body,
            credentials: "same-origin",
            keepalive: true
        }).catch(function () {});
    }

    function restoreAnswers() {
        if (!draftInput || !draftInput.value) {
            return;
        }
        var draft;
        try {
            draft = JSON.parse(draftInput.value);
        } catch (error) {
            return;
        }
        Object.keys(draft || {}).forEach(function (key) {
            var values = Array.isArray(draft[key]) ? draft[key].map(String) : [String(draft[key])];
            Array.prototype.forEach.call(form.querySelectorAll("[name^='answers[" + key + "]']"), function (input) {
                if (values.indexOf(input.value) !== -1) {
                    if (input.type === "checkbox" || input.type === "radio") {
                        input.checked = true;
                    } else {
                        input.value = draft[key];
                    }
                }
            });
        });
    }

    function showWarning() {
        if (submitted) {
            return;
        }

        overlay.hidden = false;
        overlay.querySelector("button").focus();
    }

    function requestFullscreen() {
        if (fullscreenRequested || document.fullscreenElement || !document.documentElement.requestFullscreen) {
            return;
        }
        fullscreenRequested = true;
        logEvent("assessment_fullscreen_requested");
        document.documentElement.requestFullscreen().catch(function () {});
    }

    overlay.querySelector("button").addEventListener("click", function () {
        overlay.hidden = true;
        form.querySelector("input, button")?.focus();
        requestFullscreen();
    });

    form.addEventListener("submit", function () {
        saveAnswers();
        submitted = true;
        overlay.hidden = true;
    });

    form.addEventListener("change", function () {
        window.clearTimeout(autosaveTimer);
        autosaveTimer = window.setTimeout(saveAnswers, 250);
    });
    window.setInterval(saveAnswers, 10000);

    form.addEventListener("pointerdown", requestFullscreen, { once: true });
    restoreAnswers();
    if (durationMinutes > 0) {
        window.setTimeout(function () {
            if (!submitted) {
                logEvent("assessment_time_expired");
                showWarning();
            }
        }, durationMinutes * 60 * 1000);
    }
    logEvent("assessment_started");

    ["copy", "cut", "paste", "contextmenu"].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            if (form.contains(event.target)) {
                event.preventDefault();
                logEvent("assessment_" + eventName + "_blocked");
            }
        });
    });

    document.addEventListener("keydown", function (event) {
        if (!form.contains(event.target)) {
            return;
        }
        if ((event.ctrlKey || event.metaKey) && ["c", "x", "v"].indexOf(event.key.toLowerCase()) !== -1) {
            event.preventDefault();
            logEvent("assessment_shortcut_blocked");
        }
    });

    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "hidden") {
            leftAssessment = true;
            if (fullscreenActive) {
                logEvent("assessment_tab_left");
            }
        } else if (leftAssessment) {
            if (fullscreenActive) {
                showWarning();
            }
            leftAssessment = false;
        }
    });

    document.addEventListener("fullscreenchange", function () {
        fullscreenActive = Boolean(document.fullscreenElement);
        if (fullscreenRequested && !document.fullscreenElement) {
            logEvent("assessment_fullscreen_exited");
            showWarning();
        }
    });

    window.addEventListener("beforeunload", function (event) {
        if (!submitted && fullscreenActive) {
            logEvent("assessment_leave_warning");
            event.preventDefault();
            event.returnValue = "Your assessment is not submitted yet.";
        }
    });

}());
