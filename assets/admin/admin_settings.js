(function($){
    function setMsg($wrap, type, text){
        var $msg = $wrap.find('.fo-verify-msg');
        if (!$msg.length) return;
        $msg.removeClass('ok err').addClass(type).text(text).show();
    }

    $(document).on('click', '.fo-verify-btn', function(e){
        e.preventDefault();

        var $btn  = $(this);
        var code  = String($btn.data('provider') || '');
        var $wrap = $btn.closest('.fo-provider');

        if (!code) {
            setMsg($wrap, 'err', 'Missing provider code');
            return;
        }

        setMsg($wrap, 'ok', 'Verifying...');

        $.post(window.FRM_IMAGE_ENHANCER.ajax_url, {
            action: 'frm_image_enhancer_verify_provider',
            nonce: window.FRM_IMAGE_ENHANCER.nonce,
            provider: code
        }).done(function(resp){
            if (resp && resp.success && resp.data && resp.data.ok) {
                setMsg($wrap, 'ok', 'OK');
            } else {
                setMsg($wrap, 'err', 'Failed');
            }
        }).fail(function(){
            setMsg($wrap, 'err', 'Request error');
        });
    });
})(jQuery);
