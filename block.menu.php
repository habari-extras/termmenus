<?php /*Utils::debug( $content->vocabulary ); */
$v = $content->vocabulary;
echo Format::term_tree( $v->get_tree(), $v->name, '%s', '<ol style="#menu">', '</ol>');
?>
