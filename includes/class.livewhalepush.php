<?php

require_once($_LW->INCLUDES_DIR_PATH.'/client/utilities/class.inflector.php');
require_once($_LW->INCLUDES_DIR_PATH.'/client/utilities/class.httpstatuscodes.php');

class LiveWhalePush {

  /* database tables */
  static $_subscription_table = 'livewhale_hubsubscriptions';
  static $_subscription_columns = array(
    'id',
    'created_at',
    'client_id',
    'object',
    'callback_url'
    );
  static $_clients_table = 'livewhale_apiclients';

  /* api settings */
  static $_api_client_id_length = 32;
  static $_api_client_secret_length = 32;

  /* pubsub settings */
  static $_subscription_objects = array(
    'news',
    'events',
    'blurbs'
    );
  static $_subscription_aspects = array(
    'tag' => array(
      'allow' => 'alphanumeric characters plus dash or underscore',
      'transform' => "return preg_replace('~[^a-z\d_\-]+~i', '', \$value);"
      ),
    'group_id' => array(
      'allow' => 'an integer',
      'transform' => "return (int) \$value;"
      ),
    'place_id' => array(
      'allow' => 'an integer',
      'transform' => "return (int) \$value;"
      ),
    'radius' => array(
      'allow' => 'a positive decimal',
      'transform' => "return abs((float) \$value);"
      )
    );
  static $_subscription_public_columns = array(
    'id',
    'object',
    'group_id',
    'place_id',
    'radius',
    'tag',
    'callback_url'
    );

  /* distance calculations */
  static $_subscription_max_radius = 10; // miles
  static $_earth_radius = 3960.0; // miles
  static $_degrees_to_radians = 0.017453292519943; // (pi() / 180)

  /* curl settings */
	protected $_curl_defaults = array(
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CONNECTTIMEOUT_MS => 5000,
		CURLOPT_TIMEOUT_MS => 10000,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false,
		CURLOPT_HEADER => true,
		CURLOPT_RETURNTRANSFER => true
		);

  protected $_watching = FALSE;
  protected $_object_type = "";
  protected $_query = "";
  protected $_before_update = array();
  protected $_delete_ids = array();
  protected $_subscriptions = array();

  /* initialization */

  public function __construct () {
  	global $_LW;
    $this->_object_type = preg_replace('~_(edit|list)$~', '', $_LW->page);
    if ( in_array($this->_object_type, LiveWhalePush::$_subscription_objects) ) $this->_watching = TRUE;
    if ( $this->_watching ) {
      $this->_degrees_to_radians = (pi() / 180.0);
      $this->_query = "SELECT `livewhale_{$this->_object_type}`.*, GROUP_CONCAT(`livewhale_places`.`id`) AS `place_ids`, GROUP_CONCAT(`livewhale_places`.`latitude`) AS `place_latitudes`, GROUP_CONCAT(`livewhale_places`.`longitude`) AS `place_longitudes` FROM `livewhale_{$this->_object_type}` INNER JOIN `livewhale_places2any` ON (`livewhale_{$this->_object_type}`.`id` = `livewhale_places2any`.`id2` AND `livewhale_places2any`.`type` = '{$this->_object_type}') INNER JOIN `livewhale_places` ON `livewhale_places`.`id` = `livewhale_places2any`.`id1` WHERE `livewhale_{$this->_object_type}`.`id`";
      if ( !empty($_GET['d']) ) { // editor delete
        $delete_id = preg_replace('~[^\d]+~', '', $_GET['d']);
        if ( $_GET['d'] == $delete_id ) {
          $this->_delete_ids = array($delete_id);
          $this->_query .= " = {$delete_id};";
        }
      } else if ( !empty($_LW->_POST['dropdown_checked']) && !empty($_LW->_POST['items']) ) { // manager dropdown action
        if ( $_LW->_POST['dropdown_checked'] == "{$this->_object_type}_delete" ) $this->_delete_ids = $_LW->_POST['items'];
        $this->_query .= " IN (" . implode(',', $_LW->_POST['items']) . ");";
      } else if ( !empty($_LW->_POST['item_id']) ) { // editor update
        $this->_query .= " = " . $_LW->_POST['item_id'] . ";";
      } else if ( !empty($_LW->_POST[$_LW->page]) && is_array($_LW->_POST[$_LW->page]) ) { // manager multi-click action
        $this->_query .= " IN (" . implode(',', $_LW->_POST[$_LW->page]) . ");";
      } else { // else create
        unset($this->_query);
        return $this;
      }
      if ( !empty($this->_query) ) {
        $result = $_LW->query($this->_query);
        if ( !empty($result) && $result->num_rows ) while ( $item = $result->fetch_assoc() ) $this->_before_update[$item['id']] = $item;
        return $this;
      }
      $this->_watching = FALSE;
    }
  }

  /* Subscription Handling */
  private function require_valid_api_client ( $args ) {
    global $_LW;
    if ( array_key_exists('client_id', $args) ) $id = preg_replace('~[^a-f\d]~', '', $args['client_id']);
    if ( array_key_exists('client_secret', $args) ) $secret = preg_replace('~[^a-f\d]~', '', $args['client_secret']);
    if ( empty($id) || strlen($id) != LiveWhalePush::$_api_client_id_length ) HTTPStatusCodes::unauthorized("This action requires a valid API client id.");
    if ( empty($secret) || strlen($secret) != LiveWhalePush::$_api_client_secret_length ) HTTPStatusCodes::unauthorized("This action requires a valid API client secret.");
    $result = $_LW->query("SELECT * FROM `" . LiveWhalePush::$_clients_table . "` WHERE `client_id` = '{$id}' AND `client_secret` = '{$secret}';");
    if ( !empty($result) && $result->num_rows == 1 ) return $result->fetch_assoc();
    HTTPStatusCodes::unauthorized("This action requires a valid API user; your client id and secret were not found.");
  }

  private function require_valid_subscription_request ( &$args ) {
    if ( array_key_exists('id', $args) ) {
      if ( empty($args['id']) || (int) $args['id'] != $args['id'] ) HTTPStatusCodes::bad_request("Subscription requests require a valid integer for id when id is provided.");
      $args['id'] = (int) $args['id'];
    } else {
      if ( !array_key_exists('object', $args) || empty($args['object']) ) HTTPStatusCodes::bad_request("Subscription requests require an object.");
      if ( !in_array($args['object'], LiveWhalePush::$_subscription_objects) ) HTTPStatusCodes::bad_request("Subscription requests require an object to be one of: " . Inflector::to_sentence(LiveWhalePush::$_subscription_objects, ', ', ' or ') . ".");
      if ( !array_key_exists('callback_url', $args) || empty($args['callback_url']) ) HTTPStatusCodes::bad_request("Subscription requests require a callback_url.");
      if ( preg_replace('~[^a-z\d:/\-_\.]~i', '', $args['callback_url']) != $args['callback_url'] ) HTTPStatusCodes::bad_request("Subscription requests require a callback_url containing only alphanumeric characters plus colon, period, slash, dash and underscore.");
      foreach ( LiveWhalePush::$_subscription_aspects as $aspect => $details ) {
        if ( array_key_exists($aspect, $args) ) {
          if ( empty($args[$aspect]) ) HTTPStatusCodes::bad_request("Subscription requests that possess an aspect such as {$aspect} must provide a value; don't send it otherwise.");
          $value = $args[$aspect];
          if ( eval($details['transform']) != $args[$aspect] ) HTTPStatusCodes::bad_request("Subscription requests that possess an aspect of {$aspect} may contain only {$details['allow']}.");
          if ( !is_numeric($args[$aspect]) ) $this->as_tag($args[$aspect]);
        }
      }
    }
  }

  private function subscription_query ( $args, $lead = 'SELECT * FROM' ) {
    $query = "{$lead} `" . LiveWhalePush::$_subscription_table . "` WHERE `client_id` = {$args['client_id']}";
    if ( array_key_exists('id', $args) && !empty($args['id']) ) {
      $query .= " AND `id` = {$args['id']}";
    } else {
      $query .= " AND `object` = '{$args['object']}' AND `callback_url` = '{$args['callback_url']}'";
      if ( !empty($args['place_id']) && !isset($args['radius']) ) $args['radius'] = 0;
      foreach ( LiveWhalePush::$_subscription_aspects as $aspect => $details ) {
        if ( (!array_key_exists($aspect, $args) || !isset($args[$aspect])) && $lead == 'SELECT * FROM' ) {
          $query .= " AND `{$aspect}` IS NULL";
        } else if ( array_key_exists($aspect, $args) && isset($args[$aspect]) ) {
          $value = $args[$aspect];
          $value = eval($details['transform']);
          $query .= " AND `{$aspect}` = " . ((is_numeric($value)) ? $value : "'{$value}'");
        }
      }
    }
    return "{$query} ORDER BY created_at;";
  }

  private function require_subscription_not_exist ( $args ) {
    global $_LW;
    $query = $this->subscription_query($args);
    $result = $_LW->query($query);
    if ( !empty($result) && $result->num_rows >= 1 ) HTTPStatusCodes::ok($this->subscription_to_json($result->fetch_assoc()));
  }

  private function require_successful_challenge ( $args ) {
    $challenge = hash_hmac('sha1', "{$_SERVER['REMOTE_ADDR']}--{$args['callback_url']}", $args['client_secret']);
		$session = curl_init("{$args['callback_url']}?hub.mode=subscription&hub.challenge={$challenge}" . ((!empty($args['verify_token'])) ? "&hub.verify_token={$args['verify_token']}" : ""));
		if ( !is_resource($session) ) HTTPStatusCodes::server_error("We were unable to initialize a request to {$args['callback_url']} at this time.");
		curl_setopt_array($session, $this->_curl_defaults);
		$response = curl_exec($session);
    curl_close($session);
    $result = $this->is_valid_response($response, $args['callback_url']);
    if ( is_string($result) ) HTTPStatusCodes::server_error($result);
		$body = trim(array_pop(explode("\r\n\r\n", $response)));
		if ( $body != $challenge ) HTTPStatusCodes::bad_request("We were unable to complete a request to {$args['callback_url']} at this time; the challenge failed.");
	}

  private function as_column ( &$value, $key ) {
    $value = "`{$value}`";
  }

  private function as_value ( &$value, $key, $args ) {
    if ( array_key_exists($key, $args) && !empty($args[$key]) ) {
      $value = $args[$key];
      if ( array_key_exists($key, LiveWhalePush::$_subscription_aspects) && array_key_exists('transform', LiveWhalePush::$_subscription_aspects[$key]) && !empty(LiveWhalePush::$_subscription_aspects[$key]['transform']) ) $value = eval(LiveWhalePush::$_subscription_aspects[$key]['transform']);
      if ( $key == 'radius' && $value > LiveWhalePush::$_subscription_max_radius ) $value = LiveWhalePush::$_subscription_max_radius;
      if ( !is_int($args[$key]) && !is_numeric($args[$key]) ) $value = "'{$value}'";
    } else if ( substr($key, -3) == '_at' ) {
      $value = "NOW()";
    }
  }

  private function as_tag ( &$value, $key ) {
    $value = Inflector::singularize(mb_strtolower($value, 'UTF-8'));
  }

  private function create_subscription ( $args ) {
    global $_LW;
    $columns = LiveWhalePush::$_subscription_columns;
    $columns = array_merge(array_keys(LiveWhalePush::$_subscription_aspects), $columns);
    $values = array_fill_keys($columns, 'NULL');
    array_walk($values, array($this, 'as_value'), $args);
    if ( !empty($values['place_id']) && $values['radius'] == 'NULL' ) $values['radius'] = 0;
    array_walk($columns, array($this, 'as_column'));
    $query = "INSERT INTO `" . LiveWhalePush::$_subscription_table . "` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");";
    $result = $_LW->query($query);
    if ( empty($result) ) HTTPStatusCodes::server_error("We were unable to create your subscription at this time. Query( {$query} )");
		$result = $_LW->query('SELECT LAST_INSERT_ID() AS id;');
		if ( empty($result) || $result->num_rows < 1 ) {
      $query = $this->subscription_query($args, "DELETE FROM");
      $_LW->query($query);
		  HTTPStatusCodes::server_error("We were unable to create your subscription at this time.");
		}
	  $row = $result->fetch_assoc();
    $values['id'] = (int) $row['id'];
    HTTPStatusCodes::created($this->subscription_to_json($values));
  }

  private function delete_subscription ( $args ) {
    global $_LW;
    $query = $this->subscription_query($args, "DELETE FROM");
    $result = $_LW->query($query);
    if ( empty($result) ) HTTPStatusCodes::server_error("We were unable to delete your subscription at this time.");
    HTTPStatusCodes::ok("Subscription deleted.");
  }

  /* public subscription controls */
  public function subscribe ( $args = array() ) {
    while ( is_array($args) && count($args) == 1 && is_array($args[0]) ) $args = $args[0];
    $args['client'] = $this->require_valid_api_client($args);
    $this->require_valid_subscription_request($args);
    $args['client_id'] = (int) $args['client']['id'];
    $this->require_subscription_not_exist($args);
    $this->require_successful_challenge($args);
    $this->create_subscription($args);
  }

  public function unsubscribe ( $args = array() ) {
    while ( is_array($args) && count($args) == 1 && is_array($args[0]) ) $args = $args[0];
    $args['client'] = $this->require_valid_api_client($args);
    $this->require_valid_subscription_request($args);
    $args['client_id'] = (int) $args['client']['id'];
    $this->delete_subscription($args);
  }

  public function subscriptions ( $args = array() ) {
    global $_LW;
    while ( is_array($args) && count($args) == 1 && is_array($args[0]) ) $args = $args[0];
    $args['client'] = $this->require_valid_api_client($args);
    $args['client_id'] = (int) $args['client']['id'];
		$result = $_LW->query("SELECT * FROM `" . LiveWhalePush::$_subscription_table . "` WHERE `client_id` = {$args['client_id']};");
		if ( empty($result) || $result->num_rows < 1 ) HTTPStatusCodes::not_found("There were no subscriptions found.");
		$subscriptions = array();
		while ( $row = $result->fetch_assoc() ) $subscriptions[] = $this->public_subscription_for($row);
		HTTPStatusCodes::ok($this->to_json($subscriptions));
  }

  /* output methods */
  private function public_subscription_for ( $subscription = array() ) {
    $output = array();
    foreach ( LiveWhalePush::$_subscription_public_columns as $key ) {
      if ( array_key_exists($key, $subscription) && !empty($subscription[$key]) && $subscription[$key] != 'NULL' ) {
        $output[$key] = preg_replace('~^\'(.*)\'$~', '$1', $subscription[$key]);
      }
    }
    return $output;
  }

  private function to_json( $content = array() ) {
    if ( !empty($content) ) return json_encode($content);
    return "";
  }

  private function subscription_to_json ( $subscription = array() ) {
    return $this->to_json($this->public_subscription_for($subscription));
  }

  /* cURL response handling */

  private function is_valid_response ( $response, $callback_url ) {
		if ( $response === FALSE ) return "We were unable to complete a request to {$callback_url} at this time.";
		if ( !preg_match('~HTTP/1\.[01] ([\d]{3}) ([^\r\n]+)~', $response, $matches) ) return "We were unable to complete a request to {$callback_url} at this time; the server response could not be parsed.";
		if ( (int) $matches[1] != 200 ) return "We were unable to complete a request to {$callback_url} at this time; the server responded with: {$matches[1]} {$matches[2]}.";
		return true;
  }

  /* places handling */

  private function has_matching_places ( $before, $after ) {
    if ( !is_array($before) ) $before = explode(',', $before);
    if ( !is_array($after) ) $after = explode(',', $after);
    if ( count(array_diff($before, $after)) === 0 ) return TRUE;
    return FALSE;
  }

  private function get_existing_places ( $ids ) {
    global $_LW;
    // getting new places to be assigned; a bad way to do this, since the association hasnÕt been saved yet
    if ( is_array($ids) ) $ids = implode(',', $ids);
    $places = $_LW->query("SELECT * FROM `livewhale_places` WHERE `id` IN ({$ids});");
    if ( $places && $places->num_rows ) {
      $place_ids = array();
      $place_latitudes = array();
      $place_longitudes = array();
      while ( $place = $places->fetch_assoc() ) {
        $place_ids[] = $place['id'];
        $place_latitudes[] = $place['latitude'];
        $place_longitudes[] = $place['longitude'];
      }
      return array(implode(',', $place_ids), implode(',', $place_latitudes), implode(',', $place_longitudes));
    }
    return array(NULL, NULL, NULL);
  }

  /* update handling */

  private function changed ( $before, $after ) {
    global $_LW;
    $changed = array();
    foreach ( $before as $key => $value ) if ( $key != 'last_modified' && $value != $after[$key] ) $changed[$key] = $after[$key];
    return $changed;
  }

  private function is_changed ( $before, $after ) {
    foreach ( $before as $key => $value ) if ( $key != 'last_modified' && $value != $after[$key] ) return TRUE;
    return FALSE;
  }

  private function find_subscriptions_for ( $object, $tags, $changed ) {
  	global $_LW;
  	$query = "SELECT `" . LiveWhalePush::$_subscription_table . "`.*, `" . LiveWhalePush::$_clients_table . "`.`client_secret`, `" . LiveWhalePush::$_clients_table . "`.`email` FROM `" . LiveWhalePush::$_subscription_table . "` JOIN `" . LiveWhalePush::$_clients_table . "` ON `" . LiveWhalePush::$_subscription_table . "`.`client_id` = `" . LiveWhalePush::$_clients_table . "`.`id`";
  	if ( !empty($object['place_latitudes']) && !empty($object['place_longitudes']) ) {
      $latitude_in_radians = $object['place_latitudes'] * LiveWhalePush::$_degrees_to_radians;
      $longitude_in_radians = $object['place_longitudes'] * LiveWhalePush::$_degrees_to_radians;
      $query .= " LEFT JOIN `livewhale_places` ON `livewhale_places`.`id` = `" . LiveWhalePush::$_subscription_table . "`.`place_id` WHERE (" . LiveWhalePush::$_subscription_table . ".`radius` IS NULL OR " . LiveWhalePush::$_subscription_table . ".`radius` >= (ACOS(SIN(`livewhale_places`.`latitude` * " . LiveWhalePush::$_degrees_to_radians . ") * SIN({$latitude_in_radians}) + COS(`livewhale_places`.`latitude` * " . LiveWhalePush::$_degrees_to_radians . ") * COS({$latitude_in_radians}) * COS({$longitude_in_radians} - `livewhale_places`.`longitude` * " . LiveWhalePush::$_degrees_to_radians . ")) * " . LiveWhalePush::$_earth_radius . ")) AND";
  	} else {
      $query .= " WHERE";
  	}
    $query .= " `" . LiveWhalePush::$_subscription_table . "`.`object` = '{$this->_object_type}' AND (`" . LiveWhalePush::$_subscription_table . "`.`group_id` IS NULL" . ((!empty($object['gid'])) ? " OR `" . LiveWhalePush::$_subscription_table . "`.`group_id` = {$object['gid']}" : "") . ") AND (`" . LiveWhalePush::$_subscription_table . "`.`tag` is NULL" . ((!empty($tags)) ? " OR `" . LiveWhalePush::$_subscription_table . "`.`tag` = '" . implode("' OR `" . LiveWhalePush::$_subscription_table . "`.`tag` = '", $tags) . "'" : "") . ");";
		$result = $_LW->query($query);
  	if ( !empty($result) && $result->num_rows ) {
      while ( $subscription = $result->fetch_assoc() ) {
        $client_key = "{$subscription['client_secret']},{$subscription['email']}";
        if ( empty($this->_subscriptions[$client_key]) ) $this->_subscriptions[$client_key] = array();
        if ( empty($this->_subscriptions[$client_key]["{$subscription['callback_url']}"]) ) $this->_subscriptions[$client_key]["{$subscription['callback_url']}"] = array();
        $this->_subscriptions[$client_key]["{$subscription['callback_url']}"][] = array(
          'subscription_id' => (int) $subscription['id'],
          'object' => $this->_object_type,
          'object_id' => (int) $object['id'],
          'group_id' => ((empty($subscription['group_id'])) ? '' : (int) $subscription['group_id']),
          'place_id' => ((empty($subscription['place_id'])) ? '' : (int) $subscription['place_id']),
          'radius' => ((empty($subscription['radius'])) ? '' : (float) $subscription['radius']),
          'tag' => ((empty($subscription['tag'])) ? '' : $subscription['tag']),
          'updated_at' => date("c"),
          'is_new' => (($changed['is_new']) ? TRUE : FALSE),
          'is_deleted' => (($changed['is_deleted']) ? TRUE : FALSE),
          'changed' => ((!$changed['is_new'] && !$changed['is_deleted']) ? array_keys($changed) : array())
        );
      }
    }
    return NULL;
  }

  private function notify_subscribers () {
    if ( empty($this->_subscriptions) ) return NULL;
    foreach ( (array) $this->_subscriptions as $client_key => $notifications ) {
      list($client_secret, $client_email) = explode(',', $client_key);
      foreach ( $notifications as $callback_url => $updates ) {
        $message = "";
        try {
          $json = json_encode($updates);
          $signature = hash_hmac('sha1', $json, $client_secret);
    		  $session = curl_init($callback_url);
    		  curl_setopt_array($session, $this->_curl_defaults);
    		  curl_setopt($session, CURLOPT_HTTPHEADER, array("X-Hub-Signature: {$signature}", "Expect:"));
    		  curl_setopt($session, CURLOPT_POSTFIELDS, "body={$json}");
    		  $response = curl_exec($session);
          curl_close($session);
          $result = $this->is_valid_response($response, $callback_url);
          if ( is_string($result) ) @mail($client_email, 'LiveWhale Push Subscription Error', "{$result}\npayload:{$json}");
        } catch (Exception $e) {
          if ( !empty($json) ) {
      		  @mail($client_email, 'LiveWhale Push Subscription Error', "Exception: {$e}\n\nThe data was: {$json}");
          } else {
      		  @mail($client_email, 'LiveWhale Push Subscription Error', "Exception: {$e}\n\nThe data was: " . var_export($updates, TRUE));
          }
        }
      }
    }
    return NULL;
  }

  /* LiveWhale Hooks */

  public function save_success () {
  	global $_LW;
  	if ( $this->_watching && !empty($this->_before_update[$_LW->_POST['item_id']]) && !empty($this->_query) ) {
      $result = $_LW->query($this->_query);
      if ( !empty($result) && $result->num_rows ) {
        $after = $result->fetch_assoc();
        $changed = $this->changed($this->_before_update[$after['id']], $after);
        if ( !empty($_LW->_POST['places_id']) && !$this->has_matching_places($this->_before_update[$after['id']]['place_ids'], $_LW->_POST['places_id']) ) list($changed['place_ids'], $changed['place_latitudes'], $changed['place_longitudes']) = $this->get_existing_places($_LW->_POST['places_id']);
        if ( !empty($_LW->_POST['places_latitude']) && !empty($_LW->_POST['places_longitude']) ) {
          $changed['place_latitudes'] = $_LW->_POST['places_latitude'];
          $changed['place_longitudes'] = $_LW->_POST['places_longitude'];
        }
        if ( empty($changed) ) return NULL;
      }
    } else if ( $this->_watching && !empty($_LW->new_id) ) {
      $this->_query = "SELECT `livewhale_{$this->_object_type}`.* FROM `livewhale_{$this->_object_type}` WHERE `livewhale_{$this->_object_type}`.`id` = {$_LW->new_id};";
      $result = $_LW->query($this->_query);
      if ( !empty($result) && $result->num_rows ) {
        $after = $result->fetch_assoc();
        $changed = array('is_new' => TRUE);
        if ( !empty($_LW->_POST['places_id']) ) list($after['place_ids'], $after['place_latitudes'], $after['place_longitudes']) = $this->get_existing_places($_LW->_POST['places_id']);
        if ( !empty($_LW->_POST['places_latitude']) && !empty($_LW->_POST['places_longitude']) ) {
          $changed['place_latitudes'] = $_LW->_POST['places_latitude'];
          $changed['place_longitudes'] = $_LW->_POST['places_longitude'];
        }
      }
    }
    if ( !empty($after) ) {
      if ( $_SERVER['REMOTE_ADDR'] == '149.175.43.62' ) @mail('davidwmckelvey@gmail.com', 'LiveWhale sav_success', var_export(array($after, $_LW->_POST), TRUE));
      if ( !empty($this->_before_update[$after['id']]['search_tags']) ) {
        $tags = explode(',', $this->_before_update[$after['id']]['search_tags']);
      } else {
        $tags = array();
      }
      if ( !empty($after['search_tags']) ) $tags = array_merge($tags, explode(',', $after['search_tags']));
      $tags = array_unique($tags);
      $this->find_subscriptions_for($after, $tags, $changed);
      return $this->notify_subscribers();
    }
    return NULL;
  }

  public function manager_submit () {
  	global $_LW;
    if ( $this->_watching && !empty($this->_before_update) && !empty($this->_query) ) {
      if ( !empty($this->_delete_ids) ) {
        foreach ( $this->_delete_ids as $id ) $this->find_subscriptions_for($this->_before_update[$id], explode(',', $this->_before_update[$id]['search_tags']), array('is_deleted' => TRUE));
      } else {
        $result = $_LW->query($this->_query);
        if ( !empty($result) && $result->num_rows ) {
          while ( $after = $result->fetch_assoc() ) {
            $changed = $this->changed($this->_before_update[$after['id']], $after);
            if ( !empty($changed) ) {
              $tags = array_unique(array_merge(explode(',', $this->_before_update[$after['id']]['search_tags']), explode(',', $after['search_tags'])));
              $this->find_subscriptions_for($after, $tags, $changed);
            }
          }
        }
      }
      return $this->notify_subscribers();
    }
    return NULL;
  }

  public function delete () {
  	if ( $this->_watching && !empty($_GET['d']) && !empty($this->_delete_ids) ) {
      $this->find_subscriptions_for($this->_before_update[$this->_delete_ids[0]], explode(',', $this->_before_update[$this->_delete_ids[0]]['search_tags']), array('is_deleted' => TRUE));
      return $this->notify_subscribers();
  	}
  	return NULL;
  }

}

?>