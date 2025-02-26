<?php
  
namespace App\Autotrade;

use DB;
use App;
use Auth;
use Queue;
use Input;
use Carbon\Carbon;
use Libraries\Rsi;

class AutoTradeBase
{
  public $cli = null;
  public $time_base = null;
  public $data_driver = null;
  public $orders_driver = null; 
  public $cleanup_driver = null;   
  public $account_driver = null;   
  public $positions_driver = null;  
  
  //
  // Construct.
  //
  public function __construct($cli, $time_base, $data_driver, $account_driver, $positions_driver, $orders_driver, $cleanup_driver)
  {
    $this->cli = $cli;
    $this->time_base = $time_base;
    $this->data_driver = $data_driver;
    $this->orders_driver = $orders_driver;
    $this->account_driver = $account_driver;
    $this->positions_driver = $positions_driver;
    $this->cleanup_driver = $cleanup_driver;    
  }
  
  //
  // Run the auto trader.
  //
  public function run($loop = true)
  {
    // If No loop.
    if(! $loop)
    {
      $now = Carbon::now();      
      
      // Call before clean up stuff.
      $this->cleanup_driver->before_on_data();      
      
      // On data time.
      $this->on_data($now, $this->data_driver->get_data($now));
      
      // Call after clean up stuff.
      $this->cleanup_driver->after_on_data();      
    }    
    
    // Just keep looping until we are done.
    while($loop)
    {
      // Get current time object
      $now = Carbon::now();

      // Call before clean up stuff.
      $this->cleanup_driver->before_on_data();

      // Fire every min.
      if(($now->second == 0) && ($this->time_base == '1 Minute'))
      {
        $this->on_data($now, $this->data_driver->get_data($now));
      }
      
      // Call clean up stuff.
      $this->cleanup_driver->after_on_data();
         
      // Sleep one second.
      sleep(1);
    }
  }
  
  //
  // We call this when we have data.
  // This function should be overwritten in 
  // a different focused file.
  //
  // $now - Carbon instance of time. 
  // $data - Data that was returned from the data driver.
  //
  public function on_data($now, $data)
  {
    return true;
  }  
}

/* End File */