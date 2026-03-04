        </div><!-- /container-fluid -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->
<!--/email_off-->

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script data-cfasync="false" src="<?= BASE_PATH ?>/assets/js/app.js"></script>
<script data-cfasync="false">
// Bootstrap declarative API fallback
// Cloudflare等でdata-bs-toggleの自動バインドが効かない場合の手動初期化
(function(){
    function init(){
        if(typeof bootstrap==='undefined')return;
        // Modal triggers
        document.querySelectorAll('[data-bs-toggle="modal"]').forEach(function(el){
            el.setAttribute('type','button');
            el.addEventListener('click',function(e){
                e.preventDefault();
                e.stopPropagation();
                var t=document.querySelector(this.getAttribute('data-bs-target'));
                if(t) bootstrap.Modal.getOrCreateInstance(t).show();
            });
        });
        // Collapse triggers
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(el){
            el.addEventListener('click',function(e){
                e.preventDefault();
                e.stopPropagation();
                var sel=this.getAttribute('href')||this.getAttribute('data-bs-target');
                var t=document.querySelector(sel);
                if(t) bootstrap.Collapse.getOrCreateInstance(t).toggle();
            });
        });
        // Dismiss triggers
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
