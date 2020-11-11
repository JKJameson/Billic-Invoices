<?php
if (!defined('BILLIC_CORE_LOADED')) {
	die('Access Denied');
}
class Invoices {
	public $settings = array(
		'admin_menu_category' => 'Accounting',
		'admin_menu_name' => 'Invoices',
		'admin_menu_icon' => '<i class="icon-tags"></i>',
		'description' => 'Invoice your users so they can pay you.',
		'permissions' => array(
			'Invoices_New',
			'Invoices_Update',
			'Invoices_Add_Payment',
			'Invoices_Change_Status',
		) ,
		'user_menu_name' => 'My Invoices',
		'user_menu_icon' => '<i class="icon-tags"></i>',
	);
	function name($invoice) {
		$name = "Invoice #{$invoice['id']}";
		if (empty($invoice['num']))
			return "Proforma $name";
		return "$name";
	}
	function name_short($invoice) {
		$name = "#{$invoice['id']}";
		if (empty($invoice['num']))
			return "Proforma $name";
		return "$name";
	}
	function admin_area() {
		global $billic, $db;
		if (isset($_GET['ID'])) {
			$invoice = $db->q('SELECT * FROM `invoices` WHERE `id` = ?', $_GET['ID']);
			$invoice = $invoice[0];
			if (empty($invoice)) {
				err('Invoice ' . $_GET['ID'] . ' does not exist');
			}
			$user_row = $db->q('SELECT * FROM `users` WHERE `id` = ?', $invoice['userid']);
			$user_row = $user_row[0];
			if (empty($user_row)) {
				err('User ' . $invoice['userid'] . ' does not exist');
			}
			if ($invoice['status'] == 'Unpaid') {
				$editable = true;
			} else {
				$editable = false;
				if ($billic->user_has_permission($billic->user, 'Invoices_Update')) {
					$not_editable_reason = 'This invoice can not be changed because the status is ' . safe($invoice['status']) . '.';
				}
			}
			if (array_key_exists('Action', $_GET) && $_GET['Action'] == 'PDF') {
				$this->generate_pdf($invoice);
			}
			if (isset($_POST['add']) && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				if (empty($_POST['add_description'])) {
					$billic->errors[] = 'Description is required';
				} else if (empty($_POST['add_amount'])) {
					$billic->errors[] = 'Amount is required';
				}
				if (empty($billic->errors)) {
					$id = $db->insert('invoiceitems', array(
						'invoiceid' => $invoice['id'],
						'description' => $_POST['add_description'],
						'amount' => $_POST['add_amount'],
						'order' => $_POST['add_order'],
					));
					$billic->module('Invoices');
					$invoice = $billic->modules['Invoices']->recalc($invoice);
					$billic->status = array(
						'added',
						$_POST['add_description'] . ' (' . $_POST['add_amount'] . ')'
					);
				}
			}
			if (isset($_POST['update']) && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				$db->q('UPDATE `invoices` SET `taxrate` = ? WHERE `id` = ?', $_POST['taxrate'], $invoice['id']);
				$invoice['taxrate'] = $_POST['taxrate'];
				foreach ($_POST['order'] as $id => $order) {
					if (empty($_POST['desc'][$id]) && round($_POST['amount']) == 0) {
						$db->q('DELETE FROM `invoiceitems` WHERE `id` = ?', $id);
					} else {
						$db->q('UPDATE `invoiceitems` SET `order` = ?, `description` = ?, `amount` = ? WHERE `id` = ?', $order, $_POST['desc'][$id], $_POST['amount'][$id], $id);
					}
				}
				$billic->module('Invoices');
				$invoice = $billic->modules['Invoices']->recalc($invoice);
				$billic->status = 'updated';
			}
			$billic->set_title('Admin/'.$this->name($invoice));
			echo '<img src="' . $billic->avatar($user_row['email'], 100) . '" class="pull-left" style="margin: 5px 5px 5px 0"><h3><a href="/Admin/Users/ID/' . $user_row['id'] . '/">' . $user_row['firstname'] . ' ' . $user_row['lastname'] . '' . (empty($user_row['companyname']) ? '' : ' - ' . $user_row['companyname']) . '</a> &raquo; ' . $this->name($invoice) . '</h3><div class="btn-group" role="group" aria-label="Invoice Actions">';
			if ($editable && $billic->user_has_permission($billic->user, 'Invoices_Add_Payment')) {
				echo '<a href="/Admin/Invoices/ID/' . $invoice['id'] . '/Do/AddPayment/" class="btn btn-success"><i class="icon-money-banknote"></i> Add a Payment</a>';
			}
			if ($billic->user_has_permission($billic->user, 'Invoices_Change_Status')) {
				if ($invoice['status'] == 'Unpaid') {
					echo '<a href="/Admin/Invoices/ID/' . $invoice['id'] . '/Do/ChangeStatus/Status/Cancelled/" class="btn btn-danger"><i class="icon-remove"></i> Mark as Cancelled</a>';
				} else {
					echo '<a href="/Admin/Invoices/ID/' . $invoice['id'] . '/Do/ChangeStatus/Status/Unpaid/" class="btn btn-warning"><i class="icon-undo"></i> Mark as Unpaid</a>';
				}
			}
			echo '<a href="/Admin/Invoices/ID/' . $invoice['id'] . '/Action/PDF/" class="btn btn-default"><i class="icon-document"></i> Generate PDF</a>';
			echo '</div><div style="clear:both"></div>';
			if (!empty($not_editable_reason)) {
				echo '<div class="alert alert-info" role="alert">' . $not_editable_reason . '</div>';
			}
			if ($_GET['Do'] == 'ChangeStatus' && $billic->user_has_permission($billic->user, 'Invoices_Change_Status')) {
				if ($_GET['Status'] != 'Unpaid' && $_GET['Status'] != 'Cancelled') {
					err('Invalid Status');
				}
				$db->q('UPDATE `invoices` SET `status` = ? WHERE `id` = ?', $_GET['Status'], $invoice['id']);
				$billic->redirect('/Admin/Invoices/ID/' . $invoice['id'] . '/');
			}
			if ($_GET['Do'] == 'AddPayment' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Add_Payment')) {
				$gateways = array();
				$modules = $billic->module_list_function('payment_callback');
				foreach ($modules as $module) {
					$gateways[] = $module['id'];
				}
				if (isset($_POST['addpayment'])) {
					if (empty($_POST['gateway']) || !in_array($_POST['gateway'], $gateways)) {
						$billic->error('Invalid Gateway');
					} else if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] <= 0 || $_POST['amount'] > $invoice['total']) {
						$billic->error('Invalid Amount');
					} else if (empty($_POST['transid'])) {
						$billic->error('Invalid Transaction ID');
					} else {
						$test = $db->q('SELECT * FROM `transactions` WHERE `gateway` = ? AND `transid` = ?', $_POST['gateway'], $_POST['transid']);
						if (!empty($test)) {
							$billic->error('A transaction already exists with that ID and Gateway');
						} else {
							$error = $this->addpayment(array(
								'gateway' => $_POST['gateway'],
								'invoiceid' => $invoice['id'],
								'amount' => $_POST['amount'],
								'currency' => get_config('billic_currency_code') ,
								'transactionid' => $_POST['transid'],
							));
							if ($error !== true) {
								die('Failed to apply payment to invoice: ' . $error);
							}
							$invoice['status'] = 'Paid';
							echo '<br><b><font color="green">Payment successfully applied</font></b><br><br>';
						}
					}
				}
				$billic->show_errors();
				echo '<form method="POST"><table class="table table-striped" style="width:50%;float:left">';
				echo '<tr><th colspan="2">Add a payment</th></tr>';
				echo '<tr><td>Gateway:</td><td><select class="form-control" name="gateway">';
				foreach ($gateways as $gateway) {
					echo '<option value="' . $gateway . '"' . ($_POST['gateway'] == $gateway ? ' selected' : '') . '>' . $gateway . '</option>';
				}
				echo '</select></td></tr>';
				echo '<tr><td>Amount:</td><td><div class="input-group" style="width: 200px"><span class="input-group-addon">' . get_config('billic_currency_prefix') . '</span><input type="text" class="form-control" name="amount" value="' . safe($_POST['amount']) . '"><span class="input-group-addon">' . get_config('billic_currency_suffix') . '</span></div></td></tr>';
				echo '<tr><td>Transaction&nbsp;ID:</td><td><input type="text" class="form-control" name="transid" value="' . safe($_POST['transid']) . '"></td></tr>';
				echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="addpayment" value="Add Payment &raquo;"></td></tr>';
				echo '</table></form><table class="table table-striped" style="width:45%; float:right"><tr><th colspan="2">' . $this->name($invoice) . '</th></tr><tr><td>Subtotal:</td><td>' . get_config('billic_currency_prefix') . $invoice['subtotal'] . get_config('billic_currency_suffix') . '</td></tr><tr><td>Tax:</td><td>' . get_config('billic_currency_prefix') . $invoice['tax'] . get_config('billic_currency_suffix') . ' (' . $invoice['taxrate'] . '%)</td></tr><tr><td>Total:</td><td>' . get_config('billic_currency_prefix') . $invoice['total'] . get_config('billic_currency_suffix') . '</td></tr><tr><td>User Credit:</td><td>' . get_config('billic_currency_prefix') . $user_row['credit'] . get_config('billic_currency_suffix') . '</td></tr></table>';
				echo '<div style="clear:both"></div>';
				exit;
			}
			$billic->show_errors();
			$this->show($invoice, $user_row, 'admin', $editable);
			return;
		}
		if (isset($_POST['mark_cancelled']) && $billic->user_has_permission($billic->user, 'Invoices_Change_Status')) {
			foreach ($_POST['ids'] as $id => $v) {
				$db->q('UPDATE `invoices` SET `status` = \'Cancelled\' WHERE `status` = \'Unpaid\' AND `id` = ?', $id);
			}
			$billic->status = 'updated';
			$_POST = json_decode(base64_decode($_POST['search_post']) , true);
		}
		if (isset($_POST['mark_unpaid']) && $billic->user_has_permission($billic->user, 'Invoices_Change_Status')) {
			foreach ($_POST['ids'] as $id => $v) {
				$db->q('UPDATE `invoices` SET `status` = \'Unpaid\' WHERE `status` = \'Cancelled\' AND `id` = ?', $id);
			}
			$billic->status = 'updated';
			$_POST = json_decode(base64_decode($_POST['search_post']) , true);
		}
		$billic->module('ListManager');
		$billic->modules['ListManager']->configure(array(
			'search' => array(
				'id' => 'text',
				'date_from' => 'date',
				'date_to' => 'date',
				'amount' => 'text',
				'status' => array(
					'All',
					'Cancelled',
					'Paid',
					'Unpaid',
				) ,
			) ,
		));
		$where = '';
		$where_values = array();
		if (isset($_POST['search'])) {
			if (!empty($_POST['amount'])) {
				$where.= '`amount` = ? AND ';
				$where_values[] = $_POST['amount'];
			}
			if ($_POST['status'] == 'Cancelled') {
				$where.= '`status` = \'Cancelled\' AND ';
			}
			if ($_POST['status'] == 'Paid') {
				$where.= '`status` = \'Paid\' AND ';
			}
			if ($_POST['status'] == 'Unpaid') {
				$where.= '`status` = \'Unpaid\' AND ';
			}
			if (!empty($_POST['date_from'])) {
				$date = date_create_from_format('Y-m-d', $_POST['date_from']);
				$date->setTime(0, 0, 0);
				$date_start = $date->getTimestamp();
				$where.= '`date` > ? AND ';
				$where_values[] = $date_start;
			}
			if (!empty($_POST['date_to'])) {
				$date = date_create_from_format('Y-m-d', $_POST['date_to']);
				$date->setTime(0, 0, 0);
				$date_end = ($date->getTimestamp() + 86399);
				$where.= '`date` < ? AND ';
				$where_values[] = $date_end;
			}
		}
		$where = substr($where, 0, -4);
		$func_array_select1 = array();
		$func_array_select1[] = '`invoices`' . (empty($where) ? '' : ' WHERE ' . $where);
		foreach ($where_values as $v) {
			$func_array_select1[] = $v;
		}
		$func_array_select2 = $func_array_select1;
		$func_array_select1[0] = 'SELECT COUNT(*) FROM ' . $func_array_select1[0];
		$total = call_user_func_array(array(
			$db,
			'q'
		) , $func_array_select1);
		$total = $total[0]['COUNT(*)'];
		$pagination = $billic->pagination(array(
			'total' => $total,
		));
		echo $pagination['menu'];
		$func_array_select2[0] = 'SELECT * FROM ' . $func_array_select2[0] . ' ORDER BY `date` DESC LIMIT ' . $pagination['start'] . ',' . $pagination['limit'];
		$invoices = call_user_func_array(array(
			$db,
			'q'
		) , $func_array_select2);
		echo '<script type="text/javascript">
		function checkAll(bx, name) {
			var input = document.getElementsByTagName(\'input\');
			for (var i = 0; i < input.length; i++) {
				if (input[i].type == \'checkbox\' && input[i].name.split(\'[\')[0] === name) {
					input[i].checked = bx.checked;
				}                
			}
		}
		</script>';
		$billic->set_title('Admin/Invoices');
		echo '<h1><i class="icon-tags"></i> Invoices</h1>';
		echo $billic->modules['ListManager']->search_box();
		echo '<div style="float: right;padding-right: 40px;">Showing ' . $pagination['start_text'] . ' to ' . $pagination['end_text'] . ' of ' . $total . ' Invoices</div>';
		echo $billic->modules['ListManager']->search_link();
		if ($billic->user_has_permission($billic->user, 'Invoices_Change_Status')) {
			echo '<form method="POST">With Selected: <input type="hidden" name="search_post" value="' . base64_encode(json_encode($_POST)) . '"><button type="submit" class="btn btn-xs btn-danger" name="mark_cancelled" onclick="return confirm(\'Are you sure you want to mark the selected invoices as Cancelled?\');"><i class="icon-remove"></i> Mark as Cancelled</button> <button type="submit" class="btn btn-xs btn-warning" name="mark_unpaid" onclick="return confirm(\'Are you sure you want to mark the selected invoices as Unpaid?\');"><i class="icon-undo"></i> Mark as Unpaid</button><br>';
		}
		echo '<table class="table table-striped"><tr><th><input type="checkbox" onclick="checkAll(this, \'ids\')"></th><th>Invoice&nbsp;#</th><th>Date</th><th>Due Date</th><th>Subtotal</th><th>Credit</th><th>Tax</th><th>Total</th><th>Status</th></tr>';
		if (empty($invoices)) {
			echo '<tr><td colspan="20">No Invoices matching filter.</td></tr>';
		}
		foreach ($invoices as $invoice) {
			echo '<tr><td><input type="checkbox" name="ids[' . $invoice['id'] . ']" value="1"></td><td><a href="/Admin/Invoices/ID/' . $invoice['id'] . '/">' . $this->name_short($invoice) . '</a></td><td>' . $billic->date_display($invoice['date']) . '</td><td>' . $billic->date_display($invoice['duedate']) . '</td><td>' . get_config('billic_currency_prefix') . $invoice['subtotal'] . get_config('billic_currency_suffix') . '</td><td>' . get_config('billic_currency_prefix') . $invoice['credit'] . get_config('billic_currency_suffix') . '</td><td>' . get_config('billic_currency_prefix') . $invoice['tax'] . get_config('billic_currency_suffix') . '</td><td>' . get_config('billic_currency_prefix') . $invoice['total'] . get_config('billic_currency_suffix') . '</td><td>';
			switch ($invoice['status']) {
				case 'Paid':
					$label = 'success';
				break;
				case 'Unpaid':
					$label = 'danger';
				break;
				case 'Cancelled':
				default:
					$label = 'default';
				break;
			}
			echo '<span class="label label-' . $label . '">' . $invoice['status'] . '</span>';
			echo '</td></tr>';
		}
		echo '</table>';
	}
	function calc_charge($params) {
		global $billic, $db;
		// is this an "Add Credit" invoice?
		$numitems_addcredit = $db->q('SELECT COUNT(*) FROM `invoiceitems` WHERE `invoiceid` = ? AND `description` = \'Add Credit\'', $params['invoice']['id']);
		$numitems_addcredit = $numitems_addcredit[0]['COUNT(*)'];
		$charge = $params['invoice']['total'];
		if ($params['user']['credit'] > 0 && $numitems_addcredit == 0) {
			if ($params['user']['credit'] >= $params['invoice']['subtotal']) {
				$charge = 0;
			} else {
				$new_subtotal = round($params['invoice']['subtotal'] - $params['user']['credit'], 2);
				if ($params['invoice']['tax'] > 0) {
					$params['invoice']['tax'] = round(($new_subtotal / 100) * $params['invoice']['taxrate'], 2);
				}
				$charge = round($new_subtotal + $params['invoice']['tax'], 2);
			}
			if ($charge < 0) {
				$charge = 0;
			}
		}
		return round($charge, 2);
	}
	function user_area() {
		global $billic, $db;
		$billic->force_login();
		if (isset($_GET['ID'])) {
			$invoice = $db->q('SELECT * FROM `invoices` WHERE `id` = ? AND `userid` = ?', $_GET['ID'], $billic->user['id']);
			$invoice = $invoice[0];
			if (empty($invoice)) {
				err("Invoice " . $_GET['ID'] . " does not exist");
			}
			// is this an "Add Credit" invoice?
			$numitems_addcredit = $db->q('SELECT COUNT(*) FROM `invoiceitems` WHERE `invoiceid` = ? AND `description` = \'Add Credit\'', $invoice['id']);
			$numitems_addcredit = $numitems_addcredit[0]['COUNT(*)'];
			if (array_key_exists('Action', $_GET) && $_GET['Action'] == 'PDF') {
				$this->generate_pdf($invoice);
			}
			if (array_key_exists('Action', $_GET) && $_GET['Action'] == 'Pay') {
				$billic->set_title('Pay #' . $invoice['id']);
				echo '<h1>Pay ' . $this->name($invoice) . ' - <span style="color: #618ca7">Total: ' . get_config('billic_currency_prefix') . $invoice['total'] . get_config('billic_currency_suffix') . '</span></h1>';
				if ($invoice['status'] != 'Unpaid') {
					err('Unable to pay an invoice with the status "' . $invoice['status'] . '"');
				}
				$params = array(
					'invoice' => $invoice,
					'user' => $billic->user,
				);
				$charge = $this->calc_charge($params);
				if ($billic->user['credit'] > 0 && $numitems_addcredit == 0) {
					echo '<p><i class="icon-info"></i> You have ' . get_config('billic_currency_prefix') . $params['user']['credit'] . get_config('billic_currency_suffix') . ' account credit.';
					if ($charge == 0) {
						if ($_GET['Method'] == 'Credit') {
							$error = $this->addpayment(array(
								'gateway' => 'credit',
								'invoiceid' => $params['invoice']['id'],
								'amount' => ($params['invoice']['subtotal'] - $params['invoice']['credit']) ,
								'currency' => get_config('billic_currency_code') ,
								'transactionid' => 'credit',
							));
							if ($error !== true) {
								die('Failed to apply payment to invoice: ' . $error);
							}
							$billic->redirect('/User/Invoices/ID/' . $params['invoice']['id'] . '/Status/Complete/');
						}
						echo '<br><a href="/User/Invoices/ID/' . $params['invoice']['id'] . '/Action/Pay/Method/Credit/">Click here to pay this invoice using your account credit</a>';
					} else {
						echo ' You will only be charged <b>' . get_config('billic_currency_prefix') . $charge . get_config('billic_currency_suffix') . '</b> through the payment processor.';
					}
					echo '</p><br><br>';
					if ($charge == 0) {
						exit;
					}
				}
				$params = array(
					'invoice' => $invoice,
					'charge' => $charge,
				);
				$verification_text = 'Requires <a href="/User/AccountVerification/">account verification</a>.';
				if (isset($_GET['Module'])) {
					$_GET['Module'] = $_GET['Module'];
					$billic->module($_GET['Module']);
					if (method_exists($billic->modules[$_GET['Module']], 'payment_page')) {
						echo call_user_func(array(
							$billic->modules[$_GET['Module']],
							'payment_page'
						) , $params);
					}
				} else {
					echo '<div class="table-responsive">';
					echo '<table class="table table-striped table-hover">';
					echo '<tr><th style="min-width:200px">Payment Method</th><th>Features</th></tr>';
					$modules = array_merge($billic->module_list_function('payment_button') , $billic->module_list_function('payment_page'));
					$modules_done = array();
					foreach ($modules as $module) {
						if (in_array($module['id'], $modules_done)) {
							continue;
						}
						echo '<tr><td>';
						$modules_done[] = $module['id'];
						$billic->module($module['id']);
						if (method_exists($billic->modules[$module['id']], 'payment_page')) {
							$show = false;
							if (method_exists($billic->modules[$module['id']], 'payment_button')) {
								$show = call_user_func(array(
									$billic->modules[$module['id']],
									'payment_button'
								) , $params);
							}
							if ($show === 'verify') {
								echo $verification_text;
							} else
							if ($show !== false) {
								if (empty($show)) {
									$show = 'Pay Now';
								}
								echo '<form action="/User/Invoices/ID/' . $invoice['id'] . '/Action/Pay/Module/' . $module['id'] . '/" method="POST"><input type="submit" class="btn btn-default" value="' . $show . '"></form>';
							}
						} else if (method_exists($billic->modules[$module['id']], 'payment_button')) {
							ob_start();
							$ret = call_user_func(array(
								$billic->modules[$module['id']],
								'payment_button'
							) , $params);
							if ($ret === 'verify') {
								echo $verification_text;
							} else {
								echo $ret;
								$button = ob_get_contents();
								ob_end_clean();
								if ($ret !== false) {
									$button = trim($button);
									//echo '<br>'.$module['id'].' - ';
									//echo var_dump($button);
									//echo '<br><br>';
									if (!empty($button)) {
										echo $button;
									}
								}
							}
						}
						echo '</td><td>';
						if (method_exists($billic->modules[$module['id']], 'payment_features')) {
							echo call_user_func(array(
								$billic->modules[$module['id']],
								'payment_features'
							));
						}
						echo '</td></tr>';
					}
					echo '</table>';
					echo '</div>';
				}
				return;
			}
			$billic->set_title($this->name($invoice));
			echo '<h1>' . $this->name($invoice) . ' - Due ' . $billic->date_display($invoice['duedate']) . ' (' . $invoice['status'] . ')</h1>';
			$editable = false;
			$this->show($invoice, $billic->user, 'client', $editable);
			return;
		}
		$service = null;
		$where = '';
		$where_values = array();
		$where_values[] = $billic->user['id'];
		if (!empty($_GET['Service'])) {
			$service = $db->q('SELECT * FROM `services` WHERE `id` = ? AND `userid` = ?', $_GET['Service'], $billic->user['id']);
			$service = $service[0];
			if (empty($service)) {
				err('Service ' . $_GET['ID'] . ' does not exist');
			}
			$invoices = $db->q('SELECT `invoiceid` FROM `invoiceitems` WHERE `relid` = ? ORDER BY `id` DESC', $_GET['Service']);
			if (empty($invoices)) {
				err('There are no invoices for service ' . $_GET['Service']);
			}
			foreach ($invoices as $invoice) {
				$where.= '`id` = ? OR ';
				$where_values[] = $invoice['invoiceid'];
			}
			$where = substr($where, 0, -4);
		}
		$billic->set_title('My Invoices');
		echo '<h1><i class="icon-tags"></i> My Invoices' . (empty($service) ? '' : ' for ' . $billic->service_type($service)) . '</h1>';
		$func_array = array();
		$func_array[] = 'SELECT * FROM `invoices` WHERE `userid` = ?' . (empty($where) ? '' : ' AND (' . $where . ')') . ' ORDER BY `id` DESC';
		foreach ($where_values as $v) {
			$func_array[] = $v;
		}
		$invoices = call_user_func_array(array(
			$db,
			'q'
		) , $func_array);
		if (empty($invoices)) {
			echo '<p>You have no invoices.</p>';
		} else {
			echo '<table class="table table-striped"><tr><th>Invoice&nbsp;ID</th><th>Due Date</th><th>Total</th><th>Status</th><th>Actions</th></tr>';
			foreach ($invoices as $invoice) {
				echo '<tr><td><a href="/User/Invoices/ID/' . $invoice['id'] . '/">' . $this->name_short($invoice) . '</a></td><td>' . $billic->date_display($invoice['duedate']) . '</td><td>' . get_config('billic_currency_prefix') . $invoice['total'] . get_config('billic_currency_suffix') . '</td><td>';
				switch ($invoice['status']) {
					case 'Paid':
						$label = 'success';
					break;
					case 'Unpaid':
						$label = 'danger';
					break;
					case 'Cancelled':
					default:
						$label = 'default';
					break;
				}
				echo '<span class="label label-' . $label . '">' . $invoice['status'] . '</span>';
				echo '</td><td><a class="btn btn-default btn-xs" href="/User/Invoices/ID/' . $invoice['id'] . '/"><i class="icon-eye"></i> View Invoice</a>';
				echo ' <a class="btn btn-default btn-xs" href="/User/Invoices/ID/' . $invoice['id'] . '/Action/PDF/"><i class="icon-document"></i> PDF</a>';
				if ($invoice['status'] == 'Unpaid') {
					echo ' <a class="btn btn-success btn-xs" href="/User/Invoices/ID/' . $invoice['id'] . '/Action/Pay/"><i class="icon-credit-card"></i> Pay</a>';
				}
				echo '</td></tr>';
			}
			echo '</table>';
		}
	}
	function user_address($user_row, $html = false) {
		global $billic, $db;
		if ($html) {
			$eol = '<br>';
		} else {
			$eol = PHP_EOL;
		}
		$r = '';
		if ($html && $billic->user_has_permission($billic->user, 'Users')) {
			$r.= '<a href="/Admin/Users/ID/' . $user_row['id'] . '/">';
		}
		$r.= $user_row['firstname'] . ' ' . $user_row['lastname'];
		if ($html && $billic->user_has_permission($billic->user, 'Users')) {
			$r.= '</a>';
		}
		$r.= (empty($user_row['companyname']) ? '' : ' (' . $user_row['companyname'] . ')') . $eol . (empty($user_row['address1']) ? '' : $user_row['address1'] . $eol) . (empty($user_row['address2']) ? '' : $user_row['address2'] . $eol) . (empty($user_row['city']) ? '' : $user_row['city'] . $eol) . (empty($user_row['state']) ? '' : $user_row['state'] . $eol) . (empty($user_row['postcode']) ? '' : $user_row['postcode'] . $eol) . (empty($user_row['country']) ? '' : $billic->countries[$user_row['country']] . $eol);
		if (!empty($user_row['vatnumber']))
			$r.= "{$eol}Tax ID: {$user_row['vatnumber']}";
		// (empty($user_row['phonenumber']) ? '' : 'Phone: ' . $user_row['phonenumber'] . $eol) . (empty($user_row['email']) ? '' : 'Email: ' . $user_row['email'])
		return $r;
	}
	function show($invoice, $user_row, $area, $editable) { // $area = admin OR client
		global $billic, $db;
		if ($invoice['status'] == 'Unpaid') {
			if ($_GET['Status'] == 'Completed') {
				$sessKey = 'invoice_'.$invoice['id'].'_complete';
				if (empty($_SESSION[$sessKey]))
					$_SESSION[$sessKey] = time();
				if ($_SESSION[$sessKey]>(time()-300)) {
					echo '<p>Please wait, we are processing your payment. Checking again in <span id="paymentRefreshCountdown">10</span> seconds.</p><script>var paymentRefreshCountdown = 10;setInterval(function() { paymentRefreshCountdown--; if (paymentRefreshCountdown===0) { location.reload(); } else if (paymentRefreshCountdown>0) { $(\'#paymentRefreshCountdown\').text(paymentRefreshCountdown); } }, 1000);</script>';
					return;
				} else {
					echo '<p>Something went wrong! Please check your payment.</p>';
					unset($_SESSION[$sessKey]);
					return;
				}
			}
		}
		echo '<div class="row"><div class="col-sm-4" style="padding: 20px"><b>' . $this->name($invoice) . '</b><br><div style="padding-left: 10px">Date: ' . $billic->date_display($invoice['date']) . '<br>Due: ' . $billic->date_display($invoice['duedate']) . '<br>Status: ';
		switch ($invoice['status']) {
			case 'Paid':
				$label = 'success';
			break;
			case 'Unpaid':
				$label = 'danger';
			break;
			case 'Cancelled':
			default:
				$label = 'default';
			break;
		}
		echo '<span class="label label-' . $label . '">' . $invoice['status'] . '</span>';
		echo '</div></div><div class="col-sm-4" style="padding: 20px"><b>Invoiced to:</b><br><div style="padding-left: 10px">';
		echo $this->user_address($user_row, true);
		echo '</div></div><div class="col-sm-4" style="padding: 20px"><b>To pay ' . get_config('billic_companyname') . ':</b><br><div style="padding-left: 10px">' . nl2br(get_config('billic_companyaddress'));
		echo '</div></div></div><br>';
		if ($area == 'client') {
			echo '<div class="row" align="center">';
			if ($invoice['status'] == 'Unpaid') {
				echo '<a class="btn btn-success" href="/User/Invoices/ID/' . $invoice['id'] . '/Action/Pay/"><i class="icon-credit-card"></i> Pay Invoice</a>';
			}
			echo '&nbsp;<a class="btn btn-default" href="/User/Invoices/ID/' . $invoice['id'] . '/Action/PDF/"><i class="icon-document"></i> Generate PDF</a></div><br><br>';
		}
			
		if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
			$_POST['add_order'] = $db->q('SELECT COUNT(*) FROM `invoiceitems` WHERE `invoiceid` = ?', $_GET['ID']);
			$_POST['add_order'] = $_POST['add_order'][0]['COUNT(*)'] * 5;
			$billic->module('ListManager');
			$billic->modules['ListManager']->configure(array(
				'add_title' => 'Add an Item',
				'add' => array(
					'add_order' => 'text',
					'add_description' => 'text',
					'add_amount' => 'text',
				) ,
			));
			echo $billic->modules['ListManager']->add_box();
			echo $billic->modules['ListManager']->add_link();
		}
		if ($area == 'admin') {
			echo '<form method="POST">';
			if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				$colspan = 3;
			} else {
				$colspan = 2;
			}
		} else {
			$colspan = 1;
		}
		echo '<table class="table table-striped">';
		echo '<tr>';
		if ($area == 'admin') {
			echo '<th width="100">Service ID</th>';
		}
		if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
			echo '<th width="50">Order</th>';
		}
		echo '<th>Description</th><th width="100">Amount</th>';
		echo '</tr>';
		$items = $db->q('SELECT * FROM `invoiceitems` WHERE `invoiceid` = ? ORDER BY `order` ASC', $_GET['ID']);
		$subtotal = 0;
		$order = 0;
		foreach ($items as $item) {
			echo '<tr>';
			if ($area == 'admin') {
				echo '<td><a href="/Admin/Services/ID/' . $item['relid'] . '/">' . $item['relid'] . '</a></td>';
			}
			if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				echo '<td><input type="text" class="form-control" name="order[' . $item['id'] . ']" style="width:40px;text-align:center" value="' . $order . '"></td>';
			}
			echo '<td>';
			$desc = safe(str_replace(PHP_EOL, ' | ', $item['description']));
			if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				echo '<input type="text" class="form-control" name="desc[' . $item['id'] . ']" style="width: 100%" value="';
			}
			echo $desc;
			if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				echo '">';
			}
			echo '</td><td>';
			if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				echo '<div class="input-group"><span class="input-group-addon">' . get_config('billic_currency_prefix') . '</span><input type="text" class="form-control" name="amount[' . $item['id'] . ']" style="width: 70px" value="';
			} else {
				echo get_config('billic_currency_prefix');
			}
			echo $item['amount'];
			if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
				echo '"><span class="input-group-addon">' . get_config('billic_currency_suffix') . '</span></div>';
			} else {
				echo get_config('billic_currency_suffix');
			}
			echo '</td>';
			echo '</tr>';
			$subtotal+= $item['amount'];
			$order+= 5;
		}
		echo '<tr><td colspan="' . $colspan . '" align="right">Subtotal:</td><td>' . get_config('billic_currency_prefix') . number_format($subtotal, 2) . get_config('billic_currency_suffix') . '</td></tr>';
		echo '<tr><td colspan="' . $colspan . '" align="right">Credit Applied:</td><td>' . get_config('billic_currency_prefix') . $invoice['credit'] . get_config('billic_currency_suffix') . '</td></tr>';
		$subtotal = $subtotal - $invoice['credit'];
		echo '<tr><td colspan="' . $colspan . '" align="right">';
		if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
			echo '<div class="input-group"><span class="input-group-addon">Tax @ </span><input type="text" class="form-control" name="taxrate" style="width: 70px" value="' . number_format($invoice['taxrate'], 2) . '"><span class="input-group-addon">%</span></div>';
		} else {
			$taxrate = 0;
			if ($invoice['taxrate']>0)
				$taxrate = $invoice['taxrate'];
			echo 'Tax @ ' . number_format($taxrate, 2) . '%';
		}
		echo '</td><td>' . get_config('billic_currency_prefix') . $invoice['tax'] . get_config('billic_currency_suffix') . '</td></tr>';
		$total = $subtotal + $invoice['tax'];
		echo '<tr><td colspan="' . $colspan . '" align="right"><b>Total:</b></td><td><b>' . get_config('billic_currency_prefix') . number_format($total, 2) . get_config('billic_currency_suffix') . '</b></td></tr>';
		echo '</table><br>';
		if ($area == 'admin' && $editable && $billic->user_has_permission($billic->user, 'Invoices_Update')) {
			echo '<div align="center"><input type="submit" class="btn btn-success" name="update" value="Update Invoice &raquo;"></div></form><br>';
		}
		echo '<h2>Payment History</h2>';
		echo '<table class="table table-striped">';
		echo '<tr><th>Date</th><th>Method</th><th>Transaction ID</th><th>Amount In</th></tr>';
		$payments = $db->q('SELECT * FROM `transactions` WHERE `invoiceid` = ?', $invoice['id']);
		if (empty($payments)) {
			echo '<tr><td colspan="4">No payments found.</td></tr>';
		}
		foreach ($payments as $payment) {
			echo '<tr><td>' . $billic->date_display($payment['date']) . '</td><td>' . $payment['gateway'] . '</td><td>' . $payment['transid'] . '</td><td>' . get_config('billic_currency_prefix') . $payment['amount'] . get_config('billic_currency_suffix') . '</td></tr>';
		}
		echo '</table><br>';
		echo '<h2>Credit History</h2>';
		echo '<table class="table table-striped">';
		echo '<tr><th>Date</th><th>Description</th><th>Amount</th></tr>';
		$credits = $db->q('SELECT * FROM `logs_credit` WHERE `description` LIKE ?', '%#' . $invoice['id']);
		if (empty($credits)) {
			echo '<tr><td colspan="3">No credit applied to this invoice.</td></tr>';
		}
		foreach ($credits as $credit) {
			echo '<tr><td>' . $billic->date_display($credit['date']) . '</td><td>' . $credit['description'] . '</td><td>' . get_config('billic_currency_prefix') . $credit['amount'] . get_config('billic_currency_suffix') . '</td></tr>';
		}
		echo '</table>';
	}
	/*
		service - the row of the service
		user - the row of the user who owns the service
		duedate - timestamp - If set, this will set the duedate of the invoice and send an email notifying of the invoice creation
		amount - number - For adding account credit (sets the amount)
	*/
	function generate($params) {
		global $billic, $db;
		if (!is_array($params['service']) && $params['service'] == 'credit') {
			$main_amount = $params['amount'];
			$amount = $main_amount;
			// Invoice item
			$item_type = 'addcredit';
			$item_relid = 0;
			$item_description = 'Add Credit';
		} else {
			if (round($params['service']['amount'], 2) === 0.00) {
				return 0;
			}
			$main_amount = $params['service']['amount'];
			$setup_amount = $params['service']['setup'];
			// Pro rata
			if ($params['prorata_day'] > 0) {
				$main_amount = $params['prorata_amount'];
			}
			if (!empty($service['import_data'])) {
				$import_data = json_decode($params['service']['import_data'], true);
				if ($import_data == null || empty($import_data)) {
					err('The exported plan data is corrupt for service ' . $params['service']['id']);
				}
				$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ? AND `import_hash` = ?', $params['service']['billingcycle'], $import_data['hash']);
			} else {
				$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ?', $params['service']['billingcycle']);
			}
			$billingcycle = $billingcycle[0];
			// billing cycle multiplier
			$main_amount = $main_amount * $billingcycle['multiplier'];
			if ($main_amount <= 0) {
				err('Invalid billingcycle multiplier for service ' . $params['service']['id']);
			}
			$amount = $main_amount + $service['setup'];
			// billing cycle discount
			$billingcycle_discount = (($amount / 100) * $billingcycle['discount']);
			$amount = ($amount - $billingcycle_discount);
			// discount tier
			$reseller_discount = 0;
			if ($billic->module_exists('DiscountTiers')) {
				$billic->module('DiscountTiers');
				$reseller_discount_percent = $billic->modules['DiscountTiers']->calc_discount_tier($params['user']);
				$reseller_discount = (($amount / 100) * $reseller_discount_percent);
				$amount = ($amount - $reseller_discount);
			}
			// Coupon
			$coupon_name = $params['service']['coupon_name'];
			$coupon_discount = 0;
			if (!empty($coupon_name)) {
				$coupon_data = json_decode($params['service']['coupon_data'], true);
				$invoice_count = $db->q('SELECT COUNT(*) FROM `invoiceitems` WHERE `relid` = ?', $params['service']['id']);
				$invoice_count = $count[0]['COUNT(*)'];
				if ($invoice_count == 0 || $coupon_data['remaining_billing_cycles'] > 0) {
					if ($coupon['data']['setup_type'] == 'fixed') {
						$coupon_discount = $coupon_data['setup'];
					} else if ($coupon['data']['setup_type'] == 'percent') {
						$coupon_discount = (($setup_amount / 100) * $coupon_data['setup']);
					}
					if ($coupon['data']['recurring_type'] == 'fixed') {
						$coupon_discount = $coupon_data['recurring'];
					} else if ($coupon['data']['recurring_type'] == 'percent') {
						$coupon_discount = (($main_amount / 100) * $coupon_data['recurring']);
					}
					$amount-= $coupon_discount;
				}
				$coupon_data['remaining_billing_cycles'] = ($coupon_data['recurring_cycles'] - 1);
				if ($coupon_data['remaining_billing_cycles'] < 0) {
					$coupon_data['remaining_billing_cycles'] = 0;
				}
				$db->q('UPDATE `services` SET `coupon_data` = ? WHERE `id` = ?', json_encode($coupon_data) , $params['service']['id']);
			}
			// Invoice item
			$item_type = '';
			$item_relid = $params['service']['id'];
			$item_description = 'Service #' . $params['service']['id'] . ' ';
			if (!empty($params['service']['domain'])) {
				$item_description.= $params['service']['domain'] . ' ';
			}
			if (!empty($params['service']['username'])) {
				$item_description.= $params['service']['username'] . ' ';
			}
			$item_description.= '(' . $billingcycle['displayname1'] . ($params['prorata_day'] > 0 ? ' Pro Rata' : '') . ')';
		}
		// tax
		$taxinfo = $db->q('SELECT `country`, `vatnumber` FROM `users` WHERE `id` = ?', $params['user']['id']);
		$taxinfo = $taxinfo[0];
		if (empty($taxinfo['country'])) {
			err('Invalid country for client ' . $params['user']['id']);
		}
		$tax_percent = NULL; // Null allows us to determine if a user is in a TAX-chargable country. It would only be "0" if the VAT rate is actually zero. If it's in a non-taxable country the VAT RATE is NULL
		// new taxable logic
		$tax_group = '';
		if (isset($params['service']['tax_group'])) {
			$tax_group = $params['service']['tax_group'];
		}
		if (isset($params['tax_group'])) {
			$tax_group = $params['tax_group'];
		}
		if (!empty($tax_group)) {
			$tax_rule = $db->q('SELECT * FROM `tax_rules` WHERE `group` = ? AND `country` = ?', $tax_group, $taxinfo['country']);
			$tax_rule = $tax_rule[0];
			if (!empty($tax_rule)) {
				$tax_percent = $tax_rule['rate'];
				if ($tax_rule['allow_eu_zero'] == 1 && !empty($taxinfo['vatnumber'])) {
					$tax_percent = 0;
				}
			}
		}
		if ($tax_percent === NULL) {
			$tax = 0;
		} else {
			$tax = (($amount / 100) * $tax_percent);
		}
		$total = $amount + $tax;
		//var_dump($amount, $tax, $total, $params['prorata_amount']); exit;
		$time = time();
		$invoiceid = $db->insert('invoices', array(
			'userid' => $params['user']['id'],
			'date' => $time,
			'duedate' => ($params['duedate'] === NULL ? $time : $params['duedate']) ,
			'subtotal' => $amount,
			'tax' => $tax,
			'total' => $total,
			'taxrate' => $tax_percent,
			'status' => 'Unpaid',
		));
		if ($tax_percent === NULL) {
			$db->q('UPDATE `invoices` SET `taxrate` = NULL WHERE `id` = ?', $invoiceid);
		}
		$prorata_time = 0;
		if ($params['prorata_time']>0) {
			$prorata_time = $params['prorata_time'];
		}
		$db->insert('invoiceitems', array(
			'invoiceid' => $invoiceid,
			'type' => $item_type,
			'relid' => $item_relid,
			'description' => $item_description,
			'amount' => $main_amount,
			'prorata_time' => $prorata_time,
		));
		if ($setup_amount > 0) {
			$db->insert('invoiceitems', array(
				'invoiceid' => $invoiceid,
				'type' => 'setup',
				'relid' => $params['service']['id'],
				'description' => 'Setup Fee',
				'amount' => $setup_amount,
			));
		}
		if ($billingcycle_discount > 0) {
			$db->insert('invoiceitems', array(
				'invoiceid' => $invoiceid,
				'type' => 'billingcycle_discount',
				'relid' => $params['service']['id'],
				'description' => $billingcycle['discount'] . '% Discount for ' . $billingcycle['displayname1'] . ' payment',
				'amount' => '-' . $billingcycle_discount,
			));
		}
		if ($reseller_discount > 0) {
			$db->insert('invoiceitems', array(
				'invoiceid' => $invoiceid,
				'type' => 'reseller_discount',
				'relid' => $params['service']['id'],
				'description' => $reseller_discount_percent . '% Reseller Discount',
				'amount' => '-' . $reseller_discount,
			));
		}
		if ($coupon_discount > 0) {
			$db->insert('invoiceitems', array(
				'invoiceid' => $invoiceid,
				'type' => 'coupon',
				'relid' => $params['service']['id'],
				'description' => 'Coupon ' . $coupon_name,
				'amount' => '-' . $coupon_discount,
			));
		}
		if ($params['service'] !== 'credit') {
			$db->q('UPDATE `services` SET `invoicegenerated` = \'1\' WHERE `id` = ?', $params['service']['id']);
		}
		if ($params['duedate'] !== NULL) {
			// Send invoice generated email
			$template_id = $db->q('SELECT `id` FROM `emailtemplates` WHERE `default` = ?', 'Invoice Generated');
			$template_id = $template_id[0]['id'];
			$billic->module('EmailTemplates');
			$billic->modules['EmailTemplates']->send(array(
				'to' => $params['user']['email'],
				'template_id' => $template_id,
				'vars' => array(
					'invoice' => array(
						'id' => $invoiceid,
						'duedatetext' => $billic->date_display($params['duedate']) ,
					) ,
					'services' => $params['service'],
					'users' => $params['user'],
				) ,
			));
		}
		if ($item_description != 'Add Credit') {
			$invoice = $db->q('SELECT * FROM `invoices` WHERE `id` = ?', $invoiceid);
			$invoice = $invoice[0];
			// Calculate amount to charge
			$params_calc_charge = array(
				'invoice' => $invoice,
				'user' => $params['user'],
			);
			$charge = $this->calc_charge($params_calc_charge);
			$params_payment_charge = array(
				'invoice' => $invoice,
				'user' => $params['user'],
				'charge' => $charge,
			);
			// Process payment charge
			$modules = $billic->module_list_function('payment_charge');
			foreach ($modules as $module) {
				$billic->module($module['id']);
				if (method_exists($billic->modules[$module['id']], 'payment_charge')) {
					$payment_charge = call_user_func(array(
						$billic->modules[$module['id']],
						'payment_charge'
					) , $params_payment_charge);
					if ($payment_charge != 'PASS' && $payment_charge !== true) {
						err('Failed to charge using module ' . $module['id'] . ': ' . $payment_charge);
					}
				}
			}
			if ($params['user']['auto_renew'] == 1 && $params['user']['credit'] >= $amount) {
				$error = $this->addpayment(array(
					'gateway' => 'credit',
					'invoiceid' => $invoiceid,
					'amount' => $amount,
					'currency' => get_config('billic_currency_code') ,
					'transactionid' => 'credit',
				));
				if ($error !== true) {
					err('Failed to apply payment to invoice: ' . $error);
				}
			}
		}
		return $invoiceid;
	}
	function recalc($invoice) {
		global $db;
		$subtotal = $db->q('SELECT SUM(`amount`) FROM `invoiceitems` WHERE `invoiceid` = ?', $invoice['id']);
		$subtotal = $subtotal[0]['SUM(`amount`)'];
		$tax = ((($subtotal - $invoice['credit']) / 100) * $invoice['taxrate']);
		$total = $subtotal + $tax;
		$db->q('UPDATE `invoices` SET `subtotal` = ?, `tax` = ?, `total` = ? WHERE `id` = ?', $subtotal, $tax, $total, $invoice['id']);
		$invoice = $db->q('SELECT * FROM `invoices` WHERE `id` = ?', $invoice['id']);
		return $invoice[0];
	}
	function addpayment($params) {
		global $billic, $db;
		$time = time(); // Make timestamp consistent in the database records
		$transaction_id = $params['transactionid'];
		$invoice_id = trim($params['invoiceid']);
		if (!ctype_digit($invoice_id)) {
			// Work out the invoice ID
			preg_match('/Invoice \#([0-9]+)/i', $params['invoiceid'], $invoice_id);
			$invoice_id = $invoice_id[1];
		}
		if (empty($invoice_id) || !ctype_digit($invoice_id) || $invoice_id < 1) {
			return 'Invalid invoice number provided by gateway "' . $params['invoiceid'] . '"';
		}
		// Get the invoice
		$invoice = $db->q('SELECT * FROM `invoices` WHERE `id` = ?', $invoice_id);
		$invoice = $invoice[0];
		if (empty($invoice)) {
			return 'Invoice "' . $invoice_id . '" does not exist';
		}
		if ($invoice['status'] != 'Unpaid') {
			return 'Invoice ' . $invoice['id'] . ' is not unpaid';
		}
		// is this an "Add Credit" invoice?
		$numitems_addcredit = $db->q('SELECT COUNT(*) FROM `invoiceitems` WHERE `invoiceid` = ? AND `description` = \'Add Credit\'', $invoice['id']);
		$numitems_addcredit = $numitems_addcredit[0]['COUNT(*)'];
		// Apply any credit
		$user_row = $db->q('SELECT * FROM `users` WHERE `id` = ?', $invoice['userid']);
		$user_row = $user_row[0];
		$new_credit_user = $user_row['credit'];
		$new_credit_invoice = 0;
		if ($user_row['credit'] > 0 && $numitems_addcredit == 0) {
			if ($user_row['credit'] >= $invoice['subtotal']) {
				if ($invoice['tax'] > 0) {
					$invoice['tax'] = 0;
				}
				$new_credit_user = round($new_credit_user - $invoice['subtotal'], 2);
				$new_credit_invoice = $invoice['subtotal'];
				$invoice['total'] = 0;
			} else {
				$new_credit_user = 0;
				$new_credit_invoice = $user_row['credit'];
				$invoice['subtotal'] = round($invoice['subtotal'] - $new_credit_invoice, 2);
				if ($invoice['tax'] > 0) {
					$invoice['tax'] = round(($invoice['subtotal'] / 100) * $invoice['taxrate'], 2);
				}
				$invoice['total'] = round($invoice['subtotal'] + $invoice['tax'], 2);
			}
		}
		if ($new_credit_invoice == 0 && $params['gateway'] == 'credit') {
			return 'General failure while attempting to pay invoice "' . $invoice['id'] . '" completely with account credit';
		}
		if ($invoice['total'] > 0) {
			// Check amount and currency
			if ($invoice['total'] > $params['amount'] || $params['currency'] != get_config('billic_currency_code')) {
				$params['amount'] = round($billic->currency_convert(array(
					'currency_from' => $params['currency'],
					'currency_to' => get_config('billic_currency_code') ,
					'amount' => $params['amount'],
				)) , 2);
				if ((($params['amount'] / 100) * 105) <= $invoice['total']) { // 105% of value in the currency to account for any exchange rate differences
					return 'Payment was not enough to cover invoice ' . $invoice['id'];
				}
				$params['amount'] = $invoice['total'];
			}
			// Apply the payment to the invoice
			$db->insert('transactions', array(
				'userid' => $invoice['userid'],
				'gateway' => $params['gateway'],
				'date' => $time,
				'description' => 'Invoice Payment',
				'amount' => $params['amount'],
				'transid' => $params['transactionid'],
				'invoiceid' => $invoice['id'],
			));
		}
		$db->q('UPDATE `invoices` SET `datepaid` = ?, `status` = \'Paid\', `credit` = ?, `subtotal` = ?, `total` = ?, `tax` = ? WHERE `id` = ?', $time, $new_credit_invoice, $invoice['subtotal'], $invoice['total'], $invoice['tax'], $invoice['id']);
		$db->q('UPDATE `users` SET `credit` = ? WHERE `id` = ?', $new_credit_user, $user_row['id']);
		if ($new_credit_invoice > 0) {
			$db->insert('logs_credit', array(
				'clientid' => $user_row['id'],
				'date' => time() ,
				'description' => 'Credit Applied to ' . $this->name($invoice),
				'amount' => $new_credit_invoice,
				'invoiceid' => $invoice['id'],
			));
		}
		$template_id = $db->q('SELECT `id` FROM `emailtemplates` WHERE `default` = ?', 'Invoice Paid');
		$template_id = $template_id[0]['id'];
		$billic->module('EmailTemplates');
		$billic->modules['EmailTemplates']->send(array(
			'to' => $user_row['email'],
			'template_id' => $template_id,
			'vars' => array(
				'invoices' => $invoice,
				'users' => $user_row,
			) ,
		));
		// Extend the service time
		$invoiceitems = $db->q('SELECT * FROM `invoiceitems` WHERE `invoiceid` = ?', $invoice['id']);
		if (empty($invoiceitems)) {
			return 'No invoice items in invoice ' . $invoice['id'];
		}
		$services_extended = array();
		foreach ($invoiceitems as $invoiceitem) {
			if ($invoiceitem['type'] == 'addcredit') {
				$db->insert('logs_credit', array(
					'clientid' => $invoice['userid'],
					'date' => time() ,
					'description' => 'Add Funds ' . $this->name($invoice),
					'amount' => $invoiceitem['amount'],
				));
				$db->q('UPDATE `users` SET `credit` = (`credit`+?) WHERE `id` = ?', $invoiceitem['amount'], $invoice['userid']);
				continue;
			}
			if ($invoiceitem['relid'] == 0) {
				continue;
			}
			$service = $db->q('SELECT * FROM `services` WHERE `id` = ?', $invoiceitem['relid']);
			$service = $service[0];
			if (in_array($service['id'], $services_extended) || empty($service)) {
				continue;
			}
			$services_extended[] = $service['id'];
			$oldtime = 0;
			$action = NULL;
			if ($service['domainstatus'] == 'Active') { // Add time to the service nextduedate
				$oldtime = $service['nextduedate'];
			} else if ($service['domainstatus'] == 'Terminated' || $service['domainstatus'] == 'Cancelled' || $service['domainstatus'] == 'Pending') { // Add time to the current time, set status to pending for re-creation
				$oldtime = time();
				$action = 'set2pending';
			} else if ($service['domainstatus'] == 'Suspended') { // Add time to the current time, unsuspend service
				$oldtime = time();
				$action = 'unsuspend';
			} else {
				return 'Failed to reactivate the service ID "' . $service['id'] . '" because the status of the service is "' . $service['domainstatus'] . '"';
			}
			if ($oldtime < 1) {
				return 'Failed to calculate oldtime for service ID "' . $service['id'] . '"';
			}
			if (!empty($service['import_data'])) {
				$import_data = json_decode($service['import_data'], true);
				if ($import_data == null || empty($import_data)) {
					err('The exported plan data is corrupt for service ' . $service['id']);
				}
				$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ? AND `import_hash` = ?', $service['billingcycle'], $import_data['hash']);
			} else {
				$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ?', $service['billingcycle']);
			}
			$billingcycle = $billingcycle[0];
			$newtime = ($oldtime + $billingcycle['seconds']);
			if ($newtime <= $oldtime) {
				return 'The billingcycle seems to have an invalid time multiplier for service ID "' . $service['id'] . '"';
			}
			// Prorata
			if ($invoiceitem['prorata_time'] > 0) {
				$newtime = $invoiceitem['prorata_time'];
			}
			$db->q('UPDATE `services` SET `nextduedate` = ?, `invoicegenerated` = \'0\', `reminderemailsent` = \'0\' WHERE `id` = ?', $newtime, $service['id']);
			switch ($action) {
				case 'set2pending':
					$db->q('UPDATE `services` SET `domainstatus` = \'Pending\' WHERE `id` = ?', $service['id']);
				break;
				case 'unsuspend':
					$billic->module($service['module']);
					if (method_exists($billic->modules[$service['module']], 'unsuspend')) {
						$array = array(
							'service' => $service,
						);
						if (call_user_func(array(
							$billic->modules[$service['module']],
							'unsuspend'
						) , $array) === false) {
							return 'Failed to unsuspend service ID "' . $service['id'] . '"';
						}
						$db->q('UPDATE `services` SET `domainstatus` = \'Active\' WHERE `id` = ?', $service['id']);
						$template_id = $db->q('SELECT `email_template_unsuspended` FROM `plans` WHERE `id` = ?', $service['packageid']);
						$template_id = $template_id[0]['email_template_unsuspended'];
						if (!is_int($template_id)) {
							$template_id = $db->q('SELECT `id` FROM `emailtemplates` WHERE `default` = ?', 'Service Unsuspended');
							$template_id = $template_id[0]['id'];
						}
						$billic->module('EmailTemplates');
						$billic->modules['EmailTemplates']->send(array(
							'to' => $user_row['email'],
							'template_id' => $template_id,
							'vars' => array(
								'services' => $service,
								'users' => $user_row,
							) ,
						));
					} else {
						return '!! No unsuspend function for module ' . $service['module'] . ' !!! (service ID "' . $service['id'] . '")';
					}
				break;
			}
			$billic->module($service['module']);
			if (method_exists($billic->modules[$service['module']], 'invoices_hook_paid')) {
				// invoices_hook_paid
				$array = array(
					'invoice' => $invoice,
					'service' => $service,
				);
				$return = $billic->module_call_functions('invoices_hook_paid', array(
					$array
				));
				if ($return !== true) {
					// send an email as a notification to the admin
					$billic->email(get_config('billic_companyemail') , 'RemoteBillicService: Failed to auto renew service', 'Billic failed to pay the invoice at the remote Billic! You may need to pay the invoice manually.<br>Local Service ID: ' . $array['service']['id'] . '<br>Error Message: ' . $return);
					return $return;
				}
			}
		}
		return true;
	}
	function get_last_invoice_num() {
		global $billic, $db;
		return (int) $db->q('SELECT `num` FROM `invoices` ORDER BY `num` DESC LIMIT 1')[0]['num'];
	}
	function cron() {
		global $billic, $db;
		// assign invoice numbers to paid invoices
		foreach($db->q('SELECT `id` FROM `invoices` WHERE `num` is NULL AND `status` = "Paid" ORDER BY `datepaid`, `id`') as $invoice) {
			$next_num = ($this->get_last_invoice_num()+1);
			$db->q('UPDATE `invoices` SET `num` = ? WHERE `id` = ?', $next_num, $invoice['id']);
		}
		
		// first day of the month
		if (date('d') == 1 && get_config('Invoices_SummaryCooldown') < time() - 172800) {
			set_config('Invoices_SummaryCooldown', time());
			$month = date('n');
			$year = date('Y');
			if ($month == 1) {
				$year = ($year - 1);
				$month = 12;
			} else {
				$month = ($month - 1);
			}
			$month_start = mktime(0, 0, 0, $month, 1, $year);
			$users = $db->q('SELECT * FROM `users` WHERE `monthly_summary` = 1');
			foreach ($users as $user) {
				$services_count = $db->q('SELECT COUNT(*) FROM `services` WHERE `regdate` > ? AND `userid` = ?', $month_start, $user['id']);
				$services_count = $services_count[0]['COUNT(*)'];
				$invoices = $db->q('SELECT COUNT(*), SUM(`subtotal`), SUM(`credit`), SUM(`tax`) FROM `invoices` WHERE `datepaid` > ? AND `status` = ? AND `userid` = ?', $month_start, 'Paid', $user['id']);
				$invoices_count = $invoices[0]['COUNT(*)'];
				$paid_total = ($invoices[0]['SUM(`subtotal`)'] - $invoices[0]['SUM(`credit`)']);
				$billic->email($user['email'], 'Last Month\'s Summary', 'Dear ' . $user['firstname'] . ',<br>Here is the report for last month.<br><br>Number of new services: ' . $services_count . '<br>Number of paid invoices: ' . $invoices_count . '<br>Sum of paid invoices: ' . $paid_total . '<br>Sum of paid tax: ' . $invoices[0]['SUM(`tax`)'] . '<br><br>Regards,<br>' . get_config('billic_companyname'));
				$billic->email(get_config('billic_companyemail') , 'Last Month\'s Summary for #' . $user['id'] . ' ' . $user['first_name'] . ' ' . $user['last_name'], 'Here is the report for last month.<br><br>Number of new services: ' . $services_count . '<br>Number of paid invoices: ' . $invoices_count . '<br>Sum of paid invoices: ' . $paid_total . '<br>Sum of paid tax: ' . $invoices[0]['SUM(`tax`)'] . '<br><br>Regards,<br>' . get_config('billic_companyname'));
			}
		}
	}
	function exportdata_submodule() {
		global $billic, $db;
		if (empty($_POST['date_start']) || empty($_POST['date_end'])) {
			echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.0/css/bootstrap-datepicker.min.css">';
			echo '<script>addLoadEvent(function() { $.getScript( "https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.0/js/bootstrap-datepicker.min.js", function( data, textStatus, jqxhr ) { $( "#date_start" ).datepicker({ format: "yyyy-mm-dd" }); $( "#date_end" ).datepicker({ format: "yyyy-mm-dd" }); }); });</script>';
			echo '<form method="POST">';
			echo '<table class="table table-striped" style="width: 300px;"><tr><th colspan="2">Select date range</th></tr>';
			echo '<tr><td>From</td><td><input type="text" class="form-control" name="date_start" id="date_start" value="' . date('Y') . '-01-01"></td></tr>';
			echo '<tr><td>To</td><td><input type="text" class="form-control" name="date_end" id="date_end" value="' . date('Y') . '-12-' . date('t', mktime(0, 0, 0, 12, 1, date('Y'))) . '"></td></tr>';
			echo '<tr><td colspan="2" align="right"><input type="submit" class="btn btn-default" name="generate" value="Generate &raquo"></td></tr>';
			echo '</table>';
			echo '</form>';
			return;
		}
		$date = date_create_from_format('Y-m-d', $_POST['date_start']);
		$date->setTime(0, 0, 0);
		$date_start = $date->getTimestamp();
		$date = date_create_from_format('Y-m-d', $_POST['date_end']);
		$date->setTime(0, 0, 0);
		$date_end = ($date->getTimestamp() + 86399);
		ob_end_clean();
		ob_start();
		/*
		              $cols = $db->q('SHOW COLUMNS FROM `invoices`');
		              $cols_txt = '';
		              foreach($cols as $col) {
		                  $cols_txt .= $col['Field'].',';
		              }
		              $cols_txt = substr($cols_txt, 0, -1);
		              $cols_txt .= ',Service ID'; # (relid in `invoiceitems`)
		              $cols_txt .= ',Remote Service ID';
		              echo $cols_txt."\r\n";
		*/
		echo "Invoice ID,User ID,Date,Subtotal,Credit,Tax,Total,Tax Rate,Status,Country,VAT Number,Description,Service ID,Remote Service ID\r\n";
		$invoices = $db->q('SELECT `id`, `userid`, `date`, `subtotal`, `credit`, `tax`, `total`, `taxrate`, `status` FROM `invoices` WHERE `date` >= ? AND `date` <= ? ORDER BY `id` ASC', $date_start, $date_end);
		foreach ($invoices as $invoice) {
			$invoice['date'] = date('Y-m-d', $invoice['date']);
			//$invoice['duedate'] = date('Y-m-d', $invoice['duedate']);
			//$invoice['datepaid'] = date('Y-m-d', $invoice['datepaid']);
			//$invoice['tmp'] = base64_encode($invoice['tmp']);
			// User Info
			$user = $db->q('SELECT `country`, `vatnumber` FROM `users` WHERE `id` = ?', $invoice['userid']);
			$user = $user[0];
			$invoice[] = $user['country'];
			$invoice[] = $user['vatnumber'];
			// Description
			$descs = $db->q('SELECT `description` FROM `invoiceitems` WHERE `invoiceid` = ?', $invoice['id']);
			$desc = '';
			foreach ($descs as $d) {
				$desc.= $d['description'] . ' | ';
			}
			$desc = substr($desc, 0, -3);
			$invoice[] = str_replace(',', '.', $desc);
			// Service ID
			$relid = $db->q('SELECT `relid` FROM `invoiceitems` WHERE `invoiceid` = ?', $invoice['id']);
			$relid = $relid[0]['relid'];
			$invoice[] = $relid;
			// Remote Service ID
			if (empty($relid)) {
				$invoice[] = 'N/A';
			} else {
				$import_data = $db->q('SELECT `import_data` FROM `services` WHERE `id` = ?', $relid);
				$import_data = $import_data[0]['import_data'];
				if (empty($import_data)) {
					$invoice[] = 'N/A';
				} else {
					$import_data = json_decode($import_data, true);
					$invoice[] = $import_data['serviceid'];
				}
			}
			echo str_replace("\n", '', str_replace("\r", '', implode(',', $invoice))) . "\r\n";
		}
		define('DISABLE_FOOTER', true);
		$output = ob_get_contents();
		ob_end_clean();
		header('Content-Disposition: attachment; filename=exported-' . strtolower($_GET['Module']) . '-' . time() . '.csv');
		header('Content-Type: application/force-download');
		header('Content-Type: application/octet-stream');
		header('Content-Type: application/download');
		header('Content-Length: ' . strlen($output));
		echo $output;
		exit;
	}
	function users_submodule($array) {
		global $billic, $db;
		echo '<table class="table table-striped"><tr><th>Invoice&nbsp;#</th><th>Due Date</th><th>Subtotal</th><th>Credit</th><th>Tax</th><th>Total</th><th>Status</th><th>Service ID</th></tr>';
		$invoices = $db->q('SELECT * FROM `invoices` WHERE `userid` = ? ORDER BY `id` DESC', $array['user']['id']);
		if (empty($invoices)) {
			echo '<tr><td colspan="20">User has no invoices</td></tr>';
		}
		foreach ($invoices as $invoice) {
			echo '<tr><td><a href="/Admin/Invoices/ID/' . $invoice['id'] . '/">' . $this->name_short($invoice) . '</a></td><td>' . $billic->date_display($invoice['duedate']) . '</td>' . '<td>' . get_config('billic_currency_prefix') . $invoice['subtotal'] . get_config('billic_currency_suffix') . '</td>' . '<td>' . get_config('billic_currency_prefix') . $invoice['credit'] . get_config('billic_currency_suffix') . '</td>' . '<td>' . get_config('billic_currency_prefix') . $invoice['tax'] . get_config('billic_currency_suffix') . '</td>' . '<td>' . get_config('billic_currency_prefix') . $invoice['total'] . get_config('billic_currency_suffix') . '</td>' . '<td>';
			switch ($invoice['status']) {
				case 'Paid':
					$label = 'success';
				break;
				case 'Unpaid':
					$label = 'danger';
				break;
				case 'Cancelled':
				default:
					$label = 'default';
				break;
			}
			echo '<span class="label label-' . $label . '">' . $invoice['status'] . '</span>';
			echo '</td><td>';
			$items = $db->q('SELECT `relid` FROM `invoiceitems` WHERE `invoiceid` = ?', $invoice['id']);
			$items = array_unique($items);
			foreach ($items as $item) {
				echo $item['relid'] . '<br>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}
	var $currentY;
	function generate_pdf($invoice) {
		global $billic, $db;
		set_include_path(get_include_path() . PATH_SEPARATOR . 'Modules/Core/fpdf');
		$billic->disable_content();
		$pdf = new Invoices_PDF_Invoice('P', 'mm', 'A4');
		$pdf->AddPage();
		$company_name = get_config('billic_companyname');
		if (empty($company_name)) {
			$company_name = ' ';
		}
		$company_address = get_config('billic_companyaddress');
		if (empty($company_address)) {
			$company_address = ' ';
		}
		$this->currentY = 10;
		$this->watermarkPosX = 120;
		$this->watermarkPosY = 220;
		$this->watermarkRotate = 30;
		
		$logo = 'i/invoice_logo.png';
		if (file_exists($logo)) {
			$pdf->SetXY(10, $this->currentY);
			$size = getimagesize($logo);
			if($size!==false) {
				$wImg = $size[0];
				$hImg = $size[1];
				$pdf->Image($logo);
				$this->currentY += ($hImg/$pdf->k);
				$this->watermarkRotate = 0;
				$this->watermarkPosX = 10 + ($wImg/$pdf->k) + ($pdf->w / 2);
				$this->watermarkPosY = 10 + ($hImg/$pdf->k/2);
			}
		}
		
		// Invoice Number
		if (empty($invoice['num']))
                        $text = 'Proforma Invoice';
                else
                        $text = $this->name($invoice);
		$pdf->SetFont("Helvetica", "B", 16);
		$pdf->SetXY(10, $this->currentY);
		$pdf->Cell($pdf->GetStringWidth($text), 5, $text);
		
		// Invoice Date
		$text = date('jS F Y', $invoice['date']);
		$pdf->SetFont("Helvetica", "", 12);
		$pdf->SetXY(10, $this->currentY+5);
		$pdf->Cell($pdf->GetStringWidth($text), 5, $text);
		
		// Company Details
		$pdf->SetFont('Helvetica', 'B', 12);
		$length_name = $pdf->GetStringWidth($company_name);
		$pdf->SetFont('Helvetica', '', 12);
		$length_address = $pdf->GetStringWidth($company_address);
		$height_company_address = $pdf->GetStringHeight($company_name, 5);
		$max_length = ($length_name>$length_address?$length_name:$length_address);

		$x1 = 68 - $max_length;
		$y1 = $this->currentY;

		$pdf->SetFont('Helvetica', 'B', 12);
		$pdf->SetXY($x1, $y1);
		$pdf->Cell($length_name, 2, $company_name);
		$pdf->SetXY($x1, $y1 + 4);
		$pdf->SetFont('Helvetica', '', 12);
		$pdf->MultiCell($length_address, 5, $company_address);
		
		// Watermark
		$text = $invoice['status'];
		$fontsize = 50;
		$pdf->SetFont('Helvetica', 'B', $fontsize);
		$pdf->SetTextColor(203, 203, 203);
		if ($this->watermarkRotate>0)
			$pdf->Rotate($this->watermarkRotate, 55, 190);
		$this->watermarkPosX -= ($pdf->GetStringWidth($text)*2);
		//$this->watermarkPosY += $fontsize;
		$pdf->Text($this->watermarkPosX, $this->watermarkPosY, $text);
		$pdf->Rotate(0);
		$pdf->SetTextColor(0, 0, 0);
		
		$pdf->SetFont("Helvetica", "", 12);
		$amount_title = 'Amount (' . get_config('billic_currency_code') . ')';
		
		// Client address
		$text = 'Invoiced To:' . PHP_EOL . $this->user_address($billic->user);
		$r1 = $pdf->w - 70;
		$r2 = $r1 + 68;
		$y1 = $this->currentY;
		$pdf->SetXY($r1, $y1);
		$pdf->MultiCell(60, 5, $text);
		$height_client_address = $pdf->GetStringHeight($text, 5);
		
		$max_height = ($height_company_address>$height_client_address?$height_company_address:$height_client_address);
		$this->currentY += $max_height + 20;
		
		$cols = array(
			"Service ID" => 23,
			"Description" => 117,
			$amount_title => 30,
			"Tax Rate" => 20
		);
		$pdf->addCols($cols, $this->currentY);
		$cols = array(
			"Service ID" => "L",
			"Description" => "L",
			$amount_title => "C",
			"Tax Rate" => "C"
		);
		$pdf->addLineFormat($cols);
		$this->currentY += 10;
		$tax_groups = array();
		$subtotal = 0;
		$total = 0;
		//$currency = get_config('billic_currency_prefix').'%01.2f'.get_config('billic_currency_suffix');
		//$currency = preg_replace_callback("/(&[0-9a-z]+;)/", function($m) { return mb_convert_encoding($m[1], 'Windows-1252', "HTML-ENTITIES"); }, $currency);
		//$currency = str_replace(chr(226), chr(128), $currency); // Fix Euro Sign
		
		$y = $this->currentY;
		$invoiceitems = $db->q('SELECT `relid`, `description`, `amount` FROM `invoiceitems` WHERE `invoiceid` = ?', $invoice['id']);
		foreach ($invoiceitems as $invoiceitem) {
			$line = array();
			$line['Service ID'] = $invoiceitem['relid'];
			if ($line['Service ID'] == 0) {
				$line['Service ID'] = 'N/A';
			}
			$line['Description'] = implode("\n (", explode(' (', $invoiceitem['description']));
			$line[$amount_title] = $invoiceitem['amount'];
			$subtotal+= $invoiceitem['amount'];
			// TODO: Change tax to column in invoiceitems
			$invoiceitem['taxgroup'] = $invoice['taxrate'];
			if ($invoiceitem['taxgroup'] === NULL) {
				$line['Tax Rate'] = 'N/A';
				$total+= $invoiceitem['amount'];
			} else {
				$tax = round(($invoiceitem['amount'] / 100) * $invoiceitem['taxgroup'], 2);
				if (!array_key_exists($invoiceitem['taxgroup'], $tax_groups)) {
					$tax_groups[$invoiceitem['taxgroup']] = 0;
				}
				$tax_groups[$invoiceitem['taxgroup']]+= $tax;
				$total+= ($invoiceitem['amount'] + $tax);
				$line['Tax Rate'] = round($invoiceitem['taxgroup']) . '%';
			}
			$size = $pdf->addLine($y, $line);
			$y+= $size + 2;
			// Draw line
			$r1 = 10;
			$r2 = $pdf->w - ($r1 * 2);
			$pdf->SetDrawColor(200);
			$pdf->Line($r1, $y, $r1 + $r2, $y);
			$pdf->SetDrawColor(0);
			$y+= 3;
		}
		if (!empty($tax_groups)) {
			$line = array();
			$line['Service ID'] = ' ';
			$line['Description'] = str_repeat(' ', 80) . 'Subtotal:';
			$line[$amount_title] = $subtotal;
			$line['Tax Rate'] = ' ';
			$size = $pdf->addLine($y, $line);
			$y+= $size + 2;
			// Draw line
			$r1 = 10;
			$r2 = $pdf->w - ($r1 * 2);
			$pdf->SetDrawColor(200);
			$pdf->Line($r1, $y, $r1 + $r2, $y);
			$pdf->SetDrawColor(0);
			$y+= 3;
			foreach ($tax_groups as $rate => $value) {
				$line = array();
				$line['Service ID'] = ' ';
				$line['Description'] = str_repeat(' ', 87) . 'Tax:';
				$line[$amount_title] = $value;
				$line['Tax Rate'] = round($rate, 2) . '%';
				$size = $pdf->addLine($y, $line);
				$y+= $size + 2;
				// Draw line
				$r1 = 10;
				$r2 = $pdf->w - ($r1 * 2);
				$pdf->SetDrawColor(200);
				$pdf->Line($r1, $y, $r1 + $r2, $y);
				$pdf->SetDrawColor(0);
				$y+= 3;
			}
		}
		$line = array();
		$line['Service ID'] = ' ';
		$line['Description'] = str_repeat(' ', 85) . 'Total:';
		$line[$amount_title] = number_format($total, 2);
		$line['Tax Rate'] = ' ';
		$size = $pdf->addLine($y, $line);
		$y+= $size + 2;
		$pdf->Output();
		exit;
	}
}
/*
	BEGIN Functions for PDF
*/
if (!class_exists('Invoices_PDF_Invoice')) {
	require_once ('Modules/Core/fpdf.php');
	// Based on original code (Version 1.02) from Xavier Nicolay in 2004
	// http://www.fpdf.org/en/script/script20.php
	class Invoices_PDF_Invoice extends FPDF {
		// private variables
		var $colonnes;
		var $format;
		var $angle = 0;
		function Rotate($angle, $x = - 1, $y = - 1) {
			if ($x == - 1) $x = $this->x;
			if ($y == - 1) $y = $this->y;
			if ($this->angle != 0) $this->_out('Q');
			$this->angle = $angle;
			if ($angle != 0) {
				$angle*= M_PI / 180;
				$c = cos($angle);
				$s = sin($angle);
				$cx = $x * $this->k;
				$cy = ($this->h - $y) * $this->k;
				$this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
			}
		}
		// public functions
		function sizeOfText($texte, $largeur) {
			$index = 0;
			$nb_lines = 0;
			$loop = TRUE;
			while ($loop) {
				$pos = strpos($texte, "\n");
				if (!$pos) {
					$loop = FALSE;
					$ligne = $texte;
				} else {
					$ligne = substr($texte, $index, $pos);
					$texte = substr($texte, $pos + 1);
				}
				$length = floor($this->GetStringWidth($ligne));
				$res = 1 + floor($length / $largeur);
				$nb_lines+= $res;
			}
			return $nb_lines;
		}
		public function GetStringHeight($txt, $lineHeight = 5) {
			$lines = substr_count($txt, "\n");
			$height = ($lines * $this->FontSize * ($lineHeight/3.25));
			return $height;
		}
		function addCols($tab, &$currentY) {
			global $colonnes;
			$r1 = 10;
			$r2 = $this->w - ($r1 * 2);
			$y1 = $currentY;
			$y2 = $this->h - 10 - $y1;
			$this->SetXY($r1, $y1);
			$this->Rect($r1, $y1, $r2, $y2, "D");
			$this->Line($r1, $y1 + 6, $r1 + $r2, $y1 + 6);
			$colX = $r1;
			$colonnes = $tab;
			while (list($lib, $pos) = each($tab)) {
				$this->SetXY($colX, $y1 + 3);
				$this->Cell($pos, 1, $lib, 0, 0, "C");
				$colX+= $pos;
				$this->Line($colX, $y1, $colX, $y1 + $y2);
			}
		}
		function addLineFormat($tab) {
			global $format, $colonnes;
			while (list($lib, $pos) = each($colonnes)) {
				if (isset($tab["$lib"])) $format[$lib] = $tab["$lib"];
			}
		}
		function lineVert($tab) {
			global $colonnes;
			reset($colonnes);
			$maxSize = 0;
			while (list($lib, $pos) = each($colonnes)) {
				$texte = $tab[$lib];
				$longCell = $pos - 2;
				$size = $this->sizeOfText($texte, $longCell);
				if ($size > $maxSize) $maxSize = $size;
			}
			return $maxSize;
		}
		function addLine($ligne, $tab) {
			global $colonnes, $format;
			$ordonnee = 10;
			$maxSize = $ligne;
			reset($colonnes);
			while (list($lib, $pos) = each($colonnes)) {
				$longCell = $pos - 2;
				$texte = $tab[$lib];
				$length = $this->GetStringWidth($texte);
				$tailleTexte = $this->sizeOfText($texte, $length);
				$formText = $format[$lib];
				$this->SetXY($ordonnee, $ligne - 1);
				$this->MultiCell($longCell, 4, $texte, 0, $formText);
				if ($maxSize < ($this->GetY())) $maxSize = $this->GetY();
				$ordonnee+= $pos;
			}
			return ($maxSize - $ligne);
		}
	}
}
/*
	END Functions for PDF
*/
