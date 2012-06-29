<?php

$_LW->REGISTERED_APPS['push']=array(
	'title'=>'Push', // the module name
	'handlers' => array('onLoad','onSaveSuccess','onManagerSubmit','onDelete')
);

// ----- PASS-THRU HANDLERS ---------------------------------------------------------

class LiveWhaleApplicationPush {

  public function onLoad () {
    global $_LW;
  	include_once($_LW->INCLUDES_DIR_PATH . '/client/modules/push/includes/class.livewhalepush.php');
    $_LW->REGISTERED_APPS['push']['object'] = new LiveWhalePush();
    return NULL;
  }
  
  public function onSaveSuccess () {
    global $_LW;
    return $_LW->REGISTERED_APPS['push']['object']->save_success();
  }
  
  public function onManagerSubmit () {
    global $_LW;
    return $_LW->REGISTERED_APPS['push']['object']->manager_submit();
  }
  
  public function onDelete () {
    global $_LW;
    return $_LW->REGISTERED_APPS['push']['object']->delete();
  }

}

?>