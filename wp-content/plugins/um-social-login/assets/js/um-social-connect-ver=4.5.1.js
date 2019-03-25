jQuery(document).ready(function() {
	
	if ( jQuery('.um-social-login-overlay').length ) {
		jQuery('body,html').css("overflow", "hidden");
	}

});

function um_social_login_popup() {
	if ( jQuery('.um-social-login-overlay').length ) {
		
		jQuery('.um-social-login-wrap .um').css({
			'max-height':  jQuery('.um-social-login-overlay').height() - 80 + 'px'
		});
		
		var p_top = ( jQuery('.um-social-login-overlay').height() - jQuery('.um-social-login-wrap').innerHeight() ) / 2;
		
		jQuery('.um-social-login-wrap').animate({
			top: p_top + 'px',
		});
		
	}
}

jQuery(window).load(function() {
	
	um_social_login_popup();

});

jQuery(window).resize(function() {
	
	um_social_login_popup();

});