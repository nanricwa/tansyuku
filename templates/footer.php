        </div><!-- /container-fluid -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->
<!--/email_off-->

<script data-cfasync="false" src="<?= BASE_PATH ?>/assets/js/bootstrap.bundle.min.js"></script>
<script data-cfasync="false" src="<?= BASE_PATH ?>/assets/js/app.js"></script>
<script data-cfasync="false">
// 診断: Bootstrap読み込み確認（問題解決後に削除）
(function(){
    var status = (typeof bootstrap!=='undefined') ? 'BS:OK' : 'BS:NG';
    var d=document.createElement('div');
    d.style.cssText='position:fixed;bottom:0;right:0;background:'+(status==='BS:OK'?'green':'red')+';color:#fff;padding:4px 12px;z-index:99999;font-size:11px;opacity:0.9';
    d.textContent=status;
    document.body.appendChild(d);
})();
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
</script>
</body>
</html>
