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

			// delete menu vocabularies that were created
			$vocabs = Vocabulary::get_all();
			foreach($vocabs as $vocab) {
				$vocab->delete();
			}

			// delete blocks that were created
			$blocks = DB::get_results('SELECT b.* FROM {blocks} b INNER JOIN {blocks_areas} ba ON ba.block_id = b.id WHERE b.type = "menu" ORDER BY ba.display_order ASC', array(), 'Block');
			foreach($blocks as $block) {
				$block->delete();
			}
		}
	}

	/**
	 * Create a vocabulary for any saved menu block
	 **/
	function action_block_update_after($block)
	{
		$vocab_name = 'menu_' . Utils::slugify($block->title, '_');

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
		$vocabs = Vocabulary::get_all();
		$vocab_array = array();
		foreach($vocabs as $vocab) {
			$vocab_array[$vocab->id] = $vocab->name;
		}
		$content = $form->append('checkboxes', 'content', $block, _t( 'Menu Vocabularies' ), $vocab_array );
		$form->append('submit', 'save', 'Save');
	}

	/**
	 * Add menus to the publish form
	 **/
	public function action_form_publish ( $form, $post )
	{
		$blocks = DB::get_results('SELECT b.* FROM {blocks} b INNER JOIN {blocks_areas} ba ON ba.block_id = b.id WHERE b.type = "menu" ORDER BY ba.display_order ASC', array(), 'Block');

		$blocklist = array();
		foreach($blocks as $block) {
			$blocklist['menu_' . Utils::slugify($block->title, '_')] = $block->title;
		}

		$settings = $form->publish_controls->append('fieldset', 'menu_set', _t('Menus'));
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
		$term_title = $post->title;
		foreach($form->menus->value as $menu_vocab_name) {
			$vocabulary = Vocabulary::get($menu_vocab_name);
			$term = $vocabulary->get_object_terms('post', $post->id);
Utils::debug($term, $post->id); die();
			if(!$term) {
				$term = new Term(array(
					'term_display' => $post->title,
					'term' => $post->slug,
				));
				$vocabulary->add_term($term);
				$vocabulary->set_object_terms('post', $post->id, array($term->term));
			}
		}
		Utils::debug($term); die();
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
