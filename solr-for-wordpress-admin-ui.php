<?php 
$parent_taxonomy = get_taxonomies();
//get a a list of all the available content types so we render out some options
$post_types = s4w_get_all_post_types();
$indexable_content = get_option('s4w_content_index');


?>
<div id="s4w-tabs" class="wrap ui-tabs-panel ui-widget-content ui-corner-bottom">
  
  <h2 class=""><?php _e('Solr For WordPress', 'solr4wp') ?></h2>
  
	<ul>
	  <li><a href="#settings">Solr Configuration</a></li>
		<li><a href="#indexing">Indexing Options</a></li>
		<li><a href="#result">Result Options</a></li>
		<li><a href="#actions">Actions</a></li>
	</ul>
<div class="wrap" id="settings">
<form method="post">
<h3><?php _e('Connection Information', 'solr4wp') ?></h3>
<table class="form-table">
    <tr valign="top">
        <th scope="row"><?php _e('Solr Host', 'solr4wp') ?></th>
        <td><input type="text" name="s4w_solr_host" value="<?php _e(get_option('s4w_solr_host'), 'solr4wp'); ?>" /></td>
    </tr>
 
    <tr valign="top">
        <th scope="row"><?php _e('Solr Port', 'solr4wp') ?></th>
        <td><input type="text" name="s4w_solr_port" value="<?php _e(get_option('s4w_solr_port'), 'solr4wp'); ?>" /></td>
    </tr>

    <tr valign="top">
        <th scope="row"><?php _e('Solr Path', 'solr4wp') ?></th>
        <td><input type="text" name="s4w_solr_path" value="<?php _e(get_option('s4w_solr_path'), 'solr4wp'); ?>" /></td>
    </tr>
</table>
<hr />
<p class="submit">
<input type="submit" class="button-primary" value="<?php _e('Save Changes', 'solr4wp') ?> "/>
<input type="submit" class="button-primary" name="s4w_ping_imitate" value="Test Connection"/>


</p>
<hr />
</div>

<div class="wrap" id="indexing">
<h3><?php _e('Indexing Options', 'solr4wp') ?></h3>

<table class="form-table">
  <?php foreach ($post_types as $post_key => $post_type) {?>
    <tr valign="top">
        <h4 class="s4w_table_header"><?php _e(ucfirst($post_type), 'solr4wp') ?></h4>
        <th scope="row" ><?php _e('Index', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_content_index[<?php echo $post_type?>]" value="1" <?php echo s4w_checkCheckbox("s4w_content_index", $post_type); ?> /></td>
        
        <th scope="row" ><?php _e('Remove on Delete', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_content_delete[<?php echo $post_type?>]" value="1" <?php echo s4w_checkCheckbox('s4w_content_delete', $post_type); ?> /></td>
        
        <th scope="row" ><?php _e('Remove on Status Change', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_content_private[<?php echo $post_type?>]" value="1" <?php echo s4w_checkCheckbox('s4w_content_private', $post_type); ?> /></td>
    </tr>
  <?php }?>
</table>
  <div>
  <div class="tableColumn"><?php _e('Excludes (comma-separated integer ids)', 'solr4wp') ?></div>
  <div class="tableColumn"><input type="text" name="s4w_content_exclude" value="<?php _e(s4w_filter_list2str(get_option('s4w_content_exclude')), 'solr4wp'); ?>" /></div>
     </div>

<BR />
<hr />

<p class="submit">
<input type="submit" class="button-primary" value="<?php _e('Save Changes', 'solr4wp') ?>" />
</p>

<hr />
</div>

<div class="wrap" id="result">
<h3><?php _e('Result Options', 'solr4wp') ?></h3>
<table class="form-table">
    <tr valign="top">
        <th scope="row" ><?php _e('Output Result Info', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_output_info" value="1" <?php echo s4w_checkCheckbox('s4w_output_info'); ?> /></td>
        <th scope="row" style="width:100px;float:left;margin-left:20px;"><?php _e('Output Result Pager', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_output_pager" value="1" <?php echo s4w_checkCheckbox('s4w_output_pager'); ?> /></td>
    </tr>
 
    <tr valign="top">
        <th scope="row" ><?php _e('Output Facets', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_output_facets" value="1" <?php echo s4w_checkCheckbox('s4w_output_facets'); ?> /></td>
        <th scope="row" style="width:100px;float:left;margin-left:20px;"><?php _e('Category Facet as Taxonomy', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_cat_as_taxo" value="1" <?php echo s4w_checkCheckbox('s4w_cat_as_taxo'); ?> /></td>
    </tr>
<?php $x=0 ;foreach ($parent_taxonomy as $parent) { 

        if ($parent != 'nav_menu' && $parent != 'link_category') {
        $parent = ($parent=='category')?'categories':$parent;
        $parent = ($parent=='post_tag')?'tags':$parent;
        
        if($x!==0 && $x%2===0)  echo '</tr>';
        
        if($x%2===0) echo '<tr valign="aaahk">';
        $x++;
  ?> 
        <th scope="row" ><?php _e(ucfirst($parent). ' as Facet', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_facets[<?php echo $parent; ?>]" value="1" <?php echo s4w_checkCheckbox('s4w_facets', $parent); ?> /></td>
   <?php     

        }
      }?>
      
    <tr valign="top">
        <th scope="row" ><?php _e('Post date as Facet', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_facets[postdate]" value="1" <?php echo s4w_checkCheckbox('s4w_facets','postdate'); ?> /></td>
        <th scope="row" style="width:100px;float:left;margin-left:20px;"><?php _e('Language as Facet', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_facets[language]" value="1" <?php echo s4w_checkCheckbox('s4w_facets', 'language'); ?> /></td>
    </tr>
      
    <tr valign="top">
        <th scope="row" ><?php _e('Author as Facet', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_facets[author]" value="1" <?php echo s4w_checkCheckbox('s4w_facets','author'); ?> /></td>
        <th scope="row" style="width:100px;float:left;margin-left:20px;"><?php _e('Type as Facet', 'solr4wp') ?></th>
        <td><input type="checkbox" name="s4w_facets[type]" value="1" <?php echo s4w_checkCheckbox('s4w_facets', 'type'); ?> /></td>
    </tr>
            
    <tr valign="top">
        <th scope="row"><?php _e('Number of Results Per Page', 'solr4wp') ?></th>
        <td><input type="text" name="s4w_num_results" value="<?php _e(get_option('s4w_num_results'), 'solr4wp'); ?>" /></td>
    </tr>   
    
    <tr valign="top">
        <th scope="row"><?php _e('Max Number of Tags to Display', 'solr4wp') ?></th>
        <td><input type="text" name="s4w_max_display_tags" value="<?php _e(get_option('s4w_max_display_tags'), 'solr4wp'); ?>" /></td>
    </tr>
</table>
<hr />
<?php settings_fields('s4w-options-group'); ?>

<p class="submit">
<input type="submit" class="button-primary" value="<?php _e('Save Changes', 'solr4wp') ?>" />
</p>
</form>
<hr />
</div>


<div class="wrap" id="actions">
<form method="post">
<h3><?php _e('Actions', 'solr4wp') ?></h3>
<table class="form-table">
    <tr valign="top">
        <th scope="row"><?php _e('Check Server Settings', 'solr4wp') ?></th>
        <td><input type="submit" class="button-primary" name="s4w_ping" value="<?php _e('Execute', 'solr4wp') ?>" /></td>
    </tr>
  <?php foreach ($post_types as $post_key => $post_type) {
          if ($indexable_content[$post_type]==1) {
  ?>
    
    <tr valign="top">
        <th scope="row"><?php _e('Index all '.ucfirst($post_type), 'solr4wp') ?></th>
        <td><input type="submit" class="button-primary" name="s4w_content_load[<?php echo $post_type?>]" value="<?php _e('Execute', 'solr4wp') ?>" /></td>
    </tr>
  <?php  
    }
  }
  
  if (count($indexable_content)>0) {
  ?>
    <tr valign="top">
        <th scope="row"><?php _e('Index All Content', 'solr4wp') ?></th>
        <td><input type="submit" class="button-primary" name="s4w_content_load[all]" value="<?php _e('Execute', 'solr4wp') ?>" /></td>
    </tr>
  <?php }?>
    <tr valign="top">
        <th scope="row"><?php _e('Delete All', 'solr4wp') ?></th>
        <td><input type="submit" class="button-primary" name="s4w_deleteall" value="<?php _e('Execute', 'solr4wp') ?>" /></td>
    </tr>
</table>
</form>

</div>