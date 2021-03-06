<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2016 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include('./include/auth.php');

$color_actions = array('1' => 'Delete');

/* set default action */
set_default_action();

switch (get_request_var('action')) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'remove':
		color_remove();

		header ('Location: color.php');
		break;
	case 'edit':
		top_header();

		color_edit();

		bottom_footer();
		break;
	case 'export':
		color_export();

		break;
	case 'import':
		top_header();

		color_import();

		bottom_footer();
		break;
	default:
		top_header();

		color();

		bottom_footer();
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if (isset_request_var('save_component_color')) {
		/* ================= input validation ================= */
		get_filter_request_var('id');
		/* ==================================================== */

		$save['id']        = get_nfilter_request_var('id');

		if (!isset_request_var('read_only')) {
			$save['name']      = get_nfilter_request_var('name');
			$save['hex']       = form_input_validate(get_nfilter_request_var('hex'),  'hex',  '^[a-fA-F0-9]+$' , false, 3);
		}else{
			$save['name']      = get_nfilter_request_var('hidden_name');
			$save['read_only'] = 'on';
		}

		if (!is_error_message()) {
			$color_id = sql_save($save, 'colors');

			if ($color_id) {
				raise_message(1);
			}else{
				raise_message(2);
			}
		}

		if (is_error_message()) {
			header('Location: color.php?header=false&action=edit&id=' . (empty($color_id) ? get_nfilter_request_var('id') : $color_id));
		}else{
			header('Location: color.php?header=false');
		}
	}elseif (isset_request_var('save_component_import')) {
		if (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
            $csv_data = file($_FILES['import_file']['tmp_name']);
			$debug_data = color_import_processor($csv_data);

			if (sizeof($debug_data)) {
				$_SESSION['import_debug_info'] = $debug_data;
			}

			header('Location: color.php?action=import');
		}
	}
}

/* -----------------------
    Color Functions
   ----------------------- */

function form_actions() {
	global $color_actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	/* ==================================================== */
	
	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') == '1') { /* delete */
				db_execute('DELETE FROM colors WHERE ' . array_to_sql_or($selected_items, 'hex'));
			}
		}

		header('Location: color.php?header=false');
		exit;
	}

	/* setup some variables */
	$color_list = ''; $i = 0;

	/* loop through each of the graphs selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$color = db_fetch_row_prepared('SELECT * FROM colors WHERE id = ?', array($matches[1]));

			$color_list .= '<li>' . ($color['name'] != '' ? htmlspecialchars($color['name']):'Unnamed Color') . ' (<span style="background-color:#' . $color['hex'] . '">' . $color['hex'] . '</span>)</li>';
			$color_array[$i] = $matches[1];

			$i++;
		}
	}

	top_header();

	form_start('color.php');

	html_start_box($color_actions{get_nfilter_request_var('drp_action')}, '60%', '', '3', 'center', '');

	if (isset($color_array) && sizeof($color_array)) {
		if (get_nfilter_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea' class='odd'>
					<p>Click 'Continue' to delete the folling Color(s).</p>
					<p><ul>$color_list</ul></p>
				</td>
			</tr>\n";

			$save_html = "<input type='button' value='Cancel' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='Continue' title='Delete Color(s)'>";
		}
	}else{
		print "<tr><td class='odd'><span class='textError'>You must select at least one Color.</span></td></tr>\n";
		$save_html = "<input type='button' value='Return' onClick='cactiReturnTo()'>";
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($color_array) ? serialize($color_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_nfilter_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

function color_import_processor(&$colors) {
	$i      = 0;
	$hexcol = 0;
	$return_array = array();

	if (sizeof($colors)) {
	foreach($colors as $color_line) {
		/* parse line */
		$line_array = explode(',', $color_line);

		/* header row */
		if ($i == 0) {
			$save_order = '(';
			$j = 0;
			$first_column = TRUE;
			$required = 0;
			$update_suffix = '';

			if (sizeof($line_array)) {
			foreach($line_array as $line_item) {
				$line_item = trim(str_replace("'", '', $line_item));
				$line_item = trim(str_replace('"', '', $line_item));

				switch ($line_item) {
					case 'hex':
						$hexcol = $j;
					case 'name':
						if (!$first_column) {
							$save_order .= ', ';
						}

						$save_order .= $line_item;

						$insert_columns[] = $j;
						$first_column = FALSE;

						if (strlen($update_suffix)) {
							$update_suffix .= ", $line_item=VALUES($line_item)";
						}else{
							$update_suffix .= " ON DUPLICATE KEY UPDATE $line_item=VALUES($line_item)";
						}

						$required++;

						break;
					default:
						/* ignore unknown columns */
				}

				$j++;
			}
			}

			$save_order .= ')';

			if ($required >= 2) {
				array_push($return_array, '<b>HEADER LINE PROCESSED OK</b>:  <br>Columns found where: ' . $save_order . '<br>');
			}else{
				array_push($return_array, '<b>HEADER LINE PROCESSING ERROR</b>: Missing required field <br>Columns found where:' . $save_order . '<br>');
				break;
			}
		}else{
			$save_value = '(';
			$j = 0;
			$first_column = TRUE;
			$sql_where = '';

			if (sizeof($line_array)) {
			foreach($line_array as $line_item) {
				if (in_array($j, $insert_columns)) {
					$line_item = trim(str_replace("'", '', $line_item));
					$line_item = trim(str_replace('"', '', $line_item));

					if (!$first_column) {
						$save_value .= ',';
					}else{
						$first_column = FALSE;
					}

					$save_value .= "'" . $line_item . "'";

					if ($j == $hexcol) {
						$sql_where = "WHERE hex='$line_item'";
					}
				}

				$j++;
			}
			}

			$save_value .= ')';

			if ($j > 0) {
				if (isset_request_var('allow_update')) {
					$sql_execute = 'INSERT INTO colors ' . $save_order .
						' VALUES ' . $save_value . $update_suffix;

					if (db_execute($sql_execute)) {
						array_push($return_array,"INSERT SUCCEEDED: $save_value");
					}else{
						array_push($return_array,"INSERT FAILED: $save_value");
					}
				}else{
					/* perform check to see if the row exists */
					$existing_row = db_fetch_row("SELECT * FROM colors $sql_where");

					if (sizeof($existing_row)) {
						array_push($return_array,"<strong>INSERT SKIPPED, EXISTING:</strong> $save_value");
					}else{
						$sql_execute = 'INSERT INTO colors ' . $save_order .
							' VALUES ' . $save_value;

						if (db_execute($sql_execute)) {
							array_push($return_array,"INSERT SUCCEEDED: $save_value");
						}else{
							array_push($return_array,"INSERT FAILED: $save_value");
						}
					}
				}
			}
		}

		$i++;
	}
	}

	return $return_array;
}

function color_import() {
	print "<form method='post' action='color.php?action=import' enctype='multipart/form-data'>\n";

	if ((isset($_SESSION['import_debug_info'])) && (is_array($_SESSION['import_debug_info']))) {
		html_start_box('Import Results', '100%', '', '3', 'center', '');

		print "<tr class='even'><td>
			<p class='textArea'>Cacti has imported the following items:</p>
		</td></tr>\n";

		if (sizeof($_SESSION['import_debug_info'])) {
			foreach($_SESSION['import_debug_info'] as $import_result) {
				print "<tr class='even'><td>" . $import_result . "</td></tr>\n";
			}
		}

		html_end_box();

		kill_session_var('import_debug_info');
	}

	html_start_box('Import Colors', '100%', '', '3', 'center', '');

	form_alternate_row();?>
		<td width='50%'><font class='textEditTitle'>Import Colors from Local File</font><br>
			Please specify the location of the CSV file containing your Color information.
		</td>
		<td align='left'>
			<div>
				<label class='import_label' for='import_file'>Select a File</label>
				<input class='import_button' type='file' id='import_file'>
				<span class='import_text'></span>
			</div>
		</td>
	</tr><?php
	form_alternate_row();?>
		<td width='50%'><font class='textEditTitle'>Overwrite Existing Data?</font><br>
			Should the import process be allowed to overwrite existing data?  Please note, this does not mean delete old rows, only update duplicate rows.
		</td>
		<td align='left'>
			<input type='checkbox' name='allow_update' id='allow_update'>Allow Existing Rows to be Updated?
		</td><?php

	html_end_box(FALSE);

	html_start_box('Required File Format Notes', '100%', '', '3', 'center', '');

	form_alternate_row();?>
		<td><strong>The file must contain a header row with the following column headings.</strong>
			<br><br>
			<strong>name</strong> - The Color Name<br>
			<strong>hex</strong> - The Hex Value<br>
			<br>
		</td>
	</tr><?php

	form_hidden_box('save_component_import','1','');

	html_end_box();

	form_save_button('color.php', 'import');
}

function color_edit() {
	global $fields_color_edit;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	if (!isempty_request_var('id')) {
		$color = db_fetch_row_prepared('SELECT * FROM colors WHERE id = ?', array(get_request_var('id')));
		$header_label = '[edit: ' . $color['hex'] . ']';
	}else{
		$header_label = '[new]';
	}

	form_start('color.php', 'color');

	html_start_box("Colors $header_label", '100%', '', '3', 'center', '');

	draw_edit_form(array(
		'config' => array('no_form_tag' => true),
		'fields' => inject_form_variables($fields_color_edit, (isset($color) ? $color : array()))
		)
	);

	html_end_box();

	form_save_button('color.php');

	?>
	<script type='text/javascript'>
	$(function() {
		checkReadonly();

		$('#hex').colorpicker().css({'width':'60px'});
		$('#read_only').click(function() {
			checkReadonly();
		});

		$('#name').keyup(function() {
			$('#hidden_name').val($(this).val());
		});

		function checkReadonly() {
			if ($('#read_only').is(':checked') || $('#read_only').val() == 'on') {
				$('#name').prop('disabled', true);
				$('#hex').prop('disabled', true);
			}else{
				$('#name').prop('disabled', false);
				$('#hex').prop('disabled', false);
			}
		}
	});
	</script>
	<?php
}

function process_request_vars() {
	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'pageset' => true,
			'default' => read_config_option('num_rows_table')
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'default' => '1'
			),
		'filter' => array(
			'filter' => FILTER_CALLBACK, 
			'pageset' => true,
			'default' => '', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK, 
			'default' => 'name', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK, 
			'default' => 'ASC', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'has_graphs' => array(
			'filter' => FILTER_VALIDATE_REGEXP, 
			'options' => array('options' => array('regexp' => '(true|false)')),
			'pageset' => true,
			'default' => 'true'
			),
		'named' => array(
			'filter' => FILTER_VALIDATE_REGEXP, 
			'options' => array('options' => array('regexp' => '(true|false)')),
			'pageset' => true,
			'default' => 'true'
			)
	);

	validate_store_request_vars($filters, 'sess_color');
	/* ================= input validation ================= */
}

function color() {
	global $color_actions, $item_rows;

	process_request_vars();

	html_start_box('Colors', '100%', '', '3', 'center', 'color.php?action=edit');

	?>
	<tr class='even'>
		<td>
			<form id='form_color' action='color.php'>
			<table class='filterTable'>
				<tr>
					<td>
						Search
					</td>
					<td>
						<input id='filter' type='text' name='filter' size='25' value='<?php print htmlspecialchars(get_request_var('filter'));?>'>
					</td>
					<td>
						Colors
					</td>
					<td>
						<select id='rows' name='rows' onChange='applyFilter()'>
							<?php
							if (sizeof($item_rows) > 0) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . htmlspecialchars($value) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<input type="checkbox" id='named' <?php print (get_request_var('named') == 'true' ? 'checked':'');?>>
					</td>
					<td>
						<label for='named'>Named Colors</label>
					</td>
					<td>
						<input type="checkbox" id='has_graphs' <?php print (get_request_var('has_graphs') == 'true' ? 'checked':'');?>>
					</td>
					<td>
						<label for='has_graphs'>Has Graphs</label>
					</td>
					<td>
						<input type='button' id='refresh' value='Go' title='Set/Refresh Filters'>
					</td>
					<td>
						<input type='button' id='clear' value='Clear' title='Clear Filters'>
					</td>
					<td>
						<input type='button' id='import' value='Import' title='Import Colors'>
					</td>
					<td>
						<input type='button' id='export' value='Export' title='Export Colors'>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_request_var('page');?>'>
			</form>
			<script type='text/javascript'>
			function applyFilter() {
				strURL = 'color.php?filter='+$('#filter').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&has_graphs='+$('#has_graphs').is(':checked')+'&named='+$('#named').is(':checked')+'&header=false';
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'color.php?clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#refresh').click(function() {
					applyFilter();
				});

				$('#has_graphs').click(function() {
					applyFilter();
				});

				$('#named').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#form_color').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});

				$('#import').click(function(event) {
					strURL = 'color.php?action=import&header=false';
					loadPageNoHeader(strURL);
				});

				$('#export').click(function(event) {
					strURL = 'color.php?action=export&header=false';
					document.location = strURL;
				});
			});
			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = "WHERE (name LIKE '%" . get_request_var('filter') . "%' 
			OR hex LIKE '%" .  get_request_var('filter') . "%')";
	}else{
		$sql_where = '';
	}

	if (get_request_var('named') == 'true') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " read_only='on'";
	}

	if (get_request_var('has_graphs') == 'true') {
		$sql_having = 'HAVING graphs>0 OR templates>0';
	}else{
		$sql_having = '';
	}

	form_start('color.php', 'chk');

	html_start_box('', '100%', '', '3', 'center', '');

	$total_rows = db_fetch_cell("SELECT
		COUNT(color)
		FROM (
			SELECT
			c.id AS color,
			SUM(CASE WHEN local_graph_id>0 THEN 1 ELSE 0 END) AS graphs,
			SUM(CASE WHEN local_graph_id=0 THEN 1 ELSE 0 END) AS templates
			FROM colors AS c
			LEFT JOIN (
				SELECT color_id, graph_template_id, local_graph_id 
				FROM graph_templates_item 
				WHERE color_id>0
			) AS gti
			ON gti.color_id=c.id
			$sql_where
			GROUP BY c.id
			$sql_having
		) AS rs");

	$colors = db_fetch_assoc("SELECT *,
        SUM(CASE WHEN local_graph_id>0 THEN 1 ELSE 0 END) AS graphs,
        SUM(CASE WHEN local_graph_id=0 THEN 1 ELSE 0 END) AS templates
        FROM (
			SELECT c.*, local_graph_id
			FROM colors AS c
			LEFT JOIN (
				SELECT color_id, graph_template_id, local_graph_id 
				FROM graph_templates_item 
				WHERE color_id>0
			) AS gti
			ON c.id=gti.color_id
		) AS rs
		$sql_where
		GROUP BY rs.id
		$sql_having
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') . "
		LIMIT " . (get_request_var('rows')*(get_request_var('page')-1)) . ',' . get_request_var('rows'));

    $nav = html_nav_bar('color.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), get_request_var('rows'), $total_rows, 8, 'Colors', 'page', 'main');

    print $nav;

	$display_text = array(
		'hex' => array('display' => 'Hex', 'align' => 'left', 'sort' => 'DESC', 'tip' => 'The Hex Value for this Color.'),
		'name' => array('display' => 'Color Name', 'align' => 'left', 'sort' => 'ASC', 'tip' => 'The name of this Color definition.'),
		'read_only' => array('display' => 'Named Color', 'align' => 'left', 'sort' => 'ASC', 'tip' => 'Is this color a named color which are read only.'),
		'nosort1' => array('display' => 'Color', 'align' => 'center', 'sort' => 'DESC', 'tip' => 'The Color as shown on the screen.'),
		'nosort' => array('display' => 'Deletable', 'align' => 'right', 'sort' => '', 'tip' => 'Colors in use can not be Deleted.  In use is defined as being referenced either by a Graph or a Graph Template.'),
		'graphs' => array('display' => 'Graphs', 'align' => 'right', 'sort' => 'DESC', 'tip' => 'The number of Graph using this Color.'),
		'templates' => array('display' => 'Templates', 'align' => 'right', 'sort' => 'DESC', 'tip' => 'The number of Graph Templates using this Color.')
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$i = 0;
	if (sizeof($colors) > 0) {
		foreach ($colors as $color) {
			if ($color['graphs'] == 0 && $color['templates'] == 0) {
				$disabled = false;
			}else{
				$disabled = true;
			}

			if ($color['name'] == '') {
				$color['name'] = 'Unnamed #'. $color['hex'];
			}

			form_alternate_row('line' . $color['id'], false, $disabled);
			form_selectable_cell("<a class='linkEditMain' href='" . htmlspecialchars('color.php?action=edit&id=' . $color['id']) . "'>" . $color['hex'] . '</a>', $color['id']);
			form_selectable_cell(strlen(get_request_var('filter')) ? preg_replace('/(' . preg_quote(get_request_var('filter'), '/') . ')/i', "<span class='filteredValue'>\\1</span>", htmlspecialchars($color['name'])) : htmlspecialchars($color['name']), $color['id']);
			form_selectable_cell($color['read_only'] == 'on' ? 'Yes':'No', $color['id']);
			form_selectable_cell('', $color['id'], '', 'text-align:right;background-color:#' . $color['hex'] . ';min-width:30%');
			form_selectable_cell($disabled ? 'No':'Yes', $color['id'], '', 'text-align:right');
			form_selectable_cell(number_format($color['graphs']), $color['id'], '', 'text-align:right');
			form_selectable_cell(number_format($color['templates']), $color['id'], '', 'text-align:right');
			form_checkbox_cell($color['name'], $color['id'], $disabled);
			form_end_row();
		}

		print $nav;
	}else{
		print "<tr class='tableRow'><td colspan='7'><em>No Colors Found</em></td></tr>\n";
	}
	html_end_box();

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($color_actions, 1);

	form_end();
}

function color_export() {
	process_request_vars();

	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = "WHERE (name LIKE '%" . get_request_var('filter') . "%' 
			OR hex LIKE '%" .  get_request_var('filter') . "%')";
	}else{
		$sql_where = '';
	}

	if (get_request_var('named') == 'true') {
		$sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " read_only='on'";
	}

	if (get_request_var('has_graphs') == 'true') {
		$sql_having = 'HAVING graphs>0 OR templates>0';
	}else{
		$sql_having = '';
	}

	$colors = db_fetch_assoc("SELECT *,
        SUM(CASE WHEN local_graph_id>0 THEN 1 ELSE 0 END) AS graphs,
        SUM(CASE WHEN local_graph_id=0 THEN 1 ELSE 0 END) AS templates
        FROM (
			SELECT c.*, local_graph_id
			FROM colors AS c
			LEFT JOIN (
				SELECT color_id, graph_template_id, local_graph_id 
				FROM graph_templates_item 
				WHERE color_id>0
			) AS gti
			ON c.id=gti.color_id
		) AS rs
		$sql_where
		GROUP BY rs.id
		$sql_having");

	if (sizeof($colors)) {
		header('Content-type: application/csv');
		header('Content-Disposition: attachment; filename=colors.csv');

		print '"name","hex"' . "\n";

		foreach($colors as $color) {
			print '"' . $color['name'] . '","' . $color['hex'] . '"' . "\n";
		}
	}
}

