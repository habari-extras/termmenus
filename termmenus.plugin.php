<?php
/**
 * TermMenus
 *
 * @todo allow renaming/editing of menu items
 * @todo style everything so it looks good
 * @todo show description with name on post publish checkboxes
 * @todo PHPDoc
 * @todo ACL, CSRF, etc.
 */
class TermMenus extends Plugin
{
	// define values to be stored as $object_id in Terms of type 'menu'
	private $item_types = array(
		'url' => 0,
		'spacer' => 1,	// a spacer is an item that goes nowhere.
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
		ACL::create_token( 'manage_menus', _t( 'Manage menus', 'termmenus' ), 'Administration', false );
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
		$this->add_template( 'menu_iframe', dirname( __FILE__ ) . '/menu_iframe.php' );
		$this->add_template( 'block.menu', dirname( __FILE__ ) . '/block.menu.php' );

		// formcontrol for tokens
		$this->add_template( 'text_tokens', dirname( __FILE__ ) . '/formcontrol_tokens.php' );

		// i18n
		$this->load_text_domain( 'termmenus' );
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

		$settings = $form->publish_controls->append( 'fieldset', 'menu_set', _t( 'Menus', 'termmenus' ) );
		$settings->append( 'checkboxes', 'menus', 'null:null', _t( 'Menus', 'termmenus' ), $menulist );

		// If this is an existing post, see if it has categories already
		if ( 0 != $post->id ) {
			// Get the terms associated to this post
			$object_terms = Vocabulary::get_all_object_terms( 'post', $post->id );
			$menu_ids = array_keys( $menulist );
			$value = array();
			// if the term is in a menu vocab, enable that checkbox
			foreach( $object_terms as $term ) {
				if( in_array( $term->vocabulary_id, $menu_ids ) ) {
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
		// might not hurt to turn this into a function to be more DRY
		$term_title = $post->title;
		$selected_menus = $form->menus->value;
		foreach( $this->get_menus() as $menu ) {
			if(in_array( $menu->id, $selected_menus ) ) {
				$terms = $menu->get_object_terms( 'post', $post->id );
				if( count( $terms ) == 0 ) {
					$term = new Term(array(
						'term_display' => $post->title,
						'term' => $post->slug,
					));
					$term->info->menu = $menu->id;
					$menu->add_term( $term );
					$menu->set_object_terms( 'post',
						$post->id,
						array( $term->term ) );
				}
			}
		}
	}

	/**
	 * Add creation and management links to the main menu
	 *
	 **/
	public function filter_adminhandler_post_loadplugins_main_menu( $menu ) {
		$menus_array = array( 'create_menu' => array(
			'title' => "Create a new Menu",
			'text' => "New Menu",
			'hotkey' => 'N',
			'url' => URL::get( 'admin', array( 'page' => 'menus', 'action' => 'create' ) ),
			'class' => 'over-spacer',
			'access' => array( 'manage_menus' => true ),
		));

		$items = 0;
		foreach ( $this->get_menus() as $item  ) {
			$menus_array[ ++$items ] = array(
				'title' => "{$item->name}: {$item->description}",
				'text' => $item->name,
				'hotkey' => $items,
				'url' => URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $item->id )),
				'access' => array( 'manage_menus' => true ),
			);
		}
		if ( count( $menus_array ) > 1 ) {
			$menus_array[1]['class'] = 'under-spacer';
		}

		// add to main menu
		$item_menu = array( 'menus' =>
		array(
			'url' => URL::get( 'admin', 'page=menus' ),
			'title' => _t( 'Menus', 'termmenus' ),
			'text' => _t( 'Menus', 'termmenus' ),
			'hotkey' => 'E',
			'selected' => false,
			'submenu' => $menus_array,
		)
		);

		$slice_point = array_search( 'themes', array_keys( $menu ) ); // Element will be inserted before "themes"
		$pre_slice = array_slice( $menu, 0, $slice_point);
		$post_slice = array_slice( $menu, $slice_point);

		$menu = array_merge( $pre_slice, $item_menu, $post_slice );

		return $menu;
	}

	/**
	 * Handle GET and POST requests
	 *
	 **/
	public function alias()
	{
		return array(
			'action_admin_theme_get_menus' => 'action_admin_theme_post_menus',
			'action_admin_theme_get_menu_iframe' => 'action_admin_theme_post_menu_iframe',
		);
	}

	/**
	 * Restrict access to the admin page
	 *
	 **/
	public function filter_admin_access_tokens( array $require_any, $page )
	{
		switch ( $page ) {
			case 'menu_iframe':
			case 'menus':
				$require_any = array( 'manage_menus' => true );
				break;
		}
		return $require_any;
	}

	/**
	 * Minimal modal forms
	 *
	 **/
	public function action_admin_theme_get_menu_iframe( AdminHandler $handler, Theme $theme )
	{
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'create';
		$term = false;
		if ( isset( $handler->handler_vars[ 'term' ] ) ) {
			$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
			$action = $term->info->type;
		}
//Utils::debug( $term, $handler->handler_vars );
		$form_action = URL::get( 'admin', array( 'page' => 'menu_iframe', 'menu' => $handler->handler_vars[ 'menu' ], 'action' => $action ) );
		$form = new FormUI( 'menu_item_edit', $action );
		$form->class[] = 'tm_db_action';
		$form->set_option( 'form_action', $form_action );
		$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
		$form->on_success( array( $this, 'term_form_save' ) );

		switch( $action ) {
			case 'link':
				$link_name = new FormControlText( 'link_name', 'null:null', _t( 'Link Title', 'termmenus' ) );
				$link_name->add_validator( 'validate_required', _t( 'A name is required.', 'termmenus' ) );
				$link_url = new FormControlText( 'link_url', 'null:null', _t( 'Link URL', 'termmenus' ) );
				$link_url->add_validator( 'validate_required' )
					->add_validator( 'validate_url', _t( 'You must supply a valid URL.', 'termmenus' ) );
				if ( $term ) {
					$link_name->value = $term->term_display;
					$link_url->value = $term->info->url;
					$form->append( 'hidden', 'term' )->value = $term->id;
				}
				$form->append( $link_name );
				$form->append( $link_url );
				$form->append( 'submit', 'submit', _t( '%s link', array( $term ? _t( 'Update', 'termmenus' ) : _t( 'Add', 'termmenus' ) ), 'termmenus' ) );
				break;

			case 'spacer':
				$spacer = new FormControlText( 'spacer_text', 'null:null', _t( 'Item text (leave blank for blank space)', 'termmenus' ) );
				if ( $term ) {
					$spacer->value = $term->term_display;
					$form->append( 'hidden', 'term' )->value = $term->id;
				}

				$form->append( $spacer );
				$form->append( 'submit', 'submit', _t( '%s spacer', array( $term ? _t( 'Update', 'termmenus' ) : _t( 'Add', 'termmenus' ) ), 'termmenus' ) );
				break;

			case 'link_to_posts':
				$post_ids = $form->append( 'text', 'post_ids', 'null:null', _t( 'Posts', 'termmenus' ) );
				$post_ids->template = 'text_tokens';
				$post_ids->ready_function = "$('#{$post_ids->field}').tokenInput( habari.url.ajaxPostTokens )";
				$form->append( 'submit', 'submit', _t( 'Add post(s)', 'termmenus' ) );
				break;
		}
		$form->properties['onsubmit'] = "return habari.menu_admin.submit_menu_item_edit()";
		$theme->page_content = $form->get();
		if(isset($_GET['result'])) {
			switch($_GET['result']) {
				case 'added':
					$treeurl = URL::get( 'admin', array('page' => 'menus', 'menu' => $handler->handler_vars[ 'menu' ], 'action' => 'edit') ) . ' #edit_menu>*';
						$msg = _t( 'Menu item added.', 'termmenus' ); // @todo: update this to reflect if more than one item has been added, or reword entirely.
					$theme->page_content .= <<< JAVSCRIPT_RESPONSE
<script type="text/javascript">
human_msg.display_msg('{$msg}');
$('#edit_menu').load('{$treeurl}', habari.menu_admin.init_form);
</script>
JAVSCRIPT_RESPONSE;
			}
		}
		$theme->display( 'menu_iframe' );
		exit;
	}

	/**
	 * Prepare and display admin page
	 *
	 **/
	public function action_admin_theme_get_menus( AdminHandler $handler, Theme $theme )
	{
		$theme->page_content = '';
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'create';
		switch( $action ) {
			case 'edit':
				$vocabulary = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				if ( $vocabulary == false ) {
					$theme->page_content = _t( '<h2>Invalid Menu.</h2>', 'termmenus' );
					// that's it, we're done. Maybe we show the list of menus instead?
					break;
				}

				$form = new FormUI( 'edit_menu' );

				$form->append( new FormControlText( 'menuname', 'null:null', _t( 'Name', 'termmenus' ) ) )
					->add_validator( 'validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator( array( $this, 'validate_newvocab' ) )
					->value = $vocabulary->name;
				$form->append( new FormControlHidden( 'oldname', 'null:null' ) )->value = $vocabulary->name;

				$form->append( new FormControlText( 'description', 'null:null', _t( 'Description', 'termmenus' ) ) )
					->value = $vocabulary->description;

				$edit_items_array = array(
					'link_to_posts' => _t( 'Link to post(s)', 'termmenus' ),
					'link' => _t( 'Link to a URL', 'termmenus' ),
					'spacer' => _t( 'Add a spacer', 'termmenus' ),
				);

				$edit_items = '';
				foreach( $edit_items_array as $action => $text ) {
					$edit_items .= '<a class="modal_popup_form menu_button_dark" href="' . URL::get('admin', array(
						'page' => 'menu_iframe',
						'action' => $action,
						'menu' => $vocabulary->id,
					) ) . "\">$text</a>";
				}

				if ( !$vocabulary->is_empty() ) {
					$form->append( 'tree', 'tree', $vocabulary->get_tree(), _t( 'Menu', 'termmenus') );
					$form->tree->config = array( 'itemcallback' => array( $this, 'tree_item_callback' ) );
//						$form->tree->value = $vocabulary->get_root_terms();
					// append other needed controls, if there are any.

					$form->append( 'static', 'buttons', _t( "<div id='menu_item_button_container'>$edit_items</div>", 'termmenus' ) );
					$form->append( 'submit', 'save', _t( 'Apply Changes', 'termmenus' ) );
				}
				else {
					$form->append( 'static', 'buttons', _t( "<div id='menu_item_button_container'>$edit_items</div>", 'termmenus' ) );
				}
				$form->append( new FormControlHidden( 'menu', 'null:null' ) )->value = $handler->handler_vars[ 'menu' ];
				$form->on_success( array( $this, 'rename_menu_form_save' ) );
				$theme->page_content .= $form->get();
				break;

			case 'create':
				$form = new FormUI('create_menu');
				$form->append( 'text', 'menuname', 'null:null', _t( 'Menu Name', 'termmenus' ) )
					->add_validator( 'validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator( array($this, 'validate_newvocab' ) );
				$form->append( 'text', 'description', 'null:null', _t( 'Description', 'termmenus' ) );
				$form->append( 'submit', 'submit', _t( 'Create Menu', 'termmenus' ) );
				$form->on_success( array( $this, 'add_menu_form_save' ) );
				$theme->page_content = $form->get();

				break;

			case 'delete_menu':
				$menu_vocab = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				$menu_vocab->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Menu deleted.', 'termmenus' ) );
				Utils::redirect( URL::get( 'admin', 'page=menus' ) );
				break;

			case 'delete_term':
				$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
				$menu_vocab = $term->vocabulary_id;
				$term->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Item deleted.', 'termmenus' ) );
				Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $menu_vocab ) ) );
				break;
			/*
		 case 'edit_term':
			 $term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
			 $menu_vocab = $term->vocabulary_id;
			 $term_type = '';
Utils::debug( $term, $term->object_types() );
			 foreach( $term->object_types() as $object_id => $type ) {
				 // render_menu_item() does this as a foreach. I'm assuming there's only one type here, but I could be wrong. Is there a better way?
				 $term_type = array_search( $object_id, $this->item_types );;
			 }
Utils::debug( $term_type, $this->item_types );
			 $form = new FormUI( 'edit_term' );
			 $form->append( 'text', 'title', 'null:null', ( $term_type !== 'spacer' ? _t( 'Item Title', 'termmenus' ) : _t( 'Spacer Text', 'termmenus' ) ) )
				 ->add_validator( 'validate_required' )
				 ->value = $term->term_display;
			 if ( $term_type == 'url' ) {
				 $form->append( 'text', 'link_url', 'null:null', _t( 'Link URL', 'termmenus' ) )
					 ->add_validator( 'validate_required' )
					 ->add_validator( 'validate_url', _t( 'You must supply a valid URL.', 'termmenus' ) )
					 ->value = $term->info->url;
			 }
			 $form->append( 'hidden', 'term' )->value = $term->id;
			 $form->append( 'hidden', 'type' )->value = intval( $term_type );
			 $form->append( 'submit', 'submit', _t( 'Apply Changes', 'termmenus' ) );
			 $form->on_success( array( $this, 'term_form_save' ) );
			 $theme->page_content = $form->get();

			 break;*/
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
			'description' => ( $form->description->value === '' ? _t( 'A vocabulary for the "%s" menu', array( $form->menuname->value ), 'termmenus' ) : $form->description->value ),
			'features' => array( 'term_menu' ), // a special feature that marks the vocabulary as a menu
		);
		$vocab = Vocabulary::create( $params );

		Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $vocab->id ) ) );
	}

	public function rename_menu_form_save( $form )
	{
		// The name of this should probably change, since it is the on_success for the whole menu edit, no longer just for renaming.
		// It only renames/modifies the description currently, as item adding/rearranging is done by the NestedSortable tree.

		// get the menu from the form, grab the values, modify the vocabulary.
		$menu_vocab = intval( $form->menu->value );
		// create a term for the link, store the URL
		$menu = Vocabulary::get_by_id( $menu_vocab );
		if( $menu->name != $form->menuname->value ) {
			$menu->name = $form->menuname->value; // could use Vocabulary::rename for this
		}
		$menu->description = $form->description->value; // no Vocabulary function for this
		$menu->update();

		$form->save();

		Session::notice( _t( 'Updated menu "%s".', array( $form->menuname->value ), 'termmenus' ) );
		Utils::redirect( URL::get( 'admin', array(
			'page' => 'menus',
			'action' => 'edit',
			'menu' => $menu->id,
		) ) );
	}

	public function term_form_save( $form )
	{
		$menu_vocab = intval( $form->menu->value );

		if ( isset( $form->term ) ) {
			$term = Term::get( intval( (string) $form->term->value ) );
//			$type = $form->type->value;
		}
		else { // if no term is set, create a new item.
			// create a term for the link, store the URL
			$menu = Vocabulary::get_by_id( $menu_vocab );

			if( isset( $form->post_ids->value ) ) {
				$post_ids = explode( ',', $form->post_ids->value );
				foreach( $post_ids as $post_id ) {
					$post = Post::get( array( 'id' => $post_id ) );
					$term_title = $post->title;

					$terms = $menu->get_object_terms( 'post', $post->id );
					if( count( $terms ) == 0 ) {
						$term = new Term( array( 'term_display' => $post->title, 'term' => $post->slug ) );
						$term->info->menu = $menu_vocab;
						$menu->add_term( $term );
						$menu->set_object_terms( 'post', $post->id, array( $term->term ) );
					}
				}
				$notice = _t( 'Link(s) added.', 'termmenus' );
				$action = 'link_to_posts';
			}
			else if ( isset( $form->link_name->value ) ) {
				$term = new Term( array(
					'term_display' => $form->link_name->value,
					'term' => Utils::slugify( $form->link_name->value ),
				));
				$term->info->type = "link";
				$term->info->url = $form->link_url->value;
				$term->info->menu = $menu_vocab;
				$menu->add_term( $term );
				$term->associate( 'menu', $this->item_types[ 'url' ] );

				$notice = _t( 'Link added.', 'termmenus' );
				$action = 'create_link';
			}
			else if ( isset( $form->spacer_text->value ) ) {

				$term = new Term( array(
					'term_display' => ( $form->spacer_text->value !== '' ? $form->spacer_text->value : '&nbsp;' ), // totally blank values collapse the term display in the formcontrol
					'term' => Utils::slugify( ($form->spacer_text->value !== '' ? $form->spacer_text->value : 'menu_spacer' ) ),
				));
				$term->info->type = "spacer";
				$term->info->menu = $menu_vocab;
				$menu->add_term( $term );
				$term->associate( 'menu', $this->item_types[ 'spacer' ] );

				$notice = _t( 'Spacer added.', 'termmenus' );
				$action = 'create_spacer';
			}
			Session::notice( $notice );
			Utils::redirect(URL::get( 'admin', array(
				'page' => 'menu_iframe',
				'action' => $action,
				'menu' => $menu_vocab,
				'result' => 'added',
			) ) );
		}
		Utils::debug( $type, $term );
		Utils::debug( $term->info->url );
	}

	public function validate_newvocab( $value, $control, $form )
	{
		if( isset( $form->oldname ) && ( $form->oldname->value ) && ( $value == $form->oldname->value ) ) {
			return array();
		}
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
			if( !$menu->term_menu ) { // check for the term_menu feature we added.
				unset( $vocabularies[ $index ] );
			}
			else {
				if( $as_array ) {
					$outarray[ $menu->id ] = $menu->name;
				}
			}
		}
		if( $as_array ) {
			return $outarray;
		}
		else {
			return $vocabularies;
		}
	}

	/**
	 * Provide a method for listing the types of menu items that are available
	 * @return array List of item types, keyed by name and having integer index values
	 **/
	public function get_item_types()
	{
		return Plugins::filter( 'get_item_types', $this->item_types );
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
			'page' => 'menu_iframe',
			'action' => 'edit_term',
			'term' => $term->id,
			'menu' => $term->info->menu,
		) );
		$delete_link = URL::get( 'admin', array(
			'page' => 'menus',
			'action' => 'delete_term',
			'term' => $term->id,
			'menu' => $term->info->menu,
		) );

		// insert them into the wrapper
		// @TODO _t() this line or replace it altogether.
		$links = "<ul class='dropbutton'><li><a title='Edit this' class='modal_popup_form' href='$edit_link'>edit</a></li><li><a title='Delete this' href='$delete_link'>delete</a></li></ul>";
		$config[ 'wrapper' ] = "<div>%s $links</div>";

		return $config;
	}

	/**
	 * Callback function for block output of menu list item
	 **/
	public function render_menu_item( Term $term, $config )
	{
		$title = $term->term_display;
		$link = '';
		$objects = $term->object_types();

		$active = false;
		$spacer = false;
		foreach( $objects as $object_id => $type ) {
			switch( $type ) {
				case 'post':
					$post = Post::get( array( 'id' => $object_id ) );
					if( $post instanceof Post ) {
						$link = $post->permalink;
						if( $config[ 'theme' ]->posts instanceof Post && $config[ 'theme' ]->posts->id == $post->id ) {
							$active = true;
						}
					}
					else {
						// The post doesn't exist or the user does not have access to it
					}
					break;
				case 'menu':
					$item_types = $this->get_item_types();
					switch( $object_id ) {
						case $item_types[ 'url' ]:
							$link = $term->info->url;
							break;
						case $item_types[ 'spacer' ]:
							if ( empty( $term->term_display ) ) {
								$title = '&nbsp;';
							}
							$spacer = true;
						// no need to break, default below is just fine.
						default:
							$link = null;
							$link = Plugins::filter( 'get_item_link', $link, $term, $object_id, $type );
							break;
					}
					break;
			}
		}
		if( empty( $link ) ) {
			$config[ 'wrapper' ] = sprintf($config[ 'linkwrapper' ], $title);
		}
		else {
			$config[ 'wrapper' ] = sprintf( $config[ 'linkwrapper' ], "<a href=\"{$link}\">{$title}</a>" );
		}
		if( $active ) {
			$config[ 'itemattr' ][ 'class' ] = 'active';
		}
		else {
			$config[ 'itemattr' ][ 'class' ] = 'inactive';
		}
		if( $spacer ) {
			$config[ 'itemattr' ][ 'class' ] .= ' spacer';
		}
		return $config;
	}
	/**
	 * Add required Javascript and, for now, CSS.
	 */
	public function action_admin_header( $theme )
	{
		if ( $theme->page == 'menus' ) {
			// Ideally the plugin would reuse reusable portions of the existing admin CSS. Until then, let's only add the CSS needed on the menus page.
			Stack::add( 'admin_stylesheet', array( $this->get_url() . '/admin.css', 'screen' ), 'admin-css' );

			// Load the plugin and its css
			Stack::add( 'admin_header_javascript', Site::get_url( 'vendor' ) . "/jquery.tokeninput.js", 'jquery-tokeninput', 'jquery.ui' );
			Stack::add( 'admin_stylesheet', array( Site::get_url( 'admin_theme' ) . '/css/token-input.css', 'screen' ), 'admin_tokeninput' );

			// Add the callback URL.
			$url = "habari.url.ajaxPostTokens = '" . URL::get( 'ajax', array( 'context' => 'post_tokens' ) ) . "';";
			Stack::add( 'admin_header_javascript', $url, 'post_tokens_url', 'post_tokens' );

			// Add the menu administration javascript
			Stack::add( 'admin_header_javascript', $this->get_url('/menus_admin.js'), 'menus_admin', 'admin');
		}
	}

	/**
	 * Respond to Javascript callbacks
	 * The name of this method is action_ajax_ followed by what you passed to the context parameter above.
	 */
	public function action_ajax_post_tokens( $handler )
	{
		// Get the data that was sent
		$response = $handler->handler_vars[ 'q' ];
		// Wipe anything else that's in the buffer
		ob_end_clean();

		$new_response = Posts::get( array( "title_search" => $response ) );

		$final_response = array();
		foreach ( $new_response as $post ) {

			$final_response[] = array(
				'id' => $post->id,
				'name' => $post->title,
			);
		}
		// Send the response
		echo json_encode( $final_response );
	}
}
?>