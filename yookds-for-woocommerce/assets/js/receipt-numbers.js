/* Display only. Never changes order IDs, URLs, payment references or API parameters. */
(function () {
    'use strict';
    function format(value) {
        const digits=String(value || '').replace(/\D/g, '');
        return digits ? '#'+digits.slice(-4).padStart(4, '0') : '';
    }
    if (typeof module!=='undefined' && module.exports) module.exports={format};
    if (typeof document==='undefined') return;
    function start() {
        let scheduled=false;
        function update() {
            scheduled=false;
            document.querySelectorAll('.nx-order-id,.nx-id-inline,.woocommerce-order-overview__order strong').forEach(node=>{
                const value=format(node.textContent);
                if (value && node.textContent.trim()!==value) node.textContent=value;
            });
        }
        update();
        const observer=new MutationObserver(()=>{
            if (!scheduled) { scheduled=true; requestAnimationFrame(update); }
        });
        observer.observe(document.body,{childList:true,subtree:true,characterData:true});
        setTimeout(()=>observer.disconnect(),30000);
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',start); else start();
})();
