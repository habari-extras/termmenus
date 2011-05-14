<?php
/**
 * TermMenus
 *
 * @todo add domain to all _t() calls
 * @todo style everything so it looks good
 */
class TermMenus extends Plugin
{
	// define values to be stored as $object_id in Terms of type 'menu'
	private $item_type = array(
		'url' => 0,
			);

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
		// create default access token
		ACL::create_token( 'manage_menus', _t('Manage menus'), 'Administration', false );
		$group = UserGroup::get_by_name( 'admin' );
		$group->grant( 'manage_menus' );

		// register a menu type
		Vocabulary::add_object_type( 'menu' );
	}

	/**
	 * Register the templates - one for the admin page, the other for the block.
	 **/
	public function action_init()
	{
		$this->add_template( 'menus_admin', dirname( __FILE__ ) . '/menus_admin.php' );
		$this->add_template( 'block.menu', dirname( __FILE__ ) . '/block.menu.php' );
	}

	/**
	 * Remove the admin token
	 **/
	public function action_plugin_deactivation( $file )
	{
		// delete default access token
		ACL::destroy_token( 'manage_menus' );

		// delete menu vocabularies that were created
		$vocabs = DB::get_results( 'SELECT * FROM {vocabularies} WHERE name LIKE "menu_%"', array(), 'Vocabulary' );
		foreach( $vocabs as $vocab ) {
			// This should only delete the ones that are menu vocabularies, unless others have been named 'menu_xxxxx'
			$vocab->delete();
		}

		// delete blocks that were created
		$blocks = DB::get_results( 'SELECT * FROM {blocks} WHERE type = "menu"', array(), 'Block') ;
		foreach( $blocks as $block ) {
			$block->delete();
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
		$form->append('select', 'menu_taxonomy', $block, _t( 'Menu Taxonomy', 'termmenus' ), $this->get_menus( true ) );
		$form->append('checkbox', 'div_wrap', $block, _t( 'Wrap each menu link in a div', 'termmenus' ) );
		$form->append('text', 'list_class', $block, _t( 'Custom class for the tree ordered list element', 'termmenus' ) );
	}

	/**
	 * Populate the block with some content
	 **/
	public function action_block_content_menu( $block, $theme ) {
		$vocab = Vocabulary::get_by_id($block->menu_taxonomy);
		$block->vocabulary = $vocab;
		if($block->div_wrap) {
			$wrapper = '<div>%s</div>';
		}
		else {
			$wrapper = '%s';
		}

		// preprocess some things
		$tree = $vocab->get_tree();

		$block->content = Format::term_tree(
			$tree, //$vocab->get_tree(),
			$vocab->name,
			array(
				//'linkcallback' => array($this, 'render_menu_link'),
				'itemcallback' => array($this, 'render_menu_item'),
				'linkwrapper' => $wrapper,
				'treeattr' => array(
					'class' => $block->list_class,
				),
				'theme' => $theme,
			)
		);
	}

	/**
	 * Add menus to the publish form
	 **/
	public function action_form_publish ( $form, $post )
	{
		$menus = $this->get_menus();

		$menulist = array();
		foreach($menus as $menu) {
			$menulist[$menu->id] = $menu->name;
		}

		$settings = $form->publish_controls->append('fieldset', 'menu_set', _t('Menus'));
		$settings->append('checkboxes', 'menus', 'null:null', _t('Menus'), $menulist);

		// If this is an existing post, see if it has categories already
		if ( 0 != $post->id ) {
			// Get the terms associated to this post
			$object_terms = Vocabulary::get_all_object_terms('post', $post->id);
			$menu_ids = array_keys($menulist);
			$value = array();
			// if the term is in a menu vocab, enable that checkbox
			foreach($object_terms as $term) {
				if(in_array($term->vocabulary_id, $menu_ids)) {
					$value[] = $term->vocabulary_id;
				}
			}

			$form->menus->value = $value;
		}
	}

	/**
	 * Process menus when the publish form is received
	 *
	 **/
	public function action_publish_post( $post, $form )
	{
		$term_title = $post->title;
		$selected_menus = $form->menus->value;
		foreach($this->get_menus() as $menu) {
			if(in_array($menu->id, $selected_menus)) {
				$terms = $menu->get_object_terms('post', $post->id);
				if(count($terms) == 0) {
					$term = new Term(array(
						'term_display' => $post->title,
						'term' => $post->slug,
					));
					$menu->add_term($term);
					$menu->set_object_terms('post',
						$post->id,
						array($term->term));
				}
			}
		}
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
	 *
	 **/
	public function filter_admin_access_tokens( array $require_any, $page )
	{
		switch ( $page ) {
			case 'menus':
				$require_any = array( 'manage_menus' => true );
				break;
		}
		return $require_any;
	}

	/**
	 * Prepare and display admin page
	 *
	 **/
	public function action_admin_theme_get_menus( AdminHandler $handler, Theme $theme )
	{
		$theme->page_content = '';
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'list';
		switch( $action ) {
			case 'edit':
				$vocabulary = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				if ( $vocabulary == false ) {
					$theme->page_content = _t( '<h2>Invalid Menu.</h2>', 'termmenus' );
					// that's it, we're done. Maybe we show the list of menus instead?
					break;
				}
				$theme->page_content = _t( "<h4>Editing <b>{$vocabulary->name}</b></h4>", 'termmenus' );
				$form = new FormUI( 'edit_menu' );

				if ( !$vocabulary->is_empty() ) {
					$form->append( 'tree', 'tree', $vocabulary->get_tree(), _t( 'Menu', 'termmenus') );
					$form->tree->config = array( 'itemcallback' => array( $this, 'tree_item_callback' ) );
//						$form->tree->value = $vocabulary->get_root_terms();
					// append other needed controls, if there are any.

					$form->append( 'submit', 'save', _t( 'Apply Changes', 'termmenus' ) );
				}
				else {
					$form->append( 'static', 'message', _t( '<h2>No links yet.</h2>', 'termmenus' ) );
				}
				$form->append( 'static', 'create link link',
					'<a href="' . URL::get('admin', array(
						'page' => 'menus',
						'action' => 'create_link',
						'menu' => $vocabulary->id,
					) ) . '">' . _t( 'Add a link URL', 'termmenus' ) . '</a>' );
				$theme->page_content .= $form->get();
				break;

			case 'create':

				$form = new FormUI('create_menu');
				$form->append('text', 'menuname', 'null:null', _t( 'Menu Name', 'termmenus' ) )
					->add_validator('validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator(array($this, 'validate_newvocab') );
				$form->append('submit', 'submit', _t( 'Create Menu', 'termmenus' ) );
				$form->on_success(array($this, 'add_menu_form_save') );
				$theme->page_content = $form->get();

				break;

			case 'list':
				$menu_list = '';

				foreach ( $this->get_menus() as $menu ) {
					$edit_link = URL::get( 'admin', array(
						'page' => 'menus',
						'action' => 'edit',
						'menu' => $menu->id,
					) );
					$menu_name = $menu->name;
					$menu_list .= "<li><a href='$edit_link'><b>$menu_name</b> {$menu->description} - {$menu->count_total()} items</a></li>";
				}
				if ( $menu_list != '' ) {
					$theme->page_content = "<ul>$menu_list</ul>";
				}
				else {
					$theme->page_content = _t( '<h2>No Menus have been created.</h2>', 'termmenus' );
				}
				break;

			case 'delete_term':
				$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
				$menu_vocab = $term->vocabulary_id;
				$term->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Item deleted.', 'termmenus' ) );
				Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $menu_vocab ) ) );
				break;

			case 'edit_term':
				break;
			case 'create_link':
				$form = new FormUI( 'create_link' );
				$form->append( 'text', 'link_name', 'null:null', _t( 'Link title', 'termmenus' ) )
					->add_validator( 'validate_required', _t( 'A name is required.', 'termmenus' ) );
				$form->append( 'text', 'link_url', 'null:null', _t( 'Link URL', 'termmenus' ) )
					->add_validator( 'validate_required', _t( 'URL is required.', 'termmenus' ) )
					->add_validator( 'validate_url', _t( 'You must supply a valid URL.', 'termmenus' ) );
				$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
				$form->append( 'submit', 'submit', _t( 'Add link', 'termmenus' ) );

				$form->on_success( array( $this, 'create_link_form_save' ) );
				$theme->page_content = $form->get();
				break;
			default:
Utils::debug( $_GET, $action ); die();
		}

		$theme->display( 'menus_admin' );
		// End everything
		exit;
	}

	public function add_menu_form_save( $form )
	{
		$params = array(
			'name' => $form->menuname->value,
			'description' => _t( 'A vocabulary for the "%s" menu', array( $form->menuname->value ) ),
			'features' => array( 'term_menu' ), // a special feature that marks the vocabulary as a menu
		);
		$vocab = Vocabulary::create($params);
		Session::notice( _t( 'Created menu "%s".', array( $form->menuname->value ), 'termmenus' ) );
		Utils::redirect( URL::get( 'admin', 'page=menus' ));
	}

	public function create_link_form_save( $form )
	{
		$menu_vocab = intval( $form->menu->value );
		// create a term for the link, store the URL
		$menu = Vocabulary::get_by_id( $menu_vocab );
		$term = new Term( array(
			'term_display' => $form->link_name->value,
			'term' => Utils::slugify( $form->link_name->value ),
			));
		$term->info->url = $form->link_url->value;
		$menu->add_term( $term );
		$term->associate( 'menu', $this->item_type[ 'url' ] );

		Session::notice( _t( 'Link added.', 'termmenus' ) );
		Utils::redirect(URL::get( 'admin', array(
			'page' => 'menus',
			'action' => 'edit',
			'menu' => $menu_vocab,
			) ) );
	}

	public function validate_newvocab( $value, $control, $form )
	{
		if(Vocabulary::get( $value ) instanceof Vocabulary) {
			return array( _t( 'Please choose a vocabulary name that does not already exist.', 'termmenus' ) );
		}
		return array();
	}

	public function get_menus($as_array = false)
	{
		$vocabularies = Vocabulary::get_all();
		$outarray = array();
		foreach ( $vocabularies as $index => $menu ) {
			if(!$menu->term_menu) { // check for the term_menu feature we added.
				unset($vocabularies[$index]);
			}
			else {
				if($as_array) {
					$outarray[$menu->id] = $menu->name;
				}
			}
		}
		if($as_array) {
			return $outarray;
		}
		else {
			return $vocabularies;
		}
	}

	/**
	 *
	 * Callback for Format::term_tree to use with $config['linkcallback']
	 *
	 * @param Term $term
	 * @param array $config
	 * @return array $config modified with the new wrapper div
	 **/
	public function tree_item_callback( Term $term, $config )
	{
		// coming into this, default $config['wrapper'] is "<div>%s</div>"

		// make the links
		$edit_link = URL::get( 'admin', array(
						'page' => 'menus',
						'action' => 'edit_term',
						'term' => $term->id,
					) );
		$delete_link = URL::get( 'admin', array(
						'page' => 'menus',
						'action' => 'delete_term',
						'term' => $term->id,
					) );

		// insert them into the wrapper
		$links = "<a class='menu_item_edit' href='$edit_link'>edit</a> <a class='menu_item_delete' href='$delete_link'>delete</a>";
		$config[ 'wrapper' ] = "<div>%s $links</div>";

		return $config;
	}

	/**
	 * Callback function for block output of menu list item
	 **/
	public function render_menu_item( $term, $config )
	{
		$title = $term->term_display;
		$link = '';
		$objects = $term->object_types();

		$active = false;
		foreach($objects as $object_id => $type) {
			switch($type) {
				case 'post':
					$post = Post::get(array('id' =>$object_id));
					if($post instanceof Post) {
						$link = $post->permalink;
						if($config['theme']->posts instanceof Post && $config['theme']->posts->id == $post->id) {
							$active = true;
						}
					}
					break;
				case 'menu':
					switch( $object_id ) {
						case $this->item_type[ 'url' ]:
							$link = $term->info->url;
							break;
					}
					break;
			}
		}
		$title = $term->term_display;
		if($link == '') {
			$config['wrapper'] = sprintf($config['linkwrapper'], $title);
		}
		else {
			$config['wrapper'] = sprintf($config['linkwrapper'], "<a href=\"{$link}\">{$title}</a>");
		}
		if($active) {
			$config['itemattr']['class'] = 'active';
		}
		else {
			$config['itemattr']['class'] = 'inactive';
		}

		return $config;
	}

}

?>
