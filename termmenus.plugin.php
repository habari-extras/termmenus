<?php

class TermMenus extends Plugin
{
	public function  __get($name)
	{
		switch ( $name ) {
			case 'vocabulary':
				if ( !isset($this->_vocabulary) ) {
					$this->_vocabulary = Vocabulary::get(self::$vocabulary);
				}
				return $this->_vocabulary;
		}
	}

	/**
	 * Create an admin token for editing menus
	 **/
	public function action_plugin_activation($file)
	{
		if ( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
			// create default access token
			ACL::create_token( 'manage_menus', _t('Manage menus'), 'Administration', false );
			$group = UserGroup::get_by_name( 'admin' );
			$group->grant( 'manage_menus' );
		}
	}

	/**
	 * Remove the admin token
	 **/
	public function action_plugin_deactivation($file)
	{
		if ( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
			// delete default access token
			ACL::destroy_token( 'manage_menus' );
		}
	}

	/**
	 * Create a vocabulary for any saved menu block
	 **/
	function block_update_after($block)
	{
		$vocab_name = 'menu_' . Utils::slugify($block->title);

		if(!Vocabulary::exists($vocab_name)) {
			$params = array(
				'name' => $vocab_name,
				'description' => _t('A vocabulary for the "%s" menu', array($block->title)),
				'features' => array( 'unique', 'hierarchical' ),
			);

			$menu_vocab = new Vocabulary( $params );
			$menu_vocab->insert();
		}
	}

	/**
	 * Add to the list of possible block types.
	 **/
	public function filter_block_list($block_list)
	{
		$block_list['menu'] = _t('Menu');
		return $block_list;
	}

	/**
	 * Produce the form to configure a menu
	 **/
	public function action_block_form_menu($form, $block)
	{
		$content = $form->append('textarea', 'content', $block, _t( 'Menus!' ) );
		$content->rows = 5;
		$form->append('submit', 'save', 'Save');
	}

	/**
	 * Add menus to the publish form
	 **/
	public function action_form_publish ( $form, $post )
	{
		$parent_term = null;
		$descendants = null;

		$settings = $form->publish_controls->append('fieldset', 'settings', _t('Menus'));

		$blocks = DB::get_results('SELECT b.* FROM {blocks} b INNER JOIN {blocks_areas} ba ON ba.block_id = b.id WHERE b.type = "menu" ORDER BY ba.display_order ASC', array(), 'Block');

		$blocklist = array();
		foreach($blocks as $block) {
			$blocklist['menu_' . Utils::slugify($block->title)] = $block->title;
		}

		$settings->append('checkboxes', 'menus', 'null:null', _t('Menus'), $blocklist);

		// If this is an existing post, see if it has categories already
		if ( 0 != $post->id ) {
//			$form->categories->value = implode( ', ', array_values( $this->get_categories( $post ) ) );
		}
	}

	/**
	 * Process categories when the publish form is received
	 *
	 **/
	public function action_publish_post( $post, $form )
	{
		if ( $post->content_type == Post::type( self::$content_type ) ) {
			$categories = array();
			$categories = $this->parse_categories( $form->categories->value );
			$this->vocabulary->set_object_terms( 'post', $post->id, $categories );
		}
	}

	/**
	 * Enable update notices to be sent using the Habari beacon
	 **/
	public function action_update_check()
	{
		Update::add( 'Menus', 'FE473F55-2704-4CF0-B192-38F41268C58E', $this->info->version );
	}
}

?>
