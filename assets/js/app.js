/**
 * 短縮URLツール JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    // サイドバートグル
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    // クリップボードコピー
    document.querySelectorAll('.btn-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const text = this.getAttribute('data-copy');
            if (text) {
                navigator.clipboard.writeText(text).then(function () {
                    btn.classList.add('copied');
                    const originalHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check"></i> Copied';
                    setTimeout(function () {
                        btn.innerHTML = originalHtml;
                        btn.classList.remove('copied');
                    }, 2000);
                });
            }
        });
    });

    // フラッシュメッセージ自動非表示
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });
});
