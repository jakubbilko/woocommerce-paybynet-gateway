<?php
/*
 * PayByNet Woocommerce Payment Gateway
 *
 * @author Jakub Bilko
 *
 * Plugin Name: PayByNet Woocommerce Payment Gateway
 * Plugin URI: http://www.jakubbilko.pl
 * Description: Brama płatności PayByNet do WooCommerce.
 * Author: Jakub Bilko
 * Author URI: http://www.jakubbilko.pl
 * Version: 1.0
*/

// load the plugin
add_action('plugins_loaded', 'init_paybynet_gateway');

function init_paybynet_gateway() {
	
	class WC_Gateway_PayByNet extends WC_Payment_Gateway {
		
		function __construct() {
			
			global $woocommerce;
			
			$this->id = __('paybynet', 'woocommerce');
			$this->has_fields = true;
			$this->method_title = __('Paybynet', 'woocommerce');
			$this->notify_link = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_PayByNet', home_url('/')));
			$this->icon = apply_filters('woocommerce_paybynet_icon', plugins_url('assets/pbn_logo.png', __FILE__));

			$this->form_fields();
			$this->init_settings();
			
			$this->title = $this->get_option('title');
	        $this->description = $this->get_option('description');
	        $this->seller_id = $this->get_option('seller_id');
	        $this->password = $this->get_option('password');
	        $this->account = $this->get_option('seller_account');
	        $this->sname = $this->get_option('seller_name');
	        $this->post = $this->get_option('seller_post');
	        $this->city = $this->get_option('seller_city');
	        $this->street = $this->get_option('seller_street');
	        $this->country = $this->get_option('seller_country');
	        $this->test = $this->get_option('test');
	        $this->banklist = $this->get_option('bank_list');
	        $this->post_status = $this->get_option('post_status');
	        
	        // actions, hooks and filters
	        
	        add_filter('woocommerce_payment_gateways', array($this, 'add_paybynet_gateway'));
			
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			
			add_filter('payment_fields', array($this, 'payment_fields'));
			
			add_action('woocommerce_api_wc_gateway_paybynet', array($this, 'gateway_communication'));
			
			add_action('wp_enqueue_scripts', array($this, 'pbn_styles'));
			
		}
		
		function pbn_styles() {
				wp_enqueue_style('pbn', plugins_url('assets/pbn.css', __FILE__));
		}
		
		function gateway_communication() {
			if(isset($_GET['order_id'])) {
				$this->send_payment($_GET['order_id']);
			} else if(isset($_GET['oid'])) {
				$this->complete_payment($_GET['oid'], $_GET['status']);
			} else if(isset($_GET['redirect'])) {
				$order = new WC_Order($_GET['id']);
				wp_redirect( $this->get_return_url( $order ) );
			} else if(isset($_POST['newStatus'])) {
				$order_id = ltrim($_POST['paymentId'], '0');
				$this->complete_payment($order_id, $_POST['newStatus']);
				die('OK');
			}
			exit();
		}
		
		function complete_payment($order_id, $status) {
			$order = new WC_Order($order_id);
			if($status == 'success' || $status == '2203' || $status == '2303') {
				$order->update_status('processing', __('Zapłacono.'));
				wp_redirect( $this->get_return_url( $order ) );
				exit();
			} else {
				$order->update_status('failed', __('Błąd podczas zapłaty.'));
				wp_redirect( $this->get_return_url( $order ) );
				exit();
			}
			
		}
		
		function send_payment($order_id) {
			
			global $wp;
			$order = new WC_Order($order_id);
			
			$url = $this->test == 'yes' ? 'https://pbn.paybynet.com.pl/PayByNetT/trans.do' : 'https://pbn.paybynet.com.pl/PayByNet/trans.do';
			
			$data_template = "<id_client>%s</id_client><id_trans>%s</id_trans><date_valid>%s</date_valid><amount>%s</amount><currency>PLN</currency><email>%s</email><account>%s</account><accname>%s^NM^%s^ZP^%s^CI^%s^ST^%s^CT^</accname><backpage>%s</backpage><backpagereject>%s</backpagereject>";
			
			$success_url = $this->notify_link . ($this->post_status == 'yes' ? '&status=success&redirect=true&id='. $order_id : '&status=success&oid=' . $order_id );
			$fail_url = $this->notify_link . ($this->post_status == 'yes' ? '&status=fail&redirect=true&id='. $order_id : '&status=fail&oid=' . $order_id );
			
			$date = new DateTime('now');
			$date->modify('+1 day');

			$data = sprintf($data_template, $this->seller_id, str_pad($order_id, 10, "0", STR_PAD_LEFT), $date->format('d-m-Y H:i:s'), $order->get_total(), $order->billing_email, $this->account, $this->sname, $this->post, $this->city, $this->street, $this->country, $success_url, $fail_url);
			
			$password = '<password>' . $this->password . '</password>';
			$hash = '<hash>' . sha1($data . $password) . '</hash>';
			$data .= $hash;
			
			//echo $data;
			
			$data_encoded = base64_encode($data);
			if(isset($_GET['bank'])) $bank = $_GET['bank'];
			
			
			$form = "<form action='$url' method='post' id='pbn_form'>";
			$form .= "<input type='hidden' name='hashtrans' value='$data_encoded' />";
			isset($bank) ? $form .= "<input type='hidden' name='idbank' value='$bank' />" : '';
			$form .= "</form><script type='text/javascript'>document.getElementById('pbn_form').submit();</script>";
			
			echo $form;			
		}
		
		function process_payment($order_id) {
			
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$order->update_status('on-hold', __( 'Oczekiwanie na płatność Paybynet', 'woocommerce' ));
			$order->reduce_order_stock();
			$woocommerce->cart->empty_cart();
			return array(
             'result' => 'success',
             'redirect' => add_query_arg(array('order_id' => $order_id, 'bank' => $_POST['bank']), $this->notify_link)
			);
		}
		
		function payment_fields() {
			
			echo "<p>{$this->description}</p>";
			
			if($this->banklist == 'yes') {
				$banks_url = $this->test == 'yes' ? 'https://pbn.paybynet.com.pl/PayByNetT/update/os/' : 'https://pbn.paybynet.com.pl/PayByNet/update/os/';
				$banks = file_get_contents($banks_url . 'banks.xml');
				$banksdata = simplexml_load_string($banks);
				$index = 0;
				echo "<div id='PBN_bank_select'>";
					foreach($banksdata as $bank) {
						echo "<div class='bank'><input type='radio' name='bank' id='{$bank->id}' value='{$bank->id}'" . ($index == 0 ? "checked" : "") . " /><label for='{$bank->id}'><img alt='{$bank->name}' src='" . $banks_url . $bank->image . "' /></label></div>";
						$index++;
					}
				echo "</div>";
			}
				
		}
		
		// add gateway
		
		function add_paybynet_gateway($methods) {
        	$methods[] = 'WC_Gateway_PayByNet';
        	return $methods;
      	}
		
		// settings fields
		
		function form_fields() {
			
			$this->form_fields = array(
				'enabled' => array(
	                 'title' => __('Włącz/Wyłącz', 'woocommerce'),
	                 'type' => 'checkbox',
	                 'label' => __('Włącz bramkę płatności Paybynet.', 'woocommerce'),
	                 'default' => 'yes'
				 ),
				 'test' => array(
	                 'title' => __('Tryb testowy', 'woocommerce'),
	                 'type' => 'checkbox',
	                 'label' => __('Włącz tryb testowy.', 'woocommerce'),
	                 'default' => 'yes'
				 ),
				 'title' => array(
	                 'title' => __('Nazwa', 'woocommerce'),
	                 'type' => 'text',
	                 'default' => __('Paybynet', 'woocommerce'),
	                 'desc_tip' => true,
				 ),
				 'description' => array(
	                 'title' => __('Opis', 'woocommerce'),
	                 'type' => 'textarea',
	                 'description' => __('Opis metody płatności przy tworzeniu zamówienia.', 'woocommerce'),
	                 'default' => __('Zapłać przez Paybynet', 'woocommerce')
				 ),
				 'seller_id' => array(
					 'title' => __('Id sprzedającego', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Id sprzedającego to jego numer NIP.', 'woocommerce'),
					 'default' => __('0', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'seller_account' => array(
					 'title' => __('Numer konta sprzedającego', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Numer konta, na który mają być przekazywane pieniądze.', 'woocommerce'),
					 'default' => __('0', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'seller_name' => array(
					 'title' => __('Nazwa sklepu', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Nazwa twojego sklepu bądź firmy.', 'woocommerce'),
					 'default' => __('sklep', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'seller_post' => array(
					 'title' => __('Kod pocztowy', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Kod pocztowy twojego sklepu lub firmy.', 'woocommerce'),
					 'default' => __('00-000', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'seller_city' => array(
					 'title' => __('Miasto', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Miasto twojego sklepu lub firmy.', 'woocommerce'),
					 'default' => __('Warszawa', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'seller_country' => array(
					 'title' => __('Kraj', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Kraj twojego sklepu lub firmy.', 'woocommerce'),
					 'default' => __('Polska', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'seller_street' => array(
					 'title' => __('Ulica', 'woocommerce'),
					 'type' => 'text',
					 'description' => __('Ulica twojego sklepu lub firmy.', 'woocommerce'),
					 'default' => __('ul. Warszawska 123', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'password' => array(
					 'title' => __('Hasło', 'woocommerce'),
					 'type' => 'password',
					 'description' => __('Twoje hasło.', 'woocommerce'),
					 'default' => __('0', 'woocommerce'),
					 'desc_tip' => true
				 ),
				 'bank_list' => array(
	                 'title' => __('Wybór banków', 'woocommerce'),
	                 'type' => 'checkbox',
	                 'label' => __('Włącz wybór banku w zamówieniu.', 'woocommerce'),
	                 'default' => 'yes'
				 ),
				 'post_status' => array(
	                 'title' => __('Aktualizacja statusów metodą POST', 'woocommerce'),
	                 'type' => 'checkbox',
	                 'label' => __('Włącz aktualizację statusów płatnocci metodą POST.<br/><br/>Adres do wysyłania potwierdzeń przez system:<br/><code>' . home_url('/') . '?wc-api=WC_Gateway_PayByNet</code>', 'woocommerce'),
	                 'default' => 'no'
				 )
			);
			
		}
		
	}
	
	new WC_Gateway_PayByNet();
	
}
	
	
?>