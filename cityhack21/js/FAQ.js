$(".close").click(function(){	
		$(".popin").removeClass("active");
		$(".legal").removeClass("active");
		$("body").removeClass("no-scroll");
	});
$(document).keyup(function(e){
        var key =  e.which;
        var opened=$(".popin").hasClass("active");
        if(key == 27&&opened){
        	$(".popin").removeClass("active");
			$(".legal").removeClass("active");
			$("body").removeClass("no-scroll");
        }
    });