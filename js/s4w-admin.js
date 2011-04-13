<!--//create a -->
jQuery(document).ready(function(){
  jQuery( "#s4w-tabs" ).tabs();
  jQuery('#actions tr:first').hide();
  jQuery('input[name="s4w_ping_imitate"]').click(function(event){
    event.preventDefault();
    jQuery('input[name="s4w_ping"]').click();
  });
});

