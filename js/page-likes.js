/**
 * Xử lý click like/dislike cho khối tip_page_likes() (templates/partials/page-likes.html.twig).
 * Vanilla JS, không phụ thuộc theme — inject trực tiếp bởi VisitorGadgetPlugin::onOutputGenerated()
 * giống cách css/visitor-gadget.css được inject, để plugin tự chứa hành vi của widget mình render ra.
 */
(function () {
    'use strict';

    function setActive(btn, active) {
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function bind(widget) {
        var route = widget.dataset.route;
        var endpoint = widget.dataset.endpoint;
        var buttons = widget.querySelectorAll('.page-likes__btn');
        var busy = false;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                busy = true;

                var reaction = btn.dataset.reaction;

                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ route: route, reaction: reaction }),
                })
                    .then(function (res) {
                        return res.ok ? res.json() : Promise.reject(res);
                    })
                    .then(function (payload) {
                        var data = payload.data || payload;

                        buttons.forEach(function (b) {
                            var count = b.querySelector('[data-count]');
                            if (count) {
                                count.textContent = data[b.dataset.reaction] ?? count.textContent;
                            }
                            setActive(b, data.reaction === b.dataset.reaction);
                        });
                    })
                    .catch(function () {
                        // Best-effort: giữ nguyên UI hiện tại nếu request lỗi (mạng, rate limit...).
                    })
                    .finally(function () {
                        busy = false;
                    });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-page-likes]').forEach(bind);
    });
})();
