<?php

$_LW->REGISTERED_APPS['pubsubhub']=array(
	'title'=>'PubSubHub', // the module name
	'handlers' => array('init','save_success','manager_submit','delete')
);

// ----- PASS-THRU HANDLERS ---------------------------------------------------------

class LiveWhaleApplicationPubsubhub {

  public function init () {
    global $_LW;
  	include_once($_LW->INCLUDES_DIR_PATH . '/client/modules/pubsubhub/includes/class.pubsubhub.php');
    $_LW->REGISTERED_APPS['pubsubhub']['object'] = new PubSubHub();
    return NULL;
  }
  
  public function saveSuccess () {
    global $_LW;
    return $_LW->REGISTERED_APPS['pubsubhub']['object']->save_success();
  }
  
  public function managerSubmit () {
    global $_LW;
    return $_LW->REGISTERED_APPS['pubsubhub']['object']->manager_submit();
  }
  
  public function delete () {
    global $_LW;
    return $_LW->REGISTERED_APPS['pubsubhub']['object']->delete();
  }

}

?>