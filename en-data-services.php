<?php
/**
 * @package Grassriots_EN_SDK
 */
/*

*/
define('ENCLIENT_FOLDER', dirname( __FILE__ ));
ini_set("auto_detect_line_endings", "1");

if(!class_exists('ENClient')) {
	class ENClient {

		protected $private_token;
		protected $public_token;

		protected $client_id;
		protected $form_id = array();

		protected $format_name = "Import Email";
		protected $format = "Email address";
		protected $first_row = "info@grassriots.com";
		protected $segment_name = "Duplicate Segment";
		protected $segment_id = "Duplicate Segment";
		protected $path_to_file;
		protected $import_file = "en_import.csv";

		public static $en_host = "https://e-activist.com";
		public static $services = array(
			"import" => array(
				"endpoint" => "/ea-dataservice/import.service",
				"method" => "POST",
				"encoding" => "multipart/form-data"
			),
			"action" => array(
				"endpoint" => "/ea-action/action",
				"method" => "GET",
				"encoding" => ""
			),
			"data" => array(
				"endpoint" => "/ea-dataservice/data.service",
				"method" => "GET",
				"encoding" => ""
			)
		);

		protected $errors = array();
		
		function __construct($options) {
			$this->path_to_file = ENCLIENT_FOLDER."/";

			$this->set_options($options);

			if(!isset($this->client_id)) {
				error_log("[ENClient] Client ID not set");
				return false;
			}
			if(!isset($this->private_token)) {
				error_log("[ENClient] private token not set");
				return false;
			}
			if(!isset($this->public_token)) {
				error_log("[ENClient] public token not set");
				return false;
			}

		}

		function has_error() {
			return (bool)count($this->errors);
		}

		function get_errors() {
			return $this->errors;
		}

		function clear_errors() {
			$this->errors = array();
		}

		function set_options($options) {
			foreach($options as $option => $value) {
				$this->{$option} = $value;
			}
		}

		function duplicate_campaign($campaign_id, $campaign_name) {

			if(!file_exists($this->path_to_file.$this->import_file)) {
				if(false === ($f = fopen($this->path_to_file.$this->import_file, 'w'))) {
					$this->errors[] = "duplicate_campaign - Unable to create import file";
					return false;
				}

				if(false === fputcsv($f, explode(',', $this->format))) {
					$this->errors[] = "duplicate_campaign - Unable to write to import file";
					return false;
				}
				else
					fputcsv($f, explode(',', $this->first_row));
				fclose($f);
			}

			if(class_exists('CurlFile'))
				$upload = new CurlFile($this->path_to_file.$this->import_file, 'text/csv', $this->import_file);
			else
				$upload = '@'.$this->path_to_file.$this->import_file;

			$params = array(
				"token" => $this->private_token,
				"name" => $this->getRandomString(),
				"upload" => $upload,
				"formatName" => $this->format_name,
				"segmentName" => $this->segment_name,
				"segmentId" => $this->segment_id,
				"campaignId" => $campaign_id,
				"campaignName" => $campaign_name
			);
			
			$response = $this->curl_en('import', $params);
			if(strpos($response, 'Error') === 0) {
				if(false !== ($new_campaign_id = $this->get_campaign_by_name($campaign_name)) )
					return $new_campaign_id;
				$this->errors[] = "duplicate_campaign - API error duplicating campaign: ".$response;
				return false;
			}

			$attempt = 0;
			while(false === ($new_campaign_id = $this->get_campaign_by_name($campaign_name)) && $attempt < 10) {
				sleep(3);
				$attempt++;
			}

			if(false === $new_campaign_id) {
				$this->errors[] = "duplicate_campaign - duplicate campaign not found";
				return false;
			}

			return $new_campaign_id;

		}

		function is_existing_user($email) {
			$params = array(
				'token' => $this->public_token,
				'service' => 'SupporterData',
				'email' => strtolower($email),
				'cachebust' => $this->getRandomString()
			);

			$response = simplexml_load_string($this->curl_en('data', $params));
			foreach($response->EaRow as $user) {
				$id = null;
				$name = null;
				foreach($user as $field) {
					if((string)$field['name'] == 'supporterExists') {
						$exists = (string)$field;
						return ($exists === "Y");
					}
				}
			}


		}

		function get_campaign_by_name($campaign_name) {
			$campaigns = $this->get_campaigns(true);

			return array_search($campaign_name, $campaigns);
		}

		function get_campaigns($cachebust = false) {
			$params = array(
				'token' => $this->public_token,
				'service' => 'EaCampaignInfo'
			);

			if($cachebust)
				$params['cachebust'] = $this->getRandomString();

			$response = simplexml_load_string($this->curl_en('data', $params));
			$campaigns = array();
			foreach($response->EaRow as $campaign) {
				$id = null;
				$name = null;
				foreach($campaign as $data) {
					if((string)$data['name'] == 'campaignId')
						$id = (string)$data;
					if((string)$data['name'] == 'campaignName')
						$name = (string)$data;
				}
				if($id && $name)
					$campaigns[$id] = $name;
			}

			return $campaigns;
		}

		function get_campaign_fields($campaign_id) {
			$fields = false;
			$params = array(
				"ea.client.id" => $this->client_id,
				"ea.campaign.id" => $campaign_id,
				"ea.campaign.mode" => "DEMO",
				"format" => "json"
			);

			$response = json_decode($this->curl_en('action', $params), true);

			if(isset($response['pages'][0]['form']['fields']) && is_array($response['pages'][0]['form']['fields'])) {
				$fields = array();
				foreach($response['pages'][0]['form']['fields'] as $key => $field) {
					if(!in_array($field['type'], array("label",""))) {
						$fields[] = $field['name'];
					}
				}
			}
			return $fields;
		}

		function send_campaign_action($campaign_id, $actiontaker, $cachebust = true) {

			if(!isset($this->form_id[$campaign_id])) {

				$response = json_decode($this->curl_en('action', array(
					"ea.client.id" => $this->client_id,
					"ea.campaign.id" => $campaign_id,
					"format" => "json",
					"ea.campaign.mode" => "DEMO"
				)), true);
				$this->form_id[$campaign_id] = $response['pages'][0]['form']['formId'];
			}
			
			$params = array(
				"ea.client.id" => $this->client_id,
				"ea.form.id" => $this->form_id[$campaign_id],
				"ea.campaign.mode" => "DEMO",
				"ea.AJAX.submit" => "true",
				"ea_requested_action" => "ea_submit_user_form",
				"ea.submitted.page" => "1",
				"ea.retain.account.session.error" => "true",
				"ea.clear.campaign.session.id" => "true",
				"ea.campaign.id" => $campaign_id,
				"format" => "json"
			);

			if($cachebust)
				$params['cachebust'] = $this->getRandomString();

			$params = array_merge($params, $actiontaker);

			//error_log(print_r($params,true));

			$response = json_decode($this->curl_en('action', $params), true);

			if($response == null) {
				$this->errors[] = "JSON not returned";
				return false;
			}

			if(count($response['messages']) > 0) {
				foreach($response['messages'] as $message) {
					$this->errors[] = $message['error'];
				}
				return false;
			}
			return true;
		}

		function getRandomString() {
			return substr(base_convert(strval(rand()*10000000000), 10, 36),2);
		}


		function curl_en($service, $params) {
			
			$url = self::$en_host.self::$services[$service]["endpoint"];

			//get the file
			$options = array(
				CURLOPT_RETURNTRANSFER => true, // return web page
				CURLOPT_HEADER => false, // don't return headers
				CURLOPT_FOLLOWLOCATION => true, // follow redirects
				CURLOPT_ENCODING => "", // handle all encodings
				CURLOPT_AUTOREFERER => true, // set referer on redirect
				CURLOPT_CONNECTTIMEOUT => 20, // timeout on connect
				CURLOPT_TIMEOUT => 30, // timeout on response
				CURLOPT_MAXREDIRS => 3, // stop after 3 redirects
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_POST => false
			);

			if(self::$services[$service]["method"] == "POST") {
				//$options[CURLOPT_POST] = "";
				$options[CURLOPT_HTTPHEADER] = array(
					"Expect:"
				);
				$options[CURLOPT_POST] = 1;
				$options[CURLOPT_POSTFIELDS] = $params;
			}
			else {
				$param_list=array();
				foreach($params as $field=>$value) {
					$param_list[] = rawurlencode($field) ."=". rawurlencode($value);
				}
				$query_string = implode("&", $param_list);

				$url .= "?".$query_string;
			}

			$ch = curl_init();
		
			$options[CURLOPT_URL] = $url; //."?".$query_string;  
			curl_setopt_array($ch, $options);
		
			$response = curl_exec($ch);
			$err = curl_errno($ch);
			$errmsg = curl_error($ch);

			curl_close($ch);
		
			if ($errmsg != '' || $err != '') {
				error_log("[ENClient] Send Error - CURL Failure: ".$err." - ".$errmsg);
				echo "curl error";
			}

			return $response;
		}


	}

}