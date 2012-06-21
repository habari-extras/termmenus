habari.menu_admin = {
	submit_menu_item_edit: function (e) {
		$.post(
			$('#menu_item_edit').attr('action'),
			$('.tm_db_action').serialize(),
			function(data){
				$('#menu_popup').html(data);
			}
		);
		return false;
	}
}