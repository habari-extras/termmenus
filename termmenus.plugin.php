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
	 * Register the template
	 **/
	public function action_init()
	{
		$this->add_template( 'menus_admin', dirname( $this->get_file() ) . '/menus_admin.php' );
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
				// WHOA! Only delete the ones that are menu vocabularies. This is going to wipe out tags, categories, etc.

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
	 * Create a vocabulary for any newly saved menu block
	 **/
	function action_block_insert_after($block)
	{
		// need to check if it's a menu block
		if ( $block->type == 'menu' ) {
			$vocab_name = 'menu_' . Utils::slugify( $block->title, '_' );

			if( !Vocabulary::exists( $vocab_name ) ) {
				$params = array(
					'name' => $vocab_name,
					'description' => _t( 'A vocabulary for the "%s" menu', array( $block->title ) ), // need termmenus domain on this _t
					'features' => array( 'unique', 'hierarchical' ),
				);

				$menu_vocab = new Vocabulary( $params );
				$menu_vocab->insert();
			}
		}
	}

	/**
	 * Add to the list of possible block types.
	 **/
	public function filter_block_list($block_list)
	{
		$block_list['menu'] = _t( 'Menu', 'termmenus' );
		return $block_list;
	}

	/**
	 * Produce the form to configure a menu
	 **/
	public function action_block_form_menu( $form, $block )
	{
		// This gets the right menu, but doesn't output a draggable menu editor
		$vocab = Vocabulary::get( 'menu_' . Utils::slugify( $block->title, '_' ) );
		$form->append('select', 'menu', $block, _t( 'Menu Taxonomy' ), $vocab->get_options());

		$form->append('submit', 'save', 'Save');
	}

	/**
	 * Populate the block with some content
	 **/
	public function action_block_content_menu( $block ) {
Utils::debug( $block );
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
	 * Process menus when the publish form is received
	 *
	 **/
	public function action_publish_post( $post, $form )
	{
		$term_title = $post->title;
		foreach($form->menus->value as $menu_vocab_name) {
			$vocabulary = Vocabulary::get($menu_vocab_name);
			$term = $vocabulary->get_object_terms('post', $post->id);
// Utils::debug($post, $post->id); die();
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
	 * Add creation and management links to the main menu
	 *
	 **/
	public function filter_adminhandler_post_loadplugins_main_menu( $menu ) {
		// obtain existing last submenu item
		$last_used = end( $menu[ 'create' ][ 'submenu' ]);
		// add a menu item at the bottom
		$menu[ 'create' ][ 'submenu' ][] = array( 
			'title' => _t( 'Create a new Menu', 'termmenus' ),
			'url' => URL::get( 'admin', array( 'page' => 'menus', 'action' => 'create' ) ),
			'text' => _t( 'Menu', 'termmenus' ),
			'hotkey' => $last_used[ 'hotkey' ] + 1, // next available hotkey is last used + 1
		);
		$last_used = end( $menu[ 'manage' ][ 'submenu' ]);
		$menu[ 'manage' ][ 'submenu' ][] = array( 
			'title' => _t( 'Manage Menus', 'termmenus' ),
			'url' => URL::get( 'admin', 'page=menus' ), // might as well make listing the existing menus the default
			'text' => _t( 'Menus', 'termmenus' ),
			'hotkey' => $last_used[ 'hotkey' ] + 1,
		);
		return $menu;
	}

	/**
	 * Handle GET and POST requests
	 *
	 **/
	public function alias()
	{
		return array(
			'action_admin_theme_get_menus' => 'action_admin_theme_post_menus'
		);
	}

	/**
	 * Restrict access to the admin page
	 * (until a token is added, restricted merely to authenticated users)
	 *
	 **/
	public function filter_admin_access( $access, $page, $post_type ) {
		// this will work for now, but this should use a token.
		if ( $page != 'menus' ) {
			return $access;
		} 
		return true;
	}

	/**
	 * Prepare and display admin page
	 *
	 **/
	public function action_admin_theme_get_menus( AdminHandler $handler, Theme $theme )
	{
		$theme->page_content = '';
		if( isset( $_GET[ 'action' ] ) ) {
			switch( $_GET[ 'action' ] ) {
				case 'edit': 
					$vocabulary = Vocabulary::get( $_GET[ 'menu' ] );
					if ( $vocabulary == false ) {
						$theme->page_content = _t( '<h2>Invalid Menu.</h2>', 'termmenus' );
						// that's it, we're done. Maybe we show the list of menus instead?
						break;
					}
					$form = new FormUI( 'edit_menu' );

					// This doesn't work. Change it to something that does (or is it because there aren't any links in the menu I'm testing?)
					$form->append( 'tree', 'tree', $vocabulary->get_root_terms(), _t( 'Menu', 'termmenus') );
					$form->tree->value = $vocabulary->get_root_terms();
					// append other needed controls, if there are any.

					$theme->page_content = $form->get();
					break;

				default:
Utils::debug( $_GET ); die();
			}
		}
		else { // no action - list the menus.
			$menu_list = '';
			// get an array of all the menu vocabularies
			$vocabularies = DB::get_results( 'SELECT * FROM {vocabularies} WHERE name LIKE "menu_%" ORDER BY name ASC', array(), 'Vocabulary' );
			foreach ( $vocabularies as $menu ) {
				$menu_name = $menu->name;
				$edit_link = URL::get( 'admin', array( 
					'page' => 'menus',
					'action' => 'edit',
					'menu' => $menu_name, // already slugified
				) );
				$menu_list .= "<li><a href='$edit_link' title='Modify $menu_name'><b>$menu_name</b> {$menu->description} - {$menu->count_total()} items</a></li>";
			}
			if ( $menu_list != '' ) {
				$theme->page_content = "<ul>$menu_list</ul>";
			}
			else {
				$theme->page_content = _t( '<h2>No Menus have been created.</h2>', 'termmenus' );
			}
		}

		$theme->display( 'menus_admin' );
		// End everything
		exit;
	}


}

?>
