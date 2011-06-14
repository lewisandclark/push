<?php

$_LW->REGISTERED_APPS['push']=array(
	'title'=>'Push', // the module name
	'handlers' => array('init','save_success','manager_submit','delete')
);

// ----- PASS-THRU HANDLERS ---------------------------------------------------------

class LiveWhaleApplicationPubsubhub {

  public function init () {
    global $_LW;
  	include_once($_LW->INCLUDES_DIR_PATH . '/client/modules/push/includes/class.push.php');
    $_LW->REGISTERED_APPS['push']['object'] = new PubSubHub();
    return NULL;
  }
  
  public function saveSuccess () {
    global $_LW;
    return $_LW->REGISTERED_APPS['push']['object']->save_success();
  }
  
  public function managerSubmit () {
    global $_LW;
    return $_LW->REGISTERED_APPS['push']['object']->manager_submit();
  }
  
  public function delete () {
    global $_LW;
    return $_LW->REGISTERED_APPS['push']['object']->delete();
  }

}

?>