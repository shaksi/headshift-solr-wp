<?php
/*  
    Copyright (c) 2009 Matt Weber

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
    THE SOFTWARE.
*/

#set defaults
if (get_option('s4w_solr_initialized') != '1') {
    update_option('s4w_solr_host', 'localhost');
    update_option('s4w_solr_port', '8983');
    update_option('s4w_solr_path', '/solr');
    
    update_option('s4w_content_index', array());
    update_option('s4w_content_delete', array());
    update_option('s4w_content_private', array());
    update_option('s4w_content_exclude', array());


    update_option('s4w_output_info', '1');
    update_option('s4w_output_pager', '1');
    update_option('s4w_output_facets', '1');
    update_option('s4w_num_results', '5');
    update_option('s4w_cat_as_taxo', '1');
    update_option('s4w_solr_initialized', '1');
    update_option('s4w_max_display_tags', '10');
    
    update_option('s4w_facets', array());
}

# checks if we need to check the checkbox
function s4w_checkCheckbox( $option_name, $field = FALSE ) {
  $option = get_option( $option_name );
 
  $option = (is_array($option) && $field)?$option[$field]:$option;
	if( $option == '1'){
		print 'checked="checked"';
	}
} 

function s4w_message_output($msg) {
  return '<div id="message" class="updated fade"><p><strong>'.__($msg,'solr4wp').'</strong></p></div>';
}

# check for any POST settings
if ($_POST['s4w_ping']) {
  if (s4w_get_solr(true)) {
    print s4w_message_output('Ping Success!');
  } 
   else {
    print s4w_message_output('Ping Failed!');
  }
} 
 else if ($_POST['s4w_content_load']) {
  $type = array_keys($_POST['s4w_content_load']);
  if (s4w_load_all_content($type[0])){
    print s4w_message_output($type[0].' has been indexed');
  }
  else {
    print s4w_message_output('Unable to index content');
  }
} 
 else if ($_POST['s4w_deleteall']) {
  s4w_delete_all();
  print s4w_message_output('All Indexed Pages Deleted!');
} 
 else if ($_POST['action'] == 'update') {
  //unsure about this, will need to do further investigation
  foreach($_POST as $field_key => $field_value) {
    if(strpos($field_key, 's4w')===0) {
       update_option($field_key, $field_value);
    }
  }
}
?>