    </main>
    <footer class="app-footer">
        <div class="container h-100 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <div class="text-white-50 small">© <?= date('Y') ?> Tailor Management System</div>
            <div class="text-white small">Smart stitching, tracking, and account management</div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            function toggleFor(button) {
                const selector = button.getAttribute('data-target');
                if (!selector) return;
                const input = document.querySelector(selector);
                if (!input) return;
                const icon = button.querySelector('i');
                const show = input.getAttribute('type') === 'password';
                input.setAttribute('type', show ? 'text' : 'password');
                button.setAttribute('aria-label', show ? 'Hide' : 'Show');
                if (icon) {
                    icon.classList.toggle('bi-eye', !show);
                    icon.classList.toggle('bi-eye-slash', show);
                }
            }

            document.addEventListener('click', function (e) {
                const target = e.target.closest ? e.target.closest('.password-toggle') : null;
                if (!target) return;
                e.preventDefault();
                toggleFor(target);
            });

            document.addEventListener('submit', function (e) {
                const form = e.target;
                if (!form || !form.classList || !form.classList.contains('js-confirm-delete')) return;
                const name = form.getAttribute('data-confirm-name') || 'this customer';
                const ok = confirm(`Are you sure you want to delete ${name}? This action cannot be undone.`);
                if (!ok) {
                    e.preventDefault();
                }
            }, true);

            // Move admin alerts into a fixed top-right stack.
            const adminContent = document.querySelector('.admin-content');
            if (adminContent) {
                const alerts = Array.from(adminContent.querySelectorAll('.alert'));
                if (alerts.length > 0) {
                    let stack = document.querySelector('.admin-alert-stack');
                    if (!stack) {
                        stack = document.createElement('div');
                        stack.className = 'admin-alert-stack';
                        document.body.appendChild(stack);
                    }
                    alerts.forEach(function (alert) {
                        // Add close button if missing.
                        if (!alert.querySelector('.btn-close')) {
                            alert.classList.add('alert-dismissible', 'fade', 'show');
                            const closeBtn = document.createElement('button');
                            closeBtn.type = 'button';
                            closeBtn.className = 'btn-close';
                            closeBtn.setAttribute('data-bs-dismiss', 'alert');
                            closeBtn.setAttribute('aria-label', 'Close');
                            alert.appendChild(closeBtn);
                        }
                        stack.appendChild(alert);

                        let hideTimer = null;
                        const hideDelayMs = 4500;

                        const removeAlert = function () {
                            if (!alert || !alert.parentNode) return;
                            alert.classList.add('alert-hiding');
                            window.setTimeout(function () {
                                if (alert.parentNode) {
                                    alert.remove();
                                }
                            }, 260);
                        };

                        const startTimer = function () {
                            window.clearTimeout(hideTimer);
                            hideTimer = window.setTimeout(removeAlert, hideDelayMs);
                        };

                        const stopTimer = function () {
                            window.clearTimeout(hideTimer);
                        };

                        alert.addEventListener('mouseenter', stopTimer);
                        alert.addEventListener('mouseleave', startTimer);
                        alert.addEventListener('closed.bs.alert', stopTimer);
                        startTimer();
                    });
                }
            }
        })();
    </script>
</body>
</html>

