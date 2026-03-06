        </div><!-- /container-fluid -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->
<!--/email_off-->

<!-- QRコードモーダル（共通） -->
<div class="modal fade" id="qrModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">QRコード</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="qrTarget" class="d-inline-block mb-2"></div>
                <div class="small text-muted text-break" id="qrUrlText"></div>
            </div>
            <div class="modal-footer py-1 justify-content-center">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="downloadQR()">
                    <i class="bi bi-download me-1"></i>画像保存
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>

<script data-cfasync="false" src="<?= BASE_PATH ?>/assets/js/bootstrap.bundle.min.js"></script>
<script data-cfasync="false" src="<?= BASE_PATH ?>/assets/js/qrcode.min.js"></script>
<script data-cfasync="false" src="<?= BASE_PATH ?>/assets/js/app.js"></script>
<script data-cfasync="false">
// Bootstrap declarative API fallback
(function(){
    function init(){
        if(typeof bootstrap==='undefined')return;
        document.querySelectorAll('[data-bs-toggle="modal"]').forEach(function(el){
            el.setAttribute('type','button');
            el.addEventListener('click',function(e){
                e.preventDefault();e.stopPropagation();
                var t=document.querySelector(this.getAttribute('data-bs-target'));
                if(t) bootstrap.Modal.getOrCreateInstance(t).show();
            });
        });
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(el){
            el.addEventListener('click',function(e){
                e.preventDefault();e.stopPropagation();
                var sel=this.getAttribute('href')||this.getAttribute('data-bs-target');
                var t=document.querySelector(sel);
                if(t) bootstrap.Collapse.getOrCreateInstance(t).toggle();
            });
        });
        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(el){
            el.addEventListener('click',function(){
                var m=this.closest('.modal');
                if(m) bootstrap.Modal.getInstance(m).hide();
            });
        });
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}
    else{init();}
})();

// QRコード表示
var _qrInstance = null;
function showQR(url) {
    var target = document.getElementById('qrTarget');
    var urlText = document.getElementById('qrUrlText');
    target.innerHTML = '';
    urlText.textContent = url;
    _qrInstance = new QRCode(target, {
        text: url, width: 200, height: 200,
        colorDark: '#000000', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
    var m = document.getElementById('qrModal');
    if (m) bootstrap.Modal.getOrCreateInstance(m).show();
}
function downloadQR() {
    var canvas = document.querySelector('#qrTarget canvas');
    if (!canvas) return;
    var a = document.createElement('a');
    a.download = 'qrcode.png';
    a.href = canvas.toDataURL('image/png');
    a.click();
}
</script>
</body>
</html>
