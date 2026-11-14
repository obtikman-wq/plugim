(function () {
    if (typeof wp === 'undefined' || !wp.apiFetch) {
        return;
    }

    var root = document.querySelector('.aifbp-wrap');

    if (!root) {
        return;
    }

    function fetch(path, options) {
        options = options || {};
        return wp.apiFetch({
            path: '/aifbp/v1' + path,
            method: options.method || 'GET',
            data: options.data || {}
        });
    }

    function showResult(ok, message) {
        var box = document.getElementById('aifbp-result');

        if (!box) return;

        box.innerHTML = '';
        var span = document.createElement('span');
        span.className = ok ? 'aifbp-ok' : 'aifbp-err';
        span.textContent = (ok ? '\u2713 ' : '\u2717 ') + message;
        box.appendChild(span);
    }

    function busy(btn, on) {
        if (!btn) return;
        btn.disabled = on;

        if (on) {
            btn.dataset.label = btn.textContent;
            btn.textContent = 'Working\u2026';
        } else if (btn.dataset.label) {
            btn.textContent = btn.dataset.label;
        }
    }

    var previewBtn = document.getElementById('aifbp-preview-btn');

    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            busy(previewBtn, true);

            fetch('/preview')
                .then(function (res) {
                    document.getElementById('aifbp-preview').hidden = false;
                    document.getElementById('aifbp-preview-caption').textContent = res.caption || '';
                    var img = document.getElementById('aifbp-preview-img');

                    if (res.product && res.product.image_url) {
                        img.src = res.product.image_url;
                    } else {
                        img.removeAttribute('src');
                    }

                    showResult(true, 'Preview generated for: ' + (res.product ? res.product.name : ''));
                })
                .catch(function (err) {
                    showResult(false, err.message || 'Preview failed.');
                })
                .finally(function () {
                    busy(previewBtn, false);
                });
        });
    }

    var postBtn = document.getElementById('aifbp-postnow-btn');

    if (postBtn) {
        postBtn.addEventListener('click', function () {
            if (!window.confirm('Post a random product to your first connected Facebook page now?')) {
                return;
            }

            busy(postBtn, true);

            fetch('/post-now', { method: 'POST' })
                .then(function (res) {
                    showResult(true, 'Posted! ' + (res.post_url || ''));
                })
                .catch(function (err) {
                    showResult(false, err.message || 'Posting failed.');
                })
                .finally(function () {
                    busy(postBtn, false);
                });
        });
    }

    document.querySelectorAll('#aifbp-process-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            busy(btn, true);

            fetch('/queue/process', { method: 'POST' })
                .then(function (res) {
                    showResult(true, 'Queue processed: ' + res.processed + ' item(s).');
                })
                .catch(function (err) {
                    showResult(false, err.message || 'Queue processing failed.');
                })
                .finally(function () {
                    busy(btn, false);
                });
        });
    });
})();
